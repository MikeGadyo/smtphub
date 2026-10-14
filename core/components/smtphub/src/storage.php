<?php
// Required afresh by the resolver: an enabled plugin may have loaded old classes
// before the new component files were copied during an upgrade.
return function ($modx, $suffix = 'smtphub_servers') {
    $prefix = $modx->getOption('table_prefix', null, 'modx_');
    if (!preg_match('/^[A-Za-z0-9_]*$/D', $prefix) || !preg_match('/^[A-Za-z0-9_]+$/D', $suffix)) {
        throw new \RuntimeException('Unsupported SMTPHub table name.');
    }
    if ($modx->getOption('dbtype', null, 'mysql') !== 'mysql') { throw new \RuntimeException('SMTPHub requires MySQL or MariaDB.'); }
    $queries = ['CREATE TABLE IF NOT EXISTS `' . $prefix . $suffix . '` (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(128) NOT NULL,
        priority INT UNSIGNED NOT NULL DEFAULT 10, enabled TINYINT NOT NULL DEFAULT 1,
        host VARCHAR(255) NOT NULL, port INT NOT NULL DEFAULT 587, secure VARCHAR(8) NOT NULL DEFAULT "tls",
        auth TINYINT NOT NULL DEFAULT 1, username VARCHAR(255) NOT NULL DEFAULT "",
        password_cipher TEXT NOT NULL, timeout INT NOT NULL DEFAULT 5, auto_tls TINYINT NOT NULL DEFAULT 1,
        sender_email VARCHAR(254) NOT NULL DEFAULT "", sender_name VARCHAR(128) NOT NULL DEFAULT "",
        revision INT UNSIGNED NOT NULL DEFAULT 1, deleted TINYINT NOT NULL DEFAULT 0,
        PRIMARY KEY (id), KEY routing_order (deleted, enabled, priority, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    'CREATE TABLE IF NOT EXISTS `' . $prefix . $suffix . '_availability` (
        server_id INT UNSIGNED NOT NULL, available TINYINT NOT NULL,
        checked_at BIGINT UNSIGNED NOT NULL, connection_hash CHAR(64) NOT NULL,
        PRIMARY KEY (server_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'];
    foreach ($queries as $query) {
        $statement = $modx->prepare($query);
        if (!$statement || !$statement->execute()) { throw new \RuntimeException('SMTPHub storage migration failed.'); }
    }
    $columns = $modx->prepare('SHOW COLUMNS FROM `' . $prefix . $suffix . '`');
    if (!$columns || !$columns->execute()) { throw new \RuntimeException('SMTPHub storage migration failed.'); }
    $known = array_column($columns->fetchAll(\PDO::FETCH_ASSOC), 'Field');
    $add = [];
    foreach (['sender_email' => 254, 'sender_name' => 128] as $name => $length) {
        if (!in_array($name, $known, true)) { $add[] = 'ADD COLUMN `' . $name . '` VARCHAR(' . $length . ') NOT NULL DEFAULT ""'; }
    }
    if ($add) {
        $statement = $modx->prepare('ALTER TABLE `' . $prefix . $suffix . '` ' . implode(', ', $add));
        if (!$statement || !$statement->execute()) { throw new \RuntimeException('SMTPHub sender migration failed.'); }
    }
};
