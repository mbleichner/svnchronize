<?php


// Tabellen, deren Daten ignoriert werden
$ignore = array('oxlogs', 'oxseologs', 'oxseo', 'oxseohistory', 'oxuserbaskets', 'oxuserbasketitems');

// Verzeichnis des Sync-Tools
define('SYNC_DIR', dirname(__FILE__));

// Verzeichnis für Dumps und Zugangsdaten
define('SYNC_DATA_DIR', dirname(SYNC_DIR)."/sync-data");



/// Getter für das REQUEST-Array
function R($key, $default = '') {
  return array_key_exists($key, $_REQUEST) ? $_REQUEST[$key] : $default;
};

/// Holt einzelnen Wert aus DB
function getvalue($query, $stripslashes = true) {
  $result = mysql_query($query);
  if (mysql_num_rows($result) > 0) {
    $row = mysql_fetch_array($result);
    $value = $stripslashes ? stripslashes($row[0]) : $row[0];
  } else {
    $value = null;
  }
  mysql_free_result($result);
  return $value;
}

/// Holt eine ganze Zeile aus der DB
function getrow($query, $stripslashes = true) {
  $result = mysql_query($query);
  if (mysql_num_rows($result) > 0) {
    $row = $stripslashes ? array_map("stripslashes", mysql_fetch_array($result)) : mysql_fetch_array($result);
  } else {
    $row = array();
  }
  mysql_free_result($result);
  return $row;
}

/// Holt mehrere Zeilen aus der DB
function getrows($query, $stripslashes = true) {
  $result = mysql_query($query);
  if (mysql_num_rows($result) > 0) {
    $rows = array();
    while ($row = mysql_fetch_array($result)) {
      $rows[] = $stripslashes ? array_map("stripslashes", $row) : $row;
    }
  } else {
    $rows = array();
  }
  mysql_free_result($result);
  return $rows;
}

/// Holt eine Spalte aus der DB
function getcol($query, $stripslashes = true) {
  $rows = getrows($query);
  if (@$rows[0][0]) {
    $result = array();
    foreach ($rows as $row)
      $result[] = $stripslashes ? stripslashes($row[0]) : $row[0];
    return $result;
  } else {
    return array();
  }
}

/// Holt 2 Spalten aus der DB und formatiert sie als assoziatives Array
function getassoc($query, $stripslashes = true) {
  $rows = getrows($query);
  $result = array();
  foreach ($rows as $row)
    $result[$row[0]] = $row[1];
  return $result;
}

/// Query ausführen und nur die Anzahl der Ergebnisse zurückgeben
function getnumrows($query) {
  $result = mysql_query($query);
  $num = mysql_num_rows($result);
  mysql_free_result($result);
  return $num;
}

/// Baut einen UPDATE-Query und führt ihn aus
function update($table, $where, $updates) {
  $tmp = array(); $tmp2 = array();
  foreach ($updates as $key => $val) {
    $key = addslashes($key); $val = addslashes($val);
    $tmp[] = "$key = '$val'";
  }
  foreach ($where as $key => $val) {
    $key = addslashes($key); $val = addslashes($val);
    $tmp2[] = "$key = '$val'";
  }
  query("UPDATE $table SET ".implode(", ", $tmp) . " WHERE ".implode(" AND ", $tmp2).";");
}

/// Baut einen INSERT-Query und führt ihn aus
function insert($table, $values, $ignore = false) {
  $tmp = array();
  foreach ($values as $key => $val) {
    $key = addslashes($key);
    $val = addslashes($val);
    $tmp[] = "$key = '$val'";
  }
  query("INSERT ".($ignore ? "IGNORE" : "")." INTO $table SET ".implode(", ", $tmp) . ";");
}

/// Baut einen REPLACE-Query und führt ihn aus
function replace($table, $values) {
  $tmp = array();
  foreach ($values as $key => $val) {
    $key = addslashes($key);
    $val = addslashes($val);
    $tmp[] = "$key = '$val'";
  }
  query("REPLACE INTO $table SET ".implode(", ", $tmp) . ";");
}

/// Liefert aus einem Array nur gewisse Schlüssel-Wert-Paare zurück
function subarray($valuearray, $filterarray) {
  $result = array();
  foreach ($filterarray as $key) {
    if (array_key_exists($key, $valuearray))
      $result[$key] = $valuearray[$key];
  }
  return $result;
}

/// Filtert aus einem Array gewisse Schlüssel-Wert-Paare heraus
function filterout($valuearray, $filterarray) {
  $result = $valuearray;
  foreach ($filterarray as $key) {
    if (array_key_exists($key, $valuearray))
      unset($result[$key]);
  }
  return $result;
}

/// Macht aus einem Server-Pfad einen HTTP-Pfad
function webdir($dir) {
  return str_replace($_SERVER['DOCUMENT_ROOT'], '', $dir);
}

/// Baut die SELECT-Klausel auf, um Daten für dump_row_query() auszulesen.
function build_dump_select_clause($desc) {
  $select = array();
  foreach ($desc as $properties) {
    $field   = $properties['Field'];
    $type = $properties['Type'];
    if ($field == 'sync_flag') continue;
    $select[] = preg_match('/blob/i', $type) ? "HEX($field) AS $field" : $field;
  }
  return implode(', ', $select);
}

/// Baut einen REPLACE INTO Query mit den Daten aus $row
function dump_row_query($row, $table, $desc) {
  $assignments = array();
  $assignments['sync_id'] = "sync_id = '{$row['sync_id']}'"; // Feld vorziehen, damit beim Updaten schneller geparst werden kann
  foreach ($desc as $properties) {
    if ($properties['Field'] == 'sync_flag' || $properties['Field'] == 'sync_id') continue;
    $field   = $properties['Field'];
    $type    = $properties['Type'];
    $default = $properties['Default'];
    if     ($row[$field] == $default)     continue;
    elseif (preg_match('/blob/i', $type)) $assignments[] = "{$field} = UNHEX('{$row[$field]}')";
    elseif (is_null($row[$field]))        $assignments[] = "{$field} = NULL";
    else                                  $assignments[] = "{$field} = '".mysql_real_escape_string($row[$field])."'";
  }
  if ($assignments)
    return "REPLACE INTO $table SET ".implode(", ", $assignments).";\n";
}

/// Effiziente Berechnung von Differenz-Arrays
function array_minus($a, $b) {
  $a = array_flip($a);
  $b = array_flip($b);
  foreach ($b as $id => $i) {
    if (array_key_exists($id, $a))
      unset($a[$id]);
  }
  return array_keys($a);
}

/// Gibt zurück, ob sich die Schamata einer Tabelle in DB und Dump unterscheiden
function schemas_differ($table) { // depends: check_dir_structure connect
  $db_schema = get_create_table($table);
  $dump_schema = file_get_contents(SYNC_DATA_DIR."/$table.schema.sql");
  return strtolower($db_schema) != strtolower($dump_schema);
}

/// Liefert die Ausgabe von SHOW CREATE TABLE und nimmt einige Änderungen vor, sodass
/// unterschiedliche MySQL-Versionen (bis auf Groß/Kleinschreibung) dieselbe Ausgabe liefern
function get_create_table($table) { // depends: connect
  $res = mysql_query("SHOW CREATE TABLE {$table};");
  $create = mysql_result($res, 0, 1);
  $create = preg_replace('/AUTO_INCREMENT=[0-9]+/', ' ', $create); // Auto-Inkrement-Wert entfernen
  $create = preg_replace('/[ ]+/', ' ', $create); // Meerfache Leerzeichen entfernen
  return $create;
}

/// Deutlich schneller als getcol("DESC $table;").
function fieldnames($table) {
  $rs = mysql_query("SELECT * FROM $table WHERE 0;");
  $fields = array();
  $n = mysql_num_fields($rs);
  for ($i = 0; $i < $n; $i++)
    $fields[] = mysql_field_name($rs, $i);
  return $fields;
}

?>