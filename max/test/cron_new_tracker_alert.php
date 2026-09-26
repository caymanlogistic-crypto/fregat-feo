<?php
/**
 * cron_new_tracker_alert.php
 * Отслеживание первой активации трекеров
 * 
 * Логика:
 * - Загружает все трекеры из Slitex API (devices + admin-fields)
 * - Сравнивает с таблицей tracker_first_activation
 * - Отправляет уведомление в MAX только при ПЕРВОЙ активации
 * - Защита от спама: is_activated=1 навсегда после первой активации
 * 
 * Версия: 2.0 - поддержка нового формата admin_fields (индексированный массив)
 */
declare(strict_types=1);

// Проверка версии PHP (требуется 7.4+)
if (PHP_VERSION_ID < 70400) {
    exit("Ошибка: Требуется PHP 7.4 или выше. Текущая: " . PHP_VERSION . "\n");
}

$config = require __DIR__ . '/config_arrival.php';
date_default_timezone_set($config['timezone']);

// 📝 Логирование
function logMsg(string $level, string $msg): void {
    global $config;
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) mkdir($logDir, 0755, true);
    $logFile = $logDir . '/new_tracker_alert.log';
    file_put_contents($logFile, sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), $level, $msg), FILE_APPEND | LOCK_EX);
}

// 🔐 Lock-файл: защита от параллельных запусков
$lockFile = __DIR__ . '/locks/cron_new_tracker_alert.lock';
$lockTimeout = 300; // 5 минут

if (file_exists($lockFile)) {
    if ((time() - filemtime($lockFile)) < $lockTimeout) {
        logMsg('WARN', 'Скрипт уже запущен или завис');
        exit(1);
    }
    unlink($lockFile);
}
$lockDir = dirname($lockFile);
if (!is_dir($lockDir)) mkdir($lockDir, 0755, true);
touch($lockFile);
register_shutdown_function(fn() => file_exists($lockFile) && unlink($lockFile));

// Устанавливаем максимальное время выполнения
set_time_limit(300);

logMsg('INFO', '🚀 Запуск скрипта отслеживания первой активации трекеров (v2.0)');

// 🗄️ Подключение к БД
try {
    $pdo = new PDO(
        "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']};charset={$config['db']['charset']}",
        $config['db']['username'], 
        $config['db']['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    logMsg('INFO', 'БД подключена');
} catch (PDOException $e) {
    logMsg('ERROR', "DB Connect: {$e->getMessage()}");
    exit(1);
}

// 📤 Функция отправки в MAX
function sendMax(string $text, ?string $format = null): array {
    global $config;
    
    $chatId = trim(rtrim($config['max']['chat_id'], '; '));
    $url = "{$config['max']['base_url']}/messages?chat_id={$chatId}";
    
    $payload = ['text' => $text];
    
    if ($format !== null && in_array($format, ['html', 'markdown'], true)) {
        $payload['format'] = $format;
    }
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $config['max']['token'],
            'Content-Type: application/json'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ['code' => $code, 'body' => json_decode($resp, true) ?: $resp];
}

// 📥 Загрузка данных из API с повторными попытками
function fetchSlitexData(string $endpoint, int $retries = 3): array {
    global $config;
    
    for ($attempt = 1; $attempt <= $retries; $attempt++) {
        $ch = curl_init("{$config['slitex']['base_url']}/api/external/{$endpoint}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["X-API-Token: {$config['slitex']['token']}"],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($code === 200 && $resp) {
            $data = json_decode($resp, true);
            if (!empty($data)) {
                return $data;
            }
        }
        
        if ($attempt < $retries) {
            $wait = 2 ** $attempt; // exponential backoff: 2, 4, 8 секунд
            logMsg('WARN', "Попытка {$attempt}/{$retries} не удалась для {$endpoint}, ждем {$wait}с");
            sleep($wait);
        }
    }
    
    logMsg('ERROR', "Не удалось загрузить {$endpoint} после {$retries} попыток");
    return [];
}

logMsg('INFO', 'Загрузка данных с Slitex API...');

$devices = fetchSlitexData('devices');
$adminFields = fetchSlitexData('devices/admin-fields');

if (empty($devices) && empty($adminFields)) {
    logMsg('WARN', 'Slitex API вернул пустой ответ или ошибку');
    exit(0);
}

// 🧹 Функция нормализации admin_fields (поддержка нового формата)
function normalizeAdminFields($adminFields): array {
    if (empty($adminFields)) {
        return [];
    }
    
    // Если это уже ассоциативный массив (старый формат)
    if (isset($adminFields['outID']) || isset($adminFields['outIP'])) {
        return $adminFields;
    }
    
    // Если это индексированный массив объектов (новый формат)
    if (is_array($adminFields) && isset($adminFields[0]) && is_array($adminFields[0])) {
        return $adminFields[0]; // Берем первый объект
    }
    
    // Неизвестный формат
    logMsg('WARN', 'Неизвестный формат admin_fields: ' . json_encode($adminFields));
    return [];
}

// Склейка данных с поддержкой нового формата
$mergedData = [];
foreach ($devices as $device) {
    $uid = $device['uniqueid'] ?? null;
    if ($uid !== null) {
        $mergedData[$uid] = $device;
    }
}

foreach ($adminFields as $item) {
    $uid = $item['uniqueid'] ?? null;
    if ($uid !== null) {
        $normalizedFields = normalizeAdminFields($item['fields'] ?? []);
        
        if (isset($mergedData[$uid])) {
            $mergedData[$uid]['admin_fields'] = $normalizedFields;
        } else {
            $mergedData[$uid] = [
                'uniqueid'     => $uid,
                'name'         => $item['name'] ?? 'Unknown',
                'motiontime'   => null,
                'admin_fields' => $normalizedFields
            ];
        }
    }
}

logMsg('INFO', 'Обработано устройств после склейки: ' . count($mergedData));

// 🔄 Основная логика: Поиск первой активации
$alertsSent = 0;
$invalidDates = ['0000-00-00 00:00:00', '1970-01-01 00:00:00', '0000-00-00T00:00:00', null, ''];

// Подготавливаем запросы
$selectStmt = $pdo->prepare("SELECT * FROM tracker_first_activation WHERE uniqueid = ?");
$insertNewStmt = $pdo->prepare("INSERT INTO tracker_first_activation 
    (uniqueid, name, motiontime, is_activated, first_activation_at, last_motiontime) 
    VALUES (?, ?, ?, ?, ?, ?)");
$updateActivatedStmt = $pdo->prepare("UPDATE tracker_first_activation 
    SET motiontime = ?, is_activated = 1, 
        first_activation_at = ?, last_motiontime = ?,
        updated_at = NOW()
    WHERE uniqueid = ?");
$updateMotionStmt = $pdo->prepare("UPDATE tracker_first_activation 
    SET motiontime = ?, last_motiontime = ?, updated_at = NOW()
    WHERE uniqueid = ?");
$updateLostStmt = $pdo->prepare("UPDATE tracker_first_activation 
    SET motiontime = NULL, updated_at = NOW()
    WHERE uniqueid = ?");

$pdo->beginTransaction();

try {
    foreach ($mergedData as $device) {
        $uid = $device['uniqueid'] ?? '';
        $name = $device['name'] ?? '';
        $motiontime = $device['motiontime'] ?? null;
        $admin = $device['admin_fields'] ?? [];
        
        // Пропускаем трекеры с именем из 6 цифр (технические/тестовые)
        if (preg_match('/^\d{6}$/', $name)) {
            continue;
        }
        
        // Нормализуем motiontime
        $isMotiontimeValid = !empty($motiontime) && !in_array($motiontime, $invalidDates, true);
        $normalizedMotiontime = $isMotiontimeValid ? $motiontime : null;
        
        // Получаем текущую запись из БД
        $selectStmt->execute([$uid]);
        $trackerRecord = $selectStmt->fetch();
        
        if (!$trackerRecord) {
            // ========== НОВЫЙ ТРЕКЕР (нет в БД) ==========
            if ($isMotiontimeValid) {
                // Сначала отправляем уведомление, потом записываем в БД
                $alertSent = sendFirstActivationAlert($uid, $name, $admin, $normalizedMotiontime);
                if ($alertSent) {
                    $insertNewStmt->execute([
                        $uid, $name, $normalizedMotiontime,
                        1, $normalizedMotiontime, $normalizedMotiontime
                    ]);
                    $alertsSent++;
                    logMsg('INFO', "🚨 Новый активный трекер: {$name} ({$uid})");
                } else {
                    logMsg('ERROR', "❌ Не удалось отправить уведомление для {$name} ({$uid}), запись не создана");
                }
            } else {
                // Ждущий первого сигнала
                $insertNewStmt->execute([
                    $uid, $name, null, 0, null, null
                ]);
                logMsg('INFO', "💤 Новый трекер в ожидании: {$name} ({$uid})");
            }
            
        } else {
            // ========== СУЩЕСТВУЮЩИЙ ТРЕКЕР ==========
            $wasActivated = (int)$trackerRecord['is_activated'] === 1;
            
            if ($isMotiontimeValid) {
                if (!$wasActivated) {
                    // 🎉 ПЕРВАЯ АКТИВАЦИЯ! Сначала отправляем уведомление
                    $alertSent = sendFirstActivationAlert($uid, $name, $admin, $normalizedMotiontime);
                    if ($alertSent) {
                        $updateActivatedStmt->execute([
                            $normalizedMotiontime,
                            $normalizedMotiontime,
                            $normalizedMotiontime,
                            $uid
                        ]);
                        $alertsSent++;
                        logMsg('INFO', "🎉 Первая активация: {$name} ({$uid})");
                    } else {
                        logMsg('ERROR', "❌ Не удалось отправить уведомление для {$name} ({$uid}), активация не подтверждена");
                    }
                    
                } else {
                    // Уже был активен - просто обновляем motiontime
                    $updateMotionStmt->execute([
                        $normalizedMotiontime,
                        $normalizedMotiontime,
                        $uid
                    ]);
                }
            } else {
                // Трекер не активен - обнуляем motiontime
                $updateLostStmt->execute([$uid]);
                
                if ($wasActivated) {
                    logMsg('WARN', "⚠️ Потеря связи: {$name} ({$uid})");
                }
            }
        }
    }
    
    $pdo->commit();
    
    // ========== ВАРИАНТ 4: ФОРСИРОВАННЫЙ ТЕСТ ДЛЯ КОНКРЕТНОГО UID ==========
    // Раскомментируйте блок ниже для теста - отправит сообщение, НО НЕ СОХРАНИТ В БД
    // ВНИМАНИЕ: Это тестовый режим! БД не изменяется, но сообщение реально уходит!
    /*
    $forceTestUID = '134816';  // Укажите UID для теста (измените на нужный)
    $forceTestFound = false;
    
    logMsg('INFO', '🧪🧪🧪 ФОРСИРОВАННЫЙ ТЕСТОВЫЙ РЕЖИМ 🧪🧪🧪');
    logMsg('INFO', "Поиск трекера с UID: {$forceTestUID} для тестовой отправки");
    
    foreach ($mergedData as $device) {
        if (($device['uniqueid'] ?? '') === $forceTestUID) {
            $forceTestFound = true;
            $testName = $device['name'] ?? 'Тестовый трекер';
            $testAdmin = $device['admin_fields'] ?? [];
            $testMotiontime = $device['motiontime'] ?? date('Y-m-d H:i:s');
            
            logMsg('INFO', "🧪 Найден трекер для теста: {$testName} ({$forceTestUID})");
            logMsg('INFO', "🧪 Admin поля: " . json_encode($testAdmin, JSON_UNESCAPED_UNICODE));
            logMsg('INFO', "🧪 Motiontime: {$testMotiontime}");
            logMsg('INFO', "🧪 ОТПРАВЛЯЕМ ТЕСТОВОЕ СООБЩЕНИЕ (БД НЕ ТРОГАЕТСЯ)...");
            
            // Отправляем сообщение (БД не трогаем!)
            $testResult = sendFirstActivationAlert($forceTestUID, $testName, $testAdmin, $testMotiontime);
            
            if ($testResult) {
                logMsg('INFO', "✅🧪 Тестовое сообщение успешно отправлено для {$forceTestUID}");
                logMsg('INFO', "✅🧪 БАЗА ДАННЫХ НЕ ИЗМЕНЕНА! Это был тест.");
            } else {
                logMsg('ERROR', "❌🧪 Ошибка при отправке тестового сообщения для {$forceTestUID}");
            }
            
            break;
        }
    }
    
    if (!$forceTestFound) {
        logMsg('WARN', "🧪 Трекер с UID {$forceTestUID} не найден в данных API");
        logMsg('WARN', "🧪 Доступные UID: " . implode(', ', array_keys($mergedData)));
    }
    */
    // ========== КОНЕЦ ТЕСТОВОГО БЛОКА ==========
    
} catch (Exception $e) {
    $pdo->rollback();
    logMsg('ERROR', "Ошибка в основной логике: {$e->getMessage()}");
    logMsg('ERROR', "Stack trace: " . $e->getTraceAsString());
    exit(1);
}

// 📊 Статистика
$statsStmt = $pdo->query("SELECT 
    COUNT(*) as total,
    SUM(is_activated) as activated,
    SUM(CASE WHEN is_activated = 0 THEN 1 ELSE 0 END) as waiting
FROM tracker_first_activation");
$stats = $statsStmt->fetch();

logMsg('INFO', "🏁 Скрипт завершён. Отправлено уведомлений: {$alertsSent}");
logMsg('INFO', "Статистика: всего {$stats['total']}, активировано {$stats['activated']}, ожидают {$stats['waiting']}");

exit(0);

// ====================================================================
// 📤 Функция отправки уведомления о первой активации
// ====================================================================
function sendFirstActivationAlert(string $uid, string $name, array $admin, string $motiontime): bool {
    // admin уже должен быть нормализован (ассоциативный массив)
    $outID = $admin['outID'] ?? '—';
    $outIP = $admin['outIP'] ?? '—';
    $outPort = $admin['outPort'] ?? '—';
    $outProtocol = $admin['outProtocol'] ?? '—';
    
    // Дополнительные поля из нового формата (in-настройки)
    $inIP = $admin['inIP'] ?? null;
    $inPort = $admin['inPort'] ?? null;
    $inProtocol = $admin['inProtocol'] ?? null;
    
    $formattedTime = date('d.m.Y H:i:s', strtotime($motiontime));
    
    $text = "**ПЕРВАЯ АКТИВАЦИЯ ТРЕКЕРА**\n\n";
    $text .= "**{$name}**\n";
    $text .= "Настройки для водителя: `{$uid}`\n\n";
    $text .= "📡 Настройки ФГИС ОПВК:\n";
    $text .= "• ID: `{$outID}`\n";
    $text .= "• Протокол: {$outProtocol}\n";
    $text .= "• Адрес: `{$outIP}:{$outPort}`\n";
    

    
    $text .= "\n🕐 Первый сигнал: {$formattedTime}\n\n";
    $text .= "> 💡 *Трекер впервые вышел на связь. Необходимо проверить ретрансляцию данных во ФГИС ОПВК. Светлана, прошу подтвердить корректность настроек.*";
    
    $result = sendMax($text, 'markdown');
    
    if (in_array($result['code'], [200, 201], true)) {
        logMsg('INFO', "✅ Уведомление отправлено для {$name} ({$uid})");
        return true;
    } else {
        logMsg('ERROR', "❌ Ошибка отправки для {$name} ({$uid}): код {$result['code']}, ответ: " . json_encode($result['body']));
        return false;
    }
}