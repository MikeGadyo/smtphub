<?php
require_once dirname(__DIR__, 3) . '/config.core.php';
require_once MODX_CORE_PATH . 'config/' . MODX_CONFIG_KEY . '.inc.php';
require_once MODX_CONNECTORS_PATH . 'index.php';
$modx->request->handleRequest(['processors_path' => MODX_CORE_PATH . 'components/smtphub/processors/']);
