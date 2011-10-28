<?php

/// \mainpage
/// Diese Version des Sync-Tools soll völlig unauffällig immer dafür sorgen, dass die DB synchron mit den Dumps ist.<BR>
/// Gegenüber der vorherigen wurde diese Version um inkrementelle Dumps erweitert, die deutlich schneller sind.
/// \section Anwendung
/// Das Tool besteht im Wesentlichen aus zwei Skripten, die in die Hauptdatei des PHP-Projekts eingebunden werden (z.B. den Dispatcher bei
/// MVC-Frameworks). Das erste Skript (start.php) wird am Anfang der Hauptdatei eingebunden und sorgt dafür, dass Änderungen in den Dumps
/// in die DB synchronisiert werden, das zweite Skript (end.php) wird am Ende eingebunden und speichert alle DB-Änderungen in den Dumps ab.
/// Zum manuellen Aufruf der beiden Synchronisationsschritte mit ausführlicher Debug-Ausgabe kann die index.php aufgerufen werden. 
/// \section Überblick
/// Jedesmal, wenn sich eine Dump-Datei ändert, wird diese beim nächsten Aufruf in die Datenbank importiert. Das Tool merkt sich die
/// importierten Dateien anhand der Timestamps. Da der Import nicht all zu oft durchgeführt werden muss, kann der ruhig länger dauern.
/// Das Schema und die Daten werden dabei komplett neu importiert.<BR>
/// Der Export dagegen muss so flott wie möglich gehen, da sich oft Daten in der DB ändern und diese innerhalb möglichst kurzer Zeit in die
/// Dumps geschrieben werden sollen, um den Benutzer nicht weiter aufzuhalten. Zu diesem Zweck installiert das Sync-Tool Trigger auf
/// alle Tabellen, die dafür sorgen, dass jeder Datensatz eine eindeutige ID bekommt. Beim nächsten Sync wird ein Diff zwischen den IDs
/// in Dump-Files und DB gemacht; die nötigen Änderungen werden dann (inkrementell) in den Dump übernommen.
/// \section Details
/// In jeder Tabelle werden automatisch zwei Spalten sync_id und sync_flag angelegt. In sync_id erhält jeder Datensatz beim Synchronisieren
/// eine eindeutige ID, mit sync_flag werden geänderte Datensätze (per Trigger) markiert.<BR>
/// Das Programm merkt sich außerdem zu jeder Tabelle Zeitpunkt und Checksumme des letzten synchronen Zustands (in sync/last-sync) und kann
/// somit Änderungen seit diesem Zeitpunkt feststellen. Hat sich die Struktur geändert, wird ein kompletter Export gemacht,
/// ansonsten ein inkrementeller. Zum <B>inkrementellen Export</B> wird der alte Dump eingelesen, dann werden folgende IDs ermittelt:
/// <UL>
/// <LI>IDs aller geänderten Datensätze (mittels sync_flag)</LI>
/// <LI>IDs aller gelöschten/einfügten Datensätze (durch Vergleich der IDs in DB und Dump-File)</LI>
/// </UL>
/// Danach wird der Dump entsprechend geändert und zurückgeschrieben. Schließlich wird das sync_flag wieder auf 0 gesetzt.
/// \section Spam-Tabellen
/// Manche Tabellen enthalten nur Datenschrott, wie z.B. oxseologs oder oxlogs in OXID. Solche Tabellen können in das $ignore-Array eingetragen
/// werden, um nur noch die Tabellenstruktur (und keine Daten mehr) zu synchronisieren.
/// \section Achtung
/// Die Reihenfolge der Datensätze in der DB bleibt nicht erhalten, da nach sync_id sortiert wird.
/// \section Komplexität
/// Die meisten Algorithmen sind von linearer Komplexität, lediglich das Sortieren nach sync_ids beim Aktualisieren eines Dumps ist teurer.
/// Wahrscheinlich kann dieser Schritt aber auch eleganter und ebenfalls in linearer Zeit gemacht werden.

require_once "definitions.php";
require_once "commands.php";

ob_start();

$success =
   headline("checking configuration")
&& check_permissions()
&& ( !R('save_config') || save_config(R('db'), R('user'), R('pw'), R('autosync')) )
&& load_config()
&& ( !R('createdb') || (root_connect(R('rootpw')) && create_db()) )
&& connect()
&& ( !R('unlock') || unlock() )
&& ( R('run') || abort("configuration ok. please select an option from above.", true) )
&& headline("starting sync")
&& lock()
&& load_timestamps()
&& load_tables()
&& ( R('export') || sync_dumps_to_db(R('import')) )
&& setup_tables(R('setup_triggers'))
&& load_tables()
&& ( R('import') || sync_db_to_dumps(R('export')) )
&& save_timestamps();

// Mutex-Lock wieder freigeben, auch wenn es Fehler gab
isset($acquired_lock) && $acquired_lock && unlock();

$output = ob_get_clean();

include 'output.php';