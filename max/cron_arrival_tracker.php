<?php
/**
 * cron_arrival_tracker.php
 * Отслеживание прибытия автотранспорта на выгрузку
 
 */
declare(strict_types=1);

// Проверка версии PHP (требуется 7.4+ для корректной работы типов и синтаксиса)
if (PHP_VERSION_ID < 70400) {
    exit("Ошибка: Требуется PHP 7.4 или выше. Текущая: " . PHP_VERSION . "\n");
}

$config = require __DIR__ . '/config_arrival.php';
date_default_timezone_set($config['timezone']);

// 📝 Логирование
function logMsg(string $level, string $msg): void {
    global $config;
    $dir = dirname($config['log_file']);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($config['log_file'], sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), $level, $msg), FILE_APPEND | LOCK_EX);
}

// 🔐 Lock-файл: защита от параллельных запусков
if (file_exists($config['lock_file'])) {
    if ((time() - filemtime($config['lock_file'])) < $config['lock_timeout']) {
        logMsg('WARN', 'Скрипт уже запущен или завис');
        exit(1);
    }
    unlink($config['lock_file']);
}
touch($config['lock_file']);
register_shutdown_function(fn() => file_exists($config['lock_file']) && unlink($config['lock_file']));

logMsg('INFO', '🚀 Запуск скрипта');

// 🗄️ Подключение к БД
try {
    $pdo = new PDO(
        "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']};charset={$config['db']['charset']}",
        $config['db']['username'], $config['db']['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    logMsg('ERROR', "DB Connect: {$e->getMessage()}");
    exit(1);
}

// 🔍 Утилиты
function extractPlate(string $str): ?string {
    $clean = preg_replace('/[^A-ZА-Я0-9 ]/ui', '', mb_strtoupper($str, 'UTF-8'));
    $clean = strtr($clean, [
        'A'=>'А','B'=>'В','E'=>'Е','K'=>'К','M'=>'М','H'=>'Н','O'=>'О','P'=>'Р','C'=>'С','T'=>'Т','X'=>'Х'
    ]);
    if (preg_match('/([АВЕКМНОРСТУХ]\s*\d{3}\s*[АВЕКМНОРСТУХ]{2}\s*\d{2,3})/u', $clean, $m)) {
        return str_replace(' ', '', $m[1]);
    }
    return null;
}

function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $R = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2)**2 + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLon/2)**2;
    return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
}

function sendMax(string $text, ?string $format = null): array {
    global $config;
    
    $chatId = trim(rtrim($config['max']['chat_id'], '; '));
    $url = "{$config['max']['base_url']}/messages?chat_id={$chatId}";
    
    // ✅ Формируем базовый массив
    $payload = ['text' => $text];
    
    // ✅ Добавляем формат, если он указан и корректен
    if ($format !== null && in_array($format, ['html', 'markdown'], true)) {
        $payload['format'] = $format;
    }
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Authorization: ' . $config['max']['token'], 'Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($resp, true) ?: $resp];
}

function fetchSlitex(): array {
    global $config;
    $ch = curl_init("{$config['slitex']['base_url']}/api/external/devices");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["X-API-Token: {$config['slitex']['token']}"],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200 && $resp) ? json_decode($resp, true) : [];
}

// 📥 Загрузка данных
$devices = fetchSlitex();
if (empty($devices)) {
    logMsg('WARN', 'Slitex API вернул пустой ответ или ошибку');
    exit(0);
}
logMsg('INFO', "Загружено устройств: " . count($devices));

// 🚚 Загрузка активных рейсов (исправлено: ручная группировка по driver_id)
$stmt = $pdo->prepare("
    SELECT f.id, f.comment, f.driver_id, d.full_name, d.vehicle_make_plate 
    FROM flights f 
    JOIN drivers d ON f.driver_id = d.id 
    WHERE f.status = 'started' AND f.actual_end_date IS NULL
");
$stmt->execute();

$flightsByDriver = [];
while ($row = $stmt->fetch()) {
    $flightsByDriver[$row['driver_id']] = $row;
}

$processed = 0;
$notified = 0;
$moscowNow = new DateTime('now', new DateTimeZone($config['timezone']));
$todayStr = $moscowNow->format('Y-m-d');
$timeStr = $moscowNow->format('d.m.Y H:i');

foreach ($devices as $dev) {
    $uid = $dev['uniqueid'] ?? '';
    $lat = $dev['lat'] ?? null;
    $lon = $dev['lon'] ?? null;

    if (!$uid || $lat === null || $lon === null || !is_numeric($lat) || !is_numeric($lon)) continue;
    if (abs((float)$lat) < 0.001 || abs((float)$lon) < 0.001) continue; // Игнор нулевых координат

    $devPlate = extractPlate($dev['name'] ?? '');
    if (!$devPlate) continue;

    // Поиск рейса водителя
    $driver = null;
    foreach ($flightsByDriver as $did => $f) {
        if (extractPlate($f['vehicle_make_plate'] ?? '') === $devPlate) {
            $driver = $f;
            break;
        }
    }
    if (!$driver) continue;

    $flightId = $driver['id'];

    // Проверка дублей
    $dup = $pdo->prepare("SELECT 1 FROM arrival_logs WHERE tracker_uniqueid = ? AND flight_id = ? LIMIT 1");
    $dup->execute([$uid, $flightId]);
    if ($dup->fetch()) continue;

    // 📍 Проверка геозоны
    $dist = haversine((float)$lat, (float)$lon, $config['unload']['lat'], $config['unload']['lon']);
   if ($dist > $config['unload']['radius_m']) continue;

    logMsg('INFO', "🎯 Прибытие: {$devPlate} ({$driver['full_name']}) → рейс #{$flightId} ({$dist}м)");

    // 📝 Формируем текст сообщения (исправлено: убран ?: из интерполяции)
    $commentText = $driver['comment'] ?: 'Без комментария';
    $familiya=explode(' ', $driver['full_name'])[0];
	$msg = "ТС ПРИБЫЛО НА РАЗГРУЗКУ
───────────────────
**{$devPlate} ({$familiya})**
#{$flightId} — {$commentText}
> 💡 *Напоминаю: для оплаты подрядчику нужен полный пакет документов (диагностическая карта, путевой лист и т.д.). Прошу не затягивать с предоставлением.*
";

    // Отправка в MAX
    $maxRes = sendMax($msg, 'markdown');
    $maxOk = in_array($maxRes['code'], [200, 201], true);
     if (!$maxOk) {
        logMsg('ERROR', "MAX Error {$maxRes['code']}: " . json_encode($maxRes['body']));
    }

    // 🗄️ Обновление рейса в БД
    $upd = $pdo->prepare("UPDATE flights SET status='completed', actual_end_date=? WHERE id=? AND status='started'");
    $updOk = $upd->execute([$todayStr, $flightId]);

    // 📝 Запись в лог прибытий
    $log = $pdo->prepare("INSERT INTO arrival_logs (tracker_uniqueid, flight_id, driver_id, arrived_at, coords_lat, coords_lon, distance_m, max_message_sent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $logOk = $log->execute([
        $uid, 
        $flightId, 
        $driver['driver_id'], 
        $moscowNow->format('Y-m-d H:i:s'), 
        $lat, 
        $lon, 
        round($dist), 
        (int)$maxOk
    ]); 

    if ($updOk && $logOk) $notified++;
    $processed++;
    usleep(50000); // 0.05 сек пауза между итерациями
}

logMsg('INFO', "🏁 Завершено. Обработано: {$processed}, Успешно: {$notified}");
exit(0);