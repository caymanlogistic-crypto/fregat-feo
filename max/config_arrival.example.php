<?php

return [
    'db' => [
        'host' => getenv('DB_HOST') ?: 'db',
        'dbname' => getenv('DB_NAME') ?: 'spugovxsim_frega',
        'username' => getenv('DB_USER') ?: 'feo',
        'password' => getenv('DB_PASS') ?: '',
        'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
    ],
    'unload' => [
        'lat' => 55.3228,
        'lon' => 38.6969,
        'radius_m' => 2000,
        'address' => 'Московская обл, г Воскресенск, ул Кирова, д 3 в',
    ],
    'max' => [
        'base_url' => getenv('MAX_API_BASE_URL') ?: 'https://platform-api.max.ru',
        'token' => getenv('MAX_API_TOKEN') ?: '',
        'chat_id' => getenv('MAX_CHAT_ID') ?: '',
    ],
    'slitex' => [
        'base_url' => getenv('SLITEX_BASE_URL') ?: 'https://slitex.online',
        'token' => getenv('SLITEX_API_TOKEN') ?: '',
    ],
    'timezone' => 'Europe/Moscow',
    'log_file' => __DIR__ . '/logs/arrival_tracker.log',
    'lock_file' => __DIR__ . '/.arrival_tracker.lock',
    'lock_timeout' => 300,
    'transit_log_file' => __DIR__ . '/logs/transit_report.log',
    'transit_lock_file' => __DIR__ . '/.transit_report.lock',
    'yandex' => [
        'api_key' => getenv('YANDEX_MAPS_API_KEY') ?: '',
    ],
];

