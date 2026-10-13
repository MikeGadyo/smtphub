<?php
spl_autoload_register(function ($class) {
    $prefix = 'SmtpHub\\';
    if (strpos($class, $prefix) === 0) {
        $path = __DIR__ . '/src/' . substr($class, strlen($prefix)) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});
