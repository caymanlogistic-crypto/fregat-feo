<?php
/**
 * Скрипт получения данных с трекеров ГЛОНАССсофт
 * Запуск: php trackers.php
 * Результат: trackers_data.json
 */

$workingConfig = require __DIR__ . '/working_config.php';
require_once __DIR__ . '/GlonassSoftAPI.php';

date_default_timezone_set('Europe/Moscow');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/glonass_api.log');

echo "🚀 ГЛОНАССсофт — Сбор данных с трекеров\n";
echo "Время: " . date('d.m.Y H:i:s') . "\n";
echo str_repeat("=", 70) . "\n\n";

$api = new GlonassSoftAPI(
    $workingConfig['api_base_url'],
    $workingConfig['api_login'],
    $workingConfig['api_password']
);

if (!empty($workingConfig['api_token'])) {
    $api->setToken($workingConfig['api_token']);
}

echo "🔐 Авторизация... ";
if (!$api->login()) {
    die("❌ Ошибка авторизации. Проверьте логин/пароль.\n");
}
echo "✅ Успешно!\n🔑 Токен: " . $api->getToken() . "\n\n";

$trackerIds = $workingConfig['tracker_ids'] ?? [];
if (empty($trackerIds)) {
    die("⚠️ Список трекеров пуст в working_config.php\n");
}

$results = [];
$successCount = 0;
$failCount = 0;
$total = count($trackerIds);

foreach ($trackerIds as $index => $imei) {
    echo "🔍 [" . ($index + 1) . "/$total] IMEI: $imei ... ";
    
    // 🔑 Поиск трекера: возвращает массив с numeric 'id' (vehicleId)
    $tracker = $api->getTrackerByImei($imei);
    
    if (!$tracker || empty($tracker['id']) || !empty($tracker['Error'])) {
        echo "❌ Не найден или нет доступа\n";
        $failCount++;
        continue;
    }
    
    // ✅ vehicleId — числовой, нужен для getLastData()
    $vehicleId = (int)$tracker['id'];
    $trackerName = $tracker['name'] ?? "Трекер #$vehicleId";
    echo "✅ $trackerName (ID: $vehicleId) ... ";
    
    // Получение телеметрии
    $lastData = $api->getLastData($vehicleId);
    
    if ($lastData && empty($lastData['Error'])) {
        $results[$imei] = [
            'tracker_id'   => $vehicleId,
            'name'         => $trackerName,
            'imei'         => $imei,
            'lat'          => $lastData['lat'] ?? null,
            'lon'          => $lastData['lon'] ?? null,
            'speed'        => $lastData['speed'] ?? null,
            'course'       => $lastData['course'] ?? null,
            'satellites'   => $lastData['satellites'] ?? null,
            'time'         => $lastData['time'] ?? null,
            'address'      => $lastData['address'] ?? 'Не определён',
            'valid'        => !empty($lastData['valid']),
            'battery'      => $lastData['battery'] ?? null,
            'gsm_level'    => $lastData['gsm_level'] ?? null,
        ];
        
        $coords = ($lastData['lat'] && $lastData['lon']) 
            ? sprintf("%.6f, %.6f", $lastData['lat'], $lastData['lon']) 
            : 'нет данных';
        echo "📍 $coords\n";
        $successCount++;
    } else {
        echo "⚠️ Нет телеметрии: " . ($lastData['Error'] ?? 'unknown') . "\n";
        $failCount++;
    }
    
    // Пауза для соблюдения лимитов API
    usleep(300000);
}

// 📊 Итоги
echo "\n" . str_repeat("=", 70) . "\n";
echo "📈 ИТОГИ: ✅ Успешно: $successCount | ❌ Ошибки: $failCount из $total\n";
echo str_repeat("-", 70) . "\n\n";

foreach ($results as $imei => $data) {
    echo "🚗 {$data['name']}\n";
    if ($data['lat'] && $data['lon']) {
        echo "   📍 {$data['lat']}, {$data['lon']}\n";
        echo "   🗺️ https://www.google.com/maps?q={$data['lat']},{$data['lon']}\n";
    } else {
        echo "   📍 Координаты: нет данных\n";
    }
    $speed = $data['speed'] !== null ? "{$data['speed']} км/ч" : '—';
    $course = $data['course'] !== null ? "{$data['course']}°" : '—';
    $sats = $data['satellites'] !== null ? "{$data['satellites']} сп." : '—';
    echo "   🚀 $speed | 🧭 $course | 🛰️ $sats\n";
    
    if ($data['time']) {
        $ts = strtotime($data['time']);
        $timeStr = date('d.m.Y H:i:s', $ts);
        $ago = time() - $ts;
        $agoStr = $ago < 60 ? 'только что' : ($ago < 3600 ? floor($ago/60).' мин. назад' : floor($ago/3600).' ч. назад');
        echo "   🕐 $timeStr ($agoStr)\n";
    }
    echo "   🏠 {$data['address']}\n";
    echo "   ✅ " . ($data['valid'] ? '🟢 актуально' : '🔴 устарело') . "\n";
    echo str_repeat("-", 70) . "\n";
}

// 💾 Сохранение
if (!empty($results)) {
    $output = [
        'generated_at' => date('c'),
        'server' => $workingConfig['api_base_url'],
        'total_trackers' => count($results),
        'success' => $successCount,
        'failed' => $failCount,
        'data' => $results,
    ];
    file_put_contents(__DIR__ . '/trackers_data.json', json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo "\n💾 Сохранено в trackers_data.json\n";
}

// 🔄 Обновление токена в конфиге
$currentToken = $api->getToken();
if ($currentToken && $currentToken !== $workingConfig['api_token']) {
    $content = file_get_contents(__DIR__ . '/working_config.php');
    $content = preg_replace("/'api_token'\s*=>\s*'[^']*'/", "'api_token' => '$currentToken'", $content);
    file_put_contents(__DIR__ . '/working_config.php', $content);
}

echo "\n✅ Готово!\n";