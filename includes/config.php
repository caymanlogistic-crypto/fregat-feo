<?php
declare(strict_types=1);

// =============================================================================
// КОНФИГУРАЦИЯ СИСТЕМЫ
// =============================================================================

// Путь к скрипту Python (venv в корне проекта)
define('PYTHON_EXEC', __DIR__ . '/../venv/bin/python');

// Путь к worker.py (теперь в папке includes)
define('WORKER_SCRIPT', __DIR__ . '/worker.py');

// API DaData конфигурация
define('DADATA_API_KEY', getenv('DADATA_API_TOKEN') ?: '');
define('DADATA_API_URL', 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/findById/party');

// Директория для сохранения данных
define('SAVE_DIR', __DIR__ . '/../saved_data');

// Временная директория для PDF
define('TEMP_DIR', sys_get_temp_dir() . '/pdf_parser_temp');