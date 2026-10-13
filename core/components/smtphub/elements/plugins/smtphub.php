<?php
/** @var modX|\MODX\Revolution\modX $modx */
if ($modx->event->name !== 'OnMODXInit') {
    return;
}
require_once $modx->getOption('core_path') . 'components/smtphub/autoload.php';
\SmtpHub\Integration::install($modx);
