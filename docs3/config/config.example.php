<?php
/**
 * Конфигурация приложения
 * 
 * Этот файл содержит все настройки проекта.
 * Скопируйте этот файл в config.local.php и измените значения для вашего окружения.
 * Файл config.local.php должен быть добавлен в .gitignore
 */

return [
    // База данных
    'db' => [
	'host' => getenv('DB_HOST') ?: 'db',
	'dbname' => getenv('DB_NAME') ?: 'spugovxsim_frega',
    'username' => getenv('DB_USER') ?: 'feo',
    'password' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],
    
    // DaData API
    'dadata' => [
        'api_key' => getenv('DADATA_API_TOKEN') ?: '',
        'api_url' => 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/findById/party',
        'timeout' => 10, // таймаут в секундах
    ],
    
    // Python
    'python' => [
        'binary' => __DIR__ . '/venv/bin/python',
        'worker' => __DIR__ . '/include/worker.py',
    ],
    
    // Приложение
    'app' => [
        'debug' => true,
        'upload_max_size' => 10 * 1024 * 1024, // 10 MB
    ],
];
