<?php
/**
 * Build offline with the MODX 2.8 source tree (bundled xPDO 2).
 * No database, live website or installed MODX is needed.
 * php _build/build.transport.php /absolute/path/to/modx-2.8/core
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__) . '/';
$core = isset($argv[1]) ? rtrim($argv[1], '/\\') . '/' : '';
if (!is_file($core . 'xpdo/xpdo.class.php')) {
    fwrite(STDERR, "Pass the core directory of MODX 2.8 sources.\n");
    exit(1);
}
define('MODX_CORE_PATH', $core);
require $core . 'xpdo/xpdo.class.php';
$xpdo = new xPDO('mysql:host=127.0.0.1;dbname=smtphub_build', '', '', [
    xPDO::OPT_CACHE_PATH => $root . '_packages/build-cache/',
    xPDO::OPT_SETUP => true,
]);
$xpdo->setLogLevel(xPDO::LOG_LEVEL_ERROR);
$xpdo->addPackage('modx', $core . 'model/');
foreach (['modAccessibleObject', 'modAccessibleSimpleObject', 'modElement', 'modScript'] as $class) {
    $xpdo->loadClass($class);
}
$xpdo->loadClass('transport.xPDOTransport', XPDO_CORE_PATH, true, true);
$xpdo->loadClass('transport.modTransportVehicle', $core . 'model/modx/', false, true);
$release = require $root . 'core/components/smtphub/version.php';
$signature = $release['signature'];
$output = $root . '_packages/';
if (is_file($output . $signature . '.transport.zip') || is_dir($output . $signature)) {
    fwrite(STDERR, "Build output already exists. Move it aside before rebuilding.\n");
    exit(1);
}
// Stage a strict allowlist: never package an operator's servers.php or secrets.
$stage = $output . 'stage-' . bin2hex(random_bytes(5)) . '/smtphub/';
$component = $root . 'core/components/smtphub/';
$files = ['autoload.php', 'version.php'];
foreach (['src', 'elements', 'lexicon', 'controllers', 'processors', 'templates'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($component . $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && in_array($file->getExtension(), ['php', 'tpl'], true)) {
            $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($component)));
        }
    }
}
foreach ($files as $file) {
    $target = $stage . $file;
    if (!is_dir(dirname($target))) { mkdir(dirname($target), 0755, true); }
    if (!copy($component . $file, $target)) { throw new RuntimeException('Failed to stage ' . $file); }
}
foreach (['README.md', 'TECHNICAL.md', 'CHANGELOG.md', 'LICENSE'] as $document) {
    if (!copy($root . $document, $stage . $document)) { throw new RuntimeException('Cannot stage ' . $document); }
}
$assetsStage = $output . 'assets-stage-' . bin2hex(random_bytes(5)) . '/smtphub/';
mkdir($assetsStage, 0755, true);
foreach (['connector.php', 'mgr.js', 'mgr.css'] as $file) {
    if (!copy($root . 'assets/components/smtphub/' . $file, $assetsStage . $file)) { throw new RuntimeException('Cannot stage manager assets.'); }
}
$package = new xPDOTransport($xpdo, $signature, $output);
$put = function ($object, array $attributes, array $resolvers = [], array $validators = []) use ($package) {
    $vehicle = new modTransportVehicle($object, $attributes);
    foreach ($resolvers as $resolver) { $vehicle->resolve($resolver['type'], $resolver); }
    foreach ($validators as $validator) { $vehicle->validate($validator['type'], $validator); }
    if (!$package->put($vehicle->fetch(), $vehicle->compile())) {
        throw new RuntimeException('Could not package vehicle.');
    }
};
$namespace = $xpdo->newObject('modNamespace');
$namespace->fromArray(['name' => 'smtphub', 'path' => '{core_path}components/smtphub/', 'assets_path' => '{assets_path}components/smtphub/'], '', true, true);
$put($namespace, [
    xPDOTransport::UNIQUE_KEY => 'name', xPDOTransport::PRESERVE_KEYS => true,
    xPDOTransport::UPDATE_OBJECT => true, xPDOTransport::RESOLVE_FILES => true,
    xPDOTransport::RESOLVE_FILES_REMOVE => false,
    xPDOTransport::RESOLVE_PHP => true,
], [['type' => 'file', 'source' => $stage, 'target' => "return \$transport->xpdo->getOption('core_path') . 'components/';"],
    ['type' => 'file', 'source' => $assetsStage, 'target' => "return \$transport->xpdo->getOption('assets_path') . 'components/';"],
    ['type' => 'php', 'source' => __DIR__ . '/database.resolver.php']],
   [['type' => 'php', 'source' => __DIR__ . '/validator.php']]);
$plugin = $xpdo->newObject('modPlugin');
$plugin->fromArray([
    'name' => 'SMTPHub', 'description' => 'SMTP server management and failover for MODX. Author: ' . $release['author'] . '.',
    'plugincode' => preg_replace('/^<\?php\s*/', '', file_get_contents($component . 'elements/plugins/smtphub.php')),
    'disabled' => 0,
], '', true, true);
$event = $xpdo->newObject('modPluginEvent');
$event->fromArray(['event' => 'OnMODXInit', 'priority' => 1000, 'propertyset' => 0], '', true, true);
$events = [$event];
$plugin->addMany($events, 'PluginEvents');
$put($plugin, [
    xPDOTransport::UNIQUE_KEY => 'name', xPDOTransport::PRESERVE_KEYS => false,
    xPDOTransport::UPDATE_OBJECT => true, xPDOTransport::RELATED_OBJECTS => true,
    xPDOTransport::RELATED_OBJECT_ATTRIBUTES => [
        'PluginEvents' => [xPDOTransport::PRESERVE_KEYS => true, xPDOTransport::UPDATE_OBJECT => true,
            xPDOTransport::UNIQUE_KEY => ['pluginid', 'event']],
    ],
]);
$menu = $xpdo->newObject('modMenu');
$menu->fromArray(['text' => 'smtphub', 'parent' => 'components', 'action' => 'index', 'namespace' => 'smtphub',
    'description' => 'smtphub.menu_description', 'menuindex' => 10, 'permissions' => 'settings'], '', true, true);
$put($menu, [xPDOTransport::UNIQUE_KEY => 'text', xPDOTransport::PRESERVE_KEYS => true, xPDOTransport::UPDATE_OBJECT => true]);
foreach ([['enabled', '0', 'combo-boolean'], ['cooldown', '60', 'numberfield']] as $definition) {
    $setting = $xpdo->newObject('modSystemSetting');
    $setting->fromArray([
        'key' => 'smtphub.' . $definition[0], 'value' => $definition[1], 'xtype' => $definition[2],
        'namespace' => 'smtphub', 'area' => 'smtphub_mail',
    ], '', true, true);
    $put($setting, [xPDOTransport::UNIQUE_KEY => 'key', xPDOTransport::PRESERVE_KEYS => true, xPDOTransport::UPDATE_OBJECT => false]);
}
$package->attributes['readme'] = file_get_contents($root . 'README.md');
$package->attributes['license'] = file_get_contents($root . 'LICENSE');
$package->attributes['changelog'] = file_get_contents($root . 'CHANGELOG.md');
$package->attributes['author'] = $release['author'];
if (!$package->pack()) { throw new RuntimeException('Could not pack transport archive.'); }
echo $output . $signature . ".transport.zip\n";
