<?php

$dbConfig = $dbConfig ?? [
    'host' => getenv('DB_HOST') ?: 'db',
    'port' => (int) (getenv('DB_PORT') ?: 3306),
    'dbname' => getenv('DB_NAME') ?: 'spugovxsim_frega',
    'database' => getenv('DB_NAME') ?: 'spugovxsim_frega',
    'username' => getenv('DB_USER') ?: 'feo',
    'password' => getenv('DB_PASS') ?: '',
    'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
];

$slitexBaseUrl = getenv('SLITEX_BASE_URL') ?: 'https://slitex.online';
$slitexApiToken = trim((string) (getenv('SLITEX_API_TOKEN') ?: ''));

$statusColors = [
    'search' => '#FFA459',
    'found' => '#26a69a',
    'started' => '#28a745',
    'completed' => '#6c757d',
    'attention' => '#dc3545',
    'planned_route' => '#9c27b0',
];

$statusNames = [
    'attention' => 'Срочно к вывозу',
    'search' => 'Поиск подрядчика',
    'found' => 'Исполнит. найден',
    'started' => 'Вывоз начался',
    'completed' => 'Груз сдан',
    'planned_route' => 'Планируемый маршрут',
];

