<?php
// Устанавливаем кодировку
header('Content-Type: text/html; charset=utf-8');

// Отключаем отображение предупреждений
error_reporting(0);

// ID вашей Google таблицы
$spreadsheetId = '1e-iA425O2mhvF12WDnH_lpd6Z3HbCgBOtYtWuF-F6Z4';

// Формируем URL для получения данных в формате CSV
$csvUrl = "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/export?format=csv&gid=4";

// Получаем данные
$csvData = file_get_contents($csvUrl);

if ($csvData === false) {
    die('Ошибка при получении данных из Google Sheets');
}

// Правильный парсинг CSV с учетом переносов строк в полях
$rows = [];
$inQuote = false;
$currentRow = [];
$currentField = '';

// Разбиваем на символы и обрабатываем каждый
$length = strlen($csvData);
for ($i = 0; $i < $length; $i++) {
    $char = $csvData[$i];
    $nextChar = ($i < $length - 1) ? $csvData[$i + 1] : '';
    
    if ($char === '"' && !$inQuote) {
        // Начало кавычек
        $inQuote = true;
    } elseif ($char === '"' && $inQuote && $nextChar === '"') {
        // Экранированные кавычки
        $currentField .= '"';
        $i++; // Пропускаем следующую кавычку
    } elseif ($char === '"' && $inQuote) {
        // Конец кавычек
        $inQuote = false;
    } elseif ($char === ',' && !$inQuote) {
        // Разделитель полей
        $currentRow[] = $currentField;
        $currentField = '';
    } elseif ($char === "\n" && !$inQuote) {
        // Конец строки
        $currentRow[] = $currentField;
        if (!empty(array_filter($currentRow))) {
            $rows[] = $currentRow;
        }
        $currentRow = [];
        $currentField = '';
    } else {
        // Обычный символ
        $currentField .= $char;
    }
}

// Добавляем последнюю строку если есть
if (!empty($currentField) || !empty($currentRow)) {
    $currentRow[] = $currentField;
    if (!empty(array_filter($currentRow))) {
        $rows[] = $currentRow;
    }
}

// Очищаем поля от лишних пробелов
foreach ($rows as &$row) {
    foreach ($row as &$field) {
        $field = trim($field);
    }
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Заявки - информация по маршрутам</title>
    <style>
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f0f2f5;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        h1 {
            color: #1a237e;
            margin-bottom: 25px;
            font-size: 28px;
        }
        .stats-bar {
            background: white;
            padding: 15px 20px;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
        }
        .stats-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #455a64;
        }
        .stats-item strong {
            color: #1a237e;
            font-size: 1.2em;
        }
        .table-wrapper {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            overflow-x: auto;
            max-height: 70vh;
            overflow-y: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            min-width: 1000px;
        }
        th {
            background: linear-gradient(135deg, #1a237e 0%, #283593 100%);
            color: white;
            padding: 15px 12px;
            text-align: left;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        td {
            padding: 12px;
            border-bottom: 1px solid #e0e0e0;
            vertical-align: top;
        }
        tr {
            background-color: white;
        }
        tr:nth-child(even) {
            background-color: #fafafa;
        }
        tr.valid-row {
            border-left: 3px solid #4caf50;
        }
        tr.invalid-row {
            opacity: 0.5;
            background-color: #f5f5f5;
        }
        .request-number {
            font-weight: 700;
            color: #1a237e;
        }
        .route-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            background: #e3f2fd;
            color: #1565c0;
            font-weight: 600;
            font-size: 12px;
        }
        .comments-cell, .driver-info-cell {
            max-width: 300px;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .days-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 12px;
            background: #e8f5e9;
            color: #2e7d32;
        }
        .days-badge.high {
            background: #ffebee;
            color: #c62828;
        }
        .empty-value {
            color: #9e9e9e;
            font-style: italic;
            font-size: 12px;
        }
        .debug-info {
            margin-top: 20px;
            padding: 15px;
            background: #fff3e0;
            border-radius: 8px;
            font-size: 12px;
            color: #e65100;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📋 Информация по заявкам</h1>
        
        <div class="stats-bar">
            <div class="stats-item">
                <span>📊 Всего записей:</span>
                <strong><?php echo count($rows); ?></strong>
            </div>
            <div class="stats-item">
                <span>✅ С номером заявки:</span>
                <strong>
                    <?php 
                    $validCount = 0;
                    $routeCount = 0;
                    foreach ($rows as $index => $row) {
                        if ($index > 0 && isset($row[0]) && is_numeric(trim($row[0]))) {
                            $validCount++;
                            if (!empty(trim($row[8] ?? ''))) $routeCount++;
                        }
                    }
                    echo $validCount;
                    ?>
                </strong>
            </div>
            <div class="stats-item">
                <span>🔄 Обновление:</span>
                <strong><?php echo date('d.m.Y H:i:s'); ?></strong>
            </div>
        </div>
        
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>№ Заявки</th>
                        <th>Маршрут</th>
                        <th>Комментарии</th>
                        <th>Информация для водителя</th>
                        <th>Время на подготовку</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Пропускаем заголовок (первую строку)
                    for ($i = 1; $i < count($rows); $i++) {
                        $row = $rows[$i];
                        
                        // Проверяем, является ли первое поле номером заявки (число)
                        $isValidRequest = isset($row[0]) && is_numeric(trim($row[0]));
                        
                        // Получаем значения
                        $requestNumber = isset($row[0]) ? trim($row[0]) : '';
                        $route = isset($row[8]) ? trim($row[8]) : '';
                        $comments = isset($row[11]) ? trim($row[11]) : '';
                        $driverInfo = isset($row[12]) ? trim($row[12]) : '';
                        $passDays = isset($row[13]) ? trim($row[13]) : '';
                        
                        // Определяем класс для строки
                        $rowClass = $isValidRequest ? 'valid-row' : 'invalid-row';
                        
                        echo '<tr class="' . $rowClass . '">';
                        
                        // № Заявки
                        if ($isValidRequest) {
                            echo '<td class="request-number">' . htmlspecialchars($requestNumber) . '</td>';
                        } else {
                            echo '<td><span class="empty-value">' . htmlspecialchars(substr($requestNumber, 0, 50)) . '...</span></td>';
                        }
                        
                        // Маршрут
                        if (!empty($route)) {
                            echo '<td><span class="route-badge">' . htmlspecialchars($route) . '</span></td>';
                        } else {
                            echo '<td><span class="empty-value">—</span></td>';
                        }
                        
                        // Комментарии
                        if (!empty($comments)) {
                            echo '<td class="comments-cell">' . nl2br(htmlspecialchars($comments)) . '</td>';
                        } else {
                            echo '<td><span class="empty-value">—</span></td>';
                        }
                        
                        // Информация для водителя
                        if (!empty($driverInfo)) {
                            echo '<td class="driver-info-cell">' . nl2br(htmlspecialchars($driverInfo)) . '</td>';
                        } else {
                            echo '<td><span class="empty-value">—</span></td>';
                        }
                        
                        // Время на подготовку
                        if (!empty($passDays)) {
                            $daysClass = (is_numeric($passDays) && $passDays > 3) ? 'high' : '';
                            echo '<td><span class="days-badge ' . $daysClass . '">' . htmlspecialchars($passDays) . '</span></td>';
                        } else {
                            echo '<td><span class="empty-value">—</span></td>';
                        }
                        
                        echo '</tr>';
                    }
                    ?>
                </tbody>
            </table>
        </div>
        
        <div class="debug-info">
            <strong>ℹ️ Информация:</strong> 
            Серым цветом выделены строки, которые не являются полноценными заявками (технические строки, переносы и т.д.)
        </div>
    </div>
</body>
</html>
