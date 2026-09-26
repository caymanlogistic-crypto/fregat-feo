<?php

$dbConfig = [
    'host' => getenv('DB_HOST') ?: 'db',
    'port' => (int) (getenv('DB_PORT') ?: 3306),
    'dbname' => getenv('DB_NAME') ?: 'spugovxsim_frega',
    'database' => getenv('DB_NAME') ?: 'spugovxsim_frega',
    'username' => getenv('DB_USER') ?: 'feo',
    'password' => getenv('DB_PASS') ?: '',
    'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
];

$apiKeyYandex = trim((string) (getenv('YANDEX_MAPS_API_KEY') ?: ''));

