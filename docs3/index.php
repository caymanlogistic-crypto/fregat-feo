<?php
/**
 * Главный контроллер приложения
 * Обработка запросов и отображение результатов
 */

require_once __DIR__ . '/bootstrap.php';

use App\Services\DatabaseService;
use App\Services\DadataService;
use App\Services\PythonWorkerService;
use App\Services\WasteProcessingService;
use App\Parsers\JsonFormatter;

// Загружаем конфигурацию
$config = loadConfig();

// Инициализируем сервисы
$dbService = DatabaseService::getInstance($config['db']);
$dadataService = new DadataService(
    $config['dadata']['api_key'],
    $config['dadata']['api_url'],
    $config['dadata']['timeout']
);
$pythonWorker = new PythonWorkerService(
    $config['python']['binary'],
    $config['python']['worker']
);
$wasteProcessor = new WasteProcessingService($dbService);

// Переменные для отображения
$result = null;
$error = null;
$parsedData = null;

// Обработка POST запроса
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
        $error = "Файл не выбран или ошибка загрузки";
    } else {
        try {
            $file = $_FILES['pdf']['tmp_name'];
            
            // Парсим PDF через Python worker
            $data = $pythonWorker->parsePdf($file);
            
            if ($data && is_array($data)) {
                // Добавляем юридический адрес
                if (!isset($data['document'])) {
                    $data['document'] = [];
                }
                $data['document']['Юридический адрес'] = $dadataService->getLegalAddressByINN(
                    $data['document']['ИНН'] ?? ''
                );
                
                // Исправляем названия отходов через БД
                $data = $wasteProcessor->fixWasteNames($data);
                
                // Форматируем JSON
                $result = JsonFormatter::encode($data);
                $parsedData = $data;
            }
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
        } catch (\Exception $e) {
            error_log("Unexpected error: " . $e->getMessage());
            $error = "Произошла непредвиденная ошибка";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>PDF Parser</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1400px; margin: 0 auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h2, h3, h4 { color: #333; margin-top: 0; }
        pre { background: #f8f8f8; padding: 15px; overflow-x: auto; border-radius: 8px; font-size: 12px; border: 1px solid #e0e0e0; white-space: pre-wrap; word-wrap: break-word; }
        .error { color: #d32f2f; background: #ffebee; padding: 15px; border-radius: 8px; border-left: 4px solid #d32f2f; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; font-size: 14px; }
        th, td { border: 1px solid #ddd; padding: 10px 12px; text-align: left; vertical-align: top; }
        th { background: #f0f0f0; font-weight: 600; }
        .error-row { background-color: #ffebee; }
        .table-wrapper { overflow-x: auto; max-width: 100%; border-radius: 8px; border: 1px solid #e0e0e0; }
        .status-badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .status-success { background: #e8f5e9; color: #2e7d32; }
        .status-error { background: #ffebee; color: #c62828; }
        .upload-form { background: #fafafa; padding: 20px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #e0e0e0; }
        input[type="file"] { padding: 10px; border: 1px solid #ccc; border-radius: 4px; background: white; }
        button { background: #1976d2; color: white; border: none; padding: 10px 24px; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 600; }
        button:hover { background: #1565c0; }
        hr { margin: 20px 0; border: none; border-top: 1px solid #e0e0e0; }
        details summary { cursor: pointer; color: #666; font-size: 13px; }
        .float-number { font-family: 'Courier New', monospace; }
        .phone-link { color: #1976d2; text-decoration: none; font-weight: 500; }
        .phone-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="container">
    <h2>📄 PDF Parser</h2>
    
    <div class="upload-form">
        <form method="POST" enctype="multipart/form-data">
            <input type="file" name="pdf" accept=".pdf" required>
            <button type="submit">🔍 Парсить</button>
        </form>
    </div>

    <?php if ($error): ?>
        <div class="error">
            <strong>❌ Ошибка:</strong><br>
            <pre><?= htmlspecialchars($error) ?></pre>
        </div>
    <?php endif; ?>

    <?php if (isset($parsedData) && is_array($parsedData) && !empty($parsedData)): ?>
        
        <?php if (isset($parsedData['document']) && is_array($parsedData['document'])): ?>
        <h3>📄 Информация о документе</h3>
        <div class="table-wrapper">
            <table>
                <tbody>
                    <?php foreach ($parsedData['document'] as $key => $value): ?>
                        <tr>
                            <th style="background:#f9f9f9; width:250px;"><?= htmlspecialchars((string)$key) ?></th>
 <td><?= htmlspecialchars((string)($value ?? '—')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <?php if (isset($parsedData['items']) && is_array($parsedData['items']) && count($parsedData['items']) > 0): ?>
        <h3>📦 Детализация по позициям</h3>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <?php foreach (array_keys($parsedData['items'][0]) as $column): ?>
                            <th><?= htmlspecialchars((string)$column) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($parsedData['items'] as $item): ?>
                        <tr class="<?= isset($item['fkko_found']) && !$item['fkko_found'] ? 'error-row' : '' ?>">
                            <?php foreach ($item as $key => $value): ?>
                                <td>
                                    <?php if ($key === 'fkko_found'): ?>
                                        <span class="status-badge <?= $value ? 'status-success' : 'status-error' ?>">
                                            <?= $value ? '✓ Найден в ФККО' : '✗ Не найден в ФККО' ?>
                                        </span>
                                    <?php elseif (is_float($value) || is_int($value)): ?>
                                        <span class="float-number"><?= htmlspecialchars((string)$value) ?></span>
                                    <?php else: ?>
                                        <?= htmlspecialchars((string)($value ?? '—')) ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <p>⚠️ Нет позиций</p>
        <?php endif; ?>
        
        <hr>
        <details>
            <summary>🔍 Показать нормализованный JSON</summary>
            <pre><?= htmlspecialchars($result ?? '') ?></pre>
        </details>
        
    <?php elseif ($result && !$error): ?>
        <pre><?= htmlspecialchars($result) ?></pre>
    <?php endif; ?>
    
    <?php if (!$error && !isset($parsedData) && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
        <p>Загрузите PDF файл для парсинга</p>
    <?php endif; ?>
</div>
</body>
</html>