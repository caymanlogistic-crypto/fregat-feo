<?php
session_start();
require_once 'config.php';
require_once __DIR__ . '/../../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

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
    $statuses = $stmt->fetchAll();
    foreach ($statuses as $s) {
        $statusMap[$s['статус']] = $s;
    }
} catch (Exception $e) {}

// Получаем все рейсы для маппинга заявок
$zayavkaToFlightMap = [];
$flightDetailsMap = [];
try {
    $stmt = $pdo->query("
        SELECT id, zayavki_ids, comment, status, 
               planned_start_date, planned_start_date_from, planned_start_date_to,
               actual_start_date, actual_end_date
        FROM flights
    ");
    $flights = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($flights as $flight) {
        $zayavkiIds = array_filter(explode(',', $flight['zayavki_ids']));
        foreach ($zayavkiIds as $zayavkaId) {
            if (!isset($zayavkaToFlightMap[$zayavkaId])) {
                $zayavkaToFlightMap[$zayavkaId] = $flight['id'];
            }
        }
        $flightDetailsMap[$flight['id']] = [
            'comment' => $flight['comment'],
            'status' => $flight['status'],
            'planned_start_date' => $flight['planned_start_date'],
            'planned_start_date_from' => $flight['planned_start_date_from'],
            'planned_start_date_to' => $flight['planned_start_date_to'],
            'actual_start_date' => $flight['actual_start_date'],
            'actual_end_date' => $flight['actual_end_date']
        ];
    }
} catch (Exception $e) {}

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

// AJAX-запрос для получения ВСЕХ заявок
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax']) && $_GET['ajax'] === 'get_zayavki') {
    header('Content-Type: application/json');
    try {
        $filter_zayavki = isset($_GET['filter_zayavki']) ? trim($_GET['filter_zayavki']) : '';
        
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
        
        $where_conditions = [];
        $params = [];
        
        if ($has_filter && !empty($zayavki_ids)) {
            $placeholders = implode(',', array_fill(0, count($zayavki_ids), '?'));
            $where_conditions[] = "zayavka_id IN ($placeholders)";
            $params = array_merge($params, $zayavki_ids);
        }
        
        $where_sql = "";
        if (!empty($where_conditions)) {
            $where_sql = "WHERE " . implode(' AND ', $where_conditions);
        }
        
        $count_sql = "SELECT COUNT(*) as total FROM feo $where_sql";
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        $found_zayavki = [];
        if ($has_filter && !empty($zayavki_ids)) {
            $found_sql = "SELECT zayavka_id FROM feo $where_sql";
            $stmt = $pdo->prepare($found_sql);
            $stmt->execute($params);
            $found_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $found_zayavki = array_column($found_rows, 'zayavka_id');
        }
        
        $data_sql = "SELECT * FROM feo $where_sql ORDER BY id DESC";
        $stmt = $pdo->prepare($data_sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $zayavkaToAvailableBlockMap = [];
        try {
            $stmt = $pdo->query("SELECT id, zayavki_ids FROM status_blocks WHERE status_type = 'dostupno'");
            $blocks = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($blocks as $block) {
                $zayavkiIds = array_filter(explode(',', $block['zayavki_ids']));
                foreach ($zayavkiIds as $zayavkaId) {
                    if (!isset($zayavkaToAvailableBlockMap[$zayavkaId])) {
                        $zayavkaToAvailableBlockMap[$zayavkaId] = $block['id'];
                    }
                }
            }
        } catch (Exception $e) {}
        
        $html = '';
        foreach ($rows as $row) {
            $zayavka_id = $row['zayavka_id'] ?? '';
            
            $available_block_id = isset($zayavkaToAvailableBlockMap[$zayavka_id]) ? $zayavkaToAvailableBlockMap[$zayavka_id] : '';
            $flight_id = isset($zayavkaToFlightMap[$zayavka_id]) ? $zayavkaToFlightMap[$zayavka_id] : '';
            
            $mass_kg = isset($row['mass_netto']) && $row['mass_netto'] !== '' ? floatval($row['mass_netto']) * 1000 : '';
            $mass_display = $mass_kg !== '' ? number_format($mass_kg, 0, '.', ' ') : '';
            
            $available_display = $available_block_id !== '' 
                ? '<span class="status-available">ДОСТУПНО</span>' 
                : '<span style="color:#999;">—</span>';
            
            if ($flight_id !== '' && isset($flightDetailsMap[$flight_id])) {
                $flightComment = $flightDetailsMap[$flight_id]['comment'];
                $flightDisplay = '<b>#' . htmlspecialchars($flight_id) .' ' .htmlspecialchars($flightComment).'</b>';
            } else {
                $flightDisplay = '<span style="color:#999;">—</span>';
            }
            
            if ($flight_id !== '' && isset($flightDetailsMap[$flight_id])) {
                $flightStatus = $flightDetailsMap[$flight_id]['status'];
                $statusData = isset($statusMap[$flightStatus]) ? $statusMap[$flightStatus] : null;
                if ($statusData) {
                    $styleClass = ltrim($statusData['style'], '.');
                    $statusDisplay = '<span class="status-badge ' . htmlspecialchars($styleClass) . '">' 
                                   . htmlspecialchars($statusData['наименование']) . '</span>';
                } else {
                    $statusDisplay = '<span class="status-badge" style="background: #6c757d;">' 
                                   . htmlspecialchars($flightStatus) . '</span>';
                }
            } else {
                $statusDisplay = '<span style="color:#999;">—</span>';
            }
            
            if ($flight_id !== '' && isset($flightDetailsMap[$flight_id])) {
                $datesDisplay = formatFlightDates($flightDetailsMap[$flight_id]);
            } else {
                $datesDisplay = '<span style="color:#999;">—</span>';
            }
            
            $html .= '<tr data-id="' . htmlspecialchars($row['id']) . '" data-zayavka-id="' . htmlspecialchars($zayavka_id) . '">';
            $html .= '<td style="width: 80px;">' . htmlspecialchars($zayavka_id) . '</td>';
            $html .= '<td style="width: 90px; text-align: right;">' . $mass_display . '</td>';
            $html .= '<td style="width: 150px;">' . htmlspecialchars($row['mno_region'] ?? '') . '</td>';
            $html .= '<td style="width: 150px;">' . htmlspecialchars($row['mno_mo'] ?? '') . '</td>';
            $html .= '<td style="width: 200px;">' . htmlspecialchars($row['naim_oo_gruzootpravitel'] ?? '') . '</td>';
            $html .= '<td style="text-align: left;">' . htmlspecialchars($row['mno_adres_pogruzki'] ?? '') . '</td>';
            $html .= '<td style="width: 100px; text-align: center;">' . $available_display . '</td>';
            $html .= '<td style="width: 250px; text-align: left;">' . $flightDisplay . '</td>';
            $html .= '<td style="width: 140px; text-align: center;">' . $statusDisplay . '</td>';
            $html .= '<td style="width: 200px; text-align: center; font-size: 12px;">' . $datesDisplay . '</td>';
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
            'has_filter' => $has_filter,
            'filter_count' => count($zayavki_ids),
            'found_zayavki' => $found_zayavki,
            'missing_zayavki' => array_values($missing_zayavki)
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

$_SESSION['upload_token'] = bin2hex(random_bytes(32));
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Заявки FEO</title>
<link rel="stylesheet" href="style.css">
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}
html, body {
    height: 100%;
}
.app {
    display: flex;
    flex-direction: column;
    height: 100vh;
    padding: 10px 20px;
    background: white;
}
.toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    flex-shrink: 0;
}
.toolbar h1 {
    margin: 0;
    font-size: 24px;
}
.status-available {
    color: #28a745;
    font-weight: bold;
    background: #d4edda;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 11px;
    display: inline-block;
}
.status-badge {
    display: inline-block;
    width: 120px;
    text-align: center;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    color: #ffffff;
}

/* Цвета статусов из БД */
.status-search { background: #FFA459; }
.status-found { background: #17a2b8; }
.status-started { background: #28a745; }
.status-completed { background: #6c757d; }
.status-attention { background: #dc3545; }
.status-planned-route { background: #9c27b0; }
.filter-input-group {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}
.filter-input {
    flex: 1;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
    min-width: 200px;
}
.filter-stats {
    margin-top: 10px;
    font-size: 13px;
    color: #666;
}
.filter-stats-error {
    color: #dc3545;
    background: #f8d7da;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 12px;
}
.btn {
    padding: 8px 16px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
    background: #f0f0f0;
}
.btn:hover {
    background: #e0e0e0;
}
.filter-row {
    display: flex;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 15px;
    flex-wrap: wrap;
}
.filter-col {
    flex: 1;
    min-width: 280px;
}
.filter-col h4 {
    margin: 0 0 8px 0;
    font-size: 13px;
    color: #555;
    font-weight: normal;
}

/* FLEX КОНТЕЙНЕР ДЛЯ ПРАВИЛЬНОЙ ВЫСОТЫ */
.sticky-container {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    min-height: 0;
}

.sticky-header {
    flex-shrink: 0;
    background: white;
    z-index: 20;
    padding-bottom: 5px;
}

.table-wrapper {
    flex: 1;
    overflow-x: auto;
    overflow-y: auto;
    border: 1px solid #ddd;
    border-radius: 4px;
    min-height: 0;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.data-table th {
    background: #007bff;
    color: white;
    padding: 10px 8px;
    border-bottom: 1px solid #ddd;
    font-weight: 600;
    position: sticky;
    top: 0;
    z-index: 10;
}
.data-table td {
    padding: 8px;
    border-bottom: 1px solid #eee;
    vertical-align: top;
}
.loading-indicator {
    text-align: center;
    padding: 20px;
    color: #666;
}
</style>
</head>
<body>
<?php include 'menu2.php'; ?>
<div class="app">
    <div class="toolbar">
        <h1>ВСЕ ЗАЯВКИ</h1>
        <input type="file" id="fileInput" accept=".xlsx" style="display: none;">
    </div>
    
    <div class="sticky-container">
        <div class="sticky-header">
            <div class="filter-row">
                <div class="filter-col">
                    
                    <div class="filter-input-group">
                        <input type="text" id="filterZayavki" placeholder="Введите номера заявок через запятую, пробел" class="filter-input" value="">
                        <button id="clearFilterBtn" class="btn">Очистить</button>
                    </div>
                </div>
                <div class="filter-col">
                   
                    <div class="filter-input-group">
                        <input type="text" id="searchText" placeholder="Поиск: Регион, МО, Отправитель, Адрес, Доступно, Рейс, Статус..." class="filter-input" value="">
                        <button id="clearSearchBtn" class="btn">Очистить</button>
                    </div>
                </div>
            </div>
            <div id="filterInfo" class="filter-stats">
                <span id="filterStats"></span>
            </div>
        </div>
        
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
                        <th style="width: 250px; text-align: center;">Рейс</th>
                        <th style="width: 140px; text-align: center;">Статус</th>
                        <th style="width: 200px; text-align: center;">Даты</th>
                    </tr>
                </thead>
                <tbody id="tableBody"></tbody>
            </table>
            <div id="loadingIndicator" class="loading-indicator" style="display: none;">Загрузка...</div>
        </div>
    </div>
</div>

<script>
const tableBody = document.getElementById('tableBody');
const tableWrapper = document.querySelector('.table-wrapper');
const filterInput = document.getElementById('filterZayavki');
const searchInput = document.getElementById('searchText');
const clearFilterBtn = document.getElementById('clearFilterBtn');
const clearSearchBtn = document.getElementById('clearSearchBtn');
const filterStats = document.getElementById('filterStats');
const loadingIndicator = document.getElementById('loadingIndicator');
let currentSearch = '';
let isLoading = false;
let loadTimeout = null;
let allRowsData = [];

function loadData() {
    if (isLoading) return;
    isLoading = true;
    loadingIndicator.style.display = 'block';
    const filterValue = filterInput.value.trim();
    
    fetch(`?ajax=get_zayavki&filter_zayavki=${encodeURIComponent(filterValue)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                tableBody.innerHTML = data.html;
                
                const rows = tableBody.querySelectorAll('tr');
                allRowsData = [];
                rows.forEach(row => {
                    const cells = row.querySelectorAll('td');
                    if (cells.length >= 9) {
                        allRowsData.push({
                            element: row,
                            mno_region: cells[2]?.textContent || '',
                            mno_mo: cells[3]?.textContent || '',
                            gruzootpravitel: cells[4]?.textContent || '',
                            adres_pogruzki: cells[5]?.textContent || '',
                            available: cells[6]?.textContent || '',
                            flight: cells[7]?.textContent || '',
                            status: cells[8]?.textContent || ''
                        });
                    }
                });
                updateFilterInfo(data);
                applyTextFilter();
            } else {
                tableBody.innerHTML = '<tr><td colspan="10" style="text-align: center; padding: 40px; color: #dc3545;">Ошибка: ' + data.message + 'NonNullListResourceImpl';
            }
        })
        .catch(error => {
            console.error('Ошибка загрузки:', error);
            tableBody.innerHTML = '<tr><td colspan="10" style="text-align: center; padding: 40px; color: #dc3545;">Ошибка загрузки данных数据</td>';
        })
        .finally(() => {
            isLoading = false;
            loadingIndicator.style.display = 'none';
        });
}

function applyTextFilter() {
    const searchValue = currentSearch.toLowerCase();
    if (!searchValue) {
        allRowsData.forEach(item => item.element.style.display = '');
        return;
    }
    let visibleCount = 0;
    allRowsData.forEach(item => {
        const searchableText = [
            item.mno_region, item.mno_mo, item.gruzootpravitel,
            item.adres_pogruzki, item.available, item.flight, item.status
        ].join(' ').toLowerCase();
        
        if (searchableText.includes(searchValue)) {
            item.element.style.display = '';
            visibleCount++;
        } else {
            item.element.style.display = 'none';
        }
    });
    let html = filterStats.innerHTML;
    const searchRegex = / 🔍 Найдено по тексту: \d+/;
    html = html.replace(searchRegex, '');
    if (searchValue) html += ` 🔍 Найдено по тексту: ${visibleCount}`;
    filterStats.innerHTML = html;
}

function updateFilterInfo(data) {
    if (data.has_filter && data.filter_count > 0) {
        if (data.total > 0) {
            let message = '🔍 Найдено заявок: ' + data.total + ' из ' + data.filter_count + ' введенных';
            if (data.missing_zayavki && data.missing_zayavki.length > 0) {
                message += ' <span class="filter-stats-error">⚠️ Не найдены: ' + data.missing_zayavki.join(', ') + '</span>';
            }
            filterStats.innerHTML = message;
        } else {
            let message = '⚠️ По введенным номерам заявок (' + data.filter_count + ') ничего не найдено';
            if (data.missing_zayavki && data.missing_zayavki.length > 0) {
                message += ' <span class="filter-stats-error">: ' + data.missing_zayavki.join(', ') + '</span>';
            }
            filterStats.innerHTML = message;
        }
    } else if (data.has_filter && data.filter_count === 0) {
        filterStats.innerHTML = '⚠️ Не найдено корректных номеров заявок';
    } else {
        filterStats.innerHTML = '📋 Всего заявок в базе: ' + data.total;
    }
}

function handleFilterInput() {
    if (loadTimeout) clearTimeout(loadTimeout);
    loadTimeout = setTimeout(() => {
        loadData();
        if (tableWrapper) tableWrapper.scrollTop = 0;
    }, 500);
}

function handleSearchInput() {
    currentSearch = searchInput.value.trim();
    if (allRowsData.length > 0) {
        applyTextFilter();
    } else {
        loadData();
    }
}

function clearFilter() {
    filterInput.value = '';
    loadData();
}

function clearSearch() {
    searchInput.value = '';
    currentSearch = '';
    if (allRowsData.length > 0) applyTextFilter();
}

filterInput?.addEventListener('input', handleFilterInput);
clearFilterBtn?.addEventListener('click', clearFilter);
searchInput?.addEventListener('input', handleSearchInput);
clearSearchBtn?.addEventListener('click', clearSearch);

loadData();
</script>
</body>
</html>