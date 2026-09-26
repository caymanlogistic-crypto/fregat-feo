<?php
/**
 * cron_transit_report.php
 * Два раза в день отправляет отчет по рейсам в пути с остатком км до выгрузки
 * Запуск в cron: 0 10,18 * * * /usr/bin/php /путь/cron_transit_report.php
 */
declare(strict_types=1);
$config = require __DIR__ . '/config_arrival.php';
date_default_timezone_set($config['timezone']);

// 📝 Используем отдельный лог и лок, чтобы не конфликтовать с cron_arrival_tracker.php
$logFile  = $config['transit_log_file']  ?? __DIR__ . '/logs/transit_report.log';
$lockFile = $config['transit_lock_file'] ?? __DIR__ . '/.transit_report.lock';

function logMsg(string $level, string $msg): void {
    global $logFile;
    $dir = dirname($logFile);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($logFile, sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), $level, $msg), FILE_APPEND | LOCK_EX);
}

// 🔐 Lock
if (file_exists($lockFile)) {
    if ((time() - filemtime($lockFile)) < ($config['lock_timeout'] ?? 300)) {
        logMsg('WARN', 'Скрипт уже запущен или завис'); exit(1);
    }
    unlink($lockFile);
}
touch($lockFile);
register_shutdown_function(fn() => file_exists($lockFile) && unlink($lockFile));

logMsg('INFO', '🚀 Запуск отчета по рейсам в пути');

// 🗄️ PDO
try {
    $pdo = new PDO(
        "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']};charset={$config['db']['charset']}",
        $config['db']['username'], $config['db']['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    logMsg('ERROR', "DB Connect: {$e->getMessage()}"); exit(1);
}

// 🔍 Утилиты
function extractPlate(string $str): ?string {
    $clean = preg_replace('/[^A-ZА-Я0-9 ]/ui', '', mb_strtoupper($str, 'UTF-8'));
    $clean = strtr($clean, ['A'=>'А','B'=>'В','E'=>'Е','K'=>'К','M'=>'М','H'=>'Н','O'=>'О','P'=>'Р','C'=>'С','T'=>'Т','X'=>'Х']);
    if (preg_match('/([АВЕКМНОРСТУХ]\s*\d{3}\s*[АВЕКМНОРСТУХ]{2}\s*\d{2,3})/u', $clean, $m)) {
        return str_replace(' ', '', $m[1]);
    }
    return null;
}

// Извлечение только фамилии из полного ФИО
function extractLastName(string $fullName): string {
    $parts = explode(' ', trim($fullName));
    return !empty($parts) ? $parts[0] : $fullName;
}

function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $R = 6371; $dLat = deg2rad($lat2 - $lat1); $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2)**2 + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLon/2)**2;
    return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
}

function fetchSlitex(): array {
    global $config;
    $ch = curl_init("{$config['slitex']['base_url']}/api/external/devices");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["X-API-Token: {$config['slitex']['token']}"], CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => true]);
    $resp = curl_exec($ch); 
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
    curl_close($ch);
    
    if ($code !== 200) {
        logMsg('ERROR', "Slitex API вернул код {$code}");
    }
    return ($code === 200 && $resp) ? json_decode($resp, true) : [];
}

function sendMax(string $text): bool {
    global $config;
    $url = "{$config['max']['base_url']}/messages?chat_id={$config['max']['chat_id']}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, 
        CURLOPT_POSTFIELDS => json_encode(['text' => $text, 'format' => 'markdown'], JSON_UNESCAPED_UNICODE), 
        CURLOPT_HTTPHEADER => ['Authorization: ' . $config['max']['token'], 'Content-Type: application/json'], 
        CURLOPT_RETURNTRANSFER => true, 
        CURLOPT_TIMEOUT => 20, 
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($code === 200 || $code === 201) return true;
    
    logMsg('ERROR', "MAX API Error: HTTP {$code}");
    return false;
}

// 📥 Данные
$devices = fetchSlitex();
logMsg('INFO', "Загружено устройств из Slitex: " . count($devices));

$coordsByPlate = [];
foreach ($devices as $dev) {
    $plate = extractPlate($dev['name'] ?? '');
    if ($plate && isset($dev['lat'], $dev['lon']) && is_numeric($dev['lat']) && is_numeric($dev['lon']) && abs((float)$dev['lat']) > 0.001) {
        $coordsByPlate[$plate] = ['lat' => (string)$dev['lat'], 'lon' => (string)$dev['lon']];
    }
}

// 🚚 Активные рейсы
$stmt = $pdo->prepare("SELECT f.id, f.comment, f.driver_id, d.full_name, d.vehicle_make_plate FROM flights f JOIN drivers d ON f.driver_id = d.id WHERE f.status = 'started' AND f.actual_end_date IS NULL ORDER BY f.id");
$stmt->execute();
$flights = $stmt->fetchAll();

if (empty($flights)) {
    sendMax("**ОТЧЕТ ПО РЕЙСАМ В ПУТИ**\n───────────────────\nВсе машины завершили выгрузку или ожидают старта.");
    logMsg('INFO', '✅ Активных рейсов не найдено. Отправлено пустое сообщение.');
    exit(0);
}

// 📝 Формирование отчета в НОВОМ формате
$nowMsK = new DateTime('now', new DateTimeZone($config['timezone']));
$headerDate = $nowMsK->format('d.m.Y H:i');
$report = "**ОТЧЕТ ПО РЕЙСАМ В ПУТИ**\n───────────────────\n";

$targetLat = (string)$config['unload']['lat'];
$targetLon = (string)$config['unload']['lon'];

foreach ($flights as $f) {
    $plate = extractPlate($f['vehicle_make_plate'] ?? '');
    $route = trim($f['comment']) ?: 'Без маршрута';
    $lastName = extractLastName($f['full_name']);
    
    // Заголовок рейса: жирным #ID + маршрут + ФАМИЛИЯ
    $report .= "**#{$f['id']} {$route}**\n";
    
    if (!$plate || !isset($coordsByPlate[$plate])) {
        // ⚠️ Оффлайн: предупреждение жирным
		$report .= "{$plate} ({$lastName})\n";
        
        $report .= "**⚠️ ТРЕКЕР ОФФЛАЙН⚠️**\n";
    } else {
        // ✅ Онлайн: госномер и фамилия обычным, расстояние жирным
        $report .= "{$plate} ({$lastName})\n";
        
        
        $fromLat = $coordsByPlate[$plate]['lat'];
        $fromLon = $coordsByPlate[$plate]['lon'];
        $distMeters = haversineKm((float)$fromLat, (float)$fromLon, (float)$targetLat, (float)$targetLon) * 1000;
        $km = round($distMeters / 1000);
        
        $report .= "**Осталось: ~{$km} км**\n";
    }
    
    $report .= "───────────────────\n";
}

$ok = sendMax($report);
logMsg('INFO', $ok ? "✅ Отчет успешно отправлен в MAX" : "❌ Ошибка отправки отчета в MAX");
exit(0);