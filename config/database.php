<?php

declare(strict_types=1);

$config = [
    'host' => '127.0.0.1',
    'port' => 3306,
    'database' => 'stock_management',
    'username' => 'root',
    'password' => '',
];

$localConfig = __DIR__ . '/database.local.php';

if (is_file($localConfig)) {
    $overrides = require $localConfig;

    if (is_array($overrides)) {
        $config = array_replace($config, $overrides);
    }
}

return $config;
