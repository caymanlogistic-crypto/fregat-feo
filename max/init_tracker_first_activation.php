<?php
declare(strict_types=1);

// ====================================================================
// ⚙️ СКРИПТ ИНИЦИАЛИЗАЦИИ: Заполнение tracker_first_activation
// ====================================================================

$configPath = __DIR__ . '/config_arrival.php';
if (!file_exists($configPath)) {
    exit("Ошибка: config_arrival.php не найден в " . __DIR__ . "\n");
}
$config = require $configPath;
date_default_timezone_set($config['timezone'] ?? 'Europe/Moscow');

// Логирование
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }
$logFile = $logDir . '/init_tracker_first_activation.log';

function writeLog(string $msg): void {
    global $logFile;
    $line = "[" . date('Y-m-d H:i:s') . "] " . $msg . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    echo $line;
}

writeLog("🚀 ЗАПУСК ИНИЦИАЛИЗАЦИИ: Заполнение tracker_first_activation");

// ====================================================================
// 🗄️ ПОДКЛЮЧЕНИЕ К БД
// ====================================================================

try {
    $pdo = new PDO(
        "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']};charset={$config['db']['charset']}",
        $config['db']['username'],
        $config['db']['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Создаем таблицу (если ещё нет)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `tracker_first_activation` (
      `uniqueid` varchar(50) NOT NULL,
      `name` varchar(255) DEFAULT NULL,
      `motiontime` datetime DEFAULT NULL COMMENT 'Текущее время последнего движения',
      `is_activated` tinyint(1) DEFAULT 0 COMMENT '0=ожидает первого сигнала, 1=уже был активен',
      `first_activation_at` datetime DEFAULT NULL COMMENT 'Момент первой активации',
      `last_motiontime` datetime DEFAULT NULL COMMENT 'Последнее зафиксированное движение',
      `first_seen` datetime DEFAULT CURRENT_TIMESTAMP,
      `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`uniqueid`),
      INDEX `idx_activated` (`is_activated`),
      INDEX `idx_first_activation` (`first_activation_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
    COMMENT='Отслеживание первого выхода трекера на связь'");
    
    writeLog("✅ БД подключена. Таблица `tracker_first_activation` готова.");

} catch (PDOException $e) {
    writeLog("❌ Ошибка БД: " . $e->getMessage());
    exit(1);
}

// ====================================================================
// 📥 ПОЛУЧЕНИЕ ДАННЫХ С API Slitex
// ====================================================================

writeLog("🌐 Загрузка данных с Slitex API...");

function fetchSlitexData(string $url, string $token): array {
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
    
    if ($httpCode !== 200 || $response === false) return [];
    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : [];
}

$apiUrl = $config['slitex']['base_url'] . '/api/external';
$token  = $config['slitex']['token'];

$devices = fetchSlitexData("{$apiUrl}/devices", $token);
$adminFields = fetchSlitexData("{$apiUrl}/devices/admin-fields", $token);

// Склейка данных
$mergedData = [];
foreach ($devices as $device) {
    $uid = $device['uniqueid'] ?? null;
    if ($uid !== null) $mergedData[$uid] = $device;
}
foreach ($adminFields as $item) {
    $uid = $item['uniqueid'] ?? null;
    if ($uid !== null) {
        if (isset($mergedData[$uid])) {
            $mergedData[$uid]['admin_fields'] = $item['fields'] ?? [];
        } else {
            $mergedData[$uid] = [
                'uniqueid'     => $uid,
                'name'         => 'Unknown',
                'motiontime'   => null,
                'admin_fields' => $item['fields'] ?? []
            ];
        }
    }
}

writeLog("📦 Обработано устройств после склейки: " . count($mergedData));

// ====================================================================
// 🔄 ЗАПОЛНЕНИЕ ТАБЛИЦЫ
// ====================================================================

$insertedNew = 0;
$updatedExisting = 0;
$markedActivated = 0;
$markedWaiting = 0;

// Подготавливаем запросы
$selectStmt = $pdo->prepare("SELECT uniqueid, is_activated, first_activation_at FROM tracker_first_activation WHERE uniqueid = ?");
$insertStmt = $pdo->prepare("INSERT INTO tracker_first_activation 
    (uniqueid, name, motiontime, is_activated, first_activation_at, last_motiontime, first_seen) 
    VALUES (?, ?, ?, ?, ?, ?, NOW())");
$updateStmt = $pdo->prepare("UPDATE tracker_first_activation 
    SET name = ?, motiontime = ?, is_activated = ?, 
        first_activation_at = COALESCE(first_activation_at, ?),
        last_motiontime = COALESCE(?, last_motiontime),
        updated_at = NOW()
    WHERE uniqueid = ?");

$pdo->beginTransaction();

try {
    foreach ($mergedData as $device) {
        $uid = $device['uniqueid'] ?? '';
        $name = $device['name'] ?? '';
        $motiontime = $device['motiontime'] ?? null;
        
        // Пропускаем трекеры с именем из 6 цифр
        if (preg_match('/^\d{6}$/', $name)) {
            continue;
        }
        
        // Нормализуем motiontime
        $invalidDates = ['0000-00-00 00:00:00', '1970-01-01 00:00:00', '0000-00-00T00:00:00', null, ''];
        $isMotiontimeValid = !empty($motiontime) && !in_array($motiontime, $invalidDates);
        $normalizedMotiontime = $isMotiontimeValid ? $motiontime : null;
        
        // Проверяем, есть ли уже запись
        $selectStmt->execute([$uid]);
        $existing = $selectStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existing) {
            // Новая запись
            $isActivated = $isMotiontimeValid ? 1 : 0;
            $firstActivationAt = $isMotiontimeValid ? $normalizedMotiontime : null;
            
            $insertStmt->execute([
                $uid,
                $name,
                $normalizedMotiontime,
                $isActivated,
                $firstActivationAt,
                $normalizedMotiontime
            ]);
            
            $insertedNew++;
            if ($isActivated) {
                $markedActivated++;
                writeLog("🟢 НОВЫЙ АКТИВНЫЙ: {$name} ({$uid}) — motiontime = {$motiontime}");
            } else {
                $markedWaiting++;
                writeLog("💤 НОВЫЙ В ОЖИДАНИИ: {$name} ({$uid}) — motiontime пуст");
            }
            
        } else {
            // Обновление существующей
            $wasActivated = $existing['is_activated'];
            $nowActivated = $isMotiontimeValid ? 1 : $wasActivated;
            
            // first_activation_at устанавливаем только если трекер стал активным впервые
            $firstActivationAt = null;
            if ($isMotiontimeValid && !$wasActivated) {
                $firstActivationAt = $normalizedMotiontime;
                writeLog("🔄 ПЕРВАЯ АКТИВАЦИЯ (историческая): {$name} ({$uid})");
            }
            
            $updateStmt->execute([
                $name,
                $normalizedMotiontime,
                $nowActivated,
                $firstActivationAt,
                $normalizedMotiontime,
                $uid
            ]);
            
            $updatedExisting++;
        }
    }
    
    $pdo->commit();
    
    writeLog("✅ ИНИЦИАЛИЗАЦИЯ ЗАВЕРШЕНА:");
    writeLog("   ├─ Новых записей: {$insertedNew}");
    writeLog("   ├─ Обновлено существующих: {$updatedExisting}");
    writeLog("   ├─ Помечены как активные: {$markedActivated}");
    writeLog("   └─ Помечены как ожидающие: {$markedWaiting}");
    
} catch (Exception $e) {
    $pdo->rollback();
    writeLog("❌ Ошибка при заполнении таблицы: " . $e->getMessage());
    exit(1);
}

// ====================================================================
// 📊 СТАТИСТИКА ПО ТАБЛИЦЕ
// ====================================================================

$statsStmt = $pdo->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN is_activated = 1 THEN 1 ELSE 0 END) as activated,
    SUM(CASE WHEN is_activated = 0 THEN 1 ELSE 0 END) as waiting,
    SUM(CASE WHEN first_activation_at IS NOT NULL THEN 1 ELSE 0 END) as has_first_activation
FROM tracker_first_activation");
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

writeLog("📊 СТАТИСТИКА ТАБЛИЦЫ:");
writeLog("   ├─ Всего трекеров: {$stats['total']}");
writeLog("   ├─ Уже активированы: {$stats['activated']}");
writeLog("   ├─ Ожидают активации: {$stats['waiting']}");
writeLog("   └─ Имеют отметку первой активации: {$stats['has_first_activation']}");

writeLog("🏁 Скрипт инициализации завершен успешно!");
exit(0);