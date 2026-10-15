<?php
session_start();
require_once 'config.php';
require_once __DIR__ . '/../../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

// Подключение файла с расчётом стоимости
require_once 'include/price.php';

// Подключение к БД
try {
$dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset=utf8mb4";
$pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
die("Ошибка подключения к БД: " . $e->getMessage());
}

// Создание таблицы, если не существует
createTableIfNotExists($pdo);

function createTableIfNotExists($pdo) {
$sql = "CREATE TABLE IF NOT EXISTS feo (
id INT AUTO_INCREMENT PRIMARY KEY,
zayavka_id VARCHAR(20),
data_zayavki DATE,
polnoe_naim_zakazchika TEXT,
ur_adres_zakazchika TEXT,
inn_zakazchika VARCHAR(20),
nomer_dogovora VARCHAR(50),
data_dogovora DATE,
gruzopoluchatel TEXT,
inn_op_gruzopoluchatelya VARCHAR(20),
adres_dostavki_otkhodov TEXT,
ur_adres_op_gruzopoluchatelya TEXT,
inn_oo_gruzootpravitel VARCHAR(20),
naim_oo_gruzootpravitel TEXT,
ur_adres_oo_gruzootpravitelya TEXT,
mno_region VARCHAR(100),
mno_mo VARCHAR(100),
mno_adres_pogruzki TEXT,
naim_otkhoda_fkko TEXT,
kol_vo_mest INT,
vid_tary VARCHAR(100),
mass_netto DECIMAL(10,3),
mass_brutto DECIMAL(10,3),
dlina_mesta DECIMAL(10,2),
shirina_mesta DECIMAL(10,2),
vysota_mesta DECIMAL(10,2),
summarnyy_obem DECIMAL(10,2),
nomer_opasnogo_gr VARCHAR(20),
adres_vygruzki_otkhodov TEXT,
naim_ot_gruzoperevozchik TEXT,
inn_ot VARCHAR(20),
ur_adres_ot TEXT,
data_sozdaniya_porucheniya DATE,
zakaz_s_ot_status VARCHAR(50),
nalichie_transport_nakl VARCHAR(10),
zakaz_s_op_status VARCHAR(50),
id_zakaza_s_op VARCHAR(20),
zakaz_s_ot_data_vygruzki DATE,
zayavka_zhdv_po DATE,
kontakt_tel VARCHAR(100),
tel_dopolnitelnyy VARCHAR(100),
kontakt_email VARCHAR(100),
mno_sh VARCHAR(50),
mno_d VARCHAR(50),
zakaz_s_op_stoimost DECIMAL(10,2) DEFAULT NULL,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
UNIQUE KEY unique_zayavka (zayavka_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
$pdo->exec($sql);
}

// Получаем статусы для отображения (из таблицы status)
$statusMap = [];
try {
    $stmt = $pdo->query("SELECT * FROM status ORDER BY id");
    $statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($statuses as $s) {
        $statusMap[$s['статус']] = $s;
    }
} catch (Exception $e) {}

// Функция форматирования дат рейса (из indexm.php)
function formatFlightDates($flightData) {
    if (!empty($flightData['actual_start_date']) || !empty($flightData['actual_end_date'])) {
        $parts = [];
        if (!empty($flightData['actual_start_date'])) {
            $parts[] = 'с ' . date('d.m.Y', strtotime($flightData['actual_start_date']));
        }
        if (!empty($flightData['actual_end_date'])) {
            $parts[] = 'по ' . date('d.m.Y', strtotime($flightData['actual_end_date']));
        }
        return implode(' ', $parts);
    }
    if (!empty($flightData['planned_start_date'])) {
        return date('d.m.Y', strtotime($flightData['planned_start_date']));
    }
    if (!empty($flightData['planned_start_date_from']) && !empty($flightData['planned_start_date_to'])) {
        return date('d.m.Y', strtotime($flightData['planned_start_date_from'])) . ' - ' . date('d.m.Y', strtotime($flightData['planned_start_date_to']));
    }
    if (!empty($flightData['planned_start_date_from'])) {
        return 'с ' . date('d.m.Y', strtotime($flightData['planned_start_date_from']));
    }
    if (!empty($flightData['planned_start_date_to'])) {
        return 'по ' . date('d.m.Y', strtotime($flightData['planned_start_date_to']));
    }
    return '—';
}

function formatFlightNumber($flightId, $flightData) {
    $number = '#' . (int)$flightId;
    $routeType = strtolower(trim((string)($flightData['route_type'] ?? '')));
    $unloadType = strtoupper(trim((string)($flightData['unload_type'] ?? '')));

    if (in_array($routeType, ['warehouse_to_warehouse', 'warehouse_to_utilizer'], true)) {
        return '(с)' . $number;
    }
    if ($routeType === 'mixed_to_utilizer') {
        return '(ОО+с)' . $number;
    }
    if ($routeType === 'generator_to_warehouse' || ($routeType === '' && $unloadType === 'SKLAD')) {
        return $number . '(с)';
    }
    return $number;
}

// AJAX-запрос для формирования «Списка заявок в работе»
// Берём только доступные заявки из блоков с ID > 105.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax']) && $_GET['ajax'] === 'get_work_zayavki') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $stmt = $pdo->query("
            SELECT id, zayavki_ids
            FROM status_blocks
            WHERE status_type = 'dostupno'
              AND id > 109
            ORDER BY id ASC
        ");
        $blocks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $workZayavkiIds = [];
        $seenZayavkiIds = [];

        foreach ($blocks as $block) {
            $zayavkiIds = preg_split('/[,;\s]+/', (string)($block['zayavki_ids'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($zayavkiIds as $zayavkaId) {
                $zayavkaId = preg_replace('/[^0-9]/', '', trim($zayavkaId));
                if ($zayavkaId === '' || isset($seenZayavkiIds[$zayavkaId])) {
                    continue;
                }
                $seenZayavkiIds[$zayavkaId] = true;
                $workZayavkiIds[] = $zayavkaId;
            }
        }

        echo json_encode([
            'success' => true,
            'zayavki_ids' => $workZayavkiIds,
            'count' => count($workZayavkiIds),
            'block_id_min_exclusive' => 105
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// AJAX-сводка дублей: отдельно внутри блоков «Доступно» и отдельно внутри блоков «Маршрут».
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax']) && $_GET['ajax'] === 'check_status_block_duplicates') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $stmt = $pdo->query("
            SELECT id, status_type, zayavki_ids
            FROM status_blocks
            WHERE status_type IN ('dostupno', 'marshrut')
            ORDER BY id ASC
        ");
        $blocks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $maps = [
            'dostupno' => [],
            'marshrut' => []
        ];

        foreach ($blocks as $block) {
            $statusType = $block['status_type'];
            $zayavkiIds = preg_split('/[,;\s]+/', (string)($block['zayavki_ids'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($zayavkiIds as $zayavkaId) {
                $zayavkaId = preg_replace('/[^0-9]/', '', trim($zayavkaId));
                if ($zayavkaId === '') continue;
                if (!isset($maps[$statusType][$zayavkaId])) {
                    $maps[$statusType][$zayavkaId] = [];
                }
                $maps[$statusType][$zayavkaId][] = (int)$block['id'];
            }
        }

        $duplicates = [];
        $allIds = array_values(array_unique(array_merge(array_keys($maps['dostupno']), array_keys($maps['marshrut']))));
        sort($allIds, SORT_NUMERIC);

        foreach ($allIds as $zayavkaId) {
            $availableBlocks = isset($maps['dostupno'][$zayavkaId]) ? array_values(array_unique($maps['dostupno'][$zayavkaId])) : [];
            $routeBlocks = isset($maps['marshrut'][$zayavkaId]) ? array_values(array_unique($maps['marshrut'][$zayavkaId])) : [];
            sort($availableBlocks, SORT_NUMERIC);
            sort($routeBlocks, SORT_NUMERIC);

            if (count($availableBlocks) > 1 || count($routeBlocks) > 1) {
                $duplicates[] = [
                    'zayavka_id' => $zayavkaId,
                    'dostupno_blocks' => $availableBlocks,
                    'marshrut_blocks' => $routeBlocks
                ];
            }
        }

        echo json_encode([
            'success' => true,
            'has_duplicates' => !empty($duplicates),
            'duplicates' => $duplicates
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// AJAX-запрос для получения заявок с фильтрацией (бесконечная прокрутка)
if (isset($_REQUEST['ajax']) && $_REQUEST['ajax'] === 'get_zayavki' && in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
header('Content-Type: application/json');
try {
$offset = isset($_REQUEST['offset']) ? (int)$_REQUEST['offset'] : 0;
$limit = isset($_REQUEST['limit']) ? min(100, max(10, (int)$_REQUEST['limit'])) : 50;
$filter_zayavki = isset($_REQUEST['filter_zayavki']) ? trim($_REQUEST['filter_zayavki']) : '';
$show_only_available = isset($_REQUEST['show_only_available']) && $_REQUEST['show_only_available'] === '1';
$show_only_marshrut = isset($_REQUEST['show_only_marshrut']) && $_REQUEST['show_only_marshrut'] === '1';
$show_only_flight = isset($_REQUEST['show_only_flight']) && $_REQUEST['show_only_flight'] === '1';

// Получаем доступные заявки (status_type = 'dostupno')
$availableZayavkiIds = [];
$zayavkaToAvailableBlockMap = [];
if ($show_only_available) {
try {
$stmt = $pdo->query("
SELECT id, zayavki_ids
FROM status_blocks
WHERE status_type = 'dostupno'
");
$blocks = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($blocks as $block) {
$zayavkiIds = preg_split('/[,;\s]+/', (string)($block['zayavki_ids'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
foreach ($zayavkiIds as $zayavkaId) {
$zayavkaId = preg_replace('/[^0-9]/', '', trim($zayavkaId));
if ($zayavkaId === '') continue;
$availableZayavkiIds[] = $zayavkaId;
// Храним все доступные блоки заявки, чтобы не терять информацию при дублях.
if (!isset($zayavkaToAvailableBlockMap[$zayavkaId])) {
$zayavkaToAvailableBlockMap[$zayavkaId] = [];
}
$zayavkaToAvailableBlockMap[$zayavkaId][] = (int)$block['id'];
}
}
$availableZayavkiIds = array_values(array_unique($availableZayavkiIds));
} catch (Exception $e) {}
}

// Получаем заявки в маршрутах (status_type = 'marshrut')
$marshrutZayavkiIds = [];
$zayavkaToMarshrutMap = [];
if ($show_only_marshrut) {
try {
$stmt = $pdo->query("
SELECT id, zayavki_ids
FROM status_blocks
WHERE status_type = 'marshrut'
");
$blocks = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($blocks as $block) {
$zayavkiIds = preg_split('/[,;\s]+/', (string)($block['zayavki_ids'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
foreach ($zayavkiIds as $zayavkaId) {
$zayavkaId = preg_replace('/[^0-9]/', '', trim($zayavkaId));
if ($zayavkaId === '') continue;
$marshrutZayavkiIds[] = $zayavkaId;
if (!isset($zayavkaToMarshrutMap[$zayavkaId])) {
$zayavkaToMarshrutMap[$zayavkaId] = [];
}
$zayavkaToMarshrutMap[$zayavkaId][] = (int)$block['id'];
}
}
$marshrutZayavkiIds = array_values(array_unique($marshrutZayavkiIds));
} catch (Exception $e) {}
}

// Получаем заявки в рейсах + детали рейсов
$flightZayavkiIds = [];
$zayavkaToFlightMap = [];
$flightDetailsMap = [];
if ($show_only_flight) {
try {
$stmt = $pdo->query("
SELECT id, zayavki_ids, comment, status, unload_type, route_type,
planned_start_date, planned_start_date_from, planned_start_date_to,
actual_start_date, actual_end_date
FROM flights
");
$flights = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($flights as $flight) {
$zayavkiIds = array_filter(explode(',', $flight['zayavki_ids']));
foreach ($zayavkiIds as $zayavkaId) {
$zayavkaId = trim($zayavkaId);
if ($zayavkaId === '') continue;
$flightZayavkiIds[] = $zayavkaId;
if (!isset($zayavkaToFlightMap[$zayavkaId])) {
$zayavkaToFlightMap[$zayavkaId] = [];
}
$zayavkaToFlightMap[$zayavkaId][] = (int)$flight['id'];
}
$flightDetailsMap[$flight['id']] = [
'comment' => $flight['comment'],
'status' => $flight['status'],
'unload_type' => $flight['unload_type'] ?? '',
'route_type' => $flight['route_type'] ?? '',
'planned_start_date' => $flight['planned_start_date'],
'planned_start_date_from' => $flight['planned_start_date_from'],
'planned_start_date_to' => $flight['planned_start_date_to'],
'actual_start_date' => $flight['actual_start_date'],
'actual_end_date' => $flight['actual_end_date']
];
}
$flightZayavkiIds = array_unique($flightZayavkiIds);
} catch (Exception $e) {}
}

// Парсим номера заявок из фильтра
$zayavki_ids = [];
$has_filter = false;
if (!empty($filter_zayavki)) {
$has_filter = true;
$parts = preg_split('/[,;\s]+/', $filter_zayavki);
foreach ($parts as $part) {
$part = trim($part);
if (empty($part)) continue;
$clean_part = preg_replace('/[^0-9]/', '', $part);
if (!empty($clean_part)) {
$zayavki_ids[] = $clean_part;
}
}
$zayavki_ids = array_unique($zayavki_ids);
}

// Построение запроса
$where_conditions = [];
$params = [];
if ($has_filter && !empty($zayavki_ids)) {
$placeholders = implode(',', array_fill(0, count($zayavki_ids), '?'));
$where_conditions[] = "zayavka_id IN ($placeholders)";
$params = array_merge($params, $zayavki_ids);
}
if ($show_only_available && !empty($availableZayavkiIds)) {
$placeholders = implode(',', array_fill(0, count($availableZayavkiIds), '?'));
$where_conditions[] = "zayavka_id IN ($placeholders)";
$params = array_merge($params, $availableZayavkiIds);
} elseif ($show_only_available && empty($availableZayavkiIds)) {
echo json_encode([
'success' => true, 'html' => '', 'total' => 0, 'offset' => 0, 'limit' => $limit,
'has_more' => false, 'has_filter' => $has_filter, 'filter_count' => count($zayavki_ids),
'found_zayavki' => [], 'missing_zayavki' => $has_filter ? $zayavki_ids : [],
'show_only_available' => true, 'show_only_marshrut' => false, 'show_only_flight' => false
]);
exit;
}
if ($show_only_marshrut && !empty($marshrutZayavkiIds)) {
$placeholders = implode(',', array_fill(0, count($marshrutZayavkiIds), '?'));
$where_conditions[] = "zayavka_id IN ($placeholders)";
$params = array_merge($params, $marshrutZayavkiIds);
} elseif ($show_only_marshrut && empty($marshrutZayavkiIds)) {
echo json_encode([
'success' => true, 'html' => '', 'total' => 0, 'offset' => 0, 'limit' => $limit,
'has_more' => false, 'has_filter' => $has_filter, 'filter_count' => count($zayavki_ids),
'found_zayavki' => [], 'missing_zayavki' => $has_filter ? $zayavki_ids : [],
'show_only_available' => false, 'show_only_marshrut' => true, 'show_only_flight' => false
]);
exit;
}
if ($show_only_flight && !empty($flightZayavkiIds)) {
$placeholders = implode(',', array_fill(0, count($flightZayavkiIds), '?'));
$where_conditions[] = "zayavka_id IN ($placeholders)";
$params = array_merge($params, $flightZayavkiIds);
} elseif ($show_only_flight && empty($flightZayavkiIds)) {
echo json_encode([
'success' => true, 'html' => '', 'total' => 0, 'offset' => 0, 'limit' => $limit,
'has_more' => false, 'has_filter' => $has_filter, 'filter_count' => count($zayavki_ids),
'found_zayavki' => [], 'missing_zayavki' => $has_filter ? $zayavki_ids : [],
'show_only_available' => false, 'show_only_marshrut' => false, 'show_only_flight' => true
]);
exit;
}

$where_sql = "";
if (!empty($where_conditions)) {
$where_sql = "WHERE " . implode(' AND ', $where_conditions);
}

$count_sql = "SELECT COUNT(*) as total, COALESCE(SUM(mass_netto), 0) * 1000 as total_mass_kg FROM feo $where_sql";
$stmt = $pdo->prepare($count_sql);
$stmt->execute($params);
$count_row = $stmt->fetch(PDO::FETCH_ASSOC);
$total = $count_row['total'];
$total_mass_kg = (float)$count_row['total_mass_kg'];

$found_zayavki = [];
if ($has_filter && !empty($zayavki_ids)) {
$found_sql = "SELECT zayavka_id FROM feo $where_sql";
$stmt = $pdo->prepare($found_sql);
$stmt->execute($params);
$found_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$found_zayavki = array_column($found_rows, 'zayavka_id');
}

$data_sql = "SELECT * FROM feo $where_sql ORDER BY id DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
$stmt = $pdo->prepare($data_sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Получаем актуальный маппинг для отображения
$zayavkaToAvailableBlockMap = [];
$zayavkaToMarshrutMap = [];
$zayavkaToFlightMap = [];
$flightDetailsMap = [];
try {
$stmt = $pdo->query("SELECT id, zayavki_ids, status_type FROM status_blocks WHERE status_type IN ('dostupno', 'marshrut')");
$blocks = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($blocks as $block) {
$zayavkiIds = preg_split('/[,;\s]+/', (string)($block['zayavki_ids'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
foreach ($zayavkiIds as $zayavkaId) {
$zayavkaId = preg_replace('/[^0-9]/', '', trim($zayavkaId));
if ($zayavkaId === '') continue;
if ($block['status_type'] === 'dostupno') {
    // Сохраняем ВСЕ доступные блоки для заявки.
    if (!isset($zayavkaToAvailableBlockMap[$zayavkaId])) {
        $zayavkaToAvailableBlockMap[$zayavkaId] = [];
    }
    $zayavkaToAvailableBlockMap[$zayavkaId][] = (int)$block['id'];
}
if ($block['status_type'] === 'marshrut') {
    // Сохраняем ВСЕ маршрутные блоки для заявки.
    if (!isset($zayavkaToMarshrutMap[$zayavkaId])) {
        $zayavkaToMarshrutMap[$zayavkaId] = [];
    }
    $zayavkaToMarshrutMap[$zayavkaId][] = (int)$block['id'];
}
}
}
} catch (Exception $e) {}

try {
$stmt = $pdo->query("SELECT id, zayavki_ids, comment, status, unload_type, route_type, planned_start_date, planned_start_date_from, planned_start_date_to, actual_start_date, actual_end_date FROM flights");
$flights = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($flights as $flight) {
$zayavkiIds = array_filter(explode(',', $flight['zayavki_ids']));
foreach ($zayavkiIds as $zayavkaId) {
$zayavkaId = trim($zayavkaId);
if ($zayavkaId === '') continue;
if (!isset($zayavkaToFlightMap[$zayavkaId])) {
    $zayavkaToFlightMap[$zayavkaId] = [];
}
$zayavkaToFlightMap[$zayavkaId][] = (int)$flight['id'];
}
$flightDetailsMap[$flight['id']] = [
'comment' => $flight['comment'], 'status' => $flight['status'],
'unload_type' => $flight['unload_type'] ?? '', 'route_type' => $flight['route_type'] ?? '',
'planned_start_date' => $flight['planned_start_date'],
'planned_start_date_from' => $flight['planned_start_date_from'],
'planned_start_date_to' => $flight['planned_start_date_to'],
'actual_start_date' => $flight['actual_start_date'],
'actual_end_date' => $flight['actual_end_date']
];
}
} catch (Exception $e) {}

$html = '';
foreach ($rows as $row) {
$zayavka_id = $row['zayavka_id'] ?? '';
$available_block_ids = isset($zayavkaToAvailableBlockMap[$zayavka_id]) ? array_values(array_unique($zayavkaToAvailableBlockMap[$zayavka_id])) : [];
if (!empty($available_block_ids)) sort($available_block_ids, SORT_NUMERIC);
$marshrut_ids = isset($zayavkaToMarshrutMap[$zayavka_id]) ? array_values(array_unique($zayavkaToMarshrutMap[$zayavka_id])) : [];
if (!empty($marshrut_ids)) sort($marshrut_ids, SORT_NUMERIC);
$flight_ids = isset($zayavkaToFlightMap[$zayavka_id]) ? array_values(array_unique($zayavkaToFlightMap[$zayavka_id])) : [];
if (!empty($flight_ids)) sort($flight_ids, SORT_NUMERIC);

$mass_kg = isset($row['mass_netto']) && $row['mass_netto'] !== '' ? floatval($row['mass_netto']) * 1000 : '';
$mass_display = $mass_kg !== '' ? number_format($mass_kg, 0, '.', ' ') : '';

if (!empty($available_block_ids)) {
    $available_block_labels = array_map(function($blockId) {
        return 'Блок #' . htmlspecialchars((string)$blockId);
    }, $available_block_ids);
    $available_display = '<span class="status-available">' . implode('<br>', $available_block_labels) . '</span>';
} else {
    $available_display = '<span style="color:#999;">—</span>';
}

if (!empty($marshrut_ids)) {
    $marshrut_block_labels = array_map(function($blockId) {
        return 'М#' . htmlspecialchars((string)$blockId);
    }, $marshrut_ids);
    $marshrut_display = '<span class="status-marshrut">' . implode(', ', $marshrut_block_labels) . '</span>';
} else {
    $marshrut_display = '<span style="color:#999;">—</span>';
}
$flight_display = '<span style="color:#999;">—</span>';
if (!empty($flight_ids)) {
    $flight_labels = [];
    foreach ($flight_ids as $flightId) {
        $flightData = $flightDetailsMap[$flightId] ?? [];
        $flight_labels[] = htmlspecialchars(formatFlightNumber($flightId, $flightData));
    }
    $flight_display = '<span class="status-flight">' . implode('<br>', $flight_labels) . '</span>';
}

// Статус
$statusDisplay = '<span style="color:#999;">—</span>';
if (!empty($flight_ids)) {
    $statusLabels = [];
    foreach ($flight_ids as $flight_id) {
        if (!isset($flightDetailsMap[$flight_id])) continue;
        $flightStatus = $flightDetailsMap[$flight_id]['status'];
        $statusData = isset($statusMap[$flightStatus]) ? $statusMap[$flightStatus] : null;
        if ($statusData) {
            $styleClass = ltrim($statusData['style'], '.');
            $statusLabels[] = '<span class="status-badge ' . htmlspecialchars($styleClass) . '">' . htmlspecialchars($statusData['наименование']) . '</span>';
        } else {
            $statusLabels[] = '<span class="status-badge" style="background: #6c757d;">' . htmlspecialchars($flightStatus) . '</span>';
        }
    }
    if (!empty($statusLabels)) $statusDisplay = implode('<br>', $statusLabels);
}

// Даты
$datesDisplay = '<span style="color:#999;">—</span>';
if (!empty($flight_ids)) {
    $dateLabels = [];
    foreach ($flight_ids as $flight_id) {
        if (!isset($flightDetailsMap[$flight_id])) continue;
        $dateLabels[] = formatFlightDates($flightDetailsMap[$flight_id]);
    }
    if (!empty($dateLabels)) $datesDisplay = implode('<br>', $dateLabels);
}

// СТОИМОСТЬ и ₽/КГ
$costDisplay = '<span style="color:#999;">—</span>';
$pricePerKgDisplay = '<span style="color:#999;">—</span>';
if (isset($price[$zayavka_id])) {
    $costDisplay = number_format($price[$zayavka_id], 0, '.', ' ') . ' ₽';
}
if (isset($price_per_kg[$zayavka_id])) {
    $pricePerKgDisplay = number_format($price_per_kg[$zayavka_id], 2, '.', ' ');
}

$html .= '<tr data-id="' . htmlspecialchars($row['id']) . '" data-zayavka-id="' . htmlspecialchars($row['zayavka_id']) . '">';
$html .= '<td style="width: 80px;">' . htmlspecialchars($row['zayavka_id'] ?? '') . '</td>';
$html .= '<td style="width: 90px; text-align: right;">' . $mass_display . '</td>';
$html .= '<td style="width: 150px;">' . htmlspecialchars($row['mno_region'] ?? '') . '</td>';
$html .= '<td style="width: 150px;">' . htmlspecialchars($row['mno_mo'] ?? '') . '</td>';
$html .= '<td style="width: 200px;">' . htmlspecialchars($row['naim_oo_gruzootpravitel'] ?? '') . '</td>';
$html .= '<td style="text-align: left;">' . htmlspecialchars($row['mno_adres_pogruzki'] ?? '') . '</td>';
$html .= '<td style="width: 100px; text-align: center;">' . $available_display . '</td>';
$html .= '<td style="width: 100px; text-align: center;">' . $marshrut_display . '</td>';
$html .= '<td style="width: 150px; text-align: center;">' . $flight_display . '</td>';
$html .= '<td style="width: 140px; text-align: center;">' . $statusDisplay . '</td>';
$html .= '<td style="width: 180px; text-align: center; font-size: 12px;">' . $datesDisplay . '</td>';
$html .= '<td style="width: 100px; text-align: right;">' . $costDisplay . '</td>';
$html .= '<td style="width: 80px; text-align: right;">' . $pricePerKgDisplay . '</td>';
$html .= '<td style="width: 100px; text-align: center; white-space: nowrap;">
<button class="btn btn-sm" onclick="editRow(' . htmlspecialchars($row['id']) . ')">✎</button>
<button class="btn btn-sm btn-delete" onclick="deleteRow(' . htmlspecialchars($row['id']) . ', \'' . htmlspecialchars($row['zayavka_id']) . '\')">✖</button>
</td>';
$html .= '</tr>';
}

$missing_zayavki = [];
if ($has_filter && !empty($zayavki_ids)) {
$missing_zayavki = array_diff($zayavki_ids, $found_zayavki);
}

echo json_encode([
'success' => true,
'html' => $html,
'total' => $total,
'total_mass_kg' => $total_mass_kg,
'offset' => $offset,
'limit' => $limit,
'has_more' => ($offset + $limit) < $total,
'has_filter' => $has_filter,
'filter_count' => count($zayavki_ids),
'found_zayavki' => $found_zayavki,
'missing_zayavki' => array_values($missing_zayavki),
'show_only_available' => $show_only_available,
'show_only_marshrut' => $show_only_marshrut,
'show_only_flight' => $show_only_flight
]);
} catch (Exception $e) {
echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit;
}

// Обработка получения данных заявки для редактирования
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_row'])) {
header('Content-Type: application/json');
try {
$row_id = (int)$_GET['row_id'];
$stmt = $pdo->prepare("SELECT * FROM feo WHERE id = ?");
$stmt->execute([$row_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) throw new Exception('Заявка не найдена');
echo json_encode(['success' => true, 'row' => $row]);
} catch (Exception $e) {
echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit;
}

// Обработка обновления заявки
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_row'])) {
header('Content-Type: application/json');
try {
$row_id = (int)$_POST['row_id'];
$stmt = $pdo->prepare("DESCRIBE feo");
$stmt->execute();
$columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
$update_fields = [];
$params = [];
foreach ($columns as $column) {
if (in_array($column, ['id', 'created_at', 'updated_at'])) continue;
if (isset($_POST[$column])) {
$value = $_POST[$column];
if ($value === '') $value = null;
$update_fields[] = "$column = ?";
$params[] = $value;
}
}
if (empty($update_fields)) throw new Exception('Нет данных для обновления');
$params[] = $row_id;
$sql = "UPDATE feo SET " . implode(', ', $update_fields) . " WHERE id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
echo json_encode(['success' => true, 'message' => 'Заявка успешно обновлена']);
} catch (Exception $e) {
echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit;
}

// Обработка удаления заявки
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_row'])) {
header('Content-Type: application/json');
try {
$row_id = (int)$_POST['row_id'];
$stmt = $pdo->prepare("DELETE FROM feo WHERE id = ?");
$stmt->execute([$row_id]);
echo json_encode(['success' => true, 'message' => 'Заявка успешно удалена']);
} catch (Exception $e) {
echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit;
}

// Обработка загрузки файла
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
$response = ['success' => false, 'message' => ''];
try {
if (!isset($_POST['token']) || $_POST['token'] !== $_SESSION['upload_token']) throw new Exception('Недействительный токен');
$file = $_FILES['excel_file'];
if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception('Ошибка при загрузке файла');
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if ($ext !== 'xlsx') throw new Exception('Допустимы только файлы .xlsx');
$spreadsheet = IOFactory::load($file['tmp_name']);
$worksheet = $spreadsheet->getActiveSheet();
$rows = $worksheet->toArray();
if (empty($rows)) throw new Exception('Файл пуст');
$headers = array_shift($rows);
$normalizedHeaders = [];
foreach ($headers as $header) $normalizedHeaders[] = normalizeHeader($header);
$fieldMapping = getFieldMapping();
$reverseMapping = [];
foreach ($fieldMapping as $exactHeader => $fieldName) $reverseMapping[normalizeHeader($exactHeader)] = $fieldName;
$requiredFields = ['Заявка, ID', 'Юридический адрес заказчика (п.1а)', 'ИНН заказчика (п.1а)', 'Дата договора с ФГУП ФЭО (п.1а)', 'ИНН ОО (Грузоотправитель) (п.1)', 'Юридический адрес ОО (Грузоотправитель) (п.1)'];
$missingFields = [];
foreach ($requiredFields as $requiredField) {
$normalizedRequired = normalizeHeader($requiredField);
if (!in_array($normalizedRequired, $normalizedHeaders)) $missingFields[] = $requiredField;
}
if (!empty($missingFields)) throw new Exception('Отсутствуют обязательные поля: ' . implode(', ', $missingFields));
$columnMapping = [];
foreach ($normalizedHeaders as $index => $normalizedHeader) {
if (isset($reverseMapping[$normalizedHeader])) $columnMapping[$index] = $reverseMapping[$normalizedHeader];
}
$excelData = [];
$rowNum = 2;
foreach ($rows as $row) {
if (empty(array_filter($row))) continue;
$data = [];
foreach ($columnMapping as $colIndex => $fieldName) {
$value = $row[$colIndex] ?? '';
if (strpos($fieldName, 'data_') === 0 || strpos($fieldName, 'date') !== false || $fieldName == 'data_zayavki' || $fieldName == 'data_dogovora' || $fieldName == 'data_sozdaniya_porucheniya' || $fieldName == 'zakaz_s_ot_data_vygruzki' || $fieldName == 'zayavka_zhdv_po') {
if (is_numeric($value)) {
try { $value = Date::excelToDateTimeObject($value)->format('Y-m-d'); } catch (Exception $e) { $value = null; }
} else if (!empty($value)) {
$date = date_create($value);
$value = $date ? $date->format('Y-m-d') : null;
} else { $value = null; }
}
if (strpos($fieldName, 'mass_') === 0 || strpos($fieldName, 'kol_vo') === 0 || strpos($fieldName, 'dlina') === 0 || strpos($fieldName, 'shirina') === 0 || strpos($fieldName, 'vysota') === 0 || strpos($fieldName, 'summarnyy') === 0 || $fieldName == 'zakaz_s_op_stoimost') {
if (!empty($value)) { $value = str_replace(',', '.', $value); $value = is_numeric($value) ? $value : null; } else { $value = null; }
}
$data[$fieldName] = $value;
}
if (!empty($data['zayavka_id'])) { $data['_row_num'] = $rowNum; $excelData[] = $data; }
$rowNum++;
}
$_SESSION['upload_data'] = $excelData;
$analysis = analyzeData($pdo, $excelData);
$response['success'] = true;
$response['analysis'] = $analysis;
} catch (Exception $e) { $response['message'] = $e->getMessage(); }
header('Content-Type: application/json');
echo json_encode($response);
exit;
}

// Подтверждение загрузки - только новые
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_upload_new'])) {
$response = ['success' => false, 'message' => '', 'stats' => []];
try {
if (!isset($_SESSION['upload_data'])) throw new Exception('Данные не найдены');
$stats = processUploadNewOnly($pdo, $_SESSION['upload_data']);
unset($_SESSION['upload_data']);
$response['success'] = true; $response['stats'] = $stats;
} catch (Exception $e) { $response['message'] = $e->getMessage(); }
header('Content-Type: application/json');
echo json_encode($response);
exit;
}

// Подтверждение загрузки - перезаписать все
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_upload_overwrite'])) {
$response = ['success' => false, 'message' => '', 'stats' => []];
try {
if (!isset($_SESSION['upload_data'])) throw new Exception('Данные не найдены');
$stats = processUploadOverwrite($pdo, $_SESSION['upload_data']);
unset($_SESSION['upload_data']);
$response['success'] = true; $response['stats'] = $stats;
} catch (Exception $e) { $response['message'] = $e->getMessage(); }
header('Content-Type: application/json');
echo json_encode($response);
exit;
}

// Отмена загрузки
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_upload'])) {
unset($_SESSION['upload_data']);
exit;
}

$_SESSION['upload_token'] = bin2hex(random_bytes(32));

function normalizeHeader($header) { return $header === null ? '' : trim(preg_replace('/\s+/', ' ', $header)); }
function getFieldMapping() {
return [
'Заявка, ID' => 'zayavka_id', 'Дата заявки' => 'data_zayavki', 'Полное наименование заказчика (п.1а)' => 'polnoe_naim_zakazchika',
'Юридический адрес заказчика (п.1а)' => 'ur_adres_zakazchika', 'ИНН заказчика (п.1а)' => 'inn_zakazchika', '№ договора с ФГУП ФЭО (п. 1а)' => 'nomer_dogovora',
'Дата договора с ФГУП ФЭО (п.1а)' => 'data_dogovora', 'Заказ с ОП_Наименование организации (Грузополучатель) (п. 2)' => 'gruzopoluchatel',
'ИНН ОП Грузополучателя (п. 2)' => 'inn_op_gruzopoluchatelya', 'Адрес объекта обращения ОП Адрес места доставки отходов (п.2 транспртной накладной)' => 'adres_dostavki_otkhodov',
'Юридический адрес ОП Грузополучателя (п. 2)' => 'ur_adres_op_gruzopoluchatelya', 'ИНН ОО (Грузоотправитель) (п.1)' => 'inn_oo_gruzootpravitel',
'ОО_Наименование организации (Грузоотправитель) (п.1)' => 'naim_oo_gruzootpravitel', 'Юридический адрес ОО (Грузоотправитель) (п.1)' => 'ur_adres_oo_gruzootpravitelya',
'МНО, Регион' => 'mno_region', 'МНО, МО' => 'mno_mo', 'МНО, Адрес (адрес места погрузки отходов)' => 'mno_adres_pogruzki',
'Наименование отхода (код отхода по ФККО) (п. 3)' => 'naim_otkhoda_fkko', 'Количество грузовых мест, шт (п. 3)' => 'kol_vo_mest',
'Вид тары (п. 3)' => 'vid_tary', 'Суммарная масса (нетто), т (п. 3)' => 'mass_netto', 'Суммарная масса (брутто), т (п. 3)' => 'mass_brutto',
'Длина места (только если грузовое место одно) (п. 3)' => 'dlina_mesta', 'Ширина места (только если грузовое место одно) (п. 3)' => 'shirina_mesta',
'Высота места (только если грузовое место одно) (п. 3)' => 'vysota_mesta', 'Суммарный объем, куб.м (п. 3)' => 'summarnyy_obem',
'Номер опасного груза по классификации ООН (п. 3)' => 'nomer_opasnogo_gr', 'Адрес объекта обращения ОП Адрес места выгрузки отходов (п.10 транспортной накладной)' => 'adres_vygruzki_otkhodov',
'Наименование ОТ (Грузоперевозчик) (п. 6)' => 'naim_ot_gruzoperevozchik', 'ИНН ОТ (п. 6)' => 'inn_ot',
'Юридический адрес ОТ организации Перевозчика (п. 6)' => 'ur_adres_ot', 'Дата создания поручения' => 'data_sozdaniya_porucheniya',
'Заказ с ОТ, Статус' => 'zakaz_s_ot_status', 'Наличие транспортной накладной' => 'nalichie_transport_nakl',
'Заказ с ОП, Статус' => 'zakaz_s_op_status', 'ID заказа с ОП' => 'id_zakaza_s_op', 'Заказ с ОТ, дата выгрузки' => 'zakaz_s_ot_data_vygruzki',
'Заявка, ЖДВ (по)' => 'zayavka_zhdv_po', 'Контактные данные в МНО (Тел.)' => 'kontakt_tel', 'Телефон (дополнительный)' => 'tel_dopolnitelnyy',
'Контактные данные в МНО (Эл.почта)' => 'kontakt_email', 'МНО_Координаты (Ш)' => 'mno_sh', 'МНО_Координаты (Д)' => 'mno_d',
'Заказ с ОП, Стоимость услуг' => 'zakaz_s_op_stoimost'
];
}
function analyzeData($pdo, $excelData) {
$analysis = ['total' => count($excelData), 'new' => 0, 'existing' => 0];
foreach ($excelData as $index => $row) {
$zayavka_id = $row['zayavka_id'];
$stmt = $pdo->prepare("SELECT id FROM feo WHERE zayavka_id = ?");
$stmt->execute([$zayavka_id]);
$exists = $stmt->fetch();
if (!$exists) $analysis['new']++; else $analysis['existing']++;
}
return $analysis;
}
function processUploadNewOnly($pdo, $data) {
$stats = ['inserted' => 0, 'skipped' => 0];
$pdo->beginTransaction();
try {
foreach ($data as $row) {
$zayavka_id = $row['zayavka_id']; unset($row['_row_num']);
$stmt = $pdo->prepare("SELECT id FROM feo WHERE zayavka_id = ?");
$stmt->execute([$zayavka_id]);
$exists = $stmt->fetch();
if (!$exists) {
$fields = implode(', ', array_keys($row)); $placeholders = ':' . implode(', :', array_keys($row));
$sql = "INSERT INTO feo ($fields) VALUES ($placeholders)";
$stmt = $pdo->prepare($sql);
foreach ($row as $key => $value) $stmt->bindValue(":$key", $value);
$stmt->execute(); $stats['inserted']++;
} else { $stats['skipped']++; }
}
$pdo->commit();
} catch (Exception $e) { $pdo->rollBack(); throw $e; }
return $stats;
}
function processUploadOverwrite($pdo, $data) {
$stats = ['inserted' => 0, 'updated' => 0];
$pdo->beginTransaction();
try {
foreach ($data as $row) {
$zayavka_id = $row['zayavka_id']; unset($row['_row_num']);
$stmt = $pdo->prepare("SELECT id FROM feo WHERE zayavka_id = ?");
$stmt->execute([$zayavka_id]);
$exists = $stmt->fetch();
if (!$exists) {
$fields = implode(', ', array_keys($row)); $placeholders = ':' . implode(', :', array_keys($row));
$sql = "INSERT INTO feo ($fields) VALUES ($placeholders)";
$stmt = $pdo->prepare($sql);
foreach ($row as $key => $value) $stmt->bindValue(":$key", $value);
$stmt->execute(); $stats['inserted']++;
} else {
$setParts = []; foreach (array_keys($row) as $field) $setParts[] = "$field = :$field";
$sql = "UPDATE feo SET " . implode(', ', $setParts) . " WHERE zayavka_id = :zayavka_id";
$stmt = $pdo->prepare($sql);
foreach ($row as $key => $value) $stmt->bindValue(":$key", $value);
$stmt->execute(); $stats['updated']++;
}
}
$pdo->commit();
} catch (Exception $e) { $pdo->rollBack(); throw $e; }
return $stats;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Загрузка данных FEO</title>
<link rel="stylesheet" href="style.css">
<style>
.status-available { color: #28a745; font-weight: bold; background: #d4edda; padding: 2px 6px; border-radius: 3px; font-size: 11px; display: inline-block; min-width: 50px; }
.status-marshrut { color: #0066cc; font-weight: bold; background: #e7f3ff; padding: 2px 6px; border-radius: 3px; font-size: 11px; display: inline-block; min-width: 50px; }
.status-flight { color: #856404; font-weight: bold; background: #fff3cd; padding: 2px 6px; border-radius: 3px; font-size: 11px; display: inline-block; min-width: 50px; }
.status-badge { display: inline-block; width: 120px; text-align: center; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; color: #ffffff; }
.status-search { background: #FFA459; }
.status-found { background: #17a2b8; }
.status-started { background: #28a745; }
.status-completed { background: #6c757d; }
.status-attention { background: #dc3545; }
.status-planned-route { background: #9c27b0; }

.filter-checkbox-group { display: flex; flex-wrap: wrap; gap: 15px; margin-top: 10px; }
.filter-checkbox-group label { display: flex; align-items: center; gap: 5px; cursor: pointer; font-size: 14px; }
.filter-checkbox-group input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
.filter-row { display: flex; flex-direction: column; gap: 10px; }
.filter-input-group { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.filter-stats { margin-top: 10px; font-size: 13px; color: #666; }
.filter-stats-error { color: #dc3545; background: #f8d7da; padding: 2px 6px; border-radius: 3px; font-size: 12px; }
.status-duplicate-error {
    margin: 10px 0;
    padding: 12px 14px;
    border: 1px solid #dc3545;
    background: #f8d7da;
    color: #721c24;
    border-radius: 6px;
    font-size: 13px;
    line-height: 1.45;
}
.status-duplicate-error strong { display: block; margin-bottom: 6px; }
.status-duplicate-error .duplicate-row { margin-top: 3px; }
</style>
</head>
<body>
<?php include 'menuadmin.php'; ?>
<div class="app">
<div class="toolbar">
<h1>Загрузка данных FEO</h1>
<button class="btn btn-primary" type="button" onclick="window.location.href='https://plan-ex.ru/erpv2/company/feo/requests'">Выбрать файл</button>
<button class="btn" id="showWorkListBtn" type="button">Показать заявки в работе</button>
<input type="file" id="fileInput" accept=".xlsx" style="display: none;">
</div>
<div class="filter-container">
<div class="filter-row">
<div class="filter-input-group">
<label for="filterZayavki" class="filter-label">Фильтр по заявкам:</label>
<input type="text" id="filterZayavki" placeholder="Введите номера заявок через запятую, пробел" class="filter-input" value="">
<button id="clearFilterBtn" class="btn">Очистить</button>
</div>
<div class="filter-input-group">
<label for="searchText" class="filter-label">Поиск по содержимому:</label>
<input type="text" id="searchText" placeholder="Регион, МО, Отправитель, Адрес, Доступно, Маршрут, Рейс, Статус..." class="filter-input" value="">
<button id="clearSearchBtn" class="btn">Очистить</button>
</div>
</div>
<div class="filter-checkbox-group">
<label for="showOnlyAvailable"><input type="checkbox" id="showOnlyAvailable"> Доступно</label>
<label for="showOnlyMarshrut"><input type="checkbox" id="showOnlyMarshrut"> Маршрут</label>
<label for="showOnlyFlight"><input type="checkbox" id="showOnlyFlight"> Рейс</label>
</div>
<div id="filterInfo" class="filter-stats"><span id="filterStats"></span></div>
</div>
<div id="statusDuplicateError" class="status-duplicate-error" style="display:none;"></div>
<div class="table-wrapper" id="tableWrapper">
<table class="data-table" id="dataTable">
<thead>
<tr>
<th style="width: 80px;">ID Заявки</th>
<th style="width: 90px; text-align: right;">Масса, кг</th>
<th style="width: 150px;">МНО, Регион</th>
<th style="width: 150px;">МНО, МО</th>
<th style="width: 200px;">Грузоотправитель</th>
<th style="text-align: left;">Адрес погрузки</th>
<th style="width: 100px; text-align: center;">Доступно</th>
<th style="width: 100px; text-align: center;">Маршрут</th>
<th style="width: 150px; text-align: center;">Рейс</th>
<th style="width: 140px; text-align: center;">Статус</th>
<th style="width: 180px; text-align: center;">Даты</th>
<th style="width: 100px; text-align: right;">СТОИМОСТЬ</th>
<th style="width: 80px; text-align: right;">₽/КГ</th>
<th style="width: 100px; text-align: center;">ДЕЙСТВИЯ</th>
</tr>
</thead>
<tbody id="tableBody"></tbody>
</table>
<div id="loadingIndicator" class="loading-indicator" style="display: none;">Загрузка...</div>
<div id="noMoreData" class="no-more-data" style="display: none;">Все заявки загружены</div>
</div>
</div>

<div id="analysisModal" class="modal">
<div class="modal-content">
<div class="modal-header"><h3>Анализ файла</h3><span class="close" onclick="closeModal()">&times;</span></div>
<div id="analysisContent"></div>
<div class="modal-actions">
<button class="btn btn-primary" id="confirmNewBtn">Добавить только новые заявки</button>
<button class="btn btn-danger" id="confirmOverwriteBtn">Перезаписать данные всех заявок</button>
<button class="btn btn-secondary" id="cancelUploadBtn">Отмена</button>
</div>
</div>
</div>
<div id="editModal" class="modal">
<div class="modal-content modal-content-wide">
<div class="modal-header"><h3>✏️ Редактирование заявки</h3><span class="close" onclick="closeEditModal()">&times;</span></div>
<div id="editModalContent"></div>
</div>
</div>

<script>
const fileInput = document.getElementById('fileInput');
const analysisModal = document.getElementById('analysisModal');
const analysisContent = document.getElementById('analysisContent');
const tableBody = document.getElementById('tableBody');
const tableWrapper = document.querySelector('.table-wrapper');
const filterInput = document.getElementById('filterZayavki');
const clearFilterBtn = document.getElementById('clearFilterBtn');
const filterStats = document.getElementById('filterStats');
const loadingIndicator = document.getElementById('loadingIndicator');
const noMoreData = document.getElementById('noMoreData');
const showOnlyAvailableCheckbox = document.getElementById('showOnlyAvailable');
const showOnlyMarshrutCheckbox = document.getElementById('showOnlyMarshrut');
const showOnlyFlightCheckbox = document.getElementById('showOnlyFlight');
const showWorkListBtn = document.getElementById('showWorkListBtn');
const statusDuplicateError = document.getElementById('statusDuplicateError');

// Поиск по содержимому
const searchInput = document.getElementById('searchText');
const clearSearchBtn = document.getElementById('clearSearchBtn');
let currentSearch = '';
let allRowsData = [];
let lastAjaxData = null;

let currentOffset = 0;
let currentLimit = 50;
let currentFilter = '';
let currentTotal = 0;
let isLoading = false;
let hasMore = true;
let loadTimeout = null;
let currentAnalysis = null;
let scrollTimeout = null;
let dataRequestController = null;
let dataRequestSerial = 0;

function loadData(reset = true) {
// Для бесконечной прокрутки не запускаем второй параллельный запрос.
if (!reset && isLoading) return;

// Новый фильтр должен применяться сразу. Старый запрос отменяем, чтобы его
// запоздалый ответ не перезаписал результаты нового фильтра.
if (reset && dataRequestController) {
    dataRequestController.abort();
}

if (reset) {
    currentOffset = 0;
    hasMore = true;
    tableBody.innerHTML = '';
    noMoreData.style.display = 'none';
}
if (!hasMore) return;

const requestSerial = ++dataRequestSerial;
const controller = new AbortController();
dataRequestController = controller;
isLoading = true;
loadingIndicator.style.display = 'block';

const filterValue = filterInput.value.trim();
const showOnlyAvailable = showOnlyAvailableCheckbox.checked ? '1' : '0';
const showOnlyMarshrut = showOnlyMarshrutCheckbox.checked ? '1' : '0';
const showOnlyFlight = showOnlyFlightCheckbox.checked ? '1' : '0';

const requestBody = new URLSearchParams({
ajax: 'get_zayavki',
offset: String(currentOffset),
limit: String(currentLimit),
filter_zayavki: filterValue,
show_only_available: showOnlyAvailable,
show_only_marshrut: showOnlyMarshrut,
show_only_flight: showOnlyFlight
});

fetch('', {
method: 'POST',
headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
body: requestBody.toString(),
signal: controller.signal
})
.then(response => response.json())
.then(data => {
// Игнорируем устаревший ответ, если после него уже был запущен новый фильтр.
if (requestSerial !== dataRequestSerial) return;

if (data.success) {
if (reset) tableBody.innerHTML = data.html;
else tableBody.insertAdjacentHTML('beforeend', data.html);
currentTotal = data.total; currentFilter = filterValue; hasMore = data.has_more;
lastAjaxData = data;
const rowsCount = tableBody.querySelectorAll('tr').length;
currentOffset = rowsCount;
if (!hasMore && currentOffset > 0) noMoreData.style.display = 'block'; else noMoreData.style.display = 'none';
updateFilterInfo(data);
parseRowsForSearch();
applyTextFilter();
checkStatusBlockDuplicates();
} else {
tableBody.innerHTML = '<tr><td colspan="14" style="text-align: center; padding: 40px; color: #dc3545;">Ошибка: ' + data.message + '</td></tr>';
}
})
.catch(error => {
if (error.name === 'AbortError') return;
if (requestSerial !== dataRequestSerial) return;
console.error('Ошибка загрузки:', error);
tableBody.innerHTML = '<tr><td colspan="14" style="text-align: center; padding: 40px; color: #dc3545;">Ошибка загрузки данных</td></tr>';
})
.finally(() => {
if (requestSerial === dataRequestSerial) {
    isLoading = false;
    loadingIndicator.style.display = 'none';
    if (dataRequestController === controller) dataRequestController = null;
}
});
}

function checkStatusBlockDuplicates() {
    if (!statusDuplicateError) return;
    fetch('?ajax=check_status_block_duplicates')
    .then(response => response.json())
    .then(data => {
        if (!data.success) throw new Error(data.message || 'Не удалось проверить дубли блоков');
        const duplicates = Array.isArray(data.duplicates) ? data.duplicates : [];
        if (duplicates.length === 0) {
            statusDuplicateError.style.display = 'none';
            statusDuplicateError.innerHTML = '';
            return;
        }

        const rows = duplicates.map(item => {
            const parts = [];
            const available = Array.isArray(item.dostupno_blocks) ? item.dostupno_blocks : [];
            const routes = Array.isArray(item.marshrut_blocks) ? item.marshrut_blocks : [];

            if (available.length > 1) {
                parts.push('Доступно: блоки ' + available.join(', '));
            }
            if (routes.length > 1) {
                parts.push('Маршрут: блоки ' + routes.join(', '));
            }

            return '<div class="duplicate-row">Заявка <strong style="display:inline;">' +
                escapeHtml(item.zayavka_id) + '</strong> — ' + parts.join('; ') + '</div>';
        });

        statusDuplicateError.innerHTML =
            '<strong>⚠ Ошибка: обнаружены заявки, находящиеся более чем в одном блоке «Доступно» и/или более чем в одном блоке «Маршрут».</strong>' +
            rows.join('');
        statusDuplicateError.style.display = 'block';
    })
    .catch(error => {
        // Никаких всплывающих alert: при технической ошибке проверки только пишем в консоль.
        console.error('Ошибка проверки дублей блоков:', error);
        statusDuplicateError.style.display = 'none';
        statusDuplicateError.innerHTML = '';
    });
}

function updateFilterInfo(data) {
const totalMassKg = Math.round(Number(data.total_mass_kg || 0));
const totalMassText = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(totalMassKg).replace(/\u00a0/g, ' ') + ' кг.';
let filterText = [];
if (data.show_only_available) filterText.push('доступные');
if (data.show_only_marshrut) filterText.push('маршруты');
if (data.show_only_flight) filterText.push('рейсы');
let availableText = filterText.length > 0 ? ' (только ' + filterText.join(', ') + ')' : '';
if (data.has_filter && data.filter_count > 0) {
if (data.total > 0) {
let message = '🔍 Найдено заявок' + availableText + ': ' + data.total + ' из ' + data.filter_count + ' введенных&nbsp;&nbsp;' + totalMassText;
if (data.missing_zayavki && data.missing_zayavki.length > 0) message += ' <span class="filter-stats-error">: ' + data.missing_zayavki.join(', ') + '</span>';
filterStats.innerHTML = message;
} else {
let message = '⚠️ По введенным номерам заявок' + availableText + ' (' + data.filter_count + ') ничего не найдено';
if (data.missing_zayavki && data.missing_zayavki.length > 0) message += ' <span class="filter-stats-error">: ' + data.missing_zayavki.join(', ') + '</span>';
filterStats.innerHTML = message;
}
} else if (data.has_filter && data.filter_count === 0) {
filterStats.innerHTML = '⚠️ Не найдено корректных номеров заявок';
} else {
filterStats.innerHTML = '📋 Всего заявок в базе' + availableText + ': ' + data.total + '&nbsp;&nbsp;' + totalMassText;
}
}

// Логика поиска по содержимому (из indexm.php)
function parseRowsForSearch() {
allRowsData = [];
tableBody.querySelectorAll('tr').forEach(row => {
const cells = row.querySelectorAll('td');
if (cells.length >= 10) {
// Индексы столбцов: 2=Регион, 3=МО, 4=Отправитель, 5=Адрес, 6=Доступно, 7=Маршрут, 8=Рейс, 9=Статус
allRowsData.push({
element: row,
searchText: [
cells[2]?.textContent || '', cells[3]?.textContent || '', cells[4]?.textContent || '',
cells[5]?.textContent || '', cells[6]?.textContent || '', cells[7]?.textContent || '',
cells[8]?.textContent || '', cells[9]?.textContent || ''
].join(' ').toLowerCase()
});
}
});
}

function applyTextFilter() {
const searchValue = currentSearch.trim().toLowerCase();
if (!searchValue) {
allRowsData.forEach(item => item.element.style.display = '');
if (lastAjaxData) updateFilterInfo(lastAjaxData);
return;
}
let visibleCount = 0;
allRowsData.forEach(item => {
if (item.searchText.includes(searchValue)) {
item.element.style.display = '';
visibleCount++;
} else {
item.element.style.display = 'none';
}
});
if (lastAjaxData) {
let html = filterStats.innerHTML.replace(/ 🔍 Найдено по тексту: \d+/, '');
html += ` 🔍 Найдено по тексту: ${visibleCount}`;
filterStats.innerHTML = html;
}
}

function handleScroll() {
if (!tableWrapper) return;
if (scrollTimeout) clearTimeout(scrollTimeout);
scrollTimeout = setTimeout(() => {
const scrollTop = tableWrapper.scrollTop; const scrollHeight = tableWrapper.scrollHeight; const clientHeight = tableWrapper.clientHeight;
if (scrollTop + clientHeight >= scrollHeight - 100) { if (!isLoading && hasMore) loadData(false); }
}, 100);
}

function handleWorkListClick() {
if (!showWorkListBtn) return;

showWorkListBtn.disabled = true;
filterStats.textContent = '⏳ Формируется список заявок в работе...';

fetch('?ajax=get_work_zayavki')
.then(response => response.json())
.then(data => {
if (!data.success) throw new Error(data.message || 'Не удалось получить список заявок');

const ids = Array.isArray(data.zayavki_ids) ? data.zayavki_ids : [];

// Кнопка задаёт однозначный preset: номера заявок + только «Доступно».
// Другие статусные чекбоксы сбрасываем, чтобы старое состояние фильтров
// не превращало результат в неожиданное пересечение.
filterInput.value = ids.join(', ');
showOnlyAvailableCheckbox.checked = true;
showOnlyMarshrutCheckbox.checked = false;
showOnlyFlightCheckbox.checked = false;

loadData(true);
if (tableWrapper) tableWrapper.scrollTop = 0;

if (ids.length === 0) {
filterStats.textContent = '⚠️ В доступных блоках с ID > 105 заявки не найдены';
}
})
.catch(error => {
console.error('Ошибка формирования списка заявок в работе:', error);
filterStats.textContent = '⚠️ Ошибка формирования списка заявок в работе';
alert('Ошибка формирования списка заявок в работе: ' + error.message);
})
.finally(() => {
showWorkListBtn.disabled = false;
});
}

function handleFilterInput() {
if (loadTimeout) clearTimeout(loadTimeout);
loadTimeout = setTimeout(() => { loadData(true); if (tableWrapper) tableWrapper.scrollTop = 0; }, 500);
}
function handleCheckboxChange() {
if (loadTimeout) clearTimeout(loadTimeout);
loadTimeout = setTimeout(() => { loadData(true); if (tableWrapper) tableWrapper.scrollTop = 0; }, 300);
}
function clearFilter() {
filterInput.value = ''; showOnlyAvailableCheckbox.checked = false; showOnlyMarshrutCheckbox.checked = false; showOnlyFlightCheckbox.checked = false;
loadData(true); if (tableWrapper) tableWrapper.scrollTop = 0;
}
function handleSearchInput() {
currentSearch = searchInput.value.trim();
if (allRowsData.length > 0) {
applyTextFilter();
} else {
loadData(true);
}
}
function clearSearch() {
searchInput.value = '';
currentSearch = '';
if (allRowsData.length > 0) applyTextFilter();
}

if (filterInput) filterInput.addEventListener('input', handleFilterInput);
if (clearFilterBtn) clearFilterBtn.addEventListener('click', clearFilter);
if (showOnlyAvailableCheckbox) showOnlyAvailableCheckbox.addEventListener('change', handleCheckboxChange);
if (showOnlyMarshrutCheckbox) showOnlyMarshrutCheckbox.addEventListener('change', handleCheckboxChange);
if (showOnlyFlightCheckbox) showOnlyFlightCheckbox.addEventListener('change', handleCheckboxChange);
if (showWorkListBtn) showWorkListBtn.addEventListener('click', handleWorkListClick);
if (searchInput) searchInput.addEventListener('input', handleSearchInput);
if (clearSearchBtn) clearSearchBtn.addEventListener('click', clearSearch);

if (tableWrapper) {
tableWrapper.addEventListener('scroll', handleScroll);
const observer = new MutationObserver(function() {
setTimeout(() => { if (tableWrapper.scrollHeight <= tableWrapper.clientHeight && hasMore && !isLoading) loadData(false); }, 100);
});
observer.observe(tableBody, { childList: true, subtree: true });
}
loadData(true);

fileInput.addEventListener('change', function(e) {
const file = e.target.files[0]; if (!file) return;
const formData = new FormData(); formData.append('excel_file', file); formData.append('token', '<?= $_SESSION['upload_token'] ?>');
fetch('', { method: 'POST', body: formData })
.then(response => response.json())
.then(data => { if (data.success) showAnalysis(data.analysis); else alert('Ошибка: ' + data.message); })
.catch(error => { alert('Ошибка при загрузке файла'); console.error(error); });
fileInput.value = '';
});

function showAnalysis(analysis) {
currentAnalysis = analysis;
let html = '<div class="analysis-stats"><p><strong>Общее количество строк:</strong> ' + analysis.total + '</p><p><strong>Новых записей:</strong> ' + analysis.new + '</p><p><strong>Существующих записей:</strong> ' + analysis.existing + '</p></div>';
analysisContent.innerHTML = html;
document.getElementById('confirmNewBtn').onclick = () => confirmUpload('new');
document.getElementById('confirmOverwriteBtn').onclick = () => confirmUpload('overwrite');
document.getElementById('cancelUploadBtn').onclick = () => cancelUpload();
analysisModal.classList.add('show');
}
function confirmUpload(mode) {
if (!currentAnalysis) return;
const url = mode === 'new' ? 'confirm_upload_new=1' : 'confirm_upload_overwrite=1';
fetch('', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: url })
.then(response => response.json())
.then(data => {
if (data.success) {
if (mode === 'new') alert('Загрузка завершена!\nДобавлено: ' + data.stats.inserted + '\nПропущено дубликатов: ' + data.stats.skipped);
else alert('Загрузка завершена!\nДобавлено: ' + data.stats.inserted + '\nОбновлено: ' + data.stats.updated);
location.reload();
} else alert('Ошибка: ' + data.message);
closeModal();
}).catch(error => { alert('Ошибка при загрузке'); console.error(error); });
}
function cancelUpload() { fetch('', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'cancel_upload=1' }).then(() => closeModal()); }
function closeModal() { analysisModal.classList.remove('show'); currentAnalysis = null; }

function editRow(rowId) {
const modal = document.getElementById('editModal'); const content = document.getElementById('editModalContent');
content.innerHTML = '<div style="text-align: center; padding: 20px;">Загрузка данных...</div>'; modal.classList.add('show');
fetch('?get_row=1&row_id=' + rowId).then(response => response.json()).then(data => {
if (data.success) showEditForm(data.row);
else content.innerHTML = '<div style="text-align: center; padding: 20px; color: #dc3545;">Ошибка: ' + data.message + '</div>';
}).catch(error => { console.error('Ошибка:', error); content.innerHTML = '<div style="text-align: center; padding: 20px; color: #dc3545;">Ошибка загрузки данных</div>'; });
}
function showEditForm(row) {
const content = document.getElementById('editModalContent');
const fields = [
{ name: 'zayavka_id', label: 'ID Заявки', type: 'text' }, { name: 'data_zayavki', label: 'Дата заявки', type: 'date' },
{ name: 'polnoe_naim_zakazchika', label: 'Полное наименование заказчика', type: 'textarea' }, { name: 'ur_adres_zakazchika', label: 'Юридический адрес заказчика', type: 'textarea' },
{ name: 'inn_zakazchika', label: 'ИНН заказчика', type: 'text' }, { name: 'nomer_dogovora', label: '№ договора', type: 'text' },
{ name: 'data_dogovora', label: 'Дата договора', type: 'date' }, { name: 'gruzopoluchatel', label: 'Грузополучатель', type: 'textarea' },
{ name: 'inn_op_gruzopoluchatelya', label: 'ИНН ОП Грузополучателя', type: 'text' }, { name: 'adres_dostavki_otkhodov', label: 'Адрес доставки отходов', type: 'textarea' },
{ name: 'ur_adres_op_gruzopoluchatelya', label: 'Юр. адрес ОП Грузополучателя', type: 'textarea' }, { name: 'inn_oo_gruzootpravitel', label: 'ИНН ОО (Грузоотправитель)', type: 'text' },
{ name: 'naim_oo_gruzootpravitel', label: 'Наименование ОО (Грузоотправитель)', type: 'textarea' }, { name: 'ur_adres_oo_gruzootpravitelya', label: 'Юр. адрес ОО (Грузоотправитель)', type: 'textarea' },
{ name: 'mno_region', label: 'МНО, Регион', type: 'text' }, { name: 'mno_mo', label: 'МНО, МО', type: 'text' },
{ name: 'mno_adres_pogruzki', label: 'МНО, Адрес погрузки', type: 'textarea' }, { name: 'naim_otkhoda_fkko', label: 'Наименование отхода (ФККО)', type: 'textarea' },
{ name: 'kol_vo_mest', label: 'Количество мест', type: 'number', step: '1' }, { name: 'vid_tary', label: 'Вид тары', type: 'text' },
{ name: 'mass_netto', label: 'Масса нетто, т', type: 'number', step: '0.001' }, { name: 'mass_brutto', label: 'Масса брутто, т', type: 'number', step: '0.001' },
{ name: 'dlina_mesta', label: 'Длина места', type: 'number', step: '0.01' }, { name: 'shirina_mesta', label: 'Ширина места', type: 'number', step: '0.01' },
{ name: 'vysota_mesta', label: 'Высота места', type: 'number', step: '0.01' }, { name: 'summarnyy_obem', label: 'Суммарный объем, м³', type: 'number', step: '0.01' },
{ name: 'nomer_opasnogo_gr', label: 'Номер опасного груза', type: 'text' }, { name: 'adres_vygruzki_otkhodov', label: 'Адрес выгрузки отходов', type: 'textarea' },
{ name: 'naim_ot_gruzoperevozchik', label: 'Наименование перевозчика', type: 'textarea' }, { name: 'inn_ot', label: 'ИНН перевозчика', type: 'text' },
{ name: 'ur_adres_ot', label: 'Юр. адрес перевозчика', type: 'textarea' }, { name: 'data_sozdaniya_porucheniya', label: 'Дата создания поручения', type: 'date' },
{ name: 'zakaz_s_ot_status', label: 'Заказ с ОТ, Статус', type: 'text' }, { name: 'nalichie_transport_nakl', label: 'Наличие транспортной накладной', type: 'text' },
{ name: 'zakaz_s_op_status', label: 'Заказ с ОП, Статус', type: 'text' }, { name: 'id_zakaza_s_op', label: 'ID заказа с ОП', type: 'text' },
{ name: 'zakaz_s_ot_data_vygruzki', label: 'Заказ с ОТ, дата выгрузки', type: 'date' }, { name: 'zayavka_zhdv_po', label: 'Заявка, ЖДВ (по)', type: 'date' },
{ name: 'kontakt_tel', label: 'Контактный телефон', type: 'text' }, { name: 'tel_dopolnitelnyy', label: 'Дополнительный телефон', type: 'text' },
{ name: 'kontakt_email', label: 'Контактный email', type: 'email' }, { name: 'mno_sh', label: 'МНО_Координаты (Ш)', type: 'text' },
{ name: 'mno_d', label: 'МНО_Координаты (Д)', type: 'text' }, { name: 'zakaz_s_op_stoimost', label: 'Заказ с ОП, Стоимость услуг', type: 'number', step: '0.01' }
];
let html = '<form id="editForm" onsubmit="return submitEdit(event, ' + row.id + ')"><input type="hidden" name="update_row" value="1"><input type="hidden" name="row_id" value="' + row.id + '"><div class="edit-form-grid">';
for (let i = 0; i < fields.length; i++) {
const field = fields[i]; const fieldValue = row[field.name] !== null && row[field.name] !== undefined ? row[field.name] : '';
html += '<div class="edit-form-label">' + escapeHtml(field.label) + ':</div><div class="edit-form-field">';
if (field.type === 'textarea') html += '<textarea name="' + field.name + '" rows="2">' + escapeHtml(fieldValue) + '</textarea>';
else { html += '<input type="' + field.type + '" name="' + field.name + '" value="' + escapeHtml(fieldValue) + '"'; if (field.step) html += ' step="' + field.step + '"'; if (field.type === 'number') html += ' step="any"'; html += '>'; }
html += '</div>';
}
html += '</div><div class="modal-actions"><button type="submit" class="btn btn-primary">Сохранить изменения</button><button type="button" class="btn btn-secondary" onclick="closeEditModal()">Отмена</button></div></form>';
content.innerHTML = html;
}
function escapeHtml(str) { if (str === null || str === undefined) return ''; return String(str).replace(/[&<>]/g, function(m) { if (m === '&') return '&amp;'; if (m === '<') return '&lt;'; if (m === '>') return '&gt;'; return m; }); }
function submitEdit(event, rowId) {
event.preventDefault(); const form = document.getElementById('editForm'); const formData = new FormData(form);
const submitBtn = form.querySelector('button[type="submit"]'); const originalText = submitBtn.textContent;
submitBtn.disabled = true; submitBtn.textContent = 'Сохранение...';
fetch('', { method: 'POST', body: formData }).then(response => response.json()).then(data => {
if (data.success) { alert('Заявка успешно обновлена'); closeEditModal(); loadData(true); }
else { alert('Ошибка: ' + data.message); submitBtn.disabled = false; submitBtn.textContent = originalText; }
}).catch(error => { alert('Ошибка при сохранении'); console.error(error); submitBtn.disabled = false; submitBtn.textContent = originalText; });
return false;
}
function deleteRow(rowId, zayavkaId) {
if (!confirm('Вы уверены, что хотите удалить заявку ' + zayavkaId + '?')) return;
const formData = new FormData(); formData.append('delete_row', '1'); formData.append('row_id', rowId);
fetch('', { method: 'POST', body: formData }).then(response => response.json()).then(data => {
if (data.success) { alert('Заявка успешно удалена'); loadData(true); } else alert('Ошибка: ' + data.message);
}).catch(error => { alert('Ошибка при удалении'); console.error(error); });
}
function closeEditModal() { document.getElementById('editModal').classList.remove('show'); }
window.onclick = function(event) {
const analysisModalEl = document.getElementById('analysisModal'); const editModalEl = document.getElementById('editModal');
if (event.target === analysisModalEl) closeModal(); if (event.target === editModalEl) closeEditModal();
}
</script>
</body>
</html>
