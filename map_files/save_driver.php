<?php
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/Support/max_notify.php';

header('Content-Type: application/json; charset=utf-8');

function driverOut(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function readDriverInput(): array
{
    $raw = file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return $_POST;
}

function normalizeDriverFullName(string $value): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value));
    if ($value === '') {
        return '';
    }
    $parts = preg_split('/\s+/u', $value);
    $normalized = [];
    foreach ((array)$parts as $part) {
        $part = preg_replace('/[^А-Яа-яЁё-]/u', '', (string)$part);
        if ($part === '') {
            continue;
        }
        $hyphenParts = preg_split('/-+/u', $part);
        $chunks = [];
        foreach ((array)$hyphenParts as $hp) {
            $hp = trim((string)$hp);
            if ($hp === '') continue;
            $hp = mb_strtolower($hp, 'UTF-8');
            $first = mb_substr($hp, 0, 1, 'UTF-8');
            $rest = mb_substr($hp, 1, null, 'UTF-8');
            $chunks[] = mb_strtoupper($first, 'UTF-8') . $rest;
        }
        if (!empty($chunks)) {
            $normalized[] = implode('-', $chunks);
        }
    }
    return trim(implode(' ', $normalized));
}

function normalizeDriverPlate(string $value): string
{
    $value = mb_strtoupper(trim($value), 'UTF-8');
    $map = [
        'A' => 'А', 'B' => 'В', 'C' => 'С', 'E' => 'Е', 'H' => 'Н',
        'K' => 'К', 'M' => 'М', 'O' => 'О', 'P' => 'Р', 'T' => 'Т',
        'X' => 'Х', 'Y' => 'У',
    ];
    $value = strtr($value, $map);
    $value = preg_replace('/[^А-Я0-9]/u', '', $value);
    return mb_substr((string)$value, 0, 9, 'UTF-8');
}

function extractPlateOnly(string $value): string
{
    $value = mb_strtoupper(trim($value), 'UTF-8');
    $map = [
        'A' => 'А', 'B' => 'В', 'C' => 'С', 'E' => 'Е', 'H' => 'Н',
        'K' => 'К', 'M' => 'М', 'O' => 'О', 'P' => 'Р', 'T' => 'Т',
        'X' => 'Х', 'Y' => 'У',
    ];
    $value = strtr($value, $map);
    if (preg_match('/([АВЕКМНОРСТУХ]\d{3}[АВЕКМНОРСТУХ]{2}\d{2,3})/u', $value, $m)) {
        return $m[1];
    }
    return trim($value);
}

function isValidDriverName(string $name): bool
{
    $name = trim((string)$name);
    if ($name === '') return false;
    $words = preg_split('/\s+/u', $name);
    if (!is_array($words) || count($words) !== 3) return false;
    foreach ($words as $word) {
        if (!preg_match('/^[А-ЯЁ][а-яё-]+$/u', (string)$word)) {
            return false;
        }
        $plain = str_replace('-', '', (string)$word);
        if (mb_strlen($plain, 'UTF-8') < 2) {
            return false;
        }
    }
    return true;
}

function isValidDriverPlate(string $plate): bool
{
    return (bool)preg_match('/^[АВЕКМНОРСТУХ]\d{3}[АВЕКМНОРСТУХ]{2}\d{2,3}$/u', $plate);
}

function driverLabel(array $driver): string
{
    $fullName = trim((string)($driver['full_name'] ?? ''));
    $plate = extractPlateOnly((string)($driver['vehicle_make_plate'] ?? ''));
    if ($fullName !== '' && $plate !== '') {
        return $fullName . ' — ' . $plate;
    }
    if ($fullName !== '') {
        return $fullName;
    }
    if ($plate !== '') {
        return $plate;
    }
    $id = (int)($driver['id'] ?? 0);
    return 'Водитель #' . $id;
}

function loadDriversColumns(PDO $pdo): array
{
    $stmt = $pdo->query('SHOW COLUMNS FROM drivers');
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $columns = [];
    foreach ((array)$rows as $row) {
        $field = (string)($row['Field'] ?? '');
        if ($field !== '') {
            $columns[$field] = $row;
        }
    }
    return $columns;
}

function findExistingDriver(PDO $pdo, string $fullName, string $plate): ?array
{
    $stmt = $pdo->prepare('SELECT id, full_name, vehicle_make_plate FROM drivers WHERE full_name = :full_name AND vehicle_make_plate = :plate LIMIT 1');
    if (!$stmt || !$stmt->execute([':full_name' => $fullName, ':plate' => $plate])) {
        return null;
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function insertDriver(PDO $pdo, array $columns, string $fullName, string $plate, string $gpsType, ?string $trackerUniqueId): array
{
    $fields = [];
    $placeholders = [];
    $params = [];

    if (isset($columns['full_name'])) {
        $fields[] = '`full_name`';
        $placeholders[] = ':full_name';
        $params[':full_name'] = $fullName;
    }
    if (isset($columns['vehicle_make_plate'])) {
        $fields[] = '`vehicle_make_plate`';
        $placeholders[] = ':vehicle_make_plate';
        $params[':vehicle_make_plate'] = $plate;
    }
    if (isset($columns['is_active'])) {
        $fields[] = '`is_active`';
        $placeholders[] = ':is_active';
        $params[':is_active'] = 1;
    }
    if ($trackerUniqueId !== null && $trackerUniqueId !== '') {
        if (isset($columns['tracker_uniqueid'])) {
            $fields[] = '`tracker_uniqueid`';
            $placeholders[] = ':tracker_uniqueid';
            $params[':tracker_uniqueid'] = $trackerUniqueId;
        } elseif (isset($columns['tracker_id'])) {
            $fields[] = '`tracker_id`';
            $placeholders[] = ':tracker_id';
            $params[':tracker_id'] = $trackerUniqueId;
        }
    }
    if (isset($columns['gps_connection_type'])) {
        $fields[] = '`gps_connection_type`';
        $placeholders[] = ':gps_connection_type';
        $params[':gps_connection_type'] = $gpsType;
    }

    if (empty($fields)) {
        throw new RuntimeException('Drivers table does not support required fields');
    }

    $sql = 'INSERT INTO drivers (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $stmt = $pdo->prepare($sql);
    if (!$stmt || !$stmt->execute($params)) {
        throw new RuntimeException('Failed to insert driver');
    }

    $driverId = (int)$pdo->lastInsertId();
    if ($driverId <= 0) {
        throw new RuntimeException('Failed to resolve created driver id');
    }

    $stmtDriver = $pdo->prepare('SELECT id, full_name, vehicle_make_plate FROM drivers WHERE id = :id LIMIT 1');
    if (!$stmtDriver || !$stmtDriver->execute([':id' => $driverId])) {
        throw new RuntimeException('Failed to fetch created driver');
    }
    $driver = $stmtDriver->fetch(PDO::FETCH_ASSOC);
    if (!is_array($driver)) {
        throw new RuntimeException('Created driver record is empty');
    }

    return $driver;
}

function getSlitexConfig(): array
{
    $baseUrl = trim((string)($GLOBALS['slitexBaseUrl'] ?? getenv('SLITEX_BASE_URL') ?: 'https://slitex.online'));
    $token = trim((string)($GLOBALS['slitexApiToken'] ?? getenv('SLITEX_API_TOKEN') ?: ''));
    return ['base_url' => rtrim($baseUrl, '/'), 'token' => $token];
}


function createIndependentDriverPdo(array $dbConfig): PDO
{
    $host = (string)($dbConfig['host'] ?? '');
    $port = (int)($dbConfig['port'] ?? 3306);
    $database = (string)($dbConfig['database'] ?? ($dbConfig['dbname'] ?? ''));
    $username = (string)($dbConfig['username'] ?? ($dbConfig['user'] ?? ''));
    $password = (string)($dbConfig['password'] ?? '');
    $charset = (string)($dbConfig['charset'] ?? 'utf8mb4');

    if ($host === '' || $database === '' || $username === '') {
        throw new RuntimeException('Невозможно открыть независимое соединение БД для проверки назначения.');
    }

    return new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset={$charset}",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,
        ]
    );
}

function findExactDriverTrackerAssignment(PDO $pdo, string $fullName, string $plate, string $trackerUniqueId): ?array
{
    $columns = loadDriversColumns($pdo);
    if (isset($columns['tracker_uniqueid'])) {
        $trackerColumn = 'tracker_uniqueid';
    } elseif (isset($columns['tracker_id'])) {
        $trackerColumn = 'tracker_id';
    } else {
        throw new RuntimeException('В drivers отсутствует поле связи с SLITEX-трекером.');
    }

    $sql = 'SELECT id, full_name, vehicle_make_plate, `' . $trackerColumn . '` AS tracker_uniqueid '
        . 'FROM drivers '
        . 'WHERE full_name = :full_name AND vehicle_make_plate = :plate AND `' . $trackerColumn . '` = :tracker_uniqueid '
        . 'ORDER BY id DESC LIMIT 1';
    $stmt = $pdo->prepare($sql);
    if (!$stmt || !$stmt->execute([
        ':full_name' => $fullName,
        ':plate' => $plate,
        ':tracker_uniqueid' => $trackerUniqueId,
    ])) {
        throw new RuntimeException('Не удалось проверить фактическую запись назначения в drivers.');
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function reconcileDriverCommitState(array $dbConfig, string $fullName, string $plate, string $trackerUniqueId): array
{
    try {
        $verifyPdo = createIndependentDriverPdo($dbConfig);
        $driver = findExactDriverTrackerAssignment($verifyPdo, $fullName, $plate, $trackerUniqueId);
        $verifyPdo = null;

        if ($driver !== null) {
            return [
                'status' => 'present',
                'driver' => $driver,
                'message' => 'Независимое соединение подтвердило сохранённое назначение водителя.',
            ];
        }

        return [
            'status' => 'absent',
            'driver' => null,
            'message' => 'Независимое соединение подтвердило отсутствие назначения водителя.',
        ];
    } catch (Throwable $e) {
        return [
            'status' => 'unknown',
            'driver' => null,
            'message' => 'Не удалось независимо определить, был ли COMMIT фактически применён.',
            'error' => $e->getMessage(),
        ];
    }
}

function slitexRequest(string $method, string $url, string $token, ?array $payload = null): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return ['success' => false, 'status' => 0, 'error' => 'curl_init failed', 'body' => ''];
    }

    $headers = [
        'Accept: application/json',
        'X-API-Token: ' . $token,
    ];

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
    ];

    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    $options[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $options);

    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = (string)curl_error($ch);
    curl_close($ch);

    if ($resp === false || $err !== '') {
        return ['success' => false, 'status' => $status, 'error' => $err !== '' ? $err : 'Request failed', 'body' => is_string($resp) ? $resp : ''];
    }

    if ($status < 200 || $status >= 300) {
        return ['success' => false, 'status' => $status, 'error' => 'HTTP ' . $status, 'body' => (string)$resp];
    }

    return ['success' => true, 'status' => $status, 'error' => '', 'body' => (string)$resp];
}

function slitexTrackerIsFree(array $device): bool
{
    $uniqueid = (string)($device['uniqueid'] ?? '');
    $name = (string)($device['name'] ?? '');

    // SLITEX is the only source of truth for availability.
    // Fail closed: malformed uniqueid values are never considered free, and NAME
    // must be byte-for-byte identical to uniqueid. NAME is deliberately NOT trim()'ed.
    if ($uniqueid === '' || $uniqueid !== trim($uniqueid) || $name === '') {
        return false;
    }

    return hash_equals($uniqueid, $name);
}

function findSlitexDeviceByUniqueId(array $devices, string $uniqueid): ?array
{
    if ($uniqueid === '') {
        return null;
    }

    foreach ($devices as $device) {
        if (!is_array($device)) {
            continue;
        }
        if ((string)($device['uniqueid'] ?? '') === $uniqueid) {
            return $device;
        }
    }

    return null;
}

function decodeSlitexDevicesResponse(array $response): array
{
    if (!$response['success']) {
        throw new RuntimeException('Не удалось получить список устройств SLITEX: ' . ($response['error'] ?? 'неизвестная ошибка'));
    }

    $data = json_decode((string)($response['body'] ?? ''), true);
    if (!is_array($data)) {
        throw new RuntimeException('SLITEX вернул некорректный JSON по списку устройств.');
    }

    if (isset($data['data']) && is_array($data['data'])) {
        return $data['data'];
    }
    if (isset($data[0]) && is_array($data[0])) {
        return $data;
    }

    return [];
}

function acquireSlitexAssignmentLock(PDO $pdo, int $waitSeconds = 15): string
{
    // MySQL advisory lock is shared by all PHP workers/hosts using the same DB,
    // unlike a local /tmp flock. The admin SLITEX rename screen uses the same name.
    $lockName = 'maptest_slitex_tracker_assignment_v2';
    $stmt = $pdo->prepare('SELECT GET_LOCK(:lock_name, :wait_seconds)');
    if (!$stmt || !$stmt->execute([
        ':lock_name' => $lockName,
        ':wait_seconds' => max(1, $waitSeconds),
    ])) {
        throw new RuntimeException('Не удалось запросить общую блокировку назначения SLITEX-трекера.');
    }

    if ((int)$stmt->fetchColumn() !== 1) {
        throw new RuntimeException('Другой SLITEX-трекер сейчас изменяется. Повторите попытку через несколько секунд.');
    }

    return $lockName;
}

function releaseSlitexAssignmentLock(PDO $pdo, ?string $lockName): void
{
    if ($lockName === null || $lockName === '') {
        return;
    }

    try {
        $stmt = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        if ($stmt) {
            $stmt->execute([':lock_name' => $lockName]);
        }
    } catch (Throwable $ignored) {
        // MySQL releases advisory locks automatically when this DB connection closes.
    }
}

function assertSlitexAssignmentLockHeld(PDO $lockPdo, string $lockName): void
{
    if ($lockName === '') {
        throw new RuntimeException('SLITEX advisory lock name is empty.');
    }

    $stmt = $lockPdo->prepare('SELECT IS_USED_LOCK(:lock_name) AS owner_id, CONNECTION_ID() AS connection_id');
    if (!$stmt || !$stmt->execute([':lock_name' => $lockName])) {
        throw new RuntimeException('Не удалось подтвердить владение блокировкой SLITEX.');
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('Не удалось прочитать состояние блокировки SLITEX.');
    }

    $ownerId = isset($row['owner_id']) ? (string)$row['owner_id'] : '';
    $connectionId = isset($row['connection_id']) ? (string)$row['connection_id'] : '';
    if ($ownerId === '' || $connectionId === '' || !hash_equals($connectionId, $ownerId)) {
        throw new RuntimeException('Блокировка SLITEX потеряна. Автоматическое изменение трекера остановлено.');
    }
}

function reconcileFailedSlitexRenameRequest(array $cfg, string $uniqueid, string $expectedAssignedName, string $requestError, ?PDO $lockPdo = null, ?string $lockName = null): array
{
    // A failed/timeout PATCH is ambiguous: SLITEX may have applied the mutation
    // even when this PHP process never received the response. Re-read SLITEX and
    // decide only from the actual current NAME.
    $check = slitexRequest('GET', $cfg['base_url'] . '/api/external/devices', $cfg['token']);
    if (!$check['success']) {
        return [
            'status' => 'unknown',
            'success' => false,
            'safe_to_retry' => false,
            'message' => 'Результат переименования SLITEX неизвестен: PATCH завершился ошибкой, а контрольный GET недоступен.',
            'request_error' => $requestError,
        ];
    }

    try {
        $devices = decodeSlitexDevicesResponse($check);
    } catch (Throwable $e) {
        return [
            'status' => 'unknown',
            'success' => false,
            'safe_to_retry' => false,
            'message' => 'Результат переименования SLITEX неизвестен: контрольный GET вернул непроверяемые данные.',
            'request_error' => $requestError,
            'verify_error' => $e->getMessage(),
        ];
    }

    $device = findSlitexDeviceByUniqueId($devices, $uniqueid);
    if ($device === null) {
        return [
            'status' => 'unknown',
            'success' => false,
            'safe_to_retry' => false,
            'message' => 'Результат переименования SLITEX неизвестен: устройство не найдено после ошибки PATCH.',
            'request_error' => $requestError,
        ];
    }

    if (slitexTrackerIsFree($device)) {
        return [
            'status' => 'not_applied',
            'success' => true,
            'safe_to_retry' => true,
            'message' => 'Контрольный GET подтвердил, что PATCH не изменил трекер: NAME по-прежнему равен uniqueid.',
            'request_error' => $requestError,
        ];
    }

    $currentName = (string)($device['name'] ?? '');
    if (hash_equals($expectedAssignedName, $currentName)) {
        $compensation = compensateSlitexRename($cfg, $uniqueid, $expectedAssignedName, $lockPdo, $lockName);
        return [
            'status' => (bool)($compensation['success'] ?? false) ? 'applied_and_restored' : 'applied_restore_failed',
            'success' => (bool)($compensation['success'] ?? false),
            'safe_to_retry' => (bool)($compensation['success'] ?? false),
            'message' => (string)($compensation['message'] ?? 'Не удалось подтвердить возврат трекера.'),
            'request_error' => $requestError,
            'compensation' => $compensation,
        ];
    }

    return [
        'status' => 'conflict',
        'success' => false,
        'safe_to_retry' => false,
        'message' => 'После ошибки PATCH трекер имеет другое имя. Автоматически менять его нельзя; требуется ручная проверка.',
        'request_error' => $requestError,
        'current_name' => $currentName,
    ];
}

function compensateSlitexRename(array $cfg, string $uniqueid, string $expectedAssignedName, ?PDO $lockPdo = null, ?string $lockName = null): array
{
    $result = [
        'success' => false,
        'status' => 'not_restored',
        'message' => 'Не удалось подтвердить возврат трекера в свободное состояние.',
    ];

    // Never blindly overwrite SLITEX. Roll back only if the device still carries
    // exactly the name written by this request.
    $check = slitexRequest('GET', $cfg['base_url'] . '/api/external/devices', $cfg['token']);
    if (!$check['success']) {
        $result['status'] = 'check_failed';
        $result['message'] = 'Не удалось проверить текущее состояние трекера перед откатом.';
        return $result;
    }

    try {
        $devices = decodeSlitexDevicesResponse($check);
    } catch (Throwable $e) {
        $result['status'] = 'check_decode_failed';
        $result['message'] = 'SLITEX вернул некорректные данные при проверке отката.';
        return $result;
    }

    $device = findSlitexDeviceByUniqueId($devices, $uniqueid);
    if ($device === null) {
        $result['status'] = 'device_missing';
        $result['message'] = 'Трекер не найден в SLITEX при попытке отката.';
        return $result;
    }

    $currentName = (string)($device['name'] ?? '');
    if (slitexTrackerIsFree($device)) {
        return [
            'success' => true,
            'status' => 'already_free',
            'message' => 'Трекер уже находится в подтверждённо свободном состоянии.',
        ];
    }
    if (!hash_equals($expectedAssignedName, $currentName)) {
        $result['status'] = 'not_owned';
        $result['message'] = 'Трекер уже имеет другое имя; автоматический откат не выполнялся.';
        return $result;
    }

    // Compensation is destructive: perform it only while the dedicated
    // lock connection still demonstrably owns the shared SLITEX mutex.
    if (!($lockPdo instanceof PDO) || $lockName === null || $lockName === '') {
        $result['status'] = 'lock_missing';
        $result['message'] = 'Автоматический откат не выполнен: отсутствует подтверждённая блокировка SLITEX.';
        return $result;
    }
    try {
        assertSlitexAssignmentLockHeld($lockPdo, $lockName);
    } catch (Throwable $lockError) {
        $result['status'] = 'lock_lost';
        $result['message'] = 'Автоматический откат не выполнен: блокировка SLITEX потеряна.';
        $result['lock_error'] = $lockError->getMessage();
        return $result;
    }

    $patch = slitexRequest(
        'PATCH',
        $cfg['base_url'] . '/api/external/devices/' . rawurlencode($uniqueid) . '/name',
        $cfg['token'],
        ['name' => $uniqueid]
    );
    if (!$patch['success']) {
        // The rollback PATCH itself is also ambiguous on timeout/lost ACK. Inspect
        // actual SLITEX state before deciding whether recovery succeeded.
        $rollbackCheck = slitexRequest('GET', $cfg['base_url'] . '/api/external/devices', $cfg['token']);
        if (!$rollbackCheck['success']) {
            $result['status'] = 'patch_ambiguous';
            $result['message'] = 'Результат команды возврата неизвестен: PATCH завершился ошибкой, контрольный GET недоступен.';
            return $result;
        }
        try {
            $rollbackDevices = decodeSlitexDevicesResponse($rollbackCheck);
        } catch (Throwable $e) {
            $result['status'] = 'patch_ambiguous_unverifiable';
            $result['message'] = 'Результат команды возврата неизвестен: SLITEX не вернул проверяемое состояние.';
            return $result;
        }
        $rollbackDevice = findSlitexDeviceByUniqueId($rollbackDevices, $uniqueid);
        if ($rollbackDevice !== null && slitexTrackerIsFree($rollbackDevice)) {
            return [
                'success' => true,
                'status' => 'restored_after_ambiguous_patch',
                'message' => 'Ответ команды отката был неоднозначным, но контрольный GET подтвердил NAME = uniqueid.',
            ];
        }
        $actualName = $rollbackDevice !== null ? (string)($rollbackDevice['name'] ?? '') : '';
        $result['status'] = $rollbackDevice === null ? 'patch_failed_device_missing' : 'patch_failed_not_restored';
        $result['message'] = $rollbackDevice === null
            ? 'Команда возврата завершилась ошибкой, после неё трекер не найден.'
            : 'Команда возврата завершилась ошибкой; фактическое NAME не равно uniqueid. Автоматически повторять PATCH нельзя.';
        $result['current_name'] = $actualName;
        return $result;
    }

    // HTTP 2xx is not enough: verify the actual state in SLITEX.
    $verify = slitexRequest('GET', $cfg['base_url'] . '/api/external/devices', $cfg['token']);
    if (!$verify['success']) {
        $result['status'] = 'verify_failed';
        $result['message'] = 'Команда отката принята, но результат не удалось проверить.';
        return $result;
    }

    try {
        $verifyDevices = decodeSlitexDevicesResponse($verify);
    } catch (Throwable $e) {
        $result['status'] = 'verify_decode_failed';
        $result['message'] = 'Команда отката принята, но SLITEX не вернул проверяемое состояние.';
        return $result;
    }

    $verifyDevice = findSlitexDeviceByUniqueId($verifyDevices, $uniqueid);
    if ($verifyDevice === null || !slitexTrackerIsFree($verifyDevice)) {
        $result['status'] = 'verify_mismatch';
        $result['message'] = 'После отката SLITEX не подтвердил точное NAME = uniqueid.';
        return $result;
    }

    return [
        'success' => true,
        'status' => 'restored',
        'message' => 'Трекер подтверждённо возвращён в свободное состояние.',
    ];
}

function buildRetranText(string $trackerId): string
{
    $id = trim($trackerId) !== '' ? trim($trackerId) : '[ID ТРЕККЕРА УТОЧНИТЬ]';
    return 'Прошу установить ретрансляцию с треккера ' . $id
        . ' на Wialon 31.207.74.35:5039, так же предоставьте ID для ретрансляции если он не совпадает с ID треккера.'
        . "\nВам должны скинуть ID, его необходимо переслать в общую группу.";
}

function firstNonEmptyValue(array $sources, array $keys): string
{
    foreach ($sources as $source) {
        if (!is_array($source)) {
            continue;
        }
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            $value = trim((string)$source[$key]);
            if ($value !== '') {
                return $value;
            }
        }
    }
    return '';
}

function compactSourceKeysForDebug(array $sources, int $limit = 12): array
{
    $keys = [];
    foreach ($sources as $source) {
        if (!is_array($source)) {
            continue;
        }
        foreach ($source as $k => $_) {
            $ks = trim((string)$k);
            if ($ks !== '') {
                $keys[$ks] = true;
            }
            if (count($keys) >= $limit) {
                break 2;
            }
        }
    }
    return array_keys($keys);
}

function appendKeyValueRowsForFeo(array $rows, array &$target): void
{
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (isset($row['key']) && array_key_exists('value', $row)) {
            $target[] = [(string)$row['key'] => (string)$row['value']];
        }
        if (isset($row['name']) && array_key_exists('value', $row)) {
            $target[] = [(string)$row['name'] => (string)$row['value']];
        }
        if (isset($row['field']) && array_key_exists('value', $row)) {
            $target[] = [(string)$row['field'] => (string)$row['value']];
        }
    }
}

function resolveFeoParams(array $selectedTracker, ?array $renameResponse): array
{
    $sources = [];
    $appendTrackerSources = static function (array $trackerOrResponse, array &$target): void {
        $target[] = $trackerOrResponse;
        if (isset($trackerOrResponse['device']) && is_array($trackerOrResponse['device'])) {
            $target[] = $trackerOrResponse['device'];
        }
        if (isset($trackerOrResponse['data']) && is_array($trackerOrResponse['data'])) {
            $target[] = $trackerOrResponse['data'];
            if (isset($trackerOrResponse['data']['device']) && is_array($trackerOrResponse['data']['device'])) {
                $target[] = $trackerOrResponse['data']['device'];
            }
        }
        foreach (['payload', 'result', 'response', 'tracker'] as $nestedKey) {
            if (isset($trackerOrResponse[$nestedKey]) && is_array($trackerOrResponse[$nestedKey])) {
                $target[] = $trackerOrResponse[$nestedKey];
                if (isset($trackerOrResponse[$nestedKey]['device']) && is_array($trackerOrResponse[$nestedKey]['device'])) {
                    $target[] = $trackerOrResponse[$nestedKey]['device'];
                }
                if (isset($trackerOrResponse[$nestedKey]['data']) && is_array($trackerOrResponse[$nestedKey]['data'])) {
                    $target[] = $trackerOrResponse[$nestedKey]['data'];
                }
            }
        }
        if (isset($trackerOrResponse['admin_fields']) && is_array($trackerOrResponse['admin_fields'])) {
            $target[] = $trackerOrResponse['admin_fields'];
            appendKeyValueRowsForFeo($trackerOrResponse['admin_fields'], $target);
        } elseif (isset($trackerOrResponse['admin_fields']) && is_string($trackerOrResponse['admin_fields'])) {
            $decoded = json_decode($trackerOrResponse['admin_fields'], true);
            if (is_array($decoded)) {
                $target[] = $decoded;
                appendKeyValueRowsForFeo($decoded, $target);
            }
        }
        if (isset($trackerOrResponse['adminFields']) && is_array($trackerOrResponse['adminFields'])) {
            $target[] = $trackerOrResponse['adminFields'];
            appendKeyValueRowsForFeo($trackerOrResponse['adminFields'], $target);
        }
        if (isset($trackerOrResponse['admin_configs'][0]) && is_array($trackerOrResponse['admin_configs'][0])) {
            $target[] = $trackerOrResponse['admin_configs'][0];
        }
        if (isset($trackerOrResponse['admin_configs']) && is_array($trackerOrResponse['admin_configs'])) {
            appendKeyValueRowsForFeo($trackerOrResponse['admin_configs'], $target);
            foreach ($trackerOrResponse['admin_configs'] as $cfgRow) {
                if (!is_array($cfgRow)) {
                    continue;
                }
                $target[] = $cfgRow;
            }
        }
        if (isset($trackerOrResponse['adminConfigs'][0]) && is_array($trackerOrResponse['adminConfigs'][0])) {
            $target[] = $trackerOrResponse['adminConfigs'][0];
        }
        if (isset($trackerOrResponse['adminConfigs']) && is_array($trackerOrResponse['adminConfigs'])) {
            appendKeyValueRowsForFeo($trackerOrResponse['adminConfigs'], $target);
        }
    };

    if (isset($selectedTracker['device']) && is_array($selectedTracker['device'])) {
        $appendTrackerSources($selectedTracker['device'], $sources);
    }
    $appendTrackerSources($selectedTracker, $sources);
    if (is_array($renameResponse)) {
        if (isset($renameResponse['data']) && is_array($renameResponse['data'])) {
            $appendTrackerSources($renameResponse['data'], $sources);
        }
        if (isset($renameResponse['device']) && is_array($renameResponse['device'])) {
            $appendTrackerSources($renameResponse['device'], $sources);
        }
        $appendTrackerSources($renameResponse, $sources);
    }

    $outId = firstNonEmptyValue($sources, ['outID', 'outId', 'out_id', 'outid']);
    $outIp = firstNonEmptyValue($sources, ['outIP', 'outIp', 'out_ip', 'outip']);
    $outPort = firstNonEmptyValue($sources, ['outPort', 'out_port', 'outport']);
    $outProtocol = firstNonEmptyValue($sources, ['outProtocol', 'out_protocol', 'outprotocol']);

    $parts = [];
    if ($outId !== '') {
        $parts[] = $outId;
    }
    if ($outIp !== '' && $outPort !== '') {
        $parts[] = $outIp . ':' . $outPort;
    } elseif ($outIp !== '') {
        $parts[] = $outIp;
    } elseif ($outPort !== '') {
        $parts[] = $outPort;
    }
    if ($outProtocol !== '') {
        $parts[] = $outProtocol;
    }

    if (empty($parts) && function_exists('mapError')) {
        mapError('save_driver feo params missing', [
            'uniqueid' => (string)($selectedTracker['uniqueid'] ?? ($selectedTracker['device']['uniqueid'] ?? '')),
            'known_keys' => compactSourceKeysForDebug($sources),
            'has_rename_response' => is_array($renameResponse) ? 1 : 0,
            'selected_top_keys' => array_slice(array_keys($selectedTracker), 0, 20),
            'rename_top_keys' => is_array($renameResponse) ? array_slice(array_keys($renameResponse), 0, 20) : [],
            'candidate_out_values' => [
                'outID' => $outId,
                'outIP' => $outIp,
                'outPort' => $outPort,
                'outProtocol' => $outProtocol,
            ],
        ]);
    }
    if (empty($parts)) {
        $parts[] = 'н/д';
    }

    return [
        'outID' => $outId,
        'outIP' => $outIp,
        'outPort' => $outPort,
        'outProtocol' => $outProtocol,
        'line' => !empty($parts) ? implode(' ', $parts) : 'н/д',
    ];
}

function sessionThrottleKey(string $suffix): string
{
    return 'save_driver_throttle_' . $suffix;
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is not initialized');
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }

    $input = readDriverInput();
    $action = trim((string)($input['action'] ?? 'create_driver'));

    if ($action === 'check_free_trackers') {
        $cfg = getSlitexConfig();
        if ($cfg['token'] === '') {
            driverOut(['success' => false, 'message' => 'SLITEX API token не настроен на сервере.']);
        }

        $devicesResp = slitexRequest('GET', $cfg['base_url'] . '/api/external/devices', $cfg['token']);
        if (!$devicesResp['success']) {
            driverOut(['success' => false, 'message' => 'Не удалось получить список устройств SLITEX: ' . $devicesResp['error']]);
        }

        $devicesData = json_decode((string)$devicesResp['body'], true);
        if (!is_array($devicesData)) {
            driverOut(['success' => false, 'message' => 'SLITEX вернул некорректный JSON по списку устройств.']);
        }

        $devices = [];
        if (isset($devicesData['data']) && is_array($devicesData['data'])) {
            $devices = $devicesData['data'];
        } elseif (isset($devicesData[0]) && is_array($devicesData[0])) {
            $devices = $devicesData;
        }

        // Fetch admin-fields for enriched device data (FEO params)
        $adminFieldsResp = slitexRequest('GET', $cfg['base_url'] . '/api/external/devices/admin-fields', $cfg['token']);
        $adminFieldsData = [];
        if ($adminFieldsResp['success']) {
            $afDecoded = json_decode((string)$adminFieldsResp['body'], true);
            if (is_array($afDecoded)) {
                $adminFieldsData = isset($afDecoded['data']) ? $afDecoded['data'] : $afDecoded;
            }
        }
        $adminFieldsByUid = [];
        foreach ((array)$adminFieldsData as $afItem) {
            if (is_array($afItem) && !empty($afItem['uniqueid'])) {
                $adminFieldsByUid[trim((string)$afItem['uniqueid'])] = $afItem;
            }
        }
        foreach ($devices as &$dev) {
            if (!is_array($dev) || empty($dev['uniqueid'])) continue;
            $uid = trim((string)$dev['uniqueid']);
            if (isset($adminFieldsByUid[$uid]) && is_array($adminFieldsByUid[$uid])) {
                $fields = $adminFieldsByUid[$uid]['fields'] ?? $adminFieldsByUid[$uid];
                if (is_array($fields) && isset($fields[0]) && is_array($fields[0])) {
                    $fields = $fields[0];
                }
                if (is_array($fields)) {
                    $dev['admin_fields'] = $fields;
                    $dev['admin_configs'] = [$fields];
                }
            }
        }
        unset($dev);

        // SLITEX itself is the source of truth for availability.
        // Free tracker: NAME is exactly equal to uniqueid.
        $freeTrackers = [];
        foreach ((array)$devices as $device) {
            if (!is_array($device) || !slitexTrackerIsFree($device)) continue;
            $uniqueid = (string)($device['uniqueid'] ?? '');
            $freeTrackers[] = [
                'uniqueid' => $uniqueid,
                'name' => (string)($device['name'] ?? ''),
                'device' => $device,
            ];
        }

        $first = $freeTrackers[0]['uniqueid'] ?? null;
        driverOut([
            'success' => true,
            'free_count' => count($freeTrackers),
            'first_uniqueid' => $first,
            'free_trackers' => array_slice($freeTrackers, 0, 10),
            'message' => count($freeTrackers) > 0 ? 'Свободные трекеры найдены.' : 'Свободных трекеров нет.',
        ]);
    }

    if ($action === 'send_retranslation_max') {
        $fullName = normalizeDriverFullName((string)($input['full_name'] ?? ''));
        $plate = normalizeDriverPlate((string)($input['vehicle_make_plate'] ?? ''));
        $trackerId = trim((string)($input['tracker_id'] ?? ''));
        if ($trackerId === '') $trackerId = '[ID ТРЕККЕРА УТОЧНИТЬ]';
        $driverShort = ($plate !== '' ? $plate : 'ТС') . '(' . explode(' ', $fullName)[0] . ')';
        $message = implode("\n", [
            'Ретрансляция для нового водителя:',
            $driverShort,
            'ID трекера: ' . $trackerId,
            'Wialon: 31.207.74.35:5039',
            'Ожидается ID для ретрансляции, если он отличается от ID трекера.',
        ]);
        $notify = sendMaxNotify($message, 'markdown', [
            'event_key' => 'driver_retranslation_requested',
            'context' => [
                'driver' => $driverShort,
                'tracker_id' => $trackerId,
                'wialon' => '31.207.74.35:5039',
            ],
        ]);
        driverOut([
            'success' => true,
            'notify_success' => (bool)($notify['success'] ?? false),
            'notify_error' => $notify['error'] ?? null,
        ]);
    }

    $fullNameRaw = (string)($input['full_name'] ?? '');
    $vehicleNumberRaw = (string)($input['vehicle_make_plate'] ?? $input['vehicle_number'] ?? '');
    $gpsType = trim((string)($input['gps_connection_type'] ?? ''));

    $fullName = normalizeDriverFullName($fullNameRaw);
    $plate = normalizeDriverPlate($vehicleNumberRaw);

    if (!isValidDriverName($fullName)) {
        driverOut(['success' => false, 'message' => 'ФИО должно быть в формате: Фамилия Имя Отчество.']);
    }
    if (!isValidDriverPlate($plate)) {
        driverOut(['success' => false, 'message' => 'Госномер должен быть в формате А123АА45 или А123АА456.']);
    }
    if ($gpsType === 'new_tracker') {
        $gpsType = 'new_mobile_tracker';
    } elseif ($gpsType === '') {
        $gpsType = 'new_mobile_tracker';
    }
    if (!in_array($gpsType, ['new_mobile_tracker', 'retranslation'], true)) {
        $gpsType = 'new_mobile_tracker';
    }

    $throttleKey = sha1($fullName . '|' . $plate . '|' . $gpsType);
    $sessionKey = sessionThrottleKey($throttleKey);
    $lastTs = (int)($_SESSION[$sessionKey] ?? 0);
    if ($lastTs > 0 && (time() - $lastTs) < 4) {
        driverOut(['success' => false, 'message' => 'Подождите пару секунд и повторите попытку.']);
    }
    $_SESSION[$sessionKey] = time();

    $existing = findExistingDriver($pdo, $fullName, $plate);
    if ($existing) {
        $existing['id'] = (int)($existing['id'] ?? 0);
        $existing['label'] = driverLabel($existing);
        driverOut([
            'success' => true,
            'existing' => true,
            'message' => 'Такой водитель уже существует.',
            'driver' => $existing,
        ]);
    }

    $columns = loadDriversColumns($pdo);
    if (!isset($columns['full_name']) || !isset($columns['vehicle_make_plate'])) {
        throw new RuntimeException('drivers table does not contain full_name and vehicle_make_plate');
    }

    $surname = explode(' ', $fullName)[0] ?? '';
    $driverCompact = $plate . '(' . $surname . ')';

    // Ретрансляция идёт по тому же SLITEX-потоку, что и new_mobile_tracker (ниже).
    // Различие — только в event_key для MAX-уведомления.

    $cfg = getSlitexConfig();
    if ($cfg['token'] === '') {
        driverOut(['success' => false, 'message' => 'SLITEX API token не настроен на сервере.']);
    }

    // Advisory lock lives on its OWN DB connection. The business PDO below may
    // fail during INSERT/COMMIT; that must never implicitly release the SLITEX lock
    // while recovery is still deciding whether a tracker may be changed.
    $lockPdo = createIndependentDriverPdo(is_array($dbConfig ?? null) ? $dbConfig : []);
    $assignmentLock = acquireSlitexAssignmentLock($lockPdo);
    register_shutdown_function(static function () use (&$lockPdo, &$assignmentLock): void {
        if ($assignmentLock !== null && $lockPdo instanceof PDO) {
            releaseSlitexAssignmentLock($lockPdo, $assignmentLock);
            $assignmentLock = null;
        }
        $lockPdo = null;
    });

    // The optimistic duplicate check above happens before the shared SLITEX lock.
    // Re-check after acquiring the lock so two parallel requests cannot create the
    // same driver with two different trackers.
    $existingAfterLock = findExistingDriver($pdo, $fullName, $plate);
    if ($existingAfterLock) {
        $existingAfterLock['id'] = (int)($existingAfterLock['id'] ?? 0);
        $existingAfterLock['label'] = driverLabel($existingAfterLock);
        releaseSlitexAssignmentLock($lockPdo, $assignmentLock);
        $assignmentLock = null;
        $lockPdo = null;
        driverOut([
            'success' => true,
            'existing' => true,
            'message' => 'Такой водитель уже существует.',
            'driver' => $existingAfterLock,
        ]);
    }

    $devicesResp = slitexRequest('GET', $cfg['base_url'] . '/api/external/devices', $cfg['token']);
    if (!$devicesResp['success']) {
        driverOut(['success' => false, 'message' => 'Не удалось получить список устройств SLITEX: ' . $devicesResp['error']]);
    }

    $devicesData = json_decode((string)$devicesResp['body'], true);
    if (!is_array($devicesData)) {
        driverOut(['success' => false, 'message' => 'SLITEX вернул некорректный JSON по списку устройств.']);
    }

    $devices = [];
    if (isset($devicesData['data']) && is_array($devicesData['data'])) {
        $devices = $devicesData['data'];
    } elseif (isset($devicesData[0]) && is_array($devicesData[0])) {
        $devices = $devicesData;
    }

    // Fetch admin fields for enriched device data (FEO params)
    $adminFieldsData = [];
    $adminFieldsResp = slitexRequest('GET', $cfg['base_url'] . '/api/external/devices/admin-fields', $cfg['token']);
    if ($adminFieldsResp['success']) {
        $afDecoded = json_decode((string)$adminFieldsResp['body'], true);
        if (is_array($afDecoded)) {
            $adminFieldsData = isset($afDecoded['data']) ? $afDecoded['data'] : $afDecoded;
        }
    }
    $adminFieldsByUid = [];
    foreach ((array)$adminFieldsData as $afItem) {
        if (is_array($afItem) && !empty($afItem['uniqueid'])) {
            $adminFieldsByUid[trim((string)$afItem['uniqueid'])] = $afItem;
        }
    }

    // Merge admin_fields into devices (same pattern as TrackerService)
    foreach ($devices as &$dev) {
        if (!is_array($dev) || empty($dev['uniqueid'])) continue;
        $uid = trim((string)$dev['uniqueid']);
        if (isset($adminFieldsByUid[$uid]) && is_array($adminFieldsByUid[$uid])) {
            $fields = $adminFieldsByUid[$uid]['fields'] ?? $adminFieldsByUid[$uid];
            if (is_array($fields) && isset($fields[0]) && is_array($fields[0])) {
                $fields = $fields[0];
            }
            if (is_array($fields)) {
                $dev['admin_fields'] = $fields;
                $dev['admin_configs'] = [$fields];
            }
        }
    }
    unset($dev);

    // SLITEX itself is the source of truth for availability.
    // Any device whose NAME differs from uniqueid is considered occupied/reserved
    // and must never be reassigned automatically.
    $freeTrackers = [];
    foreach ($devices as $device) {
        if (!is_array($device) || !slitexTrackerIsFree($device)) continue;
        $uniqueid = (string)($device['uniqueid'] ?? '');
        $freeTrackers[] = [
            'uniqueid' => $uniqueid,
            'name' => (string)($device['name'] ?? ''),
            'device' => $device,
        ];
    }

    if (empty($freeTrackers)) {
        driverOut(['success' => false, 'message' => 'Свободных трекеров нет. Создание водителя невозможно.']);
    }

    $selected = $freeTrackers[0];

    // Перед самым назначением повторно проверяем состояние выбранного устройства
    // непосредственно в SLITEX. База drivers в определении свободности не участвует.
    $verifyResp = slitexRequest('GET', $cfg['base_url'] . '/api/external/devices', $cfg['token']);
    if (!$verifyResp['success']) {
        driverOut(['success' => false, 'message' => 'Не удалось повторно проверить выбранный трекер в SLITEX: ' . $verifyResp['error']]);
    }
    try {
        $verifyDevices = decodeSlitexDevicesResponse($verifyResp);
    } catch (Throwable $verifyError) {
        driverOut(['success' => false, 'message' => $verifyError->getMessage()]);
    }
    $verifiedSelected = findSlitexDeviceByUniqueId($verifyDevices, $selected['uniqueid']);
    if ($verifiedSelected === null) {
        driverOut(['success' => false, 'message' => 'Выбранный трекер больше не найден в SLITEX. Повторите создание водителя.']);
    }
    if (!slitexTrackerIsFree($verifiedSelected)) {
        driverOut([
            'success' => false,
            'message' => 'Выбранный трекер уже занят или зарезервирован в SLITEX. Повторите создание водителя.',
        ]);
    }

    $selected['name'] = (string)($verifiedSelected['name'] ?? '');
    $renamePayload = ['name' => $driverCompact];

    // Verify that the dedicated connection still owns the cross-process lock
    // immediately before mutating SLITEX.
    assertSlitexAssignmentLockHeld($lockPdo, $assignmentLock);

    $renameResp = slitexRequest(
        'PATCH',
        $cfg['base_url'] . '/api/external/devices/' . rawurlencode($selected['uniqueid']) . '/name',
        $cfg['token'],
        $renamePayload
    );
    if (!$renameResp['success']) {
        // A transport error/timeout does not prove that SLITEX ignored PATCH.
        // Reconcile against the current SLITEX state before deciding what to do.
        $renameFailureReconciliation = reconcileFailedSlitexRenameRequest(
            $cfg,
            $selected['uniqueid'],
            $driverCompact,
            (string)($renameResp['error'] ?? 'PATCH failed'),
            $lockPdo,
            $assignmentLock
        );

        if (function_exists('mapError')) {
            mapError('save_driver ambiguous SLITEX PATCH result', [
                'tracker_uniqueid' => $selected['uniqueid'],
                'tracker_name' => $driverCompact,
                'patch_error' => (string)($renameResp['error'] ?? ''),
                'recovery_status' => (string)($renameFailureReconciliation['status'] ?? 'unknown'),
                'recovery_message' => (string)($renameFailureReconciliation['message'] ?? ''),
            ]);
        }

        $safeToRetry = (bool)($renameFailureReconciliation['safe_to_retry'] ?? false);
        driverOut([
            'success' => false,
            'message' => $safeToRetry
                ? 'SLITEX не подтвердил исходный PATCH. Фактическое состояние проверено, трекер подтверждённо свободен. Повторите создание водителя.'
                : 'Не удалось однозначно завершить назначение трекера. Автоматически продолжать опасно; проверьте трекер ' . $selected['uniqueid'] . ' в SLITEX.',
            'tracker_uniqueid' => $selected['uniqueid'],
            'recovery_status' => (string)($renameFailureReconciliation['status'] ?? 'unknown'),
        ]);
    }
    $renameResponse = json_decode((string)$renameResp['body'], true);

    // После успешного PATCH любая ошибка до подтверждённого DB commit проходит
    // через fail-closed recovery. Неоднозначный COMMIT сверяется через отдельное
    // соединение только для диагностики; автоматическое освобождение после попытки COMMIT запрещено.
    $driver = null;
    $feoParams = null;
    $startedDriverTransaction = false;
    $commitAttempted = false;
    $commitConfirmed = false;
    try {
        $postRenameResp = slitexRequest('GET', $cfg['base_url'] . '/api/external/devices', $cfg['token']);
        if (!$postRenameResp['success']) {
            throw new RuntimeException('Трекер был переименован, но не удалось проверить результат в SLITEX.');
        }

        $postRenameDevices = decodeSlitexDevicesResponse($postRenameResp);
        $postRenameDevice = findSlitexDeviceByUniqueId($postRenameDevices, $selected['uniqueid']);
        if ($postRenameDevice === null) {
            throw new RuntimeException('SLITEX не вернул выбранный трекер после переименования.');
        }

        $postRenameName = (string)($postRenameDevice['name'] ?? '');
        if (!hash_equals($driverCompact, $postRenameName)) {
            throw new RuntimeException('SLITEX не подтвердил точное имя назначенного трекера.');
        }

        $feoParams = resolveFeoParams($selected, is_array($renameResponse) ? $renameResponse : null);

        if ($pdo->inTransaction()) {
            throw new RuntimeException('Невозможно безопасно создать водителя: обнаружена внешняя активная транзакция БД.');
        }
        if (!$pdo->beginTransaction()) {
            throw new RuntimeException('Не удалось начать транзакцию создания водителя.');
        }
        $startedDriverTransaction = true;

        $driver = insertDriver($pdo, $columns, $fullName, $plate, $gpsType, $selected['uniqueid']);

        // From this exact point a failed/exceptional commit result is ambiguous:
        // MySQL may already have persisted the row even if PHP did not receive ACK.
        $commitAttempted = true;
        if (!$pdo->commit()) {
            throw new RuntimeException('Не удалось подтвердить сохранение водителя в БД.');
        }
        $commitConfirmed = true;
        $startedDriverTransaction = false;
    } catch (Throwable $assignmentError) {
        $dbRollbackError = null;

        // A rollback failure must never prevent SLITEX recovery. Catch it separately.
        if ($startedDriverTransaction) {
            try {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (Throwable $rollbackError) {
                $dbRollbackError = $rollbackError;
            }
        }

        if ($commitAttempted && !$commitConfirmed) {
            // COMMIT outcome is unknown. A fresh connection is diagnostic only.
            // If it confirms PRESENT we can recover success; any other state remains
            // fail-closed and SLITEX is never automatically freed.
            $dbReconciliation = reconcileDriverCommitState(
                is_array($dbConfig ?? null) ? $dbConfig : [],
                $fullName,
                $plate,
                $selected['uniqueid']
            );
            $dbState = (string)($dbReconciliation['status'] ?? 'unknown');

            if ($dbState === 'present' && is_array($dbReconciliation['driver'] ?? null)) {
                // The commit actually succeeded. Keep SLITEX assigned and continue as
                // a recovered success; freeing it here would recreate the original bug.
                $driver = $dbReconciliation['driver'];
                $commitConfirmed = true;
                $startedDriverTransaction = false;

                if (function_exists('mapError')) {
                    mapError('save_driver commit acknowledgement lost but assignment confirmed', [
                        'error' => $assignmentError->getMessage(),
                        'tracker_uniqueid' => $selected['uniqueid'],
                        'tracker_name' => $driverCompact,
                        'db_reconciliation' => 'present',
                        'db_rollback_error' => $dbRollbackError ? $dbRollbackError->getMessage() : null,
                    ]);
                }
            } else {
                // Once COMMIT was attempted but ACK was not confirmed, NEVER make
                // the tracker free automatically. Even an immediate independent
                // SELECT that reports ABSENT cannot prove that the server-side
                // COMMIT will not become visible later. This is deliberately
                // fail-closed: keep NAME = driverCompact and require reconciliation.
                if (function_exists('mapError')) {
                    mapError('save_driver ambiguous DB commit kept fail-closed', [
                        'error' => $assignmentError->getMessage(),
                        'tracker_uniqueid' => $selected['uniqueid'],
                        'tracker_name' => $driverCompact,
                        'db_reconciliation' => $dbState,
                        'db_reconciliation_error' => (string)($dbReconciliation['error'] ?? ''),
                        'db_rollback_error' => $dbRollbackError ? $dbRollbackError->getMessage() : null,
                    ]);
                }

                driverOut([
                    'success' => false,
                    'message' => 'COMMIT был отправлен, но его результат не подтверждён. В целях безопасности трекер НЕ освобождён независимо от текущего результата проверки БД. Требуется сверка водителя и трекера ' . $selected['uniqueid'] . '.',
                    'tracker_uniqueid' => $selected['uniqueid'],
                    'db_reconciliation' => $dbState,
                    'recovery_status' => 'manual_reconciliation_required',
                ]);
            }
        } else {
            // The failure happened before COMMIT was attempted. Even if DB rollback
            // itself failed, it must not block SLITEX compensation.
            $compensation = compensateSlitexRename($cfg, $selected['uniqueid'], $driverCompact, $lockPdo, $assignmentLock);
            $rollbackSuccess = (bool)($compensation['success'] ?? false);

            if (function_exists('mapError')) {
                mapError('save_driver failed after SLITEX rename', [
                    'error' => $assignmentError->getMessage(),
                    'tracker_uniqueid' => $selected['uniqueid'],
                    'tracker_name' => $driverCompact,
                    'db_rollback_error' => $dbRollbackError ? $dbRollbackError->getMessage() : null,
                    'slitex_rollback_success' => $rollbackSuccess ? 1 : 0,
                    'slitex_rollback_status' => (string)($compensation['status'] ?? ''),
                    'slitex_rollback_message' => (string)($compensation['message'] ?? ''),
                ]);
            }

            driverOut([
                'success' => false,
                'message' => $rollbackSuccess
                    ? 'Назначение не завершено. Трекер подтверждённо находится в свободном состоянии; водитель не создан.'
                    : 'Назначение не завершено. Автоматически подтвердить возврат трекера не удалось. Проверьте трекер ' . $selected['uniqueid'] . ' в SLITEX.',
                'tracker_uniqueid' => $selected['uniqueid'],
                'recovery_status' => (string)($compensation['status'] ?? 'unknown'),
            ]);
        }
    }

    $driver['id'] = (int)($driver['id'] ?? 0);
    $driver['label'] = driverLabel($driver);

    // Назначение уже зафиксировано и в SLITEX, и в БД. Уведомление MAX не должно
    // удерживать блокировку и мешать созданию следующего водителя.
    releaseSlitexAssignmentLock($lockPdo, $assignmentLock);
    $assignmentLock = null;
    $lockPdo = null;

    $maxMessage = implode("\n", [
        'Настройки для нового водителя:',
        $driverCompact,
        'Для водителя: ' . $selected['uniqueid'],
        'Для ФЭО: ' . $feoParams['line'],
    ]);

    $notify = sendMaxNotify($maxMessage, 'markdown', [
        'event_key' => ($gpsType === 'retranslation' ? 'driver_retranslation_requested' : 'driver_new_tracker_configured'),
        'context' => [
            'driver' => $driverCompact,
            'tracker_uniqueid' => $selected['uniqueid'],
            'feo_params' => $feoParams['line'],
            'outID' => $feoParams['outID'],
            'outIP' => $feoParams['outIP'],
            'outPort' => $feoParams['outPort'],
            'outProtocol' => $feoParams['outProtocol'],
        ],
    ]);
    driverOut([
        'success' => true,
        'existing' => false,
        'message' => 'Трекер настроен.',
        'driver' => $driver,
        'gps_connection_type' => $gpsType,
        'tracker_uniqueid' => $selected['uniqueid'],
        'tracker_name' => $driverCompact,
        'free_trackers_remaining' => max(0, count($freeTrackers) - 1),
        'notify_success' => (bool)($notify['success'] ?? false),
        'notify_error' => $notify['error'] ?? null,
    ]);
} catch (Throwable $e) {
    if (function_exists('mapError')) {
        mapError('save_driver failed', ['error' => $e->getMessage()]);
    }
    driverOut([
        'success' => false,
        'message' => 'Ошибка создания водителя.',
    ]);
}
