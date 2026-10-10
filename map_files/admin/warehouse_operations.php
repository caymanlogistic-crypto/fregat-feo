<?php
/**
 * Read-only журнал движений по складу.
 *
 * Показывает, какой рейс, водитель и машина привезли или вывезли заявки,
 * а также накопительный остаток после каждой операции. Данные не изменяет.
 */

session_start();
require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/common.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'Database connection error';
    exit;
}

function whLedgerHtml($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function whLedgerGet(string $key, string $default = ''): string
{
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}

function whLedgerGetInt(string $key, int $default = 0): int
{
    $value = whLedgerGet($key);
    return preg_match('/^\d+$/', $value) ? (int)$value : $default;
}

function whLedgerDate(string $key): string
{
    $value = whLedgerGet($key);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
}

function whLedgerOperationLabel(string $movementType): string
{
    return [
        'receipt' => 'Поступление',
        'transfer_in' => 'Перемещение: приход',
        'issue' => 'Списание',
        'transfer_out' => 'Перемещение: расход',
    ][$movementType] ?? $movementType;
}

function whLedgerIsInbound(string $movementType): bool
{
    return in_array($movementType, ['receipt', 'transfer_in'], true);
}

function whLedgerIsOutbound(string $movementType): bool
{
    return in_array($movementType, ['issue', 'transfer_out'], true);
}

function whLedgerKg(float $tonnes): string
{
    return number_format((int)round($tonnes * 1000), 0, ',', ' ');
}

function whLedgerPlate(string $source): string
{
    $source = trim($source);
    if ($source === '') {
        return '—';
    }

    $normalized = function_exists('mb_strtoupper')
        ? mb_strtoupper($source, 'UTF-8')
        : strtoupper($source);
    if (preg_match('/[ABEKMHOPCTYXАВЕКМНОРСТУХ]\s*\d{3}\s*[ABEKMHOPCTYXАВЕКМНОРСТУХ]{2}\s*\d{2,3}/u', $normalized, $match)) {
        return preg_replace('/\s+/u', '', $match[0]);
    }

    return $source;
}

function whLedgerContains(string $haystack, string $needle): bool
{
    if (function_exists('mb_stripos')) {
        return mb_stripos($haystack, $needle, 0, 'UTF-8') !== false;
    }
    return stripos($haystack, $needle) !== false;
}

function whLedgerSnapshot(array $stock): array
{
    $requestIds = [];
    $netto = 0.0;
    $brutto = 0.0;
    $volume = 0.0;

    $negativeCount = 0;
    foreach ($stock as $zayavkaId => $row) {
        $rowNetto = (float)($row['mass_netto'] ?? 0);
        $rowBrutto = (float)($row['mass_brutto'] ?? 0);
        if ($rowNetto < -0.0001 || $rowBrutto < -0.0001) {
            $negativeCount++;
        }
        if ($rowNetto <= 0.0001 && $rowBrutto <= 0.0001) {
            continue;
        }
        $requestIds[] = (int)$zayavkaId;
        $netto += $rowNetto;
        $brutto += $rowBrutto;
        $volume += (float)($row['volume'] ?? 0);
    }

    sort($requestIds, SORT_NUMERIC);
    return [
        'request_ids' => $requestIds,
        'request_count' => count($requestIds),
        'mass_netto' => round($netto, 4),
        'mass_brutto' => round($brutto, 4),
        'volume' => round($volume, 4),
        'negative_count' => $negativeCount,
    ];
}

function whLedgerFinalizeEvent(array &$events, ?array &$event, array $stock): void
{
    if ($event === null) {
        return;
    }
    $event['zayavka_ids'] = array_values($event['zayavka_ids']);
    $event['request_count'] = count($event['zayavka_ids']);
    $event['stock_after'] = whLedgerSnapshot($stock);
    $events[] = $event;
    $event = null;
}

$warehouses = [];
$pageError = '';

try {
    $warehouseRows = $pdo->query('SELECT id, name FROM warehouses ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
    foreach ((array)$warehouseRows as $warehouseRow) {
        $warehouses[(int)$warehouseRow['id']] = trim((string)$warehouseRow['name']);
    }
} catch (Throwable $e) {
    $pageError = 'Не удалось загрузить список складов.';
}

$warehouseId = whLedgerGetInt('warehouse_id');
if ($warehouseId <= 0 || !isset($warehouses[$warehouseId])) {
    foreach ($warehouses as $candidateId => $candidateName) {
        if (whLedgerContains($candidateName, 'Красноярск')) {
            $warehouseId = $candidateId;
            break;
        }
    }
}
if (($warehouseId <= 0 || !isset($warehouses[$warehouseId])) && !empty($warehouses)) {
    $warehouseId = (int)array_key_first($warehouses);
}

$dateFrom = whLedgerDate('date_from');
$dateTo = whLedgerDate('date_to');
$operation = whLedgerGet('operation', 'all');
if (!in_array($operation, ['all', 'in', 'out'], true)) {
    $operation = 'all';
}
$limit = whLedgerGetInt('limit', 500);
if (!in_array($limit, [100, 200, 500, 1000], true)) {
    $limit = 500;
}

$events = [];
$stock = [];

if ($warehouseId > 0 && $pageError === '') {
    try {
        $sql = "SELECT
                    wm.id,
                    wm.flight_id,
                    wm.movement_type,
                    wm.zayavka_id,
                    wm.mass_netto,
                    wm.mass_brutto,
                    wm.volume,
                    wm.movement_date,
                    COALESCE(f.comment, '') AS flight_name,
                    COALESCE(d.full_name, '') AS driver_name,
                    COALESCE(d.vehicle_make_plate, '') AS vehicle_make_plate
                FROM warehouse_movements wm
                LEFT JOIN flights f ON f.id = wm.flight_id
                LEFT JOIN drivers d ON d.id = f.driver_id
                WHERE wm.status = 'active'
                  AND wm.warehouse_id = :warehouse_id
                  AND wm.movement_type IN ('receipt', 'transfer_in', 'issue', 'transfer_out')
                ORDER BY wm.movement_date ASC, wm.id ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':warehouse_id' => $warehouseId]);
        $movementRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $currentEvent = null;
        $currentEventKey = '';

        foreach ((array)$movementRows as $movementRow) {
            $movementType = (string)$movementRow['movement_type'];
            $movementDate = (string)$movementRow['movement_date'];
            $flightId = (int)$movementRow['flight_id'];
            $eventKey = $flightId . '|' . $movementType . '|' . $movementDate;

            if ($currentEvent !== null && $eventKey !== $currentEventKey) {
                whLedgerFinalizeEvent($events, $currentEvent, $stock);
            }

            if ($currentEvent === null) {
                $currentEventKey = $eventKey;
                $currentEvent = [
                    'first_movement_id' => (int)$movementRow['id'],
                    'flight_id' => $flightId,
                    'flight_name' => trim((string)$movementRow['flight_name']),
                    'driver_name' => trim((string)$movementRow['driver_name']),
                    'vehicle' => whLedgerPlate((string)$movementRow['vehicle_make_plate']),
                    'movement_type' => $movementType,
                    'movement_date' => $movementDate,
                    'zayavka_ids' => [],
                    'mass_netto' => 0.0,
                    'mass_brutto' => 0.0,
                    'volume' => 0.0,
                ];
            }

            $zayavkaId = (int)$movementRow['zayavka_id'];
            $massNetto = (float)($movementRow['mass_netto'] ?? 0);
            $massBrutto = (float)($movementRow['mass_brutto'] ?? 0);
            $volume = (float)($movementRow['volume'] ?? 0);
            $currentEvent['zayavka_ids'][$zayavkaId] = $zayavkaId;
            $sign = whLedgerIsInbound($movementType) ? 1 : -1;
            if (!isset($stock[$zayavkaId])) {
                $stock[$zayavkaId] = ['mass_netto' => 0.0, 'mass_brutto' => 0.0, 'volume' => 0.0];
            }
            $stock[$zayavkaId]['mass_netto'] += $sign * $massNetto;
            $stock[$zayavkaId]['mass_brutto'] += $sign * $massBrutto;
            $stock[$zayavkaId]['volume'] += $sign * $volume;
            $currentEvent['mass_netto'] += abs($massNetto);
            $currentEvent['mass_brutto'] += abs($massBrutto);
            $currentEvent['volume'] += abs($volume);
        }

        whLedgerFinalizeEvent($events, $currentEvent, $stock);
    } catch (Throwable $e) {
        $pageError = 'Не удалось загрузить движения склада.';
        if (function_exists('mapError')) {
            mapError('warehouse_operations failed', ['error' => $e->getMessage()]);
        }
    }
}

$currentStock = whLedgerSnapshot($stock);
$displayEvents = array_values(array_filter($events, static function (array $event) use ($dateFrom, $dateTo, $operation): bool {
    $eventDate = substr((string)$event['movement_date'], 0, 10);
    if ($dateFrom !== '' && $eventDate < $dateFrom) {
        return false;
    }
    if ($dateTo !== '' && $eventDate > $dateTo) {
        return false;
    }
    if ($operation === 'in' && !whLedgerIsInbound((string)$event['movement_type'])) {
        return false;
    }
    if ($operation === 'out' && !whLedgerIsOutbound((string)$event['movement_type'])) {
        return false;
    }
    return true;
}));
$displayEvents = array_reverse($displayEvents);
$foundCount = count($displayEvents);
$displayEvents = array_slice($displayEvents, 0, $limit);

?><!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Журнал склада</title>
    <style>
        body{margin:0;background:#f4f1ea;color:#26231f;font-family:Arial,sans-serif}
        .wrap{max-width:calc(100vw - 36px);margin:0 auto;padding:14px 0 32px}
        .top{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:12px}
        .page-title{margin:0;font-size:23px}.page-subtitle{margin:5px 0 0;color:#6e675f;font-size:13px}
        .card{background:#fff;border:1px solid #d9d4cb;border-radius:8px;padding:14px;margin-bottom:12px}
        .filters{display:grid;grid-template-columns:minmax(210px,1.4fr) repeat(2,minmax(135px,.8fr)) minmax(170px,1fr) minmax(110px,.6fr) auto;gap:10px;align-items:end}
        .field{display:grid;gap:5px}.field label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#70695f}
        input,select{height:38px;border:1px solid #cfc8bc;border-radius:6px;padding:7px 9px;background:#fff;color:#26231f;box-sizing:border-box;width:100%}
        .btn{height:38px;border:1px solid #8c7f6f;border-radius:6px;background:#786b5c;color:#fff;font-weight:700;padding:0 16px;cursor:pointer}
        .summary{display:grid;grid-template-columns:repeat(4,minmax(160px,1fr));gap:10px;margin-bottom:12px}
        .summary-item{background:#fff;border:1px solid #d9d4cb;border-radius:8px;padding:14px}.summary-value{font-size:25px;font-weight:800;margin-bottom:3px}.summary-label{font-size:11px;color:#70695f;text-transform:uppercase;letter-spacing:.04em}
        .warehouse-line{font-size:13px;line-height:1.6}.warehouse-line strong{font-size:15px}.request-list{font-family:Consolas,monospace;font-size:12px;overflow-wrap:anywhere}
        .notice{background:#fff8e6;border:1px solid #e9cf8c;color:#73561c;border-radius:8px;padding:11px 13px;margin-bottom:12px;font-size:12px}
        .error{background:#fff0f0;border:1px solid #e9aaaa;color:#8b2424;border-radius:8px;padding:12px;margin-bottom:12px}
        .table-wrap{overflow:auto;background:#fff;border:1px solid #d9d4cb;border-radius:8px}
        table{width:100%;border-collapse:collapse;min-width:1420px}th,td{padding:10px;border-right:1px solid #ebe7df;border-bottom:1px solid #e3ded5;text-align:left;vertical-align:top;font-size:12px;line-height:1.45}th{position:sticky;top:0;background:#d8d2c8;color:#39342e;text-transform:uppercase;letter-spacing:.06em;font-size:10px;z-index:2}tbody tr:nth-child(even){background:#faf8f4}tbody tr:hover{background:#fff9e9}
        .flight-id{font-weight:800;white-space:nowrap}.flight-name{font-weight:700;max-width:240px}.driver{max-width:220px}.vehicle{font-weight:700;white-space:nowrap}.operation{font-weight:700;white-space:nowrap}.operation.in,.number.in{color:#13713f}.operation.out,.number.out{color:#b13c30}.date{white-space:nowrap}.ids{min-width:260px;max-width:380px;font-weight:600}.number{text-align:center;font-weight:800;white-space:nowrap}.stock-number{text-align:center;font-weight:800;white-space:nowrap}.muted{color:#777067;font-size:11px}.legend{display:flex;gap:18px;flex-wrap:wrap;margin-top:8px;color:#70695f;font-size:11px}
        @media(max-width:1100px){.filters{grid-template-columns:repeat(3,1fr)}.summary{grid-template-columns:repeat(2,1fr)}}
        @media(max-width:700px){.wrap{max-width:calc(100vw - 18px)}.filters{grid-template-columns:1fr 1fr}.summary{grid-template-columns:1fr 1fr}.page-title{font-size:20px}}
        @media print{.admin-nav-shell,.filters-card,.legend{display:none!important}.wrap{max-width:none;padding:0}.table-wrap{overflow:visible}table{min-width:0}th,td{font-size:8px;padding:4px}.card,.table-wrap{border:0}}
    </style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <div>
            <h1 class="page-title">Журнал движений склада</h1>
            <p class="page-subtitle">Какой рейс, водитель и машина привезли или вывезли груз.</p>
        </div>
        <?php renderAdminNav('warehouse_operations'); ?>
    </div>

    <?php if ($pageError !== ''): ?>
        <div class="error"><?= whLedgerHtml($pageError) ?></div>
    <?php endif; ?>

    <div class="card filters-card">
        <form method="get" class="filters">
            <div class="field"><label for="warehouse_id">Склад</label><select id="warehouse_id" name="warehouse_id">
                <?php foreach ($warehouses as $id => $name): ?>
                    <option value="<?= (int)$id ?>" <?= $warehouseId === (int)$id ? 'selected' : '' ?>><?= whLedgerHtml($name) ?></option>
                <?php endforeach; ?>
            </select></div>
            <div class="field"><label for="date_from">Дата с</label><input id="date_from" type="date" name="date_from" value="<?= whLedgerHtml($dateFrom) ?>"></div>
            <div class="field"><label for="date_to">Дата по</label><input id="date_to" type="date" name="date_to" value="<?= whLedgerHtml($dateTo) ?>"></div>
            <div class="field"><label for="operation">Операция</label><select id="operation" name="operation">
                <option value="all" <?= $operation === 'all' ? 'selected' : '' ?>>Все операции</option>
                <option value="in" <?= $operation === 'in' ? 'selected' : '' ?>>Только поступления</option>
                <option value="out" <?= $operation === 'out' ? 'selected' : '' ?>>Только списания</option>
            </select></div>
            <div class="field"><label for="limit">Строк</label><select id="limit" name="limit">
                <?php foreach ([100, 200, 500, 1000] as $limitOption): ?>
                    <option value="<?= $limitOption ?>" <?= $limit === $limitOption ? 'selected' : '' ?>><?= $limitOption ?></option>
                <?php endforeach; ?>
            </select></div>
            <button class="btn" type="submit">Показать</button>
        </form>
    </div>

    <div class="summary">
        <div class="summary-item"><div class="summary-value"><?= $currentStock['request_count'] ?></div><div class="summary-label">Заявок на складе</div></div>
        <div class="summary-item"><div class="summary-value"><?= whLedgerKg((float)$currentStock['mass_netto']) ?></div><div class="summary-label">Масса нетто, кг</div></div>
        <div class="summary-item"><div class="summary-value"><?= whLedgerKg((float)$currentStock['mass_brutto']) ?></div><div class="summary-label">Масса брутто, кг</div></div>
        <div class="summary-item"><div class="summary-value"><?= number_format((float)$currentStock['volume'], 3, ',', ' ') ?></div><div class="summary-label">Объём, м³</div></div>
    </div>

    <div class="card warehouse-line">
        <strong><?= whLedgerHtml($warehouses[$warehouseId] ?? ('Склад #' . $warehouseId)) ?></strong>
        · Найдено операций: <strong><?= $foundCount ?></strong>
        · Сейчас на складе заявок: <strong><?= $currentStock['request_count'] ?></strong>
        · Общий вес нетто: <strong><?= whLedgerKg((float)$currentStock['mass_netto']) ?> кг</strong>
        <?php if (!empty($currentStock['request_ids'])): ?>
            <div class="request-list">Заявки на складе: <?= whLedgerHtml(implode(', ', $currentStock['request_ids'])) ?></div>
        <?php endif; ?>
        <div class="legend"><span>Расчёт FREGAT: приходы минус расходы из <code>warehouse_movements</code>. Изменения состава рейсов в ERP в этот расчёт не подмешиваются.</span></div>
    </div>

    <?php if ((int)$currentStock['negative_count'] > 0): ?>
        <div class="notice">
            Контроль данных: у <strong><?= (int)$currentStock['negative_count'] ?></strong> заявок получился отрицательный остаток. Проверьте последовательность поступлений и списаний.
        </div>
    <?php endif; ?>

    <div class="table-wrap">
        <table>
            <thead><tr>
                <th>ID</th><th>Наименование</th><th>Водитель</th><th>Машина</th><th>Операция</th><th>Дата движения</th><th>Заявки</th><th>Кол-во</th><th>Масса, кг</th><th>Заявки на складе</th><th>Вес на складе, кг</th>
            </tr></thead>
            <tbody>
            <?php if (empty($displayEvents)): ?>
                <tr><td colspan="11" class="muted">Движения не найдены.</td></tr>
            <?php else: ?>
                <?php foreach ($displayEvents as $event):
                    $isInbound = whLedgerIsInbound((string)$event['movement_type']);
                ?>
                <tr>
                    <td class="flight-id">#<?= (int)$event['flight_id'] ?></td>
                    <td class="flight-name"><?= whLedgerHtml($event['flight_name'] !== '' ? $event['flight_name'] : ('Рейс #' . $event['flight_id'])) ?></td>
                    <td class="driver"><?= whLedgerHtml($event['driver_name'] !== '' ? $event['driver_name'] : '—') ?></td>
                    <td class="vehicle"><?= whLedgerHtml($event['vehicle']) ?></td>
                    <td class="operation <?= $isInbound ? 'in' : 'out' ?>"><?= whLedgerHtml(whLedgerOperationLabel((string)$event['movement_type'])) ?></td>
                    <td class="date"><?= whLedgerHtml($event['movement_date'] ? date('d.m.Y', strtotime((string)$event['movement_date'])) : '—') ?></td>
                    <td class="ids">
                        <?= whLedgerHtml(implode(', ', $event['zayavka_ids'])) ?>
                    </td>
                    <td class="number <?= $isInbound ? 'in' : 'out' ?>"><?= (int)$event['request_count'] ?></td>
                    <td class="number <?= $isInbound ? 'in' : 'out' ?>"><?= whLedgerKg((float)$event['mass_netto']) ?></td>
                    <td class="stock-number"><?= (int)$event['stock_after']['request_count'] ?></td>
                    <td class="stock-number"><?= whLedgerKg((float)$event['stock_after']['mass_netto']) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
