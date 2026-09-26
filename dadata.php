<?php
// API конфигурация
define('DADATA_API_KEY', getenv('DADATA_API_TOKEN') ?: '');
define('DADATA_API_URL', 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/findById/party');

function getCompanyByINN($inn) {
    $options = [
        'http' => [
            'header' => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Token ' . DADATA_API_KEY
            ],
            'method' => 'POST',
            'content' => json_encode(['query' => preg_replace('/[^0-9]/', '', $inn)])
        ]
    ];
    
    $result = @file_get_contents(DADATA_API_URL, false, stream_context_create($options));
    if (!$result) return null;
    
    $response = json_decode($result, true);
    if (empty($response['suggestions'][0])) return null;
    
    $c = $response['suggestions'][0]['data'];
    return [
        'full_name' => $c['name']['full_with_opf'] ?? '—',
        'short_name' => $c['name']['short_with_opf'] ?? '—',
        'inn' => $c['inn'] ?? '—',
        'kpp' => $c['kpp'] ?? '—',
        'ogrn' => $c['ogrn'] ?? '—',
        'address' => $c['address']['value'] ?? '—',
        'status' => ($c['state']['status'] ?? '') == 'ACTIVE' ? 'Действует' : ($c['state']['status'] ?? '—'),
        'management' => ($c['management']['name'] ?? '—') . ' (' . ($c['management']['post'] ?? '—') . ')',
        'okved' => ($c['okved'] ?? '—') . ' (' . (substr($c['okved'] ?? '', 0, 2) ? 'ОКВЭД' : '—') . ')'
    ];
}

// Обработка запроса
$result = null;
$inn_input = $_POST['inn'] ?? '7707083893'; // Сбер по умолчанию
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['inn'])) {
    $result = getCompanyByINN($inn_input);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Поиск компаний по ИНН</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        }
        
        body {
            background: #f0f2f5;
            padding: 8px;
        }
        
        .container {
            max-width: 1920px;
            margin: 0 auto;
            width: 100%;
        }
        
        /* Плотная форма */
        .search-form {
            background: white;
            padding: 8px 12px;
            margin-bottom: 8px;
            border-radius: 4px;
            display: flex;
            gap: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        
        .inn-input {
            flex: 1;
            padding: 6px 8px;
            border: 1px solid #d0d7de;
            border-radius: 4px;
            font-size: 14px;
            height: 32px;
        }
        
        .submit-btn {
            padding: 6px 12px;
            background: #0969da;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            white-space: nowrap;
        }
        
        .submit-btn:hover {
            background: #0a58ca;
        }
        
        /* Плотная информационная таблица */
        .company-card {
            background: white;
            border-radius: 4px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        
        .info-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            table-layout: fixed;
        }
        
        .info-table td {
            padding: 6px 8px;
            border-bottom: 1px solid #eaeef2;
            vertical-align: top;
            line-height: 1.4;
        }
        
        .info-table tr:last-child td {
            border-bottom: none;
        }
        
        .info-table td:first-child {
            width: 120px;
            background: #f8fafc;
            font-weight: 600;
            color: #1f2328;
            white-space: nowrap;
            border-right: 1px solid #eaeef2;
        }
        
        .info-table td:last-child {
            word-break: break-word;
        }
        
        /* Таблица дополнительных данных */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            margin-top: 8px;
            background: white;
        }
        
        .data-table th {
            text-align: left;
            padding: 6px 8px;
            background: #eef2f6;
            font-weight: 600;
            color: #1f2328;
            border: 1px solid #d8dee4;
        }
        
        .data-table td {
            padding: 6px 8px;
            border: 1px solid #d8dee4;
        }
        
        .data-table tr:hover td {
            background: #f8fafc;
        }
        
        .error-message {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            padding: 8px 12px;
            border-radius: 4px;
            font-size: 13px;
        }
        
        .compact-text {
            font-size: 12px;
            color: #57606a;
            line-height: 1.3;
        }
        
        /* Убираем лишние отступы */
        h1, h2, h3 {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 4px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Плотная форма поиска -->
        <form method="POST" class="search-form">
            <input type="text" 
                   name="inn" 
                   class="inn-input" 
                   placeholder="ИНН (10 или 12 цифр)" 
                   value="<?= htmlspecialchars($inn_input) ?>"
                   pattern="[0-9]{10,12}"
                   maxlength="12"
                   autocomplete="off"
                   required>
            <button type="submit" class="submit-btn">Найти</button>
        </form>

        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
            <?php if ($result): ?>
                <!-- Основная информация (плотная таблица) -->
                <div class="company-card">
                    <table class="info-table">
                        <tr><td>Полное наименование</td><td><?= htmlspecialchars($result['full_name']) ?></td></tr>
                        <tr><td>Краткое наименование</td><td><?= htmlspecialchars($result['short_name']) ?></td></tr>
                        <tr><td>ИНН / КПП</td><td><?= $result['inn'] ?> / <?= $result['kpp'] ?></td></tr>
                        <tr><td>ОГРН</td><td><?= $result['ogrn'] ?></td></tr>
                        <tr><td>Статус</td><td><?= $result['status'] ?></td></tr>
                        <tr><td>Руководитель</td><td><?= htmlspecialchars($result['management']) ?></td></tr>
                        <tr><td>ОКВЭД</td><td><?= htmlspecialchars($result['okved']) ?></td></tr>
                        <tr><td>Адрес</td><td><?= htmlspecialchars($result['address']) ?></td></tr>
                    </table>
                </div>

                <!-- Дополнительная таблица с реквизитами (для плотного отображения данных) -->
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Параметр</th>
                            <th>Значение</th>
                            <th>Доп. информация</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Регистрация</td>
                            <td><?= date('d.m.Y') ?></td>
                            <td class="compact-text">Дата актуальности</td>
                        </tr>
                        <tr>
                            <td>Форма собственности</td>
                            <td>Организация</td>
                            <td class="compact-text">По данным ЕГРЮЛ</td>
                        </tr>
                        <tr>
                            <td>Источник</td>
                            <td>DaData.ru</td>
                            <td class="compact-text">API v4.1</td>
                        </tr>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="error-message">
                    Компания с ИНН <?= htmlspecialchars($inn_input) ?> не найдена или ошибка запроса
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>