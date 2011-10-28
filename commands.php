<?php

function failure($msg = 'failure.') {
  echo "<span class='bad'>$msg</span>\n";
  return false;
}

function success($msg = 'ok.') {
  echo "<span class='good'>$msg</span>\n";
  return true;
}

function action($msg, $highlight = false) {
  $msg = str_pad($msg, 50);
  if ($highlight) $msg = '<span class="highlight">'.$msg.'</span>';
  echo $msg;
}

function headline($msg) {
  echo "\n<span class=\"highlight\">== $msg ==</span>\n\n";
  return true;
}

function info($msg) {
  echo "<span style=\"color: #666;\">$msg</span>\n";
  return true;
}

function abort($msg) {
  echo "\n<span class=\"highlight\">$msg</span>\n\n";
  return false;
}

/// Verbindet als root zur DB.
function root_connect($rootpw) {
  action("connecting as root... ");
  if (!mysql_connect('localhost', 'root', $rootpw)) return failure(mysql_error());
  mysql_query('SET NAMES utf8;');
  mysql_query('SET CHARACTER SET utf8;');
  return success();
}

/// Prüft, ob alle benötigten Verzeichnisse und Dateien vorhanden und schreibbar sind.
function check_permissions() {
  action('checking permissions...');

  // Sorge zuerst dafür, dass SYNC_DIR und SYNC_DATA_DIR existieren
  if (!file_exists(SYNC_DATA_DIR) && !mkdir(SYNC_DATA_DIR))
    return failure('error creating '.SYNC_DATA_DIR);

  // Prüfe Dateien und Verzeichnisse auf Schreibbarkeit
  $check = array_merge(
    array(
      SYNC_DIR,
      SYNC_DIR."/last-sync",
      SYNC_DATA_DIR,
      SYNC_DATA_DIR."/config"
    ),
    glob(SYNC_DATA_DIR."/*.schema.sql"),
    glob(SYNC_DATA_DIR."/*.data.sql")
  );
  foreach ($check as $filename) {
    if (file_exists($filename) && !is_writable($filename))
      return failure("$filename must be writable");
  }

  // Lege htaccess-Datei an
  file_put_contents(SYNC_DATA_DIR.'/.htaccess', "Deny from all");
    
  return success();
}

/// Speichert DB-Zugangsdaten in der Config-Datei.
/// hängt ab von: check_permissions
function save_config($db, $user, $pw, $autosync) { 
  action("saving new config... ");
  file_put_contents(SYNC_DATA_DIR."/config", "$db\n$user\n$pw\n".($autosync ? 'active' : 'inactive'));
  return success('ok.');
}

/// Lädt den Inhalt der Config-Datei.
/// hängt ab von: check_permissions
function load_config() {
  global $db, $user, $pw, $autosync;
  action('loading config...');
  list($db, $user, $pw, $autosync) = explode("\n", file_get_contents(SYNC_DATA_DIR."/config"));
  return success();
}

/// Verbindet zur Datenbank.
/// hängt ab von: load_config
function connect() {
  global $db, $user, $pw, $connected;
  $connected = false;
  action("connecting to database... ");
  if (!mysql_connect('localhost', $user, $pw)) return failure(mysql_error());
  if (!mysql_select_db($db)) return failure(mysql_error());
  mysql_query('SET NAMES utf8;');
  mysql_query('SET CHARACTER SET utf8;');
  $connected = true;
  return success();
}

/// Erstellt die Datenbank mit den Zugangsdaten wie in der Config-Datei angegeben.
/// hängt ab von: root_connect, load_account_data
function create_db() { 
  global $db, $user, $pw;
  action("creating database... ",true);
  if (!mysql_query("CREATE DATABASE `$db`;")) return failure(mysql_error());
  if (!mysql_query("GRANT ALL PRIVILEGES ON `$db`.* TO '$user'@'localhost' IDENTIFIED BY '$pw';")) return failure(mysql_error());
  if (!mysql_query("FLUSH PRIVILEGES;")) return failure(mysql_error());
  return success();
}

/// Lädt die Namen aller Tabellen aus Datenbank und Dump-Files.
/// hängt ab von: check_permissions, connect
function load_tables() { 
  global $db_tables, $dump_tables, $all_tables;
  action('loading table names from DB and dumps...');
  $db_tables = array();
  foreach (getassoc("SHOW FULL TABLES") as $table => $type) {
    if ($type == 'VIEW') continue;
    $db_tables[] = $table;
  }
  $dump_tables = array();
  $files = glob(SYNC_DATA_DIR."/*.schema.sql");
  foreach ($files as $file)
    $dump_tables[] = basename($file, '.schema.sql');
  $all_tables = array_unique(array_merge($db_tables, $dump_tables));
  return success();
}

/// Nach Ausführung dieser Methode ist sichergestellt, dass die Tabellenstruktur stimmt (sync_id, sync_flag) und die Trigger installiert sind.
/// hängt ab von: connect
/// \todo SHOW TRIGGERS und SHOW TABLE STATUS haben ne lausige Performance. Gehts vlt irgendwie besser?
function setup_tables($force_setup_triggers = false) {
  global $ignore;
  action("configuring tables...");

  $triggers = getcol("SHOW TRIGGERS;");
  $status = getrows("SHOW TABLE STATUS;");

  foreach (getassoc("SHOW FULL TABLES") as $table => $type) {
    if ($type == 'VIEW' || in_array($table, $ignore)) continue;
    $fields = fieldnames($table);

    // Parameter CHECKSUM muss gesetzt sein
    foreach ($status as $statusrow) {
      if ($statusrow['Name'] == $table && $statusrow['Engine'] == 'MyISAM' && $statusrow['Checksum'] == null)
        mysql_query("ALTER TABLE $table CHECKSUM = 1;");
    }

    // Spalte sync_id neu anlegen, falls nötig. Der Trigger *erzwingt*, dass sync_id bei neuen Datensätzen immer NULL ist.
    if (!in_array('sync_id', $fields) || !in_array("{$table}_insert", $triggers) || $force_setup_triggers) {
      if (!in_array('sync_id', $fields))
        if (!mysql_query("ALTER TABLE $table ADD sync_id CHAR(32) DEFAULT NULL, ADD UNIQUE (sync_id);")) return failure(mysql_error());
      if (!mysql_query("DROP TRIGGER IF EXISTS {$table}_insert;")) return failure(mysql_error());
      if (!mysql_query("CREATE TRIGGER {$table}_insert BEFORE INSERT ON $table FOR EACH ROW BEGIN
          SET NEW.sync_id = NULL;
        END;"))
        return failure(mysql_error());
    }

    // Spalte sync_flag und UPDATE-Trigger darauf neu anlegen, falls nötig
    if (!in_array('sync_flag', $fields) || !in_array("{$table}_update", $triggers) || $force_setup_triggers) {
      if (!in_array('sync_flag', $fields))
        if (!mysql_query("ALTER TABLE $table ADD sync_flag TINYINT(1) NOT NULL DEFAULT 0, ADD INDEX (sync_flag);")) return failure(mysql_error());
      if (!mysql_query("DROP TRIGGER IF EXISTS {$table}_update;")) return failure(mysql_error());
      if (!mysql_query("CREATE TRIGGER {$table}_update BEFORE UPDATE ON $table FOR EACH ROW BEGIN
          IF NEW.sync_flag = -1 THEN SET NEW.sync_flag = 0;
          ELSE SET NEW.sync_flag = 1;
          END IF;
        END;"))
        return failure(mysql_error());
    }

  }

  return success();
}

/// Importiert Tabelle (Schema und Daten) aus Dump-Files.
/// hängt ab von: check_permissions, connect
function import_table($table) {
  global $timestamps;
  action("importing $table... ", true);

  // Schema droppen und neu importieren
  mysql_query("DROP TABLE IF EXISTS $table;");
  if (!mysql_query(file_get_contents(SYNC_DATA_DIR."/$table.schema.sql"))) return failure(mysql_error());

  // Performance: Tabelle locken, Key-Updates deaktivieren
  mysql_query("LOCK TABLES $table WRITE;");
  mysql_query("ALTER TABLE $table DISABLE KEYS;");

  // Daten einlesen. Bei $ignore-Tabellen gibts die Datei aber nicht, daher die Abfrage.
  if (is_file(SYNC_DATA_DIR."/$table.data.sql")) {
    foreach (file(SYNC_DATA_DIR."/$table.data.sql") as $line)
      if (!mysql_query($line)) return failure(mysql_error());
  }

  // Key-Berechnung wieder aktivieren und Tabelle freigeben
  mysql_query("ALTER TABLE $table ENABLE KEYS;");
  mysql_query("UNLOCK TABLES;");
  return success();
}

/// Löscht eine Tabelle in der Datenbank.
/// hängt ab von: connect
function drop_table($table) {
  action("dropping table $table...", true);
  if (!mysql_query("DROP TABLE $table")) return failure(mysql_error());
  return success();
}

/// Löscht die Dump-Files zu einer Tabelle.
/// hängt ab von: check_permissions
function delete_dump($table) { 
  action("deleting dump of $table...", true);
  is_file(SYNC_DATA_DIR."/$table.schema.sql") && unlink(SYNC_DATA_DIR."/$table.schema.sql");
  is_file(SYNC_DATA_DIR."/$table.data.sql")   && unlink(SYNC_DATA_DIR."/$table.data.sql");
  return success();
}

/// Erstellt den Dump einer Tabelle in 2 SQL-Dateien (Schema+Daten).
/// hängt ab von: check_permissions, connect
function dump_table($table) {
  global $ignore, $timestamps;
  action("dumping table $table...", true);

  // Tabelle locken
  if (!mysql_query("LOCK TABLES $table WRITE;")) return failure(mysql_error());

  // Schema dumpen
  file_put_contents(SYNC_DATA_DIR."/$table.schema.sql", get_create_table($table));

  // Daten dumpen
  if (!in_array($table, $ignore)) {

    // Generiere IDs für neu eingefügte Datensätze
    if (!generate_missing_ids($table)) return failure('error generating ids');

    // In Dump-File schreiben
    $f = fopen(SYNC_DATA_DIR."/$table.data.sql", 'w');
    $desc = getrows("DESC $table;");
    $rs = mysql_query("SELECT ".build_dump_select_clause($desc)." FROM $table ORDER BY sync_id;");
    while ($row = mysql_fetch_assoc($rs))
      fwrite($f, dump_row_query($row, $table, $desc));
    fclose($f);
    
  }

  // Als bearbeitet markieren und Lock freigeben
  mysql_query("UPDATE $table SET sync_flag = -1;");
  mysql_query("UNLOCK TABLES;");
  
  return success();
}

/// Aktualisiert eine Dump-Datei: es wird ein Diff zwischen den sync_id's in der DB und den sync_id's im Dump gemacht und
/// die nötigen Änderungen in der Dump-Datei vorgenommen.
/// hängt ab von: check_permissions, setup_tables
/// \todo Algorithmus so schreiben, dass die Reihenfolge aus der DB erhalten bleibt
function update_dump($table) { 
  global $ignore, $timestamps;
  if (in_array($table, $ignore)) return true;
  action("updating dump: $table", true);

  // Tabelle locken
  if (!mysql_query("LOCK TABLES $table WRITE;")) return failure(mysql_error());
  
  $desc = getrows("DESC $table;");
  
  // Dump einlesen in ein Array {ID} => {SQL-Statement}
  $index = array();
  foreach (file(SYNC_DATA_DIR."/$table.data.sql") as $i => $line) {
    preg_match('/sync_id = \'([0-9a-f]+)\'/', $line, $match);
    $hash = $match[1];
    $index[$hash] = $line;
  }

  // Generiere IDs für neu eingefügte Datensätze
  if (!generate_missing_ids($table)) return failure('error generating ids');

  // Berechne eingefügte/gelöschte/geänderte Datensätze
  $dump_ids = array_keys($index);
  $db_ids   = getcol("SELECT sync_id FROM $table ORDER BY sync_id;");
  $deleted  = array_minus($dump_ids, $db_ids);
  $inserted = array_minus($db_ids, $dump_ids);
  $modified = getcol("SELECT sync_id FROM $table WHERE sync_flag = 1;");

  // Gelöschte Datensätze entfernen
  foreach ($deleted as $id)
    unset($index[$id]);

  // Neue und modifizierte Datensätze in den Dump schreiben
  foreach (array_merge($inserted, $modified) as $id) {
    $row = getrow("SELECT ".build_dump_select_clause($desc)." FROM $table WHERE sync_id = '$id';");
    $index[$id] = dump_row_query($row, $table, $desc);
  }

  ksort($index); /// \todo Nicht so schön, da schlechte Komplexität. Gehts evtl in linearer Laufzeit?

  // Daten zurückschreiben
  $f = fopen(SYNC_DATA_DIR."/$table.data.sql", "w");
  foreach ($index as $line) fwrite($f, $line);
  fclose($f);

  // Als bearbeitet markieren und Lock freigeben
  mysql_query("UPDATE $table SET sync_flag = -1;");
  mysql_query("UNLOCK TABLES;");
  
  return success();
}

/// Lädt die Timestamps und Checksummen des letzten synchronen Zustands aus sync/last-sync.
/// Format: {Tabellenname} {Import-Timestamp der Dump-File} {Checksumme in Datenbank}<BR>
/// hängt ab von: check_permissions
function load_timestamps() {
  global $timestamps, $checksums;
  action('loading timestamps/checksums of last sync...');
  $timestamps = array();
  foreach (file(SYNC_DIR."/last-sync") as $line) {
    if (!trim($line)) continue;
    list($table, $timestamp, $checksum) = explode(' ', trim($line));
    $timestamps[$table] = $timestamp;
    $checksums[$table] = $checksum;
  }
  return success();
}

/// Speichert evtl aktualisierte Timestamps wieder in sync/last-sync.
/// hängt ab von: load_timestamps
function save_timestamps() {
  global $timestamps, $checksums;
  action('saving timestamps...');
  $f = fopen(SYNC_DIR."/last-sync", 'w');
  if (!$f) return failure("cannot write ".SYNC_DIR."/last-sync");
  foreach (getassoc("SHOW FULL TABLES;") as $table => $type) {
    if ($type == 'VIEW') continue;
    fwrite($f, "$table ".intval(@$timestamps[$table])." ".(@$checksums[$table])."\n");
  }
  return success();
}

/// Markiere eine Tabelle als synchron (muss danach mit save_timestamps() gespeichert werden).
/// hängt ab von: load_timestamps
function mark_synced($table) {
  global $timestamps, $checksums;
  $timestamps[$table] = time();
  $checksums[$table] = checksum_database($table);
  return true;
}

/// Lädt die Daten-Checksumme einer DB-Tabelle.
/// hängt ab von: connect
function checksum_database($table) { // depends: connect
  global $ignore;
  if (!in_array($table, $ignore)) {
    $chk = getrow("CHECKSUM TABLE $table;");
    return $chk[1];
  } else {
    return 0;
  }
}

/// Lädt die Daten-Checksumme zum Zeitpunkt des letzten Syncs.
/// hängt ab von: connect
function checksum_sync($table) {
  global $checksums;
  return array_key_exists($table, $checksums) ? $checksums[$table] : 0;
}

/// Hole den Timestamp der Dump-Files (File Modification Date).
/// hängt ab von: check_permissions
function timestamp_dump($table) {
  return max(@filemtime(SYNC_DATA_DIR."/$table.schema.sql"), @filemtime(SYNC_DATA_DIR."/$table.data.sql"));
}

/// Hole den Timestamp des letzten synchronen Zustands.
/// hängt ab von: load_timestamps
function timestamp_sync($table) {
  global $timestamps;
  return intval(@$timestamps[$table]);
}

/// Verhindert die parallele Ausführung mehrerer Instanzen mittels Mutex-Lock.
/// hängt ab von: check_permissions
function lock() {
  global $acquired_lock;
  $acquired_lock = false;
  action('acquiring lock...');
  if (file_exists(SYNC_DIR.'/lock'))
    return failure('sync is locked by '.SYNC_DIR.'/lock');
  file_put_contents(SYNC_DIR.'/lock', time());
  $acquired_lock = true;
  return success();
}

/// Gibt den Mutex-Lock und alle Table-Locks wieder frei.
function unlock() {
  action('unlocking...');
  if (is_file(SYNC_DIR.'/lock')) unlink(SYNC_DIR.'/lock');
  if (!mysql_query("UNLOCK TABLES;")) return failure(mysql_error());
  return success();
}

/// Importiert geänderte Dumps in die Datenbank und verwirft die Tabellen zu gelöschten Dumps.
/// hängt ab von: check_permissions, load_tables, load_timestamps
function sync_dumps_to_db($import_all = false) {
  global $all_tables, $dump_tables, $db_tables;
  $success = true; $skipped = 0;
  foreach ($all_tables as $table) {

    if (in_array($table, $dump_tables) && $import_all) // Benutzer verlangt einen vollständigen Import
      $success = $success && import_table($table) && mark_synced($table);
      
    elseif (in_array($table, $dump_tables) && !timestamp_sync($table)) // Tabelle noch nicht importiert
      $success = $success && import_table($table) && mark_synced($table);
      
    elseif (!in_array($table, $dump_tables) && timestamp_sync($table)) // Tabelle wurde schon importiert, Dump wurde gelöscht
      $success = $success && drop_table($table) && mark_synced($table);
      
    elseif (timestamp_dump($table) > timestamp_sync($table))  // Tabelle wurde bereits importiert, Dump-File aktualisiert
      $success = $success && import_table($table) && mark_synced($table);

    else $skipped++;
  }
  if ($skipped == count($db_tables)) info("no tables to be imported.");
  return $success;
}

/// Legt neue Dumps an, aktualisiert existierende oder löscht nicht mehr benötigte.
/// hängt ab von: check_permissions, load_tables, setup_tables, load_timestamps
function sync_db_to_dumps($export_all = false) {
  global $all_tables, $dump_tables, $db_tables;
  $success = true; $skipped = 0;
  foreach ($all_tables as $table) {

    if ($export_all) // Benutzer verlangt einen vollständigen Export
      $success = $success && dump_table($table) && mark_synced($table);
      
    elseif (!in_array($table, $dump_tables)) // Tabelle wurde neu angelegt und es existiert noch keine Dump-Datei
      $success = $success && dump_table($table) && mark_synced($table);
      
    elseif (!in_array($table, $db_tables)) // Tabelle wurde gelöscht, Dump existiert noch
      $success = $success && delete_dump($table) && mark_synced($table);
      
    elseif (schemas_differ($table)) // Schema (und evtl Daten) wurde modifiziert
      $success = $success && dump_table($table) && mark_synced($table);
      
    elseif (checksum_database($table) != checksum_sync($table)) // Tabelle wurde modifiziert
      $success = $success && update_dump($table) && mark_synced($table);

    else $skipped++;
  }
  if ($skipped == count($db_tables)) info("no tables to be exported.");
  return $success;
}

/// Misst vergangene Zeit zwischen Aufrufen.
function measure_time() {
  global $last_time;
  if ($last_time) {
    action("execution time since last measurement:");
    echo round(microtime(true) - $last_time, 3)."\n";
  } else {
    action("starting time measurement...");
    success();
  }
  $last_time = microtime(true);
  return true;
}

/// Zufällige, eindeutige IDs für neue Datensätze vergeben
/// hängt ab von: connect, setup_tables
function generate_missing_ids($table) {
  $k = 0;
  do mysql_query("UPDATE $table SET sync_id = MD5(CONCAT(RAND(),RAND(),RAND(),RAND(),RAND())), sync_flag = -1 WHERE sync_id IS NULL;");
  while (mysql_error() && $k++ < 100); // Bei UNIQUE-Kollisionen einfach nochmal. irgendwann passt es schon
  return $k < 100;
}

?>