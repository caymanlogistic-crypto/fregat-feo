<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/Support/max_notify.php';
require_once __DIR__ . '/Support/warehouse_movements.php';
header('Content-Type: application/json; charset=utf-8');
if (function_exists('opcache_invalidate')) {
    @opcache_invalidate(__FILE__, true);
}

const STATUS_PLANNED = 'planned_route';
const STATUS_FOUND = 'found';
const STATUS_STARTED = 'started';
const STATUS_COMPLETED = 'completed';
// ROUTE_TYPE_* constants are inherited from warehouse_movements.php (required on line 6)

function jsonOut(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function quoteIdent(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function normalizeIdsString($raw): array
{
    $parts = is_array($raw) ? $raw : explode(',', (string)$raw);
    $seen = [];
    $normalized = [];
    foreach ($parts as $part) {
        $id = trim((string)$part);
        if ($id === '' || !preg_match('/^\d+$/', $id)) {
            continue;
        }
        if (!isset($seen[$id])) {
            $seen[$id] = true;
            $normalized[] = $id;
        }
    }
    return $normalized;
}

function formatTons($tons): string
{
    return number_format((float)$tons, 3, '.', ' ');
}

function formatDateRu($value): string
{
    $v = trim((string)$value);
    if ($v === '') {
        return 'не указано';
    }
    $ts = strtotime($v);
    if ($ts === false) {
        return $v;
    }
    return date('d.m.Y H:i', $ts);
}

function sendMaxNotification($text, string $eventKey = '', array $context = []): array
{
    $payloadContext = ['message' => (string)$text];
    foreach ($context as $k => $v) {
        $payloadContext[$k] = $v;
    }
    return sendMaxNotify((string)$text, 'markdown', [
        'event_key' => $eventKey,
        'context' => $payloadContext,
    ]);
}

function getDriverLabelById(PDO $pdo, $driverId): string
{
    $driverId = (int)$driverId;
    if ($driverId <= 0) {
        return 'Водитель не указан';
    }
    try {
        $stmt = $pdo->prepare('SELECT full_name, vehicle_make_plate FROM drivers WHERE id = ? LIMIT 1');
        if (!$stmt || !$stmt->execute([$driverId])) {
            return 'Водитель не указан';
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return 'Водитель не указан';
        }
        $fullName = trim((string)($row['full_name'] ?? ''));
        $plateRaw = trim((string)($row['vehicle_make_plate'] ?? ''));
        if ($fullName === '' && $plateRaw === '') {
            return 'Водитель не указан';
        }
        // Extract gosnumber from vehicle_make_plate for compact label
        $plateOnly = '';
        if (preg_match('/([А-ЯЁA-Z]\d{3}\s*[А-ЯЁA-Z]{2}\s*\d{2,3})/u', $plateRaw, $m)) {
            $plateOnly = preg_replace('/\s+/', '', $m[1]);
        }
        $surname = trim((string)explode(' ', $fullName)[0]);
        if ($plateOnly !== '' && $surname !== '') {
            return $plateOnly . '(' . $surname . ')';
        }
        if ($plateOnly !== '') {
            return $plateOnly;
        }
        if ($surname !== '') {
            return $surname;
        }
        return trim(($fullName !== '' ? $fullName : 'Водитель') . ' / ' . ($plateRaw !== '' ? $plateRaw : 'без номера'));
    } catch (Throwable $e) {
        mapError('Driver label load failed', ['error' => $e->getMessage()]);
        return 'Водитель не указан';
    }
}

function getRouteMetrics(PDO $pdo, array $idList): array
{
    if (empty($idList)) {
        return ['count' => 0, 'sum_tons' => 0.0];
    }
    $placeholders = implode(',', array_fill(0, count($idList), '?'));
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(mass_netto), 0) AS sum_tons FROM feo WHERE zayavka_id IN ({$placeholders})");
        if (!$stmt || !$stmt->execute($idList)) {
            return ['count' => 0, 'sum_tons' => 0.0];
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'count' => isset($row['cnt']) ? (int)$row['cnt'] : 0,
            'sum_tons' => isset($row['sum_tons']) ? (float)$row['sum_tons'] : 0.0,
        ];
    } catch (Throwable $e) {
        mapError('Route metrics failed', ['error' => $e->getMessage()]);
        return ['count' => 0, 'sum_tons' => 0.0];
    }
}

function loadFlightSnapshot(PDO $pdo, $flightId): ?array
{
    try {
        $managerColumn = resolveFlightsManagerColumn($pdo) ?? 'assigned_manager_id';
        $sql = 'SELECT id, status, driver_id, zayavki_ids, planned_start_date_from, planned_start_date_to, actual_start_date, actual_end_date, comment, cost, unload_type, route_type, source_warehouse_id, destination_warehouse_id, '
            . quoteIdent($managerColumn) . ' AS assigned_manager_id FROM flights WHERE id = ? LIMIT 1';
        $stmt = $pdo->prepare($sql);
        if (!$stmt || !$stmt->execute([(int)$flightId])) {
            return null;
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $ids = normalizeIdsString($row['zayavki_ids'] ?? '');
        $metrics = getRouteMetrics($pdo, $ids);
        $row['_ids'] = $ids;
        $row['_count'] = $metrics['count'];
        $row['_sum_tons'] = $metrics['sum_tons'];
        $row['_driver_label'] = getDriverLabelById($pdo, $row['driver_id'] ?? 0);
        return $row;
    } catch (Throwable $e) {
        mapError('Load flight snapshot failed', ['flight_id' => $flightId, 'error' => $e->getMessage()]);
        return null;
    }
}

function assertTransitionAllowed(array $flight, string $targetStatus): ?string
{
    $current = (string)($flight['status'] ?? '');
    if ($current === STATUS_PLANNED && $targetStatus === STATUS_FOUND) return null;
    if ($current === STATUS_FOUND && $targetStatus === STATUS_STARTED) return null;
    if ($current === STATUS_FOUND && $targetStatus === STATUS_PLANNED) return null;
    if ($current === STATUS_STARTED && $targetStatus === STATUS_FOUND) return null;
    if ($current === STATUS_STARTED && $targetStatus === STATUS_COMPLETED) return null;
    return 'Недопустимый переход статуса';
}

function normalizeDateToDb($value): ?string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        throw new InvalidArgumentException('Некорректная дата');
    }
    return date('Y-m-d H:i:s', $ts);
}

function resolveUsersRoleColumn(PDO $pdo): ?string
{
    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM users');
        $columns = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        $map = [];
        foreach ((array)$columns as $column) {
            $map[(string)$column] = true;
        }
        foreach (['Роль', 'role', 'user_role', 'type'] as $candidate) {
            if (isset($map[$candidate])) {
                return $candidate;
            }
        }
    } catch (Throwable $e) {
        mapError('resolveUsersRoleColumn failed', ['error' => $e->getMessage()]);
    }
    return null;
}

function resolveValidLogistManagerId(PDO $pdo, $managerIdRaw): int
{
    $managerId = (int)$managerIdRaw;
    if ($managerId <= 0) {
        return 0;
    }

    $roleColumn = resolveUsersRoleColumn($pdo);
    if ($roleColumn === null) {
        return 0;
    }

    try {
        $sql = "SELECT id FROM users WHERE id = :id AND LOWER(TRIM(" . quoteIdent($roleColumn) . ")) = 'logist' LIMIT 1";
        $stmt = $pdo->prepare($sql);
        if (!$stmt || !$stmt->execute([':id' => $managerId])) {
            return 0;
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? (int)($row['id'] ?? 0) : 0;
    } catch (Throwable $e) {
        mapError('resolveValidLogistManagerId failed', ['error' => $e->getMessage(), 'manager_id' => $managerId]);
        return 0;
    }
}

function resolveFlightsManagerColumn(PDO $pdo): ?string
{
    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM flights');
        $columns = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        $map = [];
        foreach ((array)$columns as $column) {
            $map[(string)$column] = true;
        }
        foreach (['assigned_manager_id', 'manager_id'] as $candidate) {
            if (isset($map[$candidate])) {
                return $candidate;
            }
        }
    } catch (Throwable $e) {
        mapError('resolveFlightsManagerColumn failed', ['error' => $e->getMessage()]);
    }
    return null;
}

function validateRouteData(
    PDO $pdo,
    array $data,
    bool $requireFullForFoundTransition,
    bool $requireTitle = false,
    ?bool $requireRequests = null,
    ?bool $requireWarehouses = null
): array
{
    if ($requireRequests === null) {
        $requireRequests = $requireFullForFoundTransition;
    }
    if ($requireWarehouses === null) {
        $requireWarehouses = $requireFullForFoundTransition;
    }
    if ($requireTitle) {
        $title = trim((string)($data['comment'] ?? $data['name'] ?? ''));
        if ($title === '') {
            return [false, 'Укажите комментарий / заголовок рейса', [], ['comment' => 'Обязательный комментарий / заголовок']];
        }
    }

    $ids = normalizeIdsString($data['zayavki_ids'] ?? '');
    if ($requireRequests && empty($ids)) {
        return [false, 'Список заявок пустой', [], ['zayavki_ids' => 'Список заявок пустой']];
    }
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmtIds = $pdo->prepare("SELECT zayavka_id FROM feo WHERE zayavka_id IN ({$placeholders})");
        if (!$stmtIds || !$stmtIds->execute($ids)) {
            return [false, 'Не удалось проверить заявки', [], ['zayavki_ids' => 'Не удалось проверить заявки']];
        }
        $existsRows = $stmtIds->fetchAll(PDO::FETCH_COLUMN);
        $existsMap = [];
        if (is_array($existsRows)) {
            foreach ($existsRows as $v) {
                $existsMap[(string)$v] = true;
            }
        }
        foreach ($ids as $id) {
            if (!isset($existsMap[$id])) {
                return [false, "Заявка {$id} не существует", [], ['zayavki_ids' => "Заявка {$id} не существует"]];
            }
        }
    }

    $driverId = (int)($data['driver_id'] ?? 0);
    $plannedFromRaw = trim((string)($data['planned_start_date_from'] ?? ''));
    $plannedToRaw = trim((string)($data['planned_start_date_to'] ?? ''));
    $costRaw = $data['cost'] ?? null;
    $routeType = normalizeRouteType($data['route_type'] ?? '', $data['unload_type'] ?? 'OO');
    $unloadType = resolveUnloadTypeByRouteType($routeType);
    $sourceWarehouseId = normalizeWarehouseId($pdo, $data['source_warehouse_id'] ?? null);
    $destinationWarehouseId = normalizeWarehouseId($pdo, $data['destination_warehouse_id'] ?? null);

    if ($requireWarehouses && $routeType === ROUTE_TYPE_GENERATOR_TO_WAREHOUSE && $destinationWarehouseId === null) {
        return [false, 'Укажите склад назначения', [], ['destination_warehouse_id' => 'Укажите склад назначения']];
    }
    if ($requireWarehouses && $routeType === ROUTE_TYPE_WAREHOUSE_TO_WAREHOUSE) {
        if ($sourceWarehouseId === null) {
            return [false, 'Укажите склад отправления', [], ['source_warehouse_id' => 'Укажите склад отправления']];
        }
        if ($destinationWarehouseId === null) {
            return [false, 'Укажите склад назначения', [], ['destination_warehouse_id' => 'Укажите склад назначения']];
        }
        if ($sourceWarehouseId === $destinationWarehouseId) {
            return [false, 'Склад отправления и назначения не могут совпадать', [], [
                'source_warehouse_id' => 'Склад отправления и назначения не могут совпадать',
                'destination_warehouse_id' => 'Склад отправления и назначения не могут совпадать'
            ]];
        }
    }
    if ($requireWarehouses && $routeType === ROUTE_TYPE_WAREHOUSE_TO_UTILIZER && $sourceWarehouseId === null) {
        return [false, 'Укажите склад отправления', [], ['source_warehouse_id' => 'Укажите склад отправления']];
    }

    $plannedFrom = null;
    $plannedTo = null;
    if ($plannedFromRaw !== '') {
        $ts = strtotime($plannedFromRaw);
        if ($ts === false) return [false, 'Некорректная дата "С"', [], ['planned_start_date_from' => 'Некорректная дата "С"']];
        $plannedFrom = date('Y-m-d H:i:s', $ts);
    }
    if ($plannedToRaw !== '') {
        $ts = strtotime($plannedToRaw);
        if ($ts === false) return [false, 'Некорректная дата "По"', [], ['planned_start_date_to' => 'Некорректная дата "По"']];
        $plannedTo = date('Y-m-d H:i:s', $ts);
    }
    if ($plannedFrom !== null && $plannedTo !== null && strtotime($plannedFrom) > strtotime($plannedTo)) {
        return [false, 'Дата "С" не может быть позже "По"', [], [
            'planned_start_date_from' => 'Дата "С" не может быть позже "По"',
            'planned_start_date_to' => 'Дата "С" не может быть позже "По"'
        ]];
    }

    $cost = null;
    if ($costRaw !== null && $costRaw !== '') {
        if (!is_numeric($costRaw)) return [false, 'Стоимость должна быть числом', [], ['cost' => 'Стоимость должна быть числом']];
        $cost = (float)$costRaw;
    }

    $driverOk = false;
    if ($driverId > 0) {
        $stmtDriver = $pdo->prepare('SELECT id FROM drivers WHERE id = ? LIMIT 1');
        if ($stmtDriver && $stmtDriver->execute([$driverId])) {
            $driverOk = (bool)$stmtDriver->fetch(PDO::FETCH_ASSOC);
        }
    }
    if (!$driverOk && $requireFullForFoundTransition) return [false, 'Не выбран корректный водитель', [], ['driver_id' => 'Не выбран корректный водитель']];
    if ($requireFullForFoundTransition && $plannedFrom === null) {
        return [false, 'Не указана плановая дата начала рейса', [], ['planned_start_date_from' => 'Обязательная дата']];
    }
    if ($requireFullForFoundTransition && $cost === null) return [false, 'Не указана стоимость рейса', [], ['cost' => 'Обязательная стоимость']];

    return [true, '', [
        'zayavki_ids_canonical' => implode(',', $ids),
        'zayavki_count' => count($ids),
        'driver_id' => $driverId > 0 ? $driverId : null,
        'planned_start_date_from' => $plannedFrom,
        'planned_start_date_to' => $plannedTo,
        'cost' => $cost,
        'unload_type' => $unloadType,
        'route_type' => $routeType,
        'source_warehouse_id' => $sourceWarehouseId,
        'destination_warehouse_id' => $destinationWarehouseId,
    ], []];
}

function formatDateShortRu($value): string
{
    $v = trim((string)$value);
    if ($v === '') return 'не указано';
    $ts = strtotime($v);
    return $ts === false ? $v : date('d.m', $ts);
}

function formatDateRangeShortRu($from, $to): string
{
    $fromShort = formatDateShortRu($from);
    $toShort = formatDateShortRu($to);
    if ($fromShort === 'не указано' && $toShort === 'не указано') return 'не указано';
    if ($fromShort === 'не указано') return $toShort;
    if ($toShort === 'не указано') return $fromShort;
    if ($fromShort === $toShort) return $fromShort;
    return $fromShort . '–' . $toShort;
}

function formatMoneyRu($value): string
{
    if ($value === null || $value === '') return '0 ₽';
    $num = (float)$value;
    return number_format($num, 0, '.', ' ') . ' ₽';
}

function formatKgFromTons($tons): string
{
    return number_format((int)round((float)$tons * 1000), 0, '.', ' ') . ' кг';
}

function compactDriverLabel(string $label): string
{
    $v = trim($label);
    if ($v === '') return 'Водитель не указан';
    // Allow optional spaces in plate: Е007РС 29 → Е007РС29
    if (preg_match('/([А-ЯЁA-Z]\d{3}\s*[А-ЯЁA-Z]{2}\s*\d{2,3})/u', $v, $mPlate)) {
        $plate = preg_replace('/\s+/', '', $mPlate[1]);
        $surname = '';
        // Extract surname from the LAST bracket pair — the first one may contain vehicle model (Hyundai HD120),
        // while the last one (from drivers.full_name) contains the actual driver name.
        if (preg_match_all('/\(([^)]+)\)/u', $v, $mAllNames)) {
            $lastPos = count($mAllNames[1]) - 1;
            if ($lastPos >= 0) {
                $surname = trim((string)explode(' ', trim($mAllNames[1][$lastPos]))[0]);
            }
        }
        // Format: Быков Андрей Борисович / SCANIA Е007РС 29
        if ($surname === '') {
            $slashParts = explode('/', $v, 2);
            if (count($slashParts) >= 2) {
                $namePart = trim($slashParts[0]);
                $surname = trim((string)explode(' ', $namePart)[0]);
            }
        }
        if ($surname === '' && preg_match('/([А-ЯЁA-Z][а-яёa-z]+)/u', $v, $mWord)) {
            $candidate = trim((string)$mWord[1]);
            if ($candidate !== '') {
                $surname = $candidate;
            }
        }
        if ($surname !== '') return $plate . '(' . $surname . ')';
        return $plate;
    }
    return $v;
}
function getManagerDisplayNameById(PDO $pdo, $managerId): string
{
    $id = (int)$managerId;
    if ($id <= 0) return 'Менеджер не указан';
    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM users');
        $columns = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        $map = [];
        foreach ((array)$columns as $column) $map[(string)$column] = true;

        $parts = [];
        if (isset($map['Фамилия'])) $parts[] = "COALESCE(u.`Фамилия`, '')";
        if (isset($map['Имя'])) $parts[] = "COALESCE(u.`Имя`, '')";
        if (isset($map['full_name'])) $parts[] = "COALESCE(u.full_name, '')";
        if (isset($map['name'])) $parts[] = "COALESCE(u.name, '')";
        if (isset($map['username'])) $parts[] = "COALESCE(u.username, '')";
        if (isset($map['login'])) $parts[] = "COALESCE(u.login, '')";
        if (empty($parts)) return 'Менеджер #' . $id;

        $sql = 'SELECT ' . implode(", ' ', ", $parts) . ' AS manager_name FROM users u WHERE u.id = :id LIMIT 1';
        $q = $pdo->prepare($sql);
        if (!$q || !$q->execute([':id' => $id])) return 'Менеджер #' . $id;
        $row = $q->fetch(PDO::FETCH_ASSOC);
        $name = trim(preg_replace('/\s+/u', ' ', (string)($row['manager_name'] ?? '')));
        return $name !== '' ? $name : 'Менеджер #' . $id;
    } catch (Throwable $e) {
        mapError('Manager name load failed', ['manager_id' => $id, 'error' => $e->getMessage()]);
        return 'Менеджер #' . $id;
    }
}

function getWarehousesMeta(PDO $pdo): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }
    $cache = ['exists' => false, 'active_column' => null, 'name_column' => null];
    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM warehouses');
        $columns = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        if (!is_array($columns) || empty($columns)) {
            return $cache;
        }
        $map = [];
        foreach ($columns as $column) {
            $map[(string)$column] = true;
        }
        $cache['exists'] = true;
        foreach (['is_active', 'active', 'enabled', 'status'] as $candidate) {
            if (isset($map[$candidate])) {
                $cache['active_column'] = $candidate;
                break;
            }
        }
        foreach (['name', 'title', 'warehouse_name', 'label'] as $candidate) {
            if (isset($map[$candidate])) {
                $cache['name_column'] = $candidate;
                break;
            }
        }
    } catch (Throwable $e) {
        mapError('getWarehousesMeta failed', ['error' => $e->getMessage()]);
    }
    return $cache;
}

function normalizeRouteType($routeTypeRaw, $unloadTypeRaw = 'OO'): string
{
    $routeType = strtolower(trim((string)$routeTypeRaw));
    $allowed = [
        ROUTE_TYPE_GENERATOR_TO_UTILIZER,
        ROUTE_TYPE_GENERATOR_TO_WAREHOUSE,
        ROUTE_TYPE_WAREHOUSE_TO_WAREHOUSE,
        ROUTE_TYPE_WAREHOUSE_TO_UTILIZER,
    ];
    if (in_array($routeType, $allowed, true)) {
        return $routeType;
    }
    $unload = strtoupper(trim((string)$unloadTypeRaw));
    if ($unload === 'SKLAD') {
        return ROUTE_TYPE_GENERATOR_TO_WAREHOUSE;
    }
    return ROUTE_TYPE_GENERATOR_TO_UTILIZER;
}

function resolveUnloadTypeByRouteType(string $routeType): string
{
    return ($routeType === ROUTE_TYPE_GENERATOR_TO_UTILIZER || $routeType === ROUTE_TYPE_WAREHOUSE_TO_UTILIZER) ? 'OO' : 'SKLAD';
}

function normalizeWarehouseId(PDO $pdo, $idRaw): ?int
{
    $id = (int)$idRaw;
    if ($id <= 0) {
        return null;
    }
    $meta = getWarehousesMeta($pdo);
    if (empty($meta['exists'])) {
        return null;
    }
    try {
        $sql = 'SELECT id FROM warehouses WHERE id = :id';
        if (!empty($meta['active_column'])) {
            $activeCol = (string)$meta['active_column'];
            if ($activeCol === 'status') {
                $sql .= " AND LOWER(TRIM(" . quoteIdent($activeCol) . ")) IN ('1', 'active', 'enabled')";
            } else {
                $sql .= ' AND ' . quoteIdent($activeCol) . ' = 1';
            }
        }
        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        if (!$stmt || !$stmt->execute([':id' => $id])) {
            return null;
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? ((int)($row['id'] ?? 0) ?: null) : null;
    } catch (Throwable $e) {
        mapError('normalizeWarehouseId failed', ['id' => $id, 'error' => $e->getMessage()]);
        return null;
    }
}

function getWarehouseNameById(PDO $pdo, $idRaw): string
{
    $id = (int)$idRaw;
    if ($id <= 0) {
        return '';
    }
    $meta = getWarehousesMeta($pdo);
    if (empty($meta['exists'])) {
        return '';
    }
    $nameColumn = (string)($meta['name_column'] ?? '');
    if ($nameColumn === '') {
        return '';
    }
    try {
        $sql = 'SELECT ' . quoteIdent($nameColumn) . ' AS warehouse_name FROM warehouses WHERE id = :id LIMIT 1';
        $stmt = $pdo->prepare($sql);
        if (!$stmt || !$stmt->execute([':id' => $id])) {
            return '';
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return trim((string)($row['warehouse_name'] ?? ''));
    } catch (Throwable $e) {
        mapError('getWarehouseNameById failed', ['id' => $id, 'error' => $e->getMessage()]);
        return '';
    }
}


function buildRouteTitle(array $flight, int $flightId): string
{
    $title = trim((string)($flight['comment'] ?? ''));
    return $title !== '' ? $title : ('Рейс #' . $flightId);
}

function buildCompactMetaLine(array $flight): string
{
    $count = (int)($flight['_count'] ?? 0);
    $kg = formatKgFromTons((float)($flight['_sum_tons'] ?? 0));
    return "{$count} заяв. • {$kg}";
}

function buildUnloadLine(array $flight): string
{
    return '';
}

function buildRouteTypeLine(PDO $pdo, array $flight): string
{
    $routeType = normalizeRouteType($flight['route_type'] ?? '', $flight['unload_type'] ?? 'OO');
    if ($routeType === ROUTE_TYPE_GENERATOR_TO_UTILIZER) {
        return '';
    }
    if ($routeType === ROUTE_TYPE_GENERATOR_TO_WAREHOUSE) {
        $name = getWarehouseNameById($pdo, $flight['destination_warehouse_id'] ?? 0);
        return $name !== '' ? ('Выгрузка на склад: ' . $name) : 'Выгрузка на склад';
    }
    if ($routeType === ROUTE_TYPE_WAREHOUSE_TO_WAREHOUSE) {
        $from = getWarehouseNameById($pdo, $flight['source_warehouse_id'] ?? 0);
        $to = getWarehouseNameById($pdo, $flight['destination_warehouse_id'] ?? 0);
        if ($from !== '' && $to !== '') {
            return 'Перемещение: ' . $from . ' → ' . $to;
        }
        return 'Перемещение между складами';
    }
    $from = getWarehouseNameById($pdo, $flight['source_warehouse_id'] ?? 0);
    return $from !== '' ? ('Вывоз со склада: ' . $from) : 'Вывоз со склада';
}

function buildCompactFlightContext(PDO $pdo, array $flight, int $flightId): array
{
    $title = buildRouteTitle($flight, $flightId);
    $manager = getManagerDisplayNameById($pdo, $flight['assigned_manager_id'] ?? 0);
    $driver = compactDriverLabel((string)($flight['_driver_label'] ?? ''));
    $meta = buildCompactMetaLine($flight);
    return [$title, $manager, $driver, $meta];
}

function splitIds(string $raw): array
{
    return normalizeIdsString($raw);
}

function formatIdsList(array $ids): string
{
    if (empty($ids)) {
        return '';
    }
    return implode(',', array_values($ids));
}

function buildAddedRemovedIds(array $beforeIds, array $afterIds): array
{
    $beforeMap = array_fill_keys($beforeIds, true);
    $afterMap = array_fill_keys($afterIds, true);
    $removed = [];
    $added = [];
    foreach ($beforeIds as $id) {
        if (!isset($afterMap[$id])) {
            $removed[] = $id;
        }
    }
    foreach ($afterIds as $id) {
        if (!isset($beforeMap[$id])) {
            $added[] = $id;
        }
    }
    return [$removed, $added];
}

function buildRouteTypeLabel(array $flight): string
{
    $routeType = normalizeRouteType($flight['route_type'] ?? '', $flight['unload_type'] ?? 'OO');
    if ($routeType === ROUTE_TYPE_WAREHOUSE_TO_WAREHOUSE) {
        return 'Склад → Склад';
    }
    if ($routeType === ROUTE_TYPE_GENERATOR_TO_WAREHOUSE) {
        return 'Выгрузка на склад';
    }
    if ($routeType === ROUTE_TYPE_WAREHOUSE_TO_UTILIZER) {
        return 'Склад → Утилизатор';
    }
    return 'Обычная выгрузка / Утилизатор';
}

function buildRouteDiffContext(PDO $pdo, array $before, array $after, int $flightId): array
{
    $beforeIds = splitIds((string)($before['zayavki_ids'] ?? ''));
    $afterIds = splitIds((string)($after['zayavki_ids'] ?? ''));
    [$removed, $added] = buildAddedRemovedIds($beforeIds, $afterIds);
    $beforeTitle = buildRouteTitle($before, $flightId);
    $afterTitle = buildRouteTitle($after, $flightId);
    $changedFields = [];
    if ((int)($before['driver_id'] ?? 0) !== (int)($after['driver_id'] ?? 0)) $changedFields[] = 'driver';
    if ((string)($before['planned_start_date_from'] ?? '') !== (string)($after['planned_start_date_from'] ?? '')) $changedFields[] = 'date_from';
    if ((string)($before['planned_start_date_to'] ?? '') !== (string)($after['planned_start_date_to'] ?? '')) $changedFields[] = 'date_to';
    if ((string)($before['cost'] ?? '') !== (string)($after['cost'] ?? '')) $changedFields[] = 'cost';
    if ((string)($before['zayavki_ids'] ?? '') !== (string)($after['zayavki_ids'] ?? '')) $changedFields[] = 'requests';
    if ((string)($before['route_type'] ?? '') !== (string)($after['route_type'] ?? '')) $changedFields[] = 'route_type';
    if ((string)($before['source_warehouse_id'] ?? '') !== (string)($after['source_warehouse_id'] ?? '')) $changedFields[] = 'source_warehouse';
    if ((string)($before['destination_warehouse_id'] ?? '') !== (string)($after['destination_warehouse_id'] ?? '')) $changedFields[] = 'destination_warehouse';
    if (trim((string)($before['comment'] ?? '')) !== trim((string)($after['comment'] ?? ''))) $changedFields[] = 'route_title';

    return [
        'route_id' => (string)$flightId,
        'route_title' => $afterTitle !== '' ? $afterTitle : $beforeTitle,
        'manager' => getManagerDisplayNameById($pdo, $after['assigned_manager_id'] ?? ($before['assigned_manager_id'] ?? 0)),
        'status_from' => (string)($before['status'] ?? ''),
        'status_to' => (string)($after['status'] ?? ''),
        'planned_range' => formatDateRangeShortRu((string)($after['planned_start_date_from'] ?? ''), (string)($after['planned_start_date_to'] ?? '')),
        'actual_range' => formatDateRangeShortRu((string)($after['actual_start_date'] ?? ''), (string)($after['actual_end_date'] ?? '')),
        'route_type_line' => buildRouteTypeLine($pdo, $after),
        'meta_line' => buildCompactMetaLine($after),
        'driver_old' => compactDriverLabel((string)($before['_driver_label'] ?? '')),
        'driver_new' => compactDriverLabel((string)($after['_driver_label'] ?? '')),
        'date_from_old' => formatDateShortRu((string)($before['planned_start_date_from'] ?? '')),
        'date_from_new' => formatDateShortRu((string)($after['planned_start_date_from'] ?? '')),
        'date_to_old' => formatDateShortRu((string)($before['planned_start_date_to'] ?? '')),
        'date_to_new' => formatDateShortRu((string)($after['planned_start_date_to'] ?? '')),
        'cost_old' => (string)($before['cost'] ?? ''),
        'cost_new' => (string)($after['cost'] ?? ''),
        'requests_old' => implode(',', $beforeIds),
        'requests_new' => implode(',', $afterIds),
        'requests_added' => implode(',', $added),
        'requests_removed' => implode(',', $removed),
        'route_type_old' => (string)($before['route_type'] ?? ''),
        'route_type_new' => (string)($after['route_type'] ?? ''),
        'source_warehouse_old' => (string)($before['source_warehouse_id'] ?? ''),
        'source_warehouse_new' => (string)($after['source_warehouse_id'] ?? ''),
        'destination_warehouse_old' => (string)($before['destination_warehouse_id'] ?? ''),
        'destination_warehouse_new' => (string)($after['destination_warehouse_id'] ?? ''),
        'changed_fields' => implode(', ', $changedFields),
    ];
}

function buildRouteEventContext(PDO $pdo, array $route, int $flightId, array $extra = []): array
{
    $manager = getManagerDisplayNameById($pdo, $route['assigned_manager_id'] ?? 0);
    $driver = compactDriverLabel((string)($route['_driver_label'] ?? ''));
    $routeType = normalizeRouteType($route['route_type'] ?? '', $route['unload_type'] ?? 'OO');
    $routeTypeLine = 'Тип рейса: ' . buildRouteTypeLabel($route);
    $unloadTarget = buildRouteTypeLine($pdo, $route);
    $ctx = [
        'route_id' => (string)$flightId,
        'route_title' => buildRouteTitle($route, $flightId),
        'planned_range' => formatDateRangeShortRu((string)($route['planned_start_date_from'] ?? ''), (string)($route['planned_start_date_to'] ?? '')),
        'actual_range' => formatDateRangeShortRu((string)($route['actual_start_date'] ?? ''), (string)($route['actual_end_date'] ?? '')),
        'driver' => $driver !== '' ? $driver : 'не указан',
        'manager' => $manager !== '' ? $manager : 'не назначен',
        'responsible' => $manager !== '' ? $manager : 'не назначен',
        'route_type' => (string)$routeType,
        'route_type_line' => $routeTypeLine,
        'unload_target_line' => $unloadTarget,
        'meta_line' => (trim(buildCompactMetaLine($route)) !== '' ? trim(buildCompactMetaLine($route)) : 'Параметры рейса: не заданы'),
        'requests_count' => (string)((int)($route['_count'] ?? 0)),
        'weight' => formatKgFromTons((float)($route['_sum_tons'] ?? 0)),
        'cost' => formatMoneyRu($route['cost'] ?? null),
        'source_warehouse' => getWarehouseNameById($pdo, $route['source_warehouse_id'] ?? 0),
        'destination_warehouse' => getWarehouseNameById($pdo, $route['destination_warehouse_id'] ?? 0),
    ];
    $src = trim((string)$ctx['source_warehouse']);
    $dst = trim((string)$ctx['destination_warehouse']);
    $ctx['warehouse_line'] = ($src !== '' || $dst !== '')
        ? ('Склады: ' . ($src !== '' ? $src : 'не выбран') . ' → ' . ($dst !== '' ? $dst : 'не выбран'))
        : 'Склады: не выбраны';
    foreach ($extra as $k => $v) {
        $ctx[$k] = $v;
    }
    return $ctx;
}

function buildPlannedDateRangeUpdateMessage(PDO $pdo, array $before, array $after, int $flightId): string
{
    $beforeFrom = trim((string)($before['planned_start_date_from'] ?? ''));
    $beforeTo = trim((string)($before['planned_start_date_to'] ?? ''));
    $afterFrom = trim((string)($after['planned_start_date_from'] ?? ''));
    $afterTo = trim((string)($after['planned_start_date_to'] ?? ''));

    $hadBefore = ($beforeFrom !== '' || $beforeTo !== '');
    $hasAfter = ($afterFrom !== '' || $afterTo !== '');
    if (!$hasAfter) return '';
    if ($beforeFrom === $afterFrom && $beforeTo === $afterTo) return '';

    $title = buildRouteTitle($after, $flightId);
    $driver = compactDriverLabel((string)($after['_driver_label'] ?? ''));
    $manager = getManagerDisplayNameById($pdo, $after['assigned_manager_id'] ?? 0);
    $meta = buildCompactMetaLine($after);
    $unloadLine = buildUnloadLine($after);
    $routeTypeLine = buildRouteTypeLine($pdo, $after);
    $rangeAfter = formatDateRangeShortRu($afterFrom, $afterTo);

    if (!$hadBefore) {
        $lines = [
            'В плановый рейс добавлена предварительная дата начала вывоза',
            "#{$flightId} {$title}",
            "Начало вывоза: {$rangeAfter}",
        ];
        if ($unloadLine !== '') {
            $lines[] = $unloadLine;
        }
        if ($routeTypeLine !== '') {
            $lines[] = $routeTypeLine;
        }
        $lines[] = $meta;
        $lines[] = $driver;
        $lines[] = "Рейс закреплен: {$manager}";
        $lines[] = '> 💡 *Сообщаемые даты носят ознакомительный характер и могут быть изменены.*';
        return implode("\n", $lines);
    }

    $rangeBefore = formatDateRangeShortRu($beforeFrom, $beforeTo);
    $lines = [
        'В плановом маршруте изменены предварительные даты вывоза',
        "#{$flightId} {$title}",
        "Было: {$rangeBefore}",
        "Стало: {$rangeAfter}",
    ];
    if ($unloadLine !== '') {
        $lines[] = $unloadLine;
    }
    if ($routeTypeLine !== '') {
        $lines[] = $routeTypeLine;
    }
    $lines[] = "Рейс закреплен: {$manager}";
    $lines[] = '> 💡 *Обновленные даты также ознакомительные и могут быть изменены.*';
    return implode("\n", $lines);
}

function buildPlannedToFoundMessage(PDO $pdo, array $after, int $flightId): string
{
    [$title, $manager, $driver, $meta] = buildCompactFlightContext($pdo, $after, $flightId);
    $dateRange = formatDateRangeShortRu($after['planned_start_date_from'] ?? '', $after['planned_start_date_to'] ?? '');
    $lines = [
        '**РЕЙС СФОРМИРОВАН**',
        "#{$flightId} {$title}",
        "Начало вывоза: {$dateRange}",
    ];
    $unloadLine = buildUnloadLine($after);
    if ($unloadLine !== '') {
        $lines[] = $unloadLine;
    }
    $routeTypeLine = buildRouteTypeLine($pdo, $after);
    if ($routeTypeLine !== '') {
        $lines[] = $routeTypeLine;
    }
    $lines[] = $meta;
    $lines[] = $driver;
    $lines[] = "Рейс закреплен: {$manager}";
    $lines[] = '> 💡 *Просим подготовить товаросопроводительные документы на заявленные дату и водителя.*';
    return implode("\n", $lines);
}

function buildFoundDiffMessage(PDO $pdo, array $before, array $after, int $flightId): string
{
    [$title, $manager] = buildCompactFlightContext($pdo, $after, $flightId);
    $blocks = [];

    if ((int)$before['driver_id'] !== (int)$after['driver_id']) {
        $old = compactDriverLabel((string)$before['_driver_label']);
        $new = compactDriverLabel((string)$after['_driver_label']);
        $blocks[] = "Водитель:\nБыло: {$old}\nСтало: {$new}";
    }
    if ((string)$before['planned_start_date_from'] !== (string)$after['planned_start_date_from']) {
        $oldRange = formatDateShortRu($before['planned_start_date_from']) . ' — ' . formatDateShortRu($before['planned_start_date_to']);
        $newRange = formatDateShortRu($after['planned_start_date_from']) . ' — ' . formatDateShortRu($after['planned_start_date_to']);
        $blocks[] = "Период:\nБыло: {$oldRange}\nСтало: {$newRange}";
    }
    if (abs((float)$before['cost'] - (float)$after['cost']) > 0.0001) {
        $blocks[] = "Стоимость:\nБыло: " . formatMoneyRu($before['cost']) . "\nСтало: " . formatMoneyRu($after['cost']);
    }
    if ((string)$before['zayavki_ids'] !== (string)$after['zayavki_ids']) {
        [$removed, $added] = buildAddedRemovedIds(splitIds((string)($before['zayavki_ids'] ?? '')), splitIds((string)($after['zayavki_ids'] ?? '')));
        if (!empty($removed)) {
            $blocks[] = 'Исключенные заявки: ' . formatIdsList($removed);
        }
        if (!empty($added)) {
            $blocks[] = 'Добавленные заявки: ' . formatIdsList($added);
        }
        if ((int)$before['_count'] !== (int)$after['_count']) {
            $blocks[] = "Количество заявок:\nБыло: " . (int)$before['_count'] . "\nСтало: " . (int)$after['_count'];
        }
    }
    if (abs((float)$before['_sum_tons'] - (float)$after['_sum_tons']) > 0.0001) {
        $blocks[] = "Вес:\nБыло: " . formatKgFromTons((float)$before['_sum_tons']) . "\nСтало: " . formatKgFromTons((float)$after['_sum_tons']);
    }
    if (trim((string)($before['comment'] ?? '')) !== trim((string)($after['comment'] ?? ''))) {
        $blocks[] = "Название:\nБыло: " . buildRouteTitle($before, $flightId) . "\nСтало: " . buildRouteTitle($after, $flightId);
    }
    if ((string)($before['route_type'] ?? '') !== (string)($after['route_type'] ?? '')) {
        $blocks[] = "Маршрут груза:\nБыло: " . buildRouteTypeLabel($before) . "\nСтало: " . buildRouteTypeLabel($after);
    }
    if ((int)($before['source_warehouse_id'] ?? 0) !== (int)($after['source_warehouse_id'] ?? 0)) {
        $oldName = getWarehouseNameById($pdo, $before['source_warehouse_id'] ?? 0);
        $newName = getWarehouseNameById($pdo, $after['source_warehouse_id'] ?? 0);
        $blocks[] = "Склад отправления:\nБыло: " . ($oldName !== '' ? $oldName : 'не указано') . "\nСтало: " . ($newName !== '' ? $newName : 'не указано');
    }
    if ((int)($before['destination_warehouse_id'] ?? 0) !== (int)($after['destination_warehouse_id'] ?? 0)) {
        $oldName = getWarehouseNameById($pdo, $before['destination_warehouse_id'] ?? 0);
        $newName = getWarehouseNameById($pdo, $after['destination_warehouse_id'] ?? 0);
        $blocks[] = "Склад назначения:\nБыло: " . ($oldName !== '' ? $oldName : 'не указано') . "\nСтало: " . ($newName !== '' ? $newName : 'не указано');
    }

    if (empty($blocks)) {
        return '';
    }

    // Header is provided by Event Center template (route_found_updated).
    // Only diff blocks are returned — no duplicate header.
    $lines = $blocks;
    $lines[] = "Рейс закреплен: {$manager}";
    return implode("\n\n", $lines);
}


function buildStartedDiffMessage(PDO $pdo, array $before, array $after, int $flightId): string
{
    [$title, $manager] = buildCompactFlightContext($pdo, $after, $flightId);
    $blocks = [];

    if ((int)$before['driver_id'] !== (int)$after['driver_id']) {
        $old = compactDriverLabel((string)$before['_driver_label']);
        $new = compactDriverLabel((string)$after['_driver_label']);
        $blocks[] = "Водитель:\nБыло: {$old}\nСтало: {$new}";
    }
    if ((string)$before['actual_start_date'] !== (string)$after['actual_start_date']) {
        $oldDate = $before['actual_start_date'] ? formatDateShortRu($before['actual_start_date']) : 'не указано';
        $newDate = $after['actual_start_date'] ? formatDateShortRu($after['actual_start_date']) : 'не указано';
        $blocks[] = "Фактический старт:\nБыло: {$oldDate}\nСтало: {$newDate}";
    }
    if ((string)$before['actual_end_date'] !== (string)$after['actual_end_date']) {
        $oldDate = $before['actual_end_date'] ? formatDateShortRu($before['actual_end_date']) : 'не указано';
        $newDate = $after['actual_end_date'] ? formatDateShortRu($after['actual_end_date']) : 'не указано';
        $blocks[] = "Фактический финиш:\nБыло: {$oldDate}\nСтало: {$newDate}";
    }
    if (abs((float)$before['cost'] - (float)$after['cost']) > 0.0001) {
        $blocks[] = "Стоимость:\nБыло: " . formatMoneyRu($before['cost']) . "\nСтало: " . formatMoneyRu($after['cost']);
    }
    if ((string)$before['zayavki_ids'] !== (string)$after['zayavki_ids']) {
        [$removed, $added] = buildAddedRemovedIds(splitIds((string)($before['zayavki_ids'] ?? '')), splitIds((string)($after['zayavki_ids'] ?? '')));
        if (!empty($removed)) {
            $blocks[] = 'Исключенные заявки: ' . formatIdsList($removed);
        }
        if (!empty($added)) {
            $blocks[] = 'Добавленные заявки: ' . formatIdsList($added);
        }
        if ((int)$before['_count'] !== (int)$after['_count']) {
            $blocks[] = "Количество заявок:\nБыло: " . (int)$before['_count'] . "\nСтало: " . (int)$after['_count'];
        }
    }
    if (abs((float)$before['_sum_tons'] - (float)$after['_sum_tons']) > 0.0001) {
        $blocks[] = "Вес:\nБыло: " . formatKgFromTons((float)$before['_sum_tons']) . "\nСтало: " . formatKgFromTons((float)$after['_sum_tons']);
    }
    if (trim((string)($before['comment'] ?? '')) !== trim((string)($after['comment'] ?? ''))) {
        $blocks[] = "Название:\nБыло: " . buildRouteTitle($before, $flightId) . "\nСтало: " . buildRouteTitle($after, $flightId);
    }
    if ((string)($before['route_type'] ?? '') !== (string)($after['route_type'] ?? '')) {
        $blocks[] = "Маршрут груза:\nБыло: " . buildRouteTypeLabel($before) . "\nСтало: " . buildRouteTypeLabel($after);
    }
    if ((int)($before['source_warehouse_id'] ?? 0) !== (int)($after['source_warehouse_id'] ?? 0)) {
        $oldName = getWarehouseNameById($pdo, $before['source_warehouse_id'] ?? 0);
        $newName = getWarehouseNameById($pdo, $after['source_warehouse_id'] ?? 0);
        $blocks[] = "Склад отправления:\nБыло: " . ($oldName !== '' ? $oldName : 'не указано') . "\nСтало: " . ($newName !== '' ? $newName : 'не указано');
    }
    if ((int)($before['destination_warehouse_id'] ?? 0) !== (int)($after['destination_warehouse_id'] ?? 0)) {
        $oldName = getWarehouseNameById($pdo, $before['destination_warehouse_id'] ?? 0);
        $newName = getWarehouseNameById($pdo, $after['destination_warehouse_id'] ?? 0);
        $blocks[] = "Склад назначения:\nБыло: " . ($oldName !== '' ? $oldName : 'не указано') . "\nСтало: " . ($newName !== '' ? $newName : 'не указано');
    }

    if (empty($blocks)) {
        return '';
    }
    // Header is provided by Event Center template (route_started_updated).
    // Only diff lines are returned — no duplicate header.
    $lines = $blocks;
    $lines[] = 'Рейс находится в выполнении. Проверьте корректность изменений.';
    return implode("\n\n", $lines);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['success' => false, 'message' => 'Неверный метод']);
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception('Database connection is not initialized');
    }

    $data = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception('Неверный JSON');
    }

    $action = trim((string)($data['action'] ?? 'save'));
    $routeId = isset($data['id']) ? (int)$data['id'] : 0;
    $name = trim((string)($data['name'] ?? ''));

    if ($action === 'save') {
        $requireFull = false;
        $requireTitle = false;
        $requireRequests = true;
        $requireWarehouses = true;
        if ($routeId > 0) {
            $current = loadFlightSnapshot($pdo, $routeId);
            $isFoundEdit = is_array($current) && (string)$current['status'] === STATUS_FOUND;
            $requireFull = $isFoundEdit;
            $requireTitle = false;
            $requireRequests = $isFoundEdit;
            $requireWarehouses = $isFoundEdit;
            // Full fallback: all fields from existing flight if not in payload
            if (is_array($current)) {
                $fallbackFields = [
                    'driver_id', 'assigned_manager_id', 'cost',
                    'planned_start_date_from', 'planned_start_date_to',
                    'route_type', 'unload_type',
                    'source_warehouse_id', 'destination_warehouse_id',
                    'comment',
                ];
                foreach ($fallbackFields as $field) {
                    if (empty($data[$field]) && !empty($current[$field])) {
                        $data[$field] = $current[$field];
                    }
                }
            }
        }
        [$ok, $msg, $normalized, $errors] = validateRouteData(
            $pdo,
            $data,
            $requireFull,
            $requireTitle,
            $requireRequests,
            $requireWarehouses
        );
        if (!$ok) {
            jsonOut(['success' => false, 'message' => $msg, 'errors' => $errors]);
        }

        if ($routeId > 0) {
            $before = loadFlightSnapshot($pdo, $routeId);
            if (!$before) jsonOut(['success' => false, 'message' => 'Рейс не найден']);

            $statusBefore = (string)($before['status'] ?? '');
            $actualStartValue = $before['actual_start_date'] ?? null;
            $actualEndValue = $before['actual_end_date'] ?? null;
            if ($statusBefore === STATUS_STARTED) {
                try {
                    if (array_key_exists('actual_start_date', $data)) {
                        $actualStartValue = normalizeDateToDb($data['actual_start_date']);
                    }
                    if (array_key_exists('actual_end_date', $data)) {
                        $actualEndValue = normalizeDateToDb($data['actual_end_date']);
                    }
                } catch (Throwable $e) {
                    jsonOut(['success' => false, 'message' => 'Некорректные фактические даты']);
                }
            }

            $stmt = $pdo->prepare("
                UPDATE flights
                SET comment = :comment,
                    cost = :cost,
                    zayavki_ids = :zayavki_ids,
                    zayavki_count = :count,
                    planned_start_date_from = :planned_from,
                    planned_start_date_to = :planned_to,
                    actual_start_date = :actual_start_date,
                    actual_end_date = :actual_end_date,
                    unload_type = :unload_type,
                    route_type = :route_type,
                    source_warehouse_id = :source_warehouse_id,
                    destination_warehouse_id = :destination_warehouse_id,
                    driver_id = :driver_id,
                    block_date = NOW()
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute([
                ':comment' => $name !== '' ? $name : ($before['comment'] ?? ('Рейс #' . $routeId)),
                ':cost' => $normalized['cost'],
                ':zayavki_ids' => $normalized['zayavki_ids_canonical'],
                ':count' => $normalized['zayavki_count'],
                ':planned_from' => $normalized['planned_start_date_from'] ?? $before['planned_start_date_from'] ?? null,
                ':planned_to' => $normalized['planned_start_date_to'] ?? $before['planned_start_date_to'] ?? null,
                ':actual_start_date' => $actualStartValue,
                ':actual_end_date' => $actualEndValue,
                ':unload_type' => $normalized['unload_type'],
                ':route_type' => $normalized['route_type'],
                ':source_warehouse_id' => $normalized['source_warehouse_id'],
                ':destination_warehouse_id' => $normalized['destination_warehouse_id'],
                ':driver_id' => $normalized['driver_id'],
                ':id' => $routeId,
            ]);

            $after = loadFlightSnapshot($pdo, $routeId);
            $notifyResult = ['success' => true, 'error' => null];
            if ($after && (string)($before['status'] ?? '') === STATUS_FOUND) {
                $text = buildFoundDiffMessage($pdo, $before, $after, $routeId);
                if ($text !== '') {
                    $diffContext = buildRouteDiffContext($pdo, $before, $after, $routeId);
                    $diffContext = buildRouteEventContext($pdo, $after, $routeId, $diffContext);
                    $diffContext['changed_fields_text'] = $text;
                    $notifyResult = sendMaxNotification($text, 'route_found_updated', $diffContext);
                }
            } elseif ($after && (string)($before['status'] ?? '') === STATUS_STARTED) {
                $text = buildStartedDiffMessage($pdo, $before, $after, $routeId);
                if ($text !== '') {
                    $diffContext = buildRouteDiffContext($pdo, $before, $after, $routeId);
                    $diffContext = buildRouteEventContext($pdo, $after, $routeId, $diffContext);
                    $diffContext['changed_fields_text'] = $text;
                    $notifyResult = sendMaxNotification($text, 'route_started_updated', $diffContext);
                }
            }

            jsonOut([
                'success' => true,
                'message' => 'Рейс обновлён',
                'notify_success' => (bool)$notifyResult['success'],
                'notify_error' => $notifyResult['error']
            ]);
        }

        $assignedManagerId = resolveValidLogistManagerId($pdo, $data['assigned_manager_id'] ?? 0);
        if ($assignedManagerId <= 0) {
            jsonOut([
                'success' => false,
                'message' => 'Выберите менеджера для планируемого рейса.',
                'errors' => [
                    'assigned_manager_id' => 'Выберите менеджера для планируемого рейса.'
                ]
            ]);
        }

        $managerColumn = resolveFlightsManagerColumn($pdo);
        if ($managerColumn === null) {
            jsonOut([
                'success' => false,
                'message' => 'Выберите менеджера для планируемого рейса.',
                'errors' => [
                    'assigned_manager_id' => 'Выберите менеджера для планируемого рейса.'
                ]
            ]);
        }
        $quotedManagerColumn = quoteIdent($managerColumn);

        $stmt = $pdo->prepare("
            INSERT INTO flights (status, comment, cost, unload_type, route_type, source_warehouse_id, destination_warehouse_id, zayavki_ids, zayavki_count, {$quotedManagerColumn}, planned_start_date_from, planned_start_date_to, driver_id, block_date)
            VALUES (:status, :comment, :cost, :unload_type, :route_type, :source_warehouse_id, :destination_warehouse_id, :zayavki_ids, :count, :assigned_manager_id, :planned_from, :planned_to, :driver_id, NOW())
        ");
        $stmt->execute([
            ':status' => STATUS_PLANNED,
            ':comment' => $name !== '' ? $name : 'Новый рейс',
            ':cost' => $normalized['cost'],
            ':unload_type' => $normalized['unload_type'],
            ':route_type' => $normalized['route_type'],
            ':source_warehouse_id' => $normalized['source_warehouse_id'],
            ':destination_warehouse_id' => $normalized['destination_warehouse_id'],
            ':zayavki_ids' => $normalized['zayavki_ids_canonical'],
            ':count' => $normalized['zayavki_count'],
            ':assigned_manager_id' => $assignedManagerId,
            ':planned_from' => $normalized['planned_start_date_from'],
            ':planned_to' => $normalized['planned_start_date_to'],
            ':driver_id' => $normalized['driver_id'],
        ]);
        jsonOut(['success' => true, 'id' => (int)$pdo->lastInsertId(), 'message' => 'Маршрут создан']);
    }

    if ($routeId <= 0) {
        jsonOut(['success' => false, 'message' => 'Некорректный ID рейса']);
    }
    $flight = loadFlightSnapshot($pdo, $routeId);
    if (!$flight) {
        jsonOut(['success' => false, 'message' => 'Рейс не найден']);
    }

    if ($action === 'delete_route') {
        if ((string)$flight['status'] !== STATUS_PLANNED) {
            jsonOut(['success' => false, 'message' => 'Удаление доступно только для ПЛАНИРУЕМЫЙ']);
        }
        [$title, $manager] = buildCompactFlightContext($pdo, $flight, $routeId);
        $stmt = $pdo->prepare('DELETE FROM flights WHERE id = :id AND status = :status');
        $stmt->execute([':id' => $routeId, ':status' => STATUS_PLANNED]);
        $unloadLine = buildUnloadLine($flight);
        $routeTypeLine = buildRouteTypeLine($pdo, $flight);
        $notifyResult = sendMaxNotification(
            "#{$routeId} {$title} - Удален из системы\n" .
            ($unloadLine !== '' ? ($unloadLine . "\n") : '') .
            ($routeTypeLine !== '' ? ($routeTypeLine . "\n") : '') .
            "Рейс закреплен: {$manager}"
        , 'route_deleted', buildRouteEventContext($pdo, $flight, $routeId, [
            'status_from' => STATUS_PLANNED,
            'status_to' => 'deleted',
        ]));
        jsonOut([
            'success' => true,
            'message' => 'Маршрут удален',
            'notify_success' => (bool)$notifyResult['success'],
            'notify_error' => $notifyResult['error']
        ]);
    }

    if ($action === 'transition') {
        $target = trim((string)($data['target_status'] ?? ''));
        if (!in_array($target, [STATUS_PLANNED, STATUS_FOUND, STATUS_STARTED, STATUS_COMPLETED], true)) {
            jsonOut(['success' => false, 'message' => 'Некорректный целевой статус']);
        }
        $deny = assertTransitionAllowed($flight, $target);
        if ($deny !== null) {
            jsonOut(['success' => false, 'message' => $deny]);
        }

        if ($target === STATUS_FOUND) {
            $wasStarted = (string)($flight['status'] ?? '') === STATUS_STARTED;
            $payload = $data;
            if ($wasStarted) {
                if (!isset($payload['zayavki_ids']) || trim((string)$payload['zayavki_ids']) === '') $payload['zayavki_ids'] = (string)($flight['zayavki_ids'] ?? '');
                if (!isset($payload['driver_id']) || (int)$payload['driver_id'] <= 0) $payload['driver_id'] = (int)($flight['driver_id'] ?? 0);
                if (!isset($payload['planned_start_date_from']) || trim((string)$payload['planned_start_date_from']) === '') $payload['planned_start_date_from'] = (string)($flight['planned_start_date_from'] ?? '');
                if (!isset($payload['planned_start_date_to']) || trim((string)$payload['planned_start_date_to']) === '') $payload['planned_start_date_to'] = (string)($flight['planned_start_date_to'] ?? '');
                if (!array_key_exists('cost', $payload) || $payload['cost'] === '' || $payload['cost'] === null) $payload['cost'] = $flight['cost'] ?? null;
                if (!isset($payload['unload_type']) || trim((string)$payload['unload_type']) === '') $payload['unload_type'] = (string)($flight['unload_type'] ?? 'OO');
                if (!isset($payload['route_type']) || trim((string)$payload['route_type']) === '') $payload['route_type'] = (string)($flight['route_type'] ?? '');
                if (!array_key_exists('source_warehouse_id', $payload)) $payload['source_warehouse_id'] = $flight['source_warehouse_id'] ?? null;
                if (!array_key_exists('destination_warehouse_id', $payload)) $payload['destination_warehouse_id'] = $flight['destination_warehouse_id'] ?? null;
                if (!isset($payload['name']) || trim((string)$payload['name']) === '') $payload['name'] = trim((string)($flight['comment'] ?? $flight['name'] ?? ''));
            }
            $requireTitle = false;
            $strictFoundTransition = !$wasStarted;
            [$ok, $msg, $normalized, $errors] = validateRouteData(
                $pdo,
                $payload,
                $strictFoundTransition,
                $requireTitle,
                $strictFoundTransition,
                $strictFoundTransition
            );
            if (!$ok) {
                jsonOut(['success' => false, 'message' => $msg, 'errors' => $errors]);
            }
            $comment = trim((string)($payload['name'] ?? ''));
            $stmtApply = $pdo->prepare("
                UPDATE flights
                SET comment = :comment,
                    cost = :cost,
                    zayavki_ids = :zayavki_ids,
                    zayavki_count = :count,
                    planned_start_date_from = :planned_from,
                    planned_start_date_to = :planned_to,
                    unload_type = :unload_type,
                    route_type = :route_type,
                    source_warehouse_id = :source_warehouse_id,
                    destination_warehouse_id = :destination_warehouse_id,
                    driver_id = :driver_id,
                    block_date = NOW()
                WHERE id = :id
                LIMIT 1
            ");
            $stmtApply->execute([
                ':comment' => $comment !== '' ? $comment : ($flight['comment'] ?? ('Рейс #' . $routeId)),
                ':cost' => $normalized['cost'],
                ':zayavki_ids' => $normalized['zayavki_ids_canonical'],
                ':count' => $normalized['zayavki_count'],
                ':planned_from' => $normalized['planned_start_date_from'],
                ':planned_to' => $normalized['planned_start_date_to'],
                ':unload_type' => $normalized['unload_type'],
                ':route_type' => $normalized['route_type'],
                ':source_warehouse_id' => $normalized['source_warehouse_id'],
                ':destination_warehouse_id' => $normalized['destination_warehouse_id'],
                ':driver_id' => $normalized['driver_id'],
                ':id' => $routeId,
            ]);

            $flight = loadFlightSnapshot($pdo, $routeId) ?: $flight;
        }

        if ($target === STATUS_STARTED) {
            $payload = $data;
            if (!isset($payload['zayavki_ids']) || trim((string)$payload['zayavki_ids']) === '') $payload['zayavki_ids'] = (string)($flight['zayavki_ids'] ?? '');
            if (!isset($payload['driver_id']) || (int)$payload['driver_id'] <= 0) $payload['driver_id'] = (int)($flight['driver_id'] ?? 0);
            if (!isset($payload['planned_start_date_from']) || trim((string)$payload['planned_start_date_from']) === '') $payload['planned_start_date_from'] = (string)($flight['planned_start_date_from'] ?? '');
            if (!isset($payload['planned_start_date_to']) || trim((string)$payload['planned_start_date_to']) === '') $payload['planned_start_date_to'] = (string)($flight['planned_start_date_to'] ?? '');
            if (!array_key_exists('cost', $payload) || $payload['cost'] === '' || $payload['cost'] === null) $payload['cost'] = $flight['cost'] ?? null;
            if (!isset($payload['unload_type']) || trim((string)$payload['unload_type']) === '') $payload['unload_type'] = (string)($flight['unload_type'] ?? 'OO');
            if (!isset($payload['route_type']) || trim((string)$payload['route_type']) === '') $payload['route_type'] = (string)($flight['route_type'] ?? '');
            if (!array_key_exists('source_warehouse_id', $payload)) $payload['source_warehouse_id'] = $flight['source_warehouse_id'] ?? null;
            if (!array_key_exists('destination_warehouse_id', $payload)) $payload['destination_warehouse_id'] = $flight['destination_warehouse_id'] ?? null;

            [$ok, $msg, $_normalized, $errors] = validateRouteData(
                $pdo,
                $payload,
                true,
                false,
                true,
                true
            );
            if (!$ok) {
                jsonOut(['success' => false, 'message' => $msg, 'errors' => $errors]);
            }

            $actualRaw = trim((string)($data['actual_start_date'] ?? ''));
            if ($actualRaw === '') {
                jsonOut(['success' => false, 'message' => 'Укажите дату начала вывоза']);
            }
            $actualTs = strtotime($actualRaw);
            if ($actualTs === false) {
                jsonOut(['success' => false, 'message' => 'Некорректная дата начала вывоза']);
            }
            $stmt = $pdo->prepare('UPDATE flights SET status = :status, actual_start_date = :actual_start WHERE id = :id LIMIT 1');
            $stmt->execute([
                ':status' => STATUS_STARTED,
                ':actual_start' => date('Y-m-d H:i:s', $actualTs),
                ':id' => $routeId,
            ]);
            $afterStarted = loadFlightSnapshot($pdo, $routeId) ?: $flight;
            [$title, $manager, $driver, $meta] = buildCompactFlightContext($pdo, $afterStarted, $routeId);
            $notifyResult = sendMaxNotification(
                implode("\n", array_filter([
                    '**✅ ВЫВОЗ НАЧАЛСЯ**',
                    "#{$routeId} {$title}",
                    buildUnloadLine($afterStarted),
                    buildRouteTypeLine($pdo, $afterStarted),
                    "Водитель: {$driver}",
                    "Старт: " . formatDateShortRu($afterStarted['actual_start_date'] ?? ''),
                    "Заявки: " . (int)($afterStarted['_count'] ?? 0),
                    "Вес: " . formatKgFromTons((float)($afterStarted['_sum_tons'] ?? 0)),
                    "Рейс закреплен: {$manager}",
                    '> 💡 *Включено слежение за состоянием трекера.*'
                ], static fn($line) => $line !== ''))
            , 'route_found_to_started', buildRouteEventContext($pdo, $afterStarted, $routeId, [
                'actual_start_short' => formatDateShortRu($afterStarted['actual_start_date'] ?? ''),
                'status_from' => STATUS_FOUND,
                'status_to' => STATUS_STARTED,
            ]));
            jsonOut([
                'success' => true,
                'message' => 'Рейс переведен в ВЫВОЗНАЧАЛСЯ',
                'notify_success' => (bool)$notifyResult['success'],
                'notify_error' => $notifyResult['error']
            ]);
        }

        if ($target === STATUS_COMPLETED) {
            $actualEndRaw = trim((string)($data['actual_end_date'] ?? ''));
            if ($actualEndRaw === '') {
                jsonOut(['success' => false, 'message' => 'Укажите дату завершения перевозки.']);
            }
            $actualEndTs = strtotime($actualEndRaw);
            if ($actualEndTs === false) {
                jsonOut(['success' => false, 'message' => 'Некорректная дата завершения перевозки']);
            }
            if ((int)($flight['driver_id'] ?? 0) <= 0) {
                jsonOut(['success' => false, 'message' => 'Для завершения рейса укажите водителя.']);
            }
            if (count((array)($flight['_ids'] ?? [])) === 0) {
                jsonOut(['success' => false, 'message' => 'Для завершения рейса укажите заявки.']);
            }
            $warehouseValidationError = validateWarehouseRequirementsForCompleted($flight);
            if ($warehouseValidationError !== null) {
                jsonOut(['success' => false, 'message' => $warehouseValidationError]);
            }
            $stmt = $pdo->prepare('UPDATE flights SET status = :status, actual_end_date = :actual_end WHERE id = :id LIMIT 1');
            $stmt->execute([
                ':status' => STATUS_COMPLETED,
                ':actual_end' => date('Y-m-d H:i:s', $actualEndTs),
                ':id' => $routeId,
            ]);
            $afterCompleted = loadFlightSnapshot($pdo, $routeId) ?: $flight;
            $warehouseMovements = [];
            try {
                $warehouseMovements = createWarehouseMovementsForCompletedFlight($pdo, $routeId);
            } catch (Throwable $wmError) {
                mapError('Warehouse movements failed', ['flight_id' => $routeId, 'error' => $wmError->getMessage()]);
                $warehouseMovements = ['success' => false, 'errors' => [$wmError->getMessage()]];
            }
            $driver = compactDriverLabel((string)($afterCompleted['_driver_label'] ?? ''));
            $title = buildRouteTitle($afterCompleted, $routeId);
            $notifyResult = sendMaxNotification(
                implode("\n", array_filter([
                    'ТС ПРИБЫЛО НА РАЗГРУЗКУ',
                    '───────────────────',
                    buildUnloadLine($afterCompleted),
                    buildRouteTypeLine($pdo, $afterCompleted),
                    $driver,
                    "#{$routeId} — {$title}",
                    '> 💡 *Напоминаю: для оплаты подрядчику нужен полный пакет документов (диагностическая карта, путевой лист и т.д.). Прошу не затягивать с предоставлением.*'
                ], static fn($line) => $line !== ''))
            , 'route_completed', buildRouteEventContext($pdo, $afterCompleted, $routeId, [
                'status_from' => STATUS_STARTED,
                'status_to' => STATUS_COMPLETED,
                'actual_end_short' => formatDateShortRu($afterCompleted['actual_end_date'] ?? ''),
            ]));
            jsonOut([
                'success' => true,
                'message' => 'Рейс переведен в ГРУЗСДАН',
                'notify_success' => (bool)$notifyResult['success'],
                'notify_error' => $notifyResult['error'],
                'warehouse_movements' => $warehouseMovements,
            ]);
        }

        $stmt = $pdo->prepare('UPDATE flights SET status = :status WHERE id = :id LIMIT 1');
        $stmt->execute([':status' => $target, ':id' => $routeId]);
        $after = loadFlightSnapshot($pdo, $routeId) ?: $flight;

        if ($target === STATUS_FOUND) {
            [$title, $manager] = buildCompactFlightContext($pdo, $after, $routeId);
            $wasStarted = (string)($flight['status'] ?? '') === STATUS_STARTED;
            $oldActualStartDate = $wasStarted ? ($flight['actual_start_date'] ?? null) : null;
            if ($wasStarted) {
                $clearActualStart = $pdo->prepare('UPDATE flights SET actual_start_date = NULL WHERE id = :id LIMIT 1');
                $clearActualStart->execute([':id' => $routeId]);
                $after = loadFlightSnapshot($pdo, $routeId) ?: $after;
            }
            if ($wasStarted) {
                $driver = compactDriverLabel((string)($after['_driver_label'] ?? ''));
                $message = "**⚠️ ПРЕОСТАНОВКА ВЫПОЛНЯЕМОГО РЕЙСА ⚠️**\n" .
                    "#{$routeId} {$title}\n" .
                    (buildUnloadLine($after) !== '' ? (buildUnloadLine($after) . "\n") : '') .
                    (buildRouteTypeLine($pdo, $after) !== '' ? (buildRouteTypeLine($pdo, $after) . "\n") : '') .
                    "Водитель: {$driver}\n" .
                    "Старт: " . formatDateShortRu($oldActualStartDate ?? '') . "\n" .
                    "Заявки: " . (int)($after['_count'] ?? 0) . "\n" .
                    "Вес: " . formatKgFromTons((float)($after['_sum_tons'] ?? 0)) . "\n" .
                    "Рейс закреплен: {$manager}\n" .
                    "> 💡 *ВНИМАНИЕ. Статус рейса изменён с «Выполняемые» на «Сформированные». В связи с этим вероятна корректировка перечня вывозимых заявок либо замена подрядчика.*";
            } else {
                $message = buildPlannedToFoundMessage($pdo, $after, $routeId);
            }
            $notifyResult = sendMaxNotification(
                $message,
                $wasStarted ? 'route_started_to_found_rollback' : 'route_planned_to_found',
                buildRouteEventContext($pdo, $after, $routeId, [
                    'status_from' => $wasStarted ? STATUS_STARTED : STATUS_PLANNED,
                    'status_to' => STATUS_FOUND,
                    'actual_start_short' => formatDateShortRu($oldActualStartDate ?? ''),
                    'old_actual_start_date' => $oldActualStartDate ?? '',
                ])
            );
            jsonOut([
                'success' => true,
                'message' => 'Рейс сформирован',
                'notify_success' => (bool)$notifyResult['success'],
                'notify_error' => $notifyResult['error']
            ]);
        }

        if ($target === STATUS_PLANNED) {
            [$title, $manager] = buildCompactFlightContext($pdo, $after, $routeId);
            $notifyResult = sendMaxNotification(
                "**#{$routeId} {$title}**\n" .
                "возвращён в «Планируемый»\n" .
                (buildUnloadLine($after) !== '' ? (buildUnloadLine($after) . "\n") : '') .
                (buildRouteTypeLine($pdo, $after) !== '' ? (buildRouteTypeLine($pdo, $after) . "\n") : '') .
                "Рейс закреплен: {$manager}\n" .
                "> 💡 *Подготовку документов приостановить до переформирования рейса.*"
            , 'route_found_to_planned_rollback', buildRouteEventContext($pdo, $after, $routeId, [
                'status_from' => STATUS_FOUND,
                'status_to' => STATUS_PLANNED,
            ]));
            jsonOut([
                'success' => true,
                'message' => 'Рейс возвращен в ПЛАНИРУЕМЫЙ',
                'notify_success' => (bool)$notifyResult['success'],
                'notify_error' => $notifyResult['error']
            ]);
        }
    }

    jsonOut(['success' => false, 'message' => 'Неизвестное действие']);
} catch (Throwable $e) {
    mapError('save_planned_route fatal', ['error' => $e->getMessage()]);
    jsonOut(['success' => false, 'message' => 'Внутренняя ошибка']);
}


