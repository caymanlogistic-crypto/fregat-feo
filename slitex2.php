<?php
// api.php - объединение данных (адаптирован под новый формат fields[])
error_reporting(E_ALL);
ini_set('display_errors', 1);

$apiToken = trim((string) (getenv('SLITEX_API_TOKEN') ?: ''));
$baseUrl  = 'https://slitex.online';

if (!extension_loaded('curl')) {
    die('Ошибка: Расширение PHP cURL не установлено.');
}

function fetchSlitexData(string $url, string $token): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["X-API-Token: {$token}"],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || $response === false) {
        error_log("API Error: {$url} | Code: {$httpCode} | cURL: {$curlError}");
        return [];
    }

    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : [];
}

// 1. Базовые данные
$devices = fetchSlitexData("{$baseUrl}/api/external/devices", $apiToken);

// 2. Административные поля (НОВЫЙ ФОРМАТ - массив fields)
$adminFields = fetchSlitexData("{$baseUrl}/api/external/devices/admin-fields", $apiToken);

// ============================================
// ТЕХНИЧЕСКИЙ СКРИПТ ДЛЯ АНАЛИЗА СТРУКТУРЫ
// ============================================
echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Технический анализ данных</title>";
echo "<style>
    pre{background:#f4f4f4;padding:10px;border:1px solid #ddd;overflow:auto;max-height:400px} 
    table {border-collapse: collapse; width: 100%;} 
    th, td {border: 1px solid #ddd; padding: 8px; text-align: left;} 
    th {background-color: #000000; color: white;} 
    tr:nth-child(even){background-color: #f2f2f2;}
    .raw-data {background-color: #333; color: #000; padding: 15px; border-radius: 5px; font-family: monospace; font-size: 12px; overflow-x: auto; max-height: 500px;}
</style>";
echo "</head><body>";

echo "<h2>📊 Технический анализ полученных данных</h2>";

// Анализ devices
echo "<h3>1. Структура devices (" . count($devices) . " записей):</h3>";
echo "<pre>";
if (!empty($devices)) {
    echo "Первый элемент:\n";
    print_r($devices[0]);
    echo "\n\nДоступные ключи в devices:\n";
    print_r(array_keys($devices[0]));
} else {
    echo "Нет данных devices";
}
echo "</pre>";

// Анализ adminFields (новый формат)
echo "<h3>2. Структура adminFields (" . count($adminFields) . " записей):</h3>";
echo "<pre>";
if (!empty($adminFields)) {
    echo "Первый элемент:\n";
    print_r($adminFields[0]);
    echo "\n\nАнализ поля fields:\n";
    $sample = $adminFields[0];
    if (isset($sample['fields']) && is_array($sample['fields'])) {
        echo "- fields является массивом с " . count($sample['fields']) . " элементами\n";
        echo "- Первый элемент fields содержит ключи: " . implode(', ', array_keys($sample['fields'][0])) . "\n";
        echo "- Пример данных из fields[0]:\n";
        print_r($sample['fields'][0]);
    } else {
        echo "- Поле 'fields' отсутствует или не массив\n";
    }
} else {
    echo "Нет данных adminFields";
}
echo "</pre>";

// Подсчет устройств с множественными конфигурациями
echo "<h3>3. Устройства с несколькими конфигурациями в fields:</h3>";
echo "<pre>";
$multiConfigCount = 0;
foreach ($adminFields as $item) {
    if (isset($item['fields']) && is_array($item['fields']) && count($item['fields']) > 1) {
        $multiConfigCount++;
        echo "- uniqueid: {$item['uniqueid']}, name: {$item['name']}, кол-во конфигов: " . count($item['fields']) . "\n";
        // Выводим детали по каждой конфигурации
        foreach ($item['fields'] as $idx => $cfg) {
            echo "  Конфиг #" . ($idx+1) . ": outID={$cfg['outID']}, outProtocol={$cfg['outProtocol']}\n";
        }
    }
}
echo "Всего устройств с мульти-конфигурацией: {$multiConfigCount}\n";
echo "</pre>";

// ============================================
// ОСНОВНАЯ ЛОГИКА ОБЪЕДИНЕНИЯ ДАННЫХ (АДАПТИРОВАНА)
// ============================================

$mergedData = [];

// Базовое заполнение из devices
foreach ($devices as $device) {
    $uid = $device['uniqueid'] ?? null;
    if ($uid !== null) {
        $mergedData[$uid] = $device;
        // Инициализируем поле для хранения всех конфигураций
        $mergedData[$uid]['admin_configs'] = [];
    }
}

// Объединение с adminFields (НОВЫЙ ФОРМАТ)
foreach ($adminFields as $item) {
    $uid = $item['uniqueid'] ?? null;
    if ($uid !== null) {
        $configs = $item['fields'] ?? [];
        
        if (isset($mergedData[$uid])) {
            // Сохраняем все конфигурации как массив
            $mergedData[$uid]['admin_configs'] = $configs;
            // Для обратной совместимости - берем первую конфигурацию
            $mergedData[$uid]['admin_fields'] = $configs[0] ?? [];
        } else {
            // Устройство есть только в adminFields
            $mergedData[$uid] = [
                'uniqueid'       => $uid,
                'name'           => $item['name'] ?? null,
                'admin_configs'  => $configs,
                'admin_fields'   => $configs[0] ?? []
            ];
        }
    }
}

$devices_full = array_values($mergedData);

// Функции времени
function utcToMoscow(string $utcTime): string {
    if (empty($utcTime)) return '';
    try {
        $datetime = new DateTime($utcTime, new DateTimeZone('UTC'));
        $datetime->setTimezone(new DateTimeZone('Europe/Moscow'));
        return $datetime->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return '';
    }
}

function getTimeDifference(string $moscowTime): string {
    if (empty($moscowTime)) return '';
    try {
        $currentMoscow = new DateTime('now', new DateTimeZone('Europe/Moscow'));
        $lastupdateMoscow = new DateTime($moscowTime, new DateTimeZone('Europe/Moscow'));
        $interval = $currentMoscow->diff($lastupdateMoscow);
        
        $isPast = $lastupdateMoscow < $currentMoscow;
        $sign = $isPast ? '' : '-';
        
        $days = $interval->days;
        $hours = $interval->h;
        $minutes = $interval->i;
        
        if ($days > 0) {
            return $sign . $days . 'д ' . $hours . 'ч ' . $minutes . 'м';
        } elseif ($hours > 0) {
            return $sign . $hours . 'ч ' . $minutes . 'м';
        } else {
            return $sign . $minutes . 'м';
        }
    } catch (Exception $e) {
        return '';
    }
}
?>

<!-- ОСНОВНАЯ ТАБЛИЦА (АДАПТИРОВАНА ПОД НОВЫЙ ФОРМАТ) -->
<h2>📋 Основная таблица устройств (с учетом множественных конфигураций)</h2>
<table border="1" cellpadding="5" cellspacing="0">
    <thead>
        <tr>
            <th>uniqueid</th>
            <th>name</th>
            <th>lastupdate</th>
            <th>status</th>
            <th>motiontime (UTC)</th>
            <th>lastupdate (МСК)</th>
            <th>Разница</th>
            <th>created</th>
            <th>lat</th>
            <th>lon</th>
            <th>outID</th>
            <th>inIP</th>
            <th>inPort</th>
            <th>inProtocol</th>
            <th>outIP</th>
            <th>outPort</th>
            <th>outProtocol</th>
            <th>Другие конфигурации</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($devices_full as $d): 
        $configs = $d['admin_configs'] ?? [];
        $firstConfig = $configs[0] ?? [];
        $utcLastUpdate = $d['lastupdate'] ?? '';
        $moscowLastUpdate = utcToMoscow($utcLastUpdate);
        $timeDiff = getTimeDifference($moscowLastUpdate);
    ?>
        <tr>
            <td><?= htmlspecialchars($d['uniqueid'] ?? '') ?></td>
            <td><?= htmlspecialchars($d['name'] ?? '') ?></td>
            <td><?= htmlspecialchars($d['lastupdate'] ?? '') ?></td>
            <td><?= htmlspecialchars($d['status'] ?? '') ?></td>
            <td><?= htmlspecialchars($d['motiontime'] ?? '') ?></td>
            <td><?= htmlspecialchars($moscowLastUpdate) ?></td>
            <td><?= htmlspecialchars($timeDiff) ?></td>
            <td><?= htmlspecialchars($d['created'] ?? '') ?></td>
            <td><?= htmlspecialchars($d['lat'] ?? '') ?></td>
            <td><?= htmlspecialchars($d['lon'] ?? '') ?></td>
            <td><?= htmlspecialchars($firstConfig['outID'] ?? '') ?></td>
            <td><?= htmlspecialchars($firstConfig['inIP'] ?? '') ?></td>
            <td><?= htmlspecialchars($firstConfig['inPort'] ?? '') ?></td>
            <td><?= htmlspecialchars($firstConfig['inProtocol'] ?? '') ?></td>
            <td><?= htmlspecialchars($firstConfig['outIP'] ?? '') ?></td>
            <td><?= htmlspecialchars($firstConfig['outPort'] ?? '') ?></td>
            <td><?= htmlspecialchars($firstConfig['outProtocol'] ?? '') ?></td>
            <td>
                <?php if (count($configs) > 1): ?>
                    <details>
                        <summary><?= count($configs)-1 ?> доп. конфигурации</summary>
                        <?php for ($i = 1; $i < count($configs); $i++): ?>
                            <?php $c = $configs[$i]; ?>
                            <strong>Конфигурация #<?= $i+1 ?>:</strong><br>
                            outID: <?= htmlspecialchars($c['outID'] ?? '') ?><br>
                            inIP: <?= htmlspecialchars($c['inIP'] ?? '') ?>:<?= htmlspecialchars($c['inPort'] ?? '') ?><br>
                            inProtocol: <?= htmlspecialchars($c['inProtocol'] ?? '') ?><br>
                            outIP: <?= htmlspecialchars($c['outIP'] ?? '') ?>:<?= htmlspecialchars($c['outPort'] ?? '') ?><br>
                            outProtocol: <?= htmlspecialchars($c['outProtocol'] ?? '') ?><br>
                            <?php if ($i < count($configs)-1): ?><hr><?php endif; ?>
                        <?php endfor; ?>
                    </details>
                <?php else: ?>
                    -
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php
// Дополнительная статистика
echo "<h3>📈 Статистика:</h3>";
echo "<ul>";
echo "<li>Всего устройств: " . count($devices_full) . "</li>";
$withConfigs = 0;
$totalConfigs = 0;
foreach ($devices_full as $d) {
    $cnt = count($d['admin_configs'] ?? []);
    if ($cnt > 0) $withConfigs++;
    $totalConfigs += $cnt;
}
echo "<li>Устройств с конфигурациями: {$withConfigs}</li>";
echo "<li>Всего конфигураций (всех устройств): {$totalConfigs}</li>";
echo "</ul>";

echo "<hr>";
echo "<h2>🔧 Отладочный вывод (первые 3 устройства с полными данными):</h2>";
echo "<pre>";
for ($i = 0; $i < min(3, count($devices_full)); $i++) {
    echo "=== Устройство " . ($i+1) . " ===\n";
    print_r($devices_full[$i]);
    echo "\n\n";
}
echo "</pre>";

// ============================================
// ПОЛНЫЙ ВЫВОД СЫРЫХ МАССИВОВ
// ============================================
echo "<hr>";
echo "<h2>📦 ПОЛНЫЙ ВЫВОД СЫРЫХ ДАННЫХ</h2>";

echo "<h3>📡 Сырой массив devices (базовые данные устройств):</h3>";
echo "<div class='raw-data'><pre>";
echo htmlspecialchars(json_encode($devices, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo "</pre></div>";

echo "<h3>⚙️ Сырой массив adminFields (административные поля с новым форматом fields[]):</h3>";
echo "<div class='raw-data'><pre>";
echo htmlspecialchars(json_encode($adminFields, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo "</pre></div>";

echo "<h3>🔄 Сырой массив devices_full (объединенные данные с admin_configs):</h3>";
echo "<div class='raw-data'><pre>";
echo htmlspecialchars(json_encode($devices_full, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo "</pre></div>";

echo "<h3>📊 Статистика по сырым данным:</h3>";
echo "<ul>";
echo "<li>Количество записей в devices: " . count($devices) . "</li>";
echo "<li>Количество записей в adminFields: " . count($adminFields) . "</li>";
echo "<li>Количество объединенных устройств: " . count($devices_full) . "</li>";
echo "<li>Размер devices (JSON): " . round(strlen(json_encode($devices)) / 1024, 2) . " KB</li>";
echo "<li>Размер adminFields (JSON): " . round(strlen(json_encode($adminFields)) / 1024, 2) . " KB</li>";
echo "<li>Размер devices_full (JSON): " . round(strlen(json_encode($devices_full)) / 1024, 2) . " KB</li>";
echo "</ul>";

// Дополнительная информация о структуре
echo "<h3>🔍 Детальный анализ структуры adminFields[0]:</h3>";
echo "<div class='raw-data'><pre>";
if (!empty($adminFields)) {
    echo "Ключи первого элемента: " . implode(', ', array_keys($adminFields[0])) . "\n\n";
    echo "Тип поля 'fields': " . gettype($adminFields[0]['fields'] ?? null) . "\n";
    if (isset($adminFields[0]['fields']) && is_array($adminFields[0]['fields'])) {
        echo "Количество конфигураций в fields: " . count($adminFields[0]['fields']) . "\n";
        if (count($adminFields[0]['fields']) > 0) {
            echo "Ключи первой конфигурации: " . implode(', ', array_keys($adminFields[0]['fields'][0])) . "\n";
        }
    }
}
echo "</pre></div>";
?>

</body>
</html>