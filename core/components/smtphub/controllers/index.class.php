<?php
if (!class_exists('SmtpHubManagerControllerBase', false)) {
    class_alias(class_exists('MODX\\Revolution\\modExtraManagerController') ? 'MODX\\Revolution\\modExtraManagerController' : 'modExtraManagerController', 'SmtpHubManagerControllerBase');
}
class SmtphubIndexManagerController extends SmtpHubManagerControllerBase
{
    public function getPageTitle() { return 'SMTPHub'; }
    public function checkPermissions() { return $this->modx->hasPermission('settings'); }
    public function getTemplateFile() { return dirname(__DIR__) . '/templates/home.tpl'; }
    public function loadCustomCssJs()
    {
        $url = $this->modx->getOption('assets_url') . 'components/smtphub/';
        $this->modx->lexicon->load('setting');
        $modern = (int) $this->modx->getVersionData()['version'] >= 3;
        $release = require dirname(__DIR__) . '/version.php';
        $labels = [];
        foreach (['host' => 'hosts', 'port' => 'port', 'auth' => 'auth', 'username' => 'user', 'password' => 'pass',
            'timeout' => 'timeout', 'auto_tls' => 'autotls', 'secure' => $modern ? 'secure' : 'prefix'] as $field => $setting) {
            $labels[$field] = $this->modx->lexicon('setting_mail_smtp_' . $setting);
        }
        $this->addHtml('<script>window.SMTPHubConfig=' . json_encode(['connector' => $url . 'connector.php',
            'labels' => $labels, 'version' => $release['version'], 'author' => $release['author'],
            'auth' => $this->modx->user->getUserToken('mgr')], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>');
        $assetsPath = $this->modx->getOption('assets_path') . 'components/smtphub/';
        foreach (['mgr.css' => 'addCss', 'mgr.js' => 'addJavascript'] as $file => $method) {
            $revision = is_file($assetsPath . $file) ? substr(hash_file('sha256', $assetsPath . $file), 0, 12) : '';
            $this->$method($url . $file . '?v=' . $release['version'] . '-' . $revision);
        }
    }
}
return 'SmtphubIndexManagerController';
