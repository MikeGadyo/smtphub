<?php
// $object is the transported namespace, $options are installation options.
if (($options['package_action'] ?? null) === 2) {
    return true;
}
$modx = $object->xpdo;
$version = $modx->getVersionData();
$supported = PHP_VERSION_ID >= 70400 &&
    (((int) $version['version'] === 2 && version_compare($version['full_version'], '2.8.0', '>=')) ||
     (int) $version['version'] === 3);
if (!$supported) {
    $modx->log(1, '[SMTPHub] Requires PHP 7.4+ and MODX Revolution 2.8+ or 3.x.');
}
return $supported;
