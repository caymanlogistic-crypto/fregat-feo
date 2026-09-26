<?php
require_once 'config.php';
session_start();
// Функция для получения данных из Google Sheets
include ("include/googleSheetsHelper.php");
$googleData = getGoogleSheetsData();
///////////////////////////////////////
// Подключение к БД
try {
$pdo = new PDO(
"mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset=utf8mb4",
$dbConfig['username'],
$dbConfig['password'],
[
PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]
);
} catch (PDOException $e) {
die("Ошибка подключения к БД: " . $e->getMessage());
}

// Загружаем статусы из таблицы status
$statuses = [];
$statusMap = [];
try {
$stmt = $pdo->query("SELECT * FROM status ORDER BY id");
$statuses = $stmt->fetchAll();
// Создаем массив для быстрого доступа по ключу 'статус'
foreach ($statuses as $s) {
$statusMap[$s['статус']] = $s;
}
} catch (Exception $e) {
// Если таблицы нет, создаем ее
$pdo->exec("
CREATE TABLE IF NOT EXISTS `status` (
`id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
`статус` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
`наименование` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
`color` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Цвет статуса',
`style` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Название стиля',
PRIMARY KEY (`id`),
UNIQUE KEY `статус` (`статус`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
// Вставляем стандартные статусы
$pdo->exec("
INSERT INTO `status` (`статус`, `наименование`, `color`, `style`) VALUES
('search', 'Поиск подрядчика', '#FFA459', '.status-search'),
('found', 'Подрядчик определен', '#17a2b8', '.status-found'),
('started', 'Вывоз начался', '#28a745', '.status-started'),
('completed', 'Груз сдан', '#6c757d', '.status-completed')
ON DUPLICATE KEY UPDATE `наименование` = VALUES(`наименование`)
");
$stmt = $pdo->query("SELECT * FROM status ORDER BY id");
$statuses = $stmt->fetchAll();
foreach ($statuses as $s) {
$statusMap[$s['статус']] = $s;
}
}

// Генерируем CSS стили для статусов из БД
$dynamicStatusCSS = '';
foreach ($statuses as $s) {
$styleClass = ltrim($s['style'], '.');
$dynamicStatusCSS .= "
.{$styleClass} {
display: inline-block;
width: 140px;
text-align: center;
padding: 4px 12px;
background: {$s['color']};
color: #ffffff;
border-radius: 4px;
font-size: 12px;
font-weight: 600;
}
";
}

// Получаем данные из Google Sheets
$zayavkiInfo = [];
// Индексируем данные по номеру заявки (row[0])
foreach ($googleData as $index => $row) {
if ($index > 0 && isset($row[0]) && is_numeric(trim($row[0]))) {
$requestNumber = trim($row[0]);
$zayavkiInfo[$requestNumber] = [
'route' => isset($row[8]) ? trim($row[8]) : '',
'comments' => isset($row[11]) ? trim($row[11]) : '',
'driver_info' => isset($row[12]) ? trim($row[12]) : '',
'prep_time' => isset($row[13]) ? trim($row[13]) : ''
];
}
}

// Функция для удаления дубликатов из массива контактов
function uniqueContacts($items) {
$unique = [];
foreach ($items as $item) {
$item = trim($item);
if (!empty($item) && !in_array($item, $unique)) {
$unique[] = $item;
}
}
return $unique;
}

// Функция для получения и обогащения данных рейсов с возможностью фильтрации
function getFlightsWithFilter($pdo, $statusMap, $zayavkiInfo, $filterType = 'all') {
    $flights = [];
    try {
        // Базовый запрос
        $sql = "
            SELECT f.*,
                c.name as contractor_name,
                d.full_name as driver_name,
                d.vehicle_make_plate,
                CONCAT(u.Фамилия, ' ', u.Имя) as manager_name
            FROM flights f
            LEFT JOIN contractors c ON f.contractor_id = c.id
            LEFT JOIN drivers d ON f.driver_id = d.id
            LEFT JOIN users u ON f.assigned_manager_id = u.id
            WHERE 1=1
        ";

        // Применяем фильтры в зависимости от типа раздела
        switch ($filterType) {
            case 'planned':
                $sql .= " AND (f.planned_start_date IS NOT NULL 
                           OR f.planned_start_date_from IS NOT NULL 
                           OR f.planned_start_date_to IS NOT NULL)";
                break;
            case 'in_transit':
                $sql .= " AND f.actual_start_date IS NOT NULL 
                           AND f.actual_end_date IS NULL";
                break;
            case 'unloaded':
                $sql .= " AND f.actual_start_date IS NOT NULL 
                           AND f.actual_end_date IS NOT NULL";
                break;
            case 'unassigned':
                $sql .= " AND (f.planned_start_date IS NULL 
                           AND f.planned_start_date_from IS NULL 
                           AND f.planned_start_date_to IS NULL)
                           AND f.actual_start_date IS NULL";
                break;
            case 'all':
            default:
                // Все рейсы - без дополнительных условий
                break;
        }

        // Применяем сортировку в зависимости от типа раздела
        switch ($filterType) {
            case 'all':
                // Для вкладки "Все рейсы":
                // 1. Сначала нераспределенные
                // 2. Затем остальные по убыванию максимальной даты
                $sql .= "
                    ORDER BY 
                        CASE 
                            WHEN (f.planned_start_date IS NULL 
                                  AND f.planned_start_date_from IS NULL 
                                  AND f.planned_start_date_to IS NULL
                                  AND f.actual_start_date IS NULL) THEN 0
                            ELSE 1
                        END,
                        GREATEST(
                            COALESCE(f.planned_start_date, '1970-01-01'),
                            COALESCE(f.planned_start_date_from, '1970-01-01'),
                            COALESCE(f.planned_start_date_to, '1970-01-01'),
                            COALESCE(f.actual_start_date, '1970-01-01'),
                            COALESCE(f.actual_end_date, '1970-01-01')
                        ) DESC
                ";
                break;
            
            case 'in_transit':
            case 'unloaded':
                // Для "Рейсы в пути" и "Выгруженные рейсы" - сортировка по убыванию дат
                $sql .= "
                    ORDER BY 
                        GREATEST(
                            COALESCE(f.actual_start_date, '1970-01-01'),
                            COALESCE(f.actual_end_date, '1970-01-01')
                        ) DESC
                ";
                break;
            
            case 'unassigned':
                // Нераспределенные: сортировка по ID ASC (старые сверху)
                $sql .= " ORDER BY f.id ASC";
                break;
            
            default:
                // Остальные разделы: сортировка по возрастанию ближайшей даты
                $sql .= "
                    ORDER BY 
                        COALESCE(f.planned_start_date, f.planned_start_date_from, f.planned_start_date_to, f.actual_start_date, f.id) ASC
                ";
                break;
        }
        
        $sql .= " LIMIT 500";

        $stmt = $pdo->query($sql);
        $flightsRaw = $stmt->fetchAll();
        $processedFlights = [];

        foreach ($flightsRaw as $flight) {
            $zayavki_ids = array_filter(explode(',', $flight['zayavki_ids']));
            $flight['zayavki_ids_array'] = $zayavki_ids;
            if (!empty($zayavki_ids)) {
                $placeholders = implode(',', array_fill(0, count($zayavki_ids), '?'));
                $stmt2 = $pdo->prepare("
                    SELECT zayavka_id, mass_netto, naim_oo_gruzootpravitel, mno_adres_pogruzki,
                        kontakt_tel, tel_dopolnitelnyy, kontakt_email
                    FROM feo
                    WHERE zayavka_id IN ({$placeholders})
                ");
                $stmt2->execute($zayavki_ids);
                $zayavkiData = $stmt2->fetchAll();
                // Добавляем информацию из Google Sheets к каждой заявке
                $enrichedZayavkiData = [];
                $uniqueAddresses = [];
                $prepTimeFilledCount = 0;
                foreach ($zayavkiData as $zayavka) {
                    $zayavkaId = $zayavka['zayavka_id'];
                    $enrichedZayavka = $zayavka;
                    // Собираем уникальные адреса
                    if (!empty($zayavka['mno_adres_pogruzki'])) {
                        $address = trim($zayavka['mno_adres_pogruzki']);
                        if (!in_array($address, $uniqueAddresses)) {
                            $uniqueAddresses[] = $address;
                        }
                    }
                    if (isset($zayavkiInfo[$zayavkaId])) {
                        $enrichedZayavka['prep_time'] = $zayavkiInfo[$zayavkaId]['prep_time'];
                        $enrichedZayavka['comments'] = $zayavkiInfo[$zayavkaId]['comments'];
                        $enrichedZayavka['driver_info'] = $zayavkiInfo[$zayavkaId]['driver_info'];
                        $enrichedZayavka['route'] = $zayavkiInfo[$zayavkaId]['route'];
                        // Считаем заявки с заполненным ПРОПУСК
                        if (!empty($zayavkiInfo[$zayavkaId]['prep_time'])) {
                            $prepTimeFilledCount++;
                        }
                    } else {
                        $enrichedZayavka['prep_time'] = '';
                        $enrichedZayavka['comments'] = '';
                        $enrichedZayavka['driver_info'] = '';
                        $enrichedZayavka['route'] = '';
                    }
                    $enrichedZayavkiData[] = $enrichedZayavka;
                }
                $flight['zayavki'] = $enrichedZayavkiData;
                $flight['unique_addresses_count'] = count($uniqueAddresses);
                $flight['prep_time_filled_count'] = $prepTimeFilledCount;
                $flight['total_zayavki_count'] = count($zayavkiData);
                $actual_mass_kg = 0;
                foreach ($flight['zayavki'] as $zayavka) {
                    $actual_mass_kg += floatval($zayavka['mass_netto'] ?? 0) * 1000;
                }
                $flight['total_mass_kg'] = $actual_mass_kg;
            } else {
                $flight['zayavki'] = [];
                $flight['total_mass_kg'] = 0;
                $flight['unique_addresses_count'] = 0;
                $flight['prep_time_filled_count'] = 0;
                $flight['total_zayavki_count'] = 0;
            }
            $processedFlights[] = $flight;
        }
        return $processedFlights;
    } catch (Exception $e) {
        return [];
    }
}

// Получаем ID текущего пользователя для сохранения выбранного раздела (опционально)
$currentUserId = $_SESSION['user_id'] ?? null;

// Получаем выбранный раздел из GET параметра, сессии или localStorage (через cookie)
$selectedTab = 'all'; // по умолчанию
if (isset($_GET['tab']) && in_array($_GET['tab'], ['all', 'planned', 'in_transit', 'unloaded', 'unassigned'])) {
$selectedTab = $_GET['tab'];
// Сохраняем в сессию
$_SESSION['timeline_selected_tab'] = $selectedTab;
} elseif (isset($_SESSION['timeline_selected_tab']) && in_array($_SESSION['timeline_selected_tab'], ['all', 'planned', 'in_transit', 'unloaded', 'unassigned'])) {
$selectedTab = $_SESSION['timeline_selected_tab'];
}

// Получаем данные рейсов в зависимости от выбранного раздела
$flights = getFlightsWithFilter($pdo, $statusMap, $zayavkiInfo, $selectedTab);

// Подсчет количества рейсов по каждому разделу для отображения в табах
$countAll = count(getFlightsWithFilter($pdo, $statusMap, $zayavkiInfo, 'all'));
$countPlanned = count(getFlightsWithFilter($pdo, $statusMap, $zayavkiInfo, 'planned'));
$countInTransit = count(getFlightsWithFilter($pdo, $statusMap, $zayavkiInfo, 'in_transit'));
$countUnloaded = count(getFlightsWithFilter($pdo, $statusMap, $zayavkiInfo, 'unloaded'));
$countUnassigned = count(getFlightsWithFilter($pdo, $statusMap, $zayavkiInfo, 'unassigned'));

// Функция для получения иконки в зависимости от типа рейса (только для вкладки "Все рейсы")
function getDateIcon($flight, $selectedTab) {
    if ($selectedTab !== 'all') {
        return '';
    }
    
    // Проверка на нераспределенный
    $isUnassigned = (empty($flight['planned_start_date']) && 
                     empty($flight['planned_start_date_from']) && 
                     empty($flight['planned_start_date_to']) && 
                     empty($flight['actual_start_date']));
    if ($isUnassigned) {
        return '⚠️ ';
    }
    
    // Проверка на выгруженный
    if (!empty($flight['actual_start_date']) && !empty($flight['actual_end_date'])) {
        return '✅ ';
    }
    
    // Проверка на в пути
    if (!empty($flight['actual_start_date']) && empty($flight['actual_end_date'])) {
        return '🚚 ';
    }
    
    // Плановый
    return '📅 ';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>График вывоза</title>
<link rel="stylesheet" href="style.css">
<style>
/* Базовые стили для статусов */
.status-badge {
display: inline-block;
width: 140px;
text-align: center;
padding: 4px 12px;
border-radius: 4px;
font-size: 12px;
font-weight: 600;
color: #ffffff;
}
<?= $dynamicStatusCSS ?>

/* Дополнительные стили */
.status-search, .status-found, .status-started, .status-completed, .status-attention {
display: inline-block;
width: 140px;
text-align: center;
padding: 4px 12px;
border-radius: 4px;
font-size: 12px;
font-weight: 600;
color: #ffffff;
}
.status-waiting-driver {
display: inline-block;
width: 140px;
text-align: center;
padding: 4px 12px;
border-radius: 4px;
font-size: 12px;
font-weight: 600;
color: #ffffff;
background: #ffc107;
}
.unload-oo {
display: inline-block;
width: 80px;
text-align: center;
padding: 2px 8px;
background: #e7f3ff;
color: #0066cc;
border-radius: 4px;
font-size: 12px;
font-weight: 600;
}
.unload-sklad {
display: inline-block;
width: 80px;
text-align: center;
padding: 2px 8px;
background: #fff3cd;
color: #856404;
border-radius: 4px;
font-size: 12px;
font-weight: 600;
}
.toggle-arrow {
cursor: pointer;
user-select: none;
transition: transform 0.2s;
}
.toggle-arrow:hover {
transform: scale(1.2);
}
.details-table {
width: 100%;
border-collapse: collapse;
background: #f9f9f9;
table-layout: fixed;
}
.details-table th {
background: #e9ecef;
padding: 8px 12px;
font-size: 12px;
font-weight: 600;
border-bottom: 1px solid #dee2e6;
text-align: center;
word-wrap: break-word;
}
.details-table td {
padding: 8px 12px;
font-size: 13px;
border-bottom: 1px solid #e9ecef;
vertical-align: top;
word-wrap: break-word;
word-break: break-word;
white-space: normal;
}
.block-details {
padding: 12px 20px;
background: #f5f5f5;
border-top: 1px solid #dee2e6;
border-bottom: 1px solid #dee2e6;
}
.days-badge {
display: inline-block;
padding: 4px 10px;
border-radius: 12px;
font-weight: 600;
font-size: 12px;
background: #e8f5e9;
color: #2e7d32;
text-align: center;
}
.days-badge.high {
background: #ffebee;
color: #c62828;
}
.comments-cell {
white-space: pre-wrap;
word-wrap: break-word;
word-break: break-word;
font-size: 12px;
line-height: 1.4;
text-align: left !important;
}
.contacts-cell {
white-space: pre-wrap;
word-wrap: break-word;
word-break: break-word;
font-size: 12px;
line-height: 1.4;
text-align: left;
}
.route-badge {
display: inline-block;
padding: 4px 8px;
border-radius: 4px;
background: #e3f2fd;
color: #1565c0;
font-weight: 600;
font-size: 11px;
}
.text-right {
text-align: right;
}
.text-center {
text-align: center;
}
.text-muted {
color: #6c757d;
}
.empty-value {
color: #9e9e9e;
font-style: italic;
font-size: 11px;
}
.details-table th:first-child,
.details-table td:first-child {
text-align: center;
}
/* Фиксированная ширина столбцов */
.details-table th:nth-child(1),
.details-table td:nth-child(1) {
width: 80px;
}
.details-table th:nth-child(2),
.details-table td:nth-child(2) {
width: 80px;
}
.details-table th:nth-child(3),
.details-table td:nth-child(3) {
width: 300px;
}
.details-table th:nth-child(4),
.details-table td:nth-child(4) {
width: 400px;
}
.details-table th:nth-child(5),
.details-table td:nth-child(5) {
width: 150px;
}
.details-table th:nth-child(6),
.details-table td:nth-child(6) {
width: 100px;
}
.details-table th:nth-child(7),
.details-table td:nth-child(7) {
width: 200px;
}
/* Стили для столбца Прозвон */
.prozvon-badge {
display: inline-block;
padding: 4px 10px;
border-radius: 12px;
font-weight: 600;
font-size: 12px;
background: #e3f2fd;
color: #1565c0;
text-align: center;
}
.prozvon-badge.complete {
background: #e8f5e9;
color: #2e7d32;
}
.prozvon-badge.incomplete {
background: #fff3e0;
color: #ef6c00;
}

/* ===== Стили для вкладок (табов) ===== */
.tabs-container {
margin-bottom: 16px;
background: #fff;
border: 1px solid #d0d7de;
border-radius: 6px;
padding: 4px;
display: flex;
flex-wrap: wrap;
gap: 4px;
}
.tab-button {
padding: 8px 20px;
font-size: 13px;
font-weight: 500;
background: transparent;
border: none;
border-radius: 4px;
cursor: pointer;
transition: all 0.2s ease;
color: #57606a;
}
.tab-button:hover {
background: #f6f8fa;
color: #0969da;
}
.tab-button.active {
background: #0969da;
color: #fff;
}
.tab-badge {
display: inline-block;
margin-left: 6px;
padding: 2px 6px;
font-size: 11px;
font-weight: 600;
border-radius: 12px;
background: rgba(0,0,0,0.1);
}
.tab-button.active .tab-badge {
background: rgba(255,255,255,0.2);
color: #fff;
}
.tab-button:not(.active) .tab-badge {
background: #e9ecef;
color: #57606a;
}

/* Адаптив для вкладок */
@media (max-width: 768px) {
.tab-button {
padding: 6px 12px;
font-size: 11px;
}
.tab-badge {
margin-left: 4px;
padding: 1px 4px;
font-size: 10px;
}
}
</style>
</head>
<body>
<?php include 'menu2.php'; ?>
<div class="app">
<div class="toolbar">
<h1>График вывоза</h1>
</div>

<!-- Вкладки разделов -->
<div class="tabs-container">
<button class="tab-button <?= $selectedTab == 'all' ? 'active' : '' ?>" data-tab="all">
📋 Все рейсы
<span class="tab-badge"><?= $countAll ?></span>
</button>
<button class="tab-button <?= $selectedTab == 'planned' ? 'active' : '' ?>" data-tab="planned">
📅 Планы на вывоз
<span class="tab-badge"><?= $countPlanned ?></span>
</button>
<button class="tab-button <?= $selectedTab == 'in_transit' ? 'active' : '' ?>" data-tab="in_transit">
🚚 Рейсы в пути
<span class="tab-badge"><?= $countInTransit ?></span>
</button>
<button class="tab-button <?= $selectedTab == 'unloaded' ? 'active' : '' ?>" data-tab="unloaded">
✅ Выгруженные рейсы
<span class="tab-badge"><?= $countUnloaded ?></span>
</button>
<button class="tab-button <?= $selectedTab == 'unassigned' ? 'active' : '' ?>" data-tab="unassigned">
⚠️ Нераспределенные
<span class="tab-badge"><?= $countUnassigned ?></span>
</button>
</div>

<?php if (!empty($flights)): ?>
<div class="card">
<div class="table-wrapper">
<table class="data-table" id="flightsTable">
<thead>
<tr>
<th style="width: 30px;"></th>
<th style="width: 150px;">ПЛАНИРУЕМАЯ ДАТА</th>
<th style="width: 40px;">ID</th>
<th style="width: 200px;">ОПИСАНИЕ РЕЙСА</th>
<th style="text-align: center;">СТАТУС</th>
<th style="text-align: center;">ВОДИТЕЛЬ / МАШИНА</th>
<th style="text-align: center;">МЕНЕДЖЕР</th>
<th style="text-align: center;">ПРОЗВОН</th>
<th style="text-align: center;">КОЛ-ВО АДРЕСОВ / ЗАЯВОК</th>
<th style="text-align: center;">ОБЩИЙ ВЕС, КГ</th>
</tr>
</thead>
<tbody>
<?php foreach ($flights as $flight): ?>
<tr class="group-header" onclick="toggleFlight('details_<?= $flight['id'] ?>', event)">
<td class="toggle-arrow" style="text-align: center;">▶</td>
<td>
<?php
$dateIcon = getDateIcon($flight, $selectedTab);

if (!empty($flight['planned_start_date'])) {
    echo $dateIcon . date('d.m.Y', strtotime($flight['planned_start_date']));
} elseif (!empty($flight['planned_start_date_from']) && !empty($flight['planned_start_date_to'])) {
    echo $dateIcon . date('d.m.Y', strtotime($flight['planned_start_date_from'])) . ' - ' . date('d.m.Y', strtotime($flight['planned_start_date_to']));
} elseif (!empty($flight['planned_start_date_from'])) {
    echo $dateIcon . 'с ' . date('d.m.Y', strtotime($flight['planned_start_date_from']));
} elseif (!empty($flight['planned_start_date_to'])) {
    echo $dateIcon . 'по ' . date('d.m.Y', strtotime($flight['planned_start_date_to']));
} elseif (!empty($flight['actual_start_date'])) {
    echo $dateIcon . date('d.m.Y', strtotime($flight['actual_start_date']));
} else {
    echo $dateIcon . '<span style="color:#999;">—</span>';
}
?>
</td>
<td><strong>#<?= $flight['id'] ?></strong></td>
<td><?= !empty($flight['comment']) ? htmlspecialchars($flight['comment']) : '<span style="color:#999;">—</span>' ?></td>
<td style="text-align: center;">
<?php
$statusCode = $flight['status'];
$statusData = $statusMap[$statusCode] ?? null;
// Проверяем: если статус "found" но водитель не заполнен, показываем "ждем данные водителя"
$showWaitingDriver = ($statusCode === 'found' && empty($flight['driver_id']));
if ($showWaitingDriver):
?>
<span class="status-badge status-waiting-driver">
данные водителя ?
</span>
<?php elseif ($statusData):
$styleClass = ltrim($statusData['style'], '.');
?>
<span class="status-badge <?= htmlspecialchars($styleClass) ?>">
<?= htmlspecialchars($statusData['наименование']) ?>
</span>
<?php else: ?>
<span class="status-badge" style="background: #6c757d;">
<?= htmlspecialchars($flight['status']) ?>
</span>
<?php endif; ?>
</td>
<td>
<?php if (!empty($flight['driver_name'])): ?>
<?= htmlspecialchars($flight['driver_name']) ?>
<?php if (!empty($flight['vehicle_make_plate'])): ?>
<br><small style="color:#666;"><?= htmlspecialchars($flight['vehicle_make_plate']) ?></small>
<?php endif; ?>
<?php else: ?>
<span style="color:#999;">—</span>
<?php endif; ?>
</td>
<td><?= !empty($flight['manager_name']) ? htmlspecialchars($flight['manager_name']) : '<span style="color:#999;">—</span>' ?></td>
<td style="text-align: center;">
<?php
$prepFilled = $flight['prep_time_filled_count'] ?? 0;
$totalZayavki = $flight['total_zayavki_count'] ?? 0;
$prozvonClass = ($totalZayavki > 0 && $prepFilled == $totalZayavki) ? 'complete' : 'incomplete';
?>
<span class="prozvon-badge <?= $prozvonClass ?>">
<?= $prepFilled ?> / <?= $totalZayavki ?>
</span>
</td>
<td style="text-align: center;">
<?= $flight['unique_addresses_count'] ?? 0 ?> / <?= $flight['zayavki_count'] ?? 0 ?>
</td>
<td style="text-align: right;"><?= number_format($flight['total_mass_kg'] ?? 0, 0, '.', ' ') ?> кг</td>
</tr>
<tr id="details_<?= $flight['id'] ?>" style="display: none;">
<td colspan="10" style="padding: 0;">
<div class="block-details">
<table class="details-table">
<thead>
<tr>
<th>ID ЗАЯВКИ</th>
<th>МАССА, КГ</th>
<th>ГРУЗООТПРАВИТЕЛЬ</th>
<th>АДРЕС ПОГРУЗКИ</th>
<th>КОНТАКТЫ</th>
<th>ПРОПУСК</th>
<th>КОММЕНТАРИИ</th>
</tr>
</thead>
<tbody>
<?php if (!empty($flight['zayavki'])): ?>
<?php foreach ($flight['zayavki'] as $zayavkaItem):
$mass_kg = floatval($zayavkaItem['mass_netto'] ?? 0) * 1000;
$prep_time = $zayavkaItem['prep_time'] ?? '';
$comments = $zayavkaItem['comments'] ?? '';
$daysClass = (is_numeric($prep_time) && $prep_time > 3) ? 'high' : '';
// Формируем контактную информацию с удалением дубликатов
$contacts = [];
// Обработка телефонов (kontakt_tel)
if (!empty($zayavkaItem['kontakt_tel'])) {
$phones = explode('|', $zayavkaItem['kontakt_tel']);
$phones = uniqueContacts($phones);
foreach ($phones as $phone) {
$contacts[] = htmlspecialchars($phone);
}
}
// Обработка дополнительных телефонов (tel_dopolnitelnyy)
if (!empty($zayavkaItem['tel_dopolnitelnyy'])) {
$extraPhones = explode('|', $zayavkaItem['tel_dopolnitelnyy']);
$extraPhones = uniqueContacts($extraPhones);
foreach ($extraPhones as $phone) {
$contacts[] = htmlspecialchars($phone);
}
}
// Обработка email (kontakt_email)
if (!empty($zayavkaItem['kontakt_email'])) {
$emails = explode('|', $zayavkaItem['kontakt_email']);
$emails = uniqueContacts($emails);
foreach ($emails as $email) {
$contacts[] = htmlspecialchars($email);
}
}
$contactsHtml = !empty($contacts) ? implode('<br>', $contacts) : '<span class="empty-value">—</span>';
?>
<tr>
<td class="text-center"><strong><?= htmlspecialchars($zayavkaItem['zayavka_id']) ?></strong></td>
<td class="text-right"><?= number_format($mass_kg, 0, '.', ' ') ?></td>
<td style="text-align: left;"><?= htmlspecialchars($zayavkaItem['naim_oo_gruzootpravitel'] ?? '') ?></td>
<td style="text-align: left;"><?= htmlspecialchars($zayavkaItem['mno_adres_pogruzki'] ?? '') ?></td>
<td class="contacts-cell"><?= $contactsHtml ?></td>
<td class="text-center">
<?php if (!empty($prep_time)): ?>
<span class="days-badge <?= $daysClass ?>"><?= htmlspecialchars($prep_time) ?></span>
<?php else: ?>
<span class="empty-value">—</span>
<?php endif; ?>
</td>
<td class="comments-cell">
<?php if (!empty($comments)): ?>
<?= nl2br(htmlspecialchars($comments)) ?>
<?php else: ?>
<span class="empty-value">—</span>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr>
<td colspan="7" class="text-center text-muted">Данные заявок не найдены</td>
</tr>
<?php endif; ?>
</tbody>
</table>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
<?php else: ?>
<div class="card">
<p style="text-align: center; color: #666;">
<?php
switch ($selectedTab) {
case 'planned':
echo 'Рейсы с планируемой датой не найдены';
break;
case 'in_transit':
echo 'Рейсы в пути не найдены';
break;
case 'unloaded':
echo 'Выгруженные рейсы не найдены';
break;
case 'unassigned':
echo 'Нераспределенные рейсы не найдены';
break;
default:
echo 'Рейсы не найдены';
}
?>
</p>
</div>
<?php endif; ?>
</div>

<script>
function toggleFlight(flightId, event) {
if (event) event.stopPropagation();
const flightRow = document.getElementById(flightId);
const headerRow = flightRow.previousElementSibling;
const arrow = headerRow.querySelector('.toggle-arrow');
if (flightRow.style.display === 'none') {
flightRow.style.display = 'table-row';
arrow.textContent = '▼';
} else {
flightRow.style.display = 'none';
arrow.textContent = '▶';
}
}

// Функционал переключения вкладок с сохранением выбора
document.addEventListener('DOMContentLoaded', function() {
const tabButtons = document.querySelectorAll('.tab-button');
const currentTab = '<?= $selectedTab ?>';

// Функция для переключения вкладки (перезагрузка страницы с параметром)
function switchTab(tabId) {
// Сохраняем выбор в localStorage
localStorage.setItem('timeline_selected_tab', tabId);
// Перезагружаем страницу с параметром tab
window.location.href = window.location.pathname + '?tab=' + tabId;
}

// Обработчики для кнопок
tabButtons.forEach(button => {
button.addEventListener('click', function(e) {
e.preventDefault();
const tabId = this.getAttribute('data-tab');
if (tabId && tabId !== currentTab) {
switchTab(tabId);
}
});
});
});
</script>
</body>
</html>