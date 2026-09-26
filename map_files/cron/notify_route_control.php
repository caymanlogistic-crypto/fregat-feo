<?php
error_reporting(0);
ini_set('display_errors', 0);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/max_notify.php';

header('Content-Type: application/json; charset=utf-8');

const STATUS_PLANNED = 'planned_route';
const STATUS_FOUND = 'found';
const STATUS_STARTED = 'started';

function out(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function fmtDateShort($value): string
{
    $v = trim((string)$value);
    if ($v === '') return 'не указано';
    $ts = strtotime($v);
    return $ts === false ? $v : date('d.m', $ts);
}

function fmtKg($tons): string
{
    return number_format((int)round((float)$tons * 1000), 0, '.', ' ') . ' кг';
}

function compactDriver(string $label): string
{
    $v = trim($label);
    if ($v === '') return 'Водитель не указан';
    if (preg_match('/([А-ЯЁA-Z]\d{3}[А-ЯЁA-Z]{2}\d{2,3})/u', $v, $mPlate)) {
        $plate = trim($mPlate[1]);
        if (preg_match('/\(([^)]+)\)/u', $v, $mName)) {
            $surname = trim((string)explode(' ', trim($mName[1]))[0]);
            if ($surname !== '') return $plate . ' (' . $surname . ')';
        }
        return $plate;
    }
    return $v;
}

function managerName(PDO $pdo, $managerId): string
{
    $id = (int)$managerId;
    if ($id <= 0) return 'Менеджер не указан';
    try {
        $stmt = $pdo->prepare("SELECT TRIM(CONCAT(COALESCE(`Фамилия`,''),' ',COALESCE(`Имя`,''))) AS n FROM users WHERE id=:id LIMIT 1");
        if ($stmt && $stmt->execute([':id' => $id])) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $n = trim((string)($row['n'] ?? ''));
            if ($n !== '') return $n;
        }
    } catch (Throwable $e) {
        mapError('notify_route_control managerName failed', ['id' => $id, 'error' => $e->getMessage()]);
    }
    return 'Менеджер #' . $id;
}

function normalizeRouteType($routeTypeRaw, $unloadTypeRaw = 'OO'): string
{
    $routeType = strtolower(trim((string)$routeTypeRaw));
    $allowed = [
        'generator_to_utilizer',
        'generator_to_warehouse',
        'warehouse_to_warehouse',
        'warehouse_to_utilizer',
    ];
    if (in_array($routeType, $allowed, true)) {
        return $routeType;
    }
    return strtoupper(trim((string)$unloadTypeRaw)) === 'SKLAD'
        ? 'generator_to_warehouse'
        : 'generator_to_utilizer';
}

function routeTypeLine(array $row): string
{
    $routeType = normalizeRouteType($row['route_type'] ?? '', $row['unload_type'] ?? 'OO');
    $source = trim((string)($row['source_warehouse_name'] ?? ''));
    $dest = trim((string)($row['destination_warehouse_name'] ?? ''));
    if ($routeType === 'generator_to_warehouse') {
        return $dest !== '' ? ('Выгрузка на склад: ' . $dest) : 'Выгрузка на склад';
    }
    if ($routeType === 'warehouse_to_warehouse') {
        if ($source !== '' && $dest !== '') {
            return 'Перемещение: ' . $source . ' → ' . $dest;
        }
        return 'Перемещение между складами';
    }
    if ($routeType === 'warehouse_to_utilizer') {
        return $source !== '' ? ('Вывоз со склада: ' . $source) : 'Вывоз со склада';
    }
    return '';
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is not initialized');
    }

    $mode = trim((string)($_GET['mode'] ?? ''));
    if ($mode === '') {
        $hour = (int)date('G');
        $mode = $hour < 12 ? 'morning' : 'evening';
    }
    if (!in_array($mode, ['morning', 'evening'], true)) {
        $mode = 'morning';
    }

    $warehouseNameColumn = null;
    try {
        $wColsStmt = $pdo->query('SHOW COLUMNS FROM warehouses');
        $wCols = $wColsStmt ? $wColsStmt->fetchAll(PDO::FETCH_COLUMN) : [];
        $wMap = [];
        foreach ((array)$wCols as $column) {
            $wMap[(string)$column] = true;
        }
        foreach (['name', 'title', 'warehouse_name', 'label'] as $candidate) {
            if (isset($wMap[$candidate])) {
                $warehouseNameColumn = $candidate;
                break;
            }
        }
    } catch (Throwable $e) {
        $warehouseNameColumn = null;
    }
    $sourceWarehouseNameSelect = $warehouseNameColumn ? ('ws.`' . str_replace('`', '``', $warehouseNameColumn) . '`') : 'NULL';
    $destWarehouseNameSelect = $warehouseNameColumn ? ('wd.`' . str_replace('`', '``', $warehouseNameColumn) . '`') : 'NULL';

    $sql = "
        SELECT f.id, f.status, f.comment, f.cost, f.unload_type, f.zayavki_ids, f.assigned_manager_id,
               f.route_type, f.source_warehouse_id, f.destination_warehouse_id,
               f.planned_start_date_from, f.planned_start_date_to,
               COALESCE(f.zayavki_count, 0) AS zayavki_count,
               d.vehicle_make_plate, d.full_name,
               (SELECT COALESCE(SUM(COALESCE(mass_netto,0)),0) FROM feo WHERE FIND_IN_SET(zayavka_id, f.zayavki_ids) > 0) AS sum_tons,
               {$sourceWarehouseNameSelect} AS source_warehouse_name,
               {$destWarehouseNameSelect} AS destination_warehouse_name
        FROM flights f
        LEFT JOIN drivers d ON d.id = f.driver_id
        LEFT JOIN warehouses ws ON ws.id = f.source_warehouse_id
        LEFT JOIN warehouses wd ON wd.id = f.destination_warehouse_id
        WHERE DATE(f.planned_start_date_from) = CURDATE()
          AND f.status IN ('planned_route', 'found')
        ORDER BY f.status ASC, f.id DESC
    ";
    $stmt = $pdo->query($sql);
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    if (empty($rows)) {
        out(['success' => true, 'mode' => $mode, 'sent' => 0, 'message' => 'Проблемных рейсов нет']);
    }

    $header = $mode === 'morning' ? 'MAX: контроль вывоза на сегодня' : 'MAX: вечерний контроль вывоза';
    $lines = [$header];

    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) continue;
        $title = trim((string)($row['comment'] ?? ''));
        if ($title === '') $title = 'Рейс #' . $id;
        $manager = managerName($pdo, $row['assigned_manager_id'] ?? 0);
        $status = ((string)($row['status'] ?? '') === STATUS_FOUND) ? 'Рейс сформирован' : 'Планируемый';
        $driverRaw = trim((string)($row['vehicle_make_plate'] ?? ''));
        $full = trim((string)($row['full_name'] ?? ''));
        if ($full !== '') $driverRaw .= ($driverRaw !== '' ? " ({$full})" : $full);
        $driver = compactDriver($driverRaw);
        $count = (int)($row['zayavki_count'] ?? 0);
        $kg = fmtKg((float)($row['sum_tons'] ?? 0));
        $period = fmtDateShort($row['planned_start_date_from'] ?? '') . '–' . fmtDateShort($row['planned_start_date_to'] ?? '');

        $lines[] = "#{$id} {$title} | {$manager}";
        $lines[] = "Статус: {$status}";
        $routeLine = routeTypeLine($row);
        if ($routeLine !== '') {
            $lines[] = $routeLine;
        }
        $lines[] = "{$count} заяв. • {$kg}";
        $lines[] = "Водитель: {$driver}";
        $lines[] = "Даты: {$period}";
        if ((string)($row['status'] ?? '') === STATUS_FOUND) {
            $lines[] = $mode === 'morning'
                ? 'Если вывоз начался — переведите рейс в «Вывоз начался».'
                : 'Если вывоз начался — переведите рейс в «Вывоз начался». Если дата изменилась — скорректируйте даты рейса.';
        } else {
            $lines[] = 'Назначьте водителя/стоимость или измените дату вывоза.';
        }
    }

    $text = implode("\n", $lines);
    $notify = sendMaxNotify($text, 'markdown', [
        'event_key' => 'route_control_cron',
        'context' => ['message' => $text],
    ]);
    out([
        'success' => true,
        'mode' => $mode,
        'sent' => 1,
        'notify_success' => (bool)$notify['success'],
        'notify_error' => $notify['error']
    ]);
} catch (Throwable $e) {
    mapError('notify_route_control fatal', ['error' => $e->getMessage()]);
    out(['success' => false, 'message' => 'Внутренняя ошибка']);
}
