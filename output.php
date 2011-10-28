<html>
<head>
  <style type="text/css">
    * { font-family: inherit; font-size: inherit; padding: 0px; margin: 0px; }
    body { font-family: verdana, arial, sans-serif; font-size: 12px; color: #555; background: #ddd url(<?php echo webdir(SYNC_DIR) ?>/bg.png) repeat-x top; }
    pre { font-family: monospace; font-size: 12px; }
    pre, table, form { margin: 15px 0px; }
    form { text-align: center; }
    input { border: 1px solid #555; padding: 1px 5px; border-radius: 3px; }
    input[type="submit"] { background: #777; color: white; font-weight: bold; }
    #container { width: 900px; margin: auto; margin-top: 20px; }
    #output { border: 2px solid black; background: #000; padding: 15px; font-weight: bold; color: #ccc; overflow-x: auto; }
    #output .good { color: #8f8; }
    #output .bad { color: #f88; }
    #output .highlight { color: #fff; }
    form { border: 2px solid black; background: white; padding: 15px; font-weight: bold; overflow-x: auto; }
    .caption { float: left; color: #aaa; line-height: 18px; height: 18px; }
  </style>
</head>
<body>

  <div id="container">

    <form method="post">
      <div class="caption">Configuration</div>
      <input type="checkbox" name="autosync" <?php echo ($autosync == 'active') ? 'checked="checked"' : ''; ?>/> sync automatically?
      &nbsp;&nbsp;&nbsp;&nbsp;
      <input type="text" name="db" value="<?php echo $db ? $db : 'db-name'; ?>" size="12"/>
      <input type="text" name="user" value="<?php echo $user ? $user : 'username'; ?>" size="12"/>
      <input type="text" name="pw" value="<?php echo $pw ? $pw : 'password'; ?>" size="12"/>
      <input type="submit" name="save_config" value="speichern">
    </form>

    <form method="post">
      <div class="caption">Start sync</div>
      <input type="hidden" name="run" value="1"/>
      <input type="submit" name="sync" value="normal sync"/>
      <input type="submit" name="unlock" value="unlock &amp; sync"/>
      <input type="submit" name="import" value="import only"/>
      <input type="submit" name="export" value="export only"/>
      &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; <span style="color: #aaa;">Debugging:</span> &nbsp;
      <input type="submit" name="setup_triggers" value="re-setup triggers"/>
    </form>

    <?php if (!$connected && $user && $pw && $db): ?>
      <form method="post">
        Enter root password to create database: &nbsp;
        <input type="password" name="rootpw" size="12"/>
        <input type="submit" name="createdb" value="create database"/>
      </form>
    <?php endif; ?>

    <?php if ($output): ?>
      <pre id="output"><?php echo $output; ?></pre>
    <?php endif; ?>

  </div>

</body>
</html>