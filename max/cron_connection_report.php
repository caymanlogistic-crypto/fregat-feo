<?php
declare(strict_types=1);

// 1. Загрузка конфигурации
$configPath = __DIR__ . '/config_arrival.php';
if (!file_exists($configPath)) {
    error_log("ОШИБКА: config_arrival.php не найден в " . __DIR__);
    exit(0);
}
$config = require $configPath;

// Устанавливаем часовой пояс
date_default_timezone_set($config['timezone'] ?? 'Europe/Moscow');

// Настройки логирования и блокировки
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}

$logFile  = $logDir . '/connection_report.log';
$lockFile = __DIR__ . '/.connection_report.lock';

function writeLog(string $msg): void {
    global $logFile;
    $line = "[" . date('Y-m-d H:i:s') . "] " . $msg . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

writeLog("🚀 ЗАПУСК СКРИПТА ОТЧЕТА ПО СВЯЗИ");

// 2. Защита от параллельных запусков (Lock)
if (file_exists($lockFile)) {
    $lockTime = filemtime($lockFile);
    if ((time() - $lockTime) < 300) {
        writeLog("⛔ Скрипт уже запущен. Выход.");
        exit(0);
    }
    writeLog("⚠️ Найден старый lock-файл. Удаляю.");
    unlink($lockFile);
}
touch($lockFile);
register_shutdown_function(fn() => file_exists($lockFile) && unlink($lockFile));

// 3. Подключение к Базе Данных
try {
    $pdo = new PDO(
        "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']};charset={$config['db']['charset']}",
        $config['db']['username'],
        $config['db']['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    writeLog("✅ Подключение к БД успешно.");
} catch (PDOException $e) {
    writeLog("❌ Ошибка подключения к БД: " . $e->getMessage());
    exit(1);
}

// 4. Вспомогательные функции

// Функция извлечения госномера из строки
function extractPlate(string $str): ?string {
    $clean = mb_strtoupper(trim($str), 'UTF-8');
    $clean = strtr($clean, [
        'A'=>'А', 'B'=>'В', 'E'=>'Е', 'K'=>'К', 'M'=>'М', 'H'=>'Н', 'O'=>'О', 'P'=>'Р', 'C'=>'С', 'T'=>'Т', 'X'=>'Х'
    ]);
    if (preg_match('/([АВЕКМНОРСТУХ]\s*\d{3}\s*[АВЕКМНОРСТУХ]{2}\s*\d{2,3})/u', $clean, $matches)) {
        return str_replace(' ', '', $matches[1]);
    }
    return null;
}

// Среди парных трекеров выбираем самый свежий, а не первый в ответе API.
function findConnectionReportDevice(array $devices, ?string $plate): ?array {
    if ($plate === null) {
        return null;
    }

    $selected = null;
    $latestTimestamp = null;

    foreach ($devices as $device) {
        if (extractPlate($device['name'] ?? '') !== $plate) {
            continue;
        }

        // Если ни один трекер не передавал данные, сохраняем предупреждение.
        $selected ??= $device;
        if (empty($device['lastupdate'])) {
            continue;
        }

        try {
            $timestamp = (new DateTimeImmutable($device['lastupdate'], new DateTimeZone('UTC')))->getTimestamp();
        } catch (Exception $e) {
            continue;
        }

        if ($latestTimestamp === null || $timestamp > $latestTimestamp) {
            $selected = $device;
            $latestTimestamp = $timestamp;
        }
    }

    return $selected;
}

// Функция получения данных с Slitex
function fetchSlitexDevices(array $config): array {
    $url = "{$config['slitex']['base_url']}/api/external/devices";
    $token = $config['slitex']['token'];
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["X-API-Token: {$token}"],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        return json_decode($response, true);
    }
    return [];
}

// Функция отправки сообщения в MAX
function sendMaxMessage(string $text, array $config): bool {
    // 🔧 Автоочистка chat_id от пробелов и знаков препинания
    $chatId = trim(rtrim($config['max']['chat_id'], '; '));
    $token  = $config['max']['token'];
    $url    = "{$config['max']['base_url']}/messages?chat_id={$chatId}";
    
    $payload = [
        'text'   => $text,
        'format' => 'markdown'
    ];
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Authorization: ' . $token,
            'Content-Type: application/json'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 || $httpCode === 201) {
        writeLog("✅ Сообщение отправлено в MAX.");
        return true;
    }
    
    writeLog("❌ Ошибка отправки в MAX. Код: {$httpCode}. Ответ: {$response}");
    return false;
}

// 5. Основная логика

// А. Получаем рейсы, которые сейчас в пути
$query = "
    SELECT f.id, f.comment, d.full_name, d.vehicle_make_plate 
    FROM flights f 
    JOIN drivers d ON f.driver_id = d.id 
    WHERE f.status = 'started' AND f.actual_end_date IS NULL
    ORDER BY f.id
";
$stmt = $pdo->query($query);
$flights = $stmt->fetchAll();

writeLog("Найдено рейсов в пути: " . count($flights));

if (empty($flights)) {
    writeLog("ℹ️ Рейсов в пути нет. Отчет не отправляется.");
    exit(0);
}

// Б. Получаем данные с трекеров
$devices = fetchSlitexDevices($config);
writeLog("Получено устройств с Slitex: " . count($devices));

$now = new DateTime('now');
$reportItems = [];

foreach ($flights as $flight) {
    $plateDB = extractPlate($flight['vehicle_make_plate'] ?? '');
    $driverName = explode(' ', trim($flight['full_name'] ?? ''))[0] ?? 'Водитель'; 
    $route = trim($flight['comment']) ?: 'Без маршрута';
    
    // Пустые и устаревшие дубли не перекрывают действующий трекер.
    $foundDevice = findConnectionReportDevice($devices, $plateDB);
    
    // --- ФОРМИРОВАНИЕ СООБЩЕНИЯ ---
    $indicator = '🟢'; 
    $line1 = ""; $line2 = ""; $line3 = "";

    // Сценарий 1: Устройства нет в ответе API вообще
    if (!$foundDevice) {
        $indicator = '🚫';
        $line1 = "**#{$flight['id']} {$route}**";
        $line2 = $plateDB ? "{$plateDB}({$driverName})" : "({$driverName})";
        $line3 = "⚠️ ДАННЫХ НЕ ПОСТУПАЛО ⚠️";
    } 
    // Сценарий 2: Устройство есть, но нет даты обновления (оффлайн)
    elseif (empty($foundDevice['lastupdate'])) {
        $indicator = '🔴';
        $line1 = "**#{$flight['id']} {$route}**";
        $line2 = $plateDB ? "{$plateDB}({$driverName})" : "({$driverName})";
        $line3 = "⚠️ ТРЕКЕР ОФФЛАЙН ⚠️";
    } 
    // Сценарий 3: Считаем время с последнего обновления
    else {
        try {
            $lastUpdate = new DateTime($foundDevice['lastupdate'], new DateTimeZone('UTC'));
            $diff = $now->diff($lastUpdate);
            $totalMinutes = ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;

            // ✅ ГЛАВНОЕ ИЗМЕНЕНИЕ: Пропускаем машины, которые выходили на связь менее часа назад
            if ($totalMinutes <= 60) {
                continue;
            }

            // Форматируем время
            $timeStr = "";
            if ($diff->days > 0) $timeStr .= "{$diff->days}д ";
            if ($diff->h > 0 || $diff->days > 0) $timeStr .= "{$diff->h}ч ";
            $timeStr .= "{$diff->i}м назад";
            $timeStr = trim($timeStr);

            $line1 = "**#{$flight['id']} {$route}**";
            $line2 = $plateDB ? "{$plateDB}({$driverName})" : "({$driverName})";
            $line3 = "**🕒 {$timeStr}**";

            // Индикаторы для задержек > 1 часа
            if ($totalMinutes > 180) {
                $indicator = '🟠'; // Более 3 часов
            } else {
                $indicator = '🟡'; // 1–3 часа
            }
        } catch (Exception $e) {
            $indicator = '🔴'; 
            $line1 = "**#{$flight['id']} {$route}**";
            $line2 = "({$driverName})";
            $line3 = "❌ ОШИБКА ДАТЫ";
        }
    }

    // Собираем блок и добавляем в отчет
    $reportItems[] = "{$indicator} {$line1}\n{$line2}\n{$line3}";
}

// 6. Формирование итогового сообщения
// Если после фильтрации массив пуст (все машины на связи <1ч), ничего не отправляем
if (empty($reportItems)) {
    writeLog("ℹ️ Нет машин с задержкой >1ч. Выход без отправки.");
    exit(0);
}

$nowStr = date('d.m.Y H:i');
$msg = "**📡 ОТЧЕТ ПО СВЯЗИ ТРЕКЕРОВ**\n───────────────────\n";
$msg .= implode("\n───────────────────\n", $reportItems) . "\n───────────────────";

// 7. Отправка
sendMaxMessage($msg, $config);
writeLog("🏁 СКРИПТ ЗАВЕРШЕН.");
exit(0);
