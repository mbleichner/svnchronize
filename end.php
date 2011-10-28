<?php

/// Diese Datei aktualisiert nur die Dump-Files

require_once "definitions.php";
require_once "commands.php";

ob_start();

$GLOBALS['sync_start_success'] // Wenn das Start-Skript nicht durchgelaufen ist, gar nicht erst weitermachen
&& check_permissions()
&& load_config()
&& connect()
&& lock()
&& load_tables()
&& load_timestamps()
&& sync_db_to_dumps()
&& save_timestamps();

// Theoretisch könnten in der Zwischenzeit neue Tabellen angelegt worden sein, die per setup_tables() noch angepasst werden müssen.
// Ist aber meist nicht der Fall, daher verzichten wir zugunsten der Performance darauf.

isset($acquired_lock) && $acquired_lock && unlock(); // Mutex-Lock wieder freigeben, auch wenn es Fehler gab

ob_end_clean();
