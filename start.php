<?php

/// Diese Datei aktualisiert *nur* die Datenbank

require_once "definitions.php";
require_once "commands.php";

ob_start();

$GLOBALS['sync_start_success'] =
   check_permissions()
&& load_config()
&& ($autosync == 'active')
&& connect()
&& lock()
&& load_tables()
&& load_timestamps()
&& sync_dumps_to_db()
&& save_timestamps()
&& setup_tables();
isset($acquired_lock) && $acquired_lock && unlock(); // Mutex-Lock wieder freigeben, auch wenn es Fehler gab

ob_end_clean();