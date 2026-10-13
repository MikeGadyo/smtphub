<?php
if (($options['package_action'] ?? null) === 2) { return true; }
$modx = $object->xpdo;
require_once $modx->getOption('core_path') . 'components/smtphub/autoload.php';
try {
    $initialize = require $modx->getOption('core_path') . 'components/smtphub/src/storage.php';
    $initialize($modx);
    (new \SmtpHub\Crypto($modx))->initialize();
    return true;
} catch (\Throwable $e) {
    $modx->log(1, '[SMTPHub] Could not create server storage or encryption key. MySQL/MariaDB and OpenSSL are required.');
    return false;
}
