<?php
require_once 'config.php';
try {
$pdo = new PDO("mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset=utf8mb4", $dbConfig['username'], $dbConfig['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
die("Ошибка подключения к базе данных: " . $e->getMessage());
}
// === НАСТРОЙКИ SLITEX API ===
$apiToken = trim((string) (getenv('SLITEX_API_TOKEN') ?: ''));
$baseUrl = 'https://slitex.online';
// === ФУНКЦИИ ДЛЯ РАБОТЫ С ТРЕКЕРАМИ ===
function fetchSlitexData(string $url, string $token): array {
$ch = curl_init();
curl_setopt_array($ch, [
CURLOPT_URL => $url,
CURLOPT_RETURNTRANSFER => true,
CURLOPT_HTTPHEADER => ["X-API-Token: {$token}"],
CURLOPT_TIMEOUT => 30,
CURLOPT_SSL_VERIFYPEER => true,
]);
$response = curl_exec($ch);
curl_close($ch);
return json_decode($response, true) ?: [];
}
function utcToMoscow(string $utcTime): string {
if (empty($utcTime)) return '';
$dt = new DateTime($utcTime, new DateTimeZone('UTC'));
$dt->setTimezone(new DateTimeZone('Europe/Moscow'));
return $dt->format('Y-m-d H:i:s');
}
function getTimeDiff(string $moscowTime): array {
if (empty($moscowTime)) return ['text' => 'Нет данных', 'minutes' => 9999];
$now = new DateTime('now', new DateTimeZone('Europe/Moscow'));
$then = new DateTime($moscowTime, new DateTimeZone('Europe/Moscow'));
$interval = $now->diff($then);
$totalMin = ($interval->days * 1440) + ($interval->h * 60) + $interval->i;
if ($interval->d > 0) $text = $interval->d . 'д ' . $interval->h . 'ч ' . $interval->i . 'м';
elseif ($interval->h > 0) $text = $interval->h . 'ч ' . $interval->i . 'м';
else $text = $interval->i . 'м';
return ['text' => $text, 'minutes' => $totalMin];
}
function getShortName(string $fullName): string {
if (preg_match('/\(([^)]+)\)/', $fullName, $matches)) {
return $matches[1];
}
return $fullName;
}
// === ЗАГРУЗКА ДАННЫХ ТРЕКЕРОВ ===
$trackers = [];
$devices = fetchSlitexData("{$baseUrl}/api/external/devices", $apiToken);
$adminFields = fetchSlitexData("{$baseUrl}/api/external/devices/admin-fields", $apiToken);
$merged = [];
foreach ($devices as $d) { $merged[$d['uniqueid']] = $d; }
foreach ($adminFields as $f) {
$uid = $f['uniqueid'];
if (isset($merged[$uid])) $merged[$uid]['admin_fields'] = $f['fields'] ?? [];
else $merged[$uid] = ['uniqueid' => $uid, 'name' => $f['name'] ?? null, 'admin_fields' => $f['fields'] ?? []];
}
foreach ($merged as $t) {
$name = $t['name'] ?? '';
if (preg_match('/^\d{6}$/', $name)) continue;
if (empty($t['lat']) || empty($t['lon'])) continue;
$lastupdateMSK = utcToMoscow($t['lastupdate'] ?? '');
$diff = getTimeDiff($lastupdateMSK);
$trackers[] = [
'uniqueid' => $t['uniqueid'],
'name' => $name,
'short_name' => getShortName($name),
'lat' => (float)$t['lat'],
'lon' => (float)$t['lon'],
'lastupdate_msk' => $lastupdateMSK,
'time_diff_text' => $diff['text'],
'time_diff_minutes' => $diff['minutes'],
'status' => $t['status'] ?? 'unknown'
];
}

// 🔹 НОВОЕ: ПОЛУЧЕНИЕ РАЗРЕШЁННЫХ DRIVER_ID ПО УСЛОВИЯМ 1-4
$stmt = $pdo->prepare("
    SELECT DISTINCT driver_id FROM flights WHERE driver_id IS NOT NULL AND (
        -- 1. Рейс в пути: старт был, финиша нет
        (actual_start_date IS NOT NULL AND actual_end_date IS NULL) OR
        -- 2. Недавно завершился: финиш сегодня
        (actual_end_date = CURDATE()) OR
        -- 3. Собираются вскоре: план старта в ближайшие 2 дня (включая сегодня)
        (planned_start_date IS NOT NULL AND planned_start_date <= DATE_ADD(CURDATE(), INTERVAL 2 DAY)) OR
        (planned_start_date_from IS NOT NULL AND planned_start_date_from <= DATE_ADD(CURDATE(), INTERVAL 2 DAY))
    )
");
$stmt->execute();
$allowedDriverIds = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));

// 🔹 УСЛОВИЕ 4 (Новые водители): Если нужно показывать только тех, кто ещё не встречался в завершённых рейсах,
// раскомментируйте блок ниже. В текущей версии он уже покрыт условиями 1-3.
/*
$stmtNew = $pdo->prepare("
    SELECT DISTINCT driver_id FROM flights WHERE driver_id IS NOT NULL 
    AND driver_id NOT IN (SELECT driver_id FROM flights WHERE actual_end_date IS NOT NULL)
");
$stmtNew->execute();
$allowedDriverIds = array_unique(array_merge($allowedDriverIds, array_map('strval', $stmtNew->fetchAll(PDO::FETCH_COLUMN))));
*/

$allowedDriverIdsJson = json_encode($allowedDriverIds);
// 🔹 КОНЕЦ БЛОКА

$trackersJson = json_encode($trackers);
// === КОНЕЦ БЛОКА ТРЕКЕРОВ ===
$statusColors = ['search' => '#FFA459', 'found' => '#17a2b8', 'started' => '#28a745', 'completed' => '#6c757d', 'attention' => '#dc3545'];
$statusNames = ['attention' => 'Срочно к вывозу', 'search' => 'Поиск подрядчика', 'found' => 'Исполнит. найден', 'started' => 'Вывоз начался', 'completed' => 'Груз сдан'];
function getActualStatuses($pdo) {
global $statusColors;
$stmt = $pdo->query("SELECT id, zayavki_ids, status FROM flights ORDER BY block_date DESC");
$statuses = []; $processed = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
foreach (explode(',', $row['zayavki_ids']) as $id) {
$id = trim($id);
if (!isset($processed[$id])) {
$statuses[$id] = ['status' => $row['status'], 'color' => $statusColors[$row['status']] ?? '#000', 'flight_id' => $row['id']];
$processed[$id] = true;
}
}
}
return $statuses;
}
function getAvailableZayavkiIds($pdo) {
$stmt = $pdo->query("SELECT zayavki_ids, comment FROM status_blocks WHERE status_type = 'dostupno'");
$available = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
$comment = $row['comment'] ?? ''; $layer = null; $color = null;
if (strpos($comment, '#') !== false) {
$pos = strpos($comment, '#');
$name = trim(substr($comment, 0, $pos)); $col = trim(substr($comment, $pos));
if (preg_match('/^#[0-9A-Fa-f]{6}$/', $col) || preg_match('/^#[0-9A-Fa-f]{3}$/', $col)) { $layer = $name; $color = $col; }
}
foreach (explode(',', $row['zayavki_ids']) as $id) { $id = trim($id); if ($id) $available[$id] = ['layer_name' => $layer, 'color' => $color]; }
}
return $available;
}
$flightStatuses = getActualStatuses($pdo);
$availableZayavki = getAvailableZayavkiIds($pdo);
$stmt = $pdo->query("SELECT zayavka_id, mass_netto, naim_oo_gruzootpravitel, mno_adres_pogruzki, mno_sh, mno_d, zakaz_s_ot_status FROM feo WHERE mno_sh IS NOT NULL AND mno_d IS NOT NULL");
$points = $stmt->fetchAll(PDO::FETCH_ASSOC);
$filteredPoints = [];
foreach ($points as $point) {
$inFlight = isset($flightStatuses[$point['zayavka_id']]); $isAvailable = isset($availableZayavki[$point['zayavka_id']]);
if (!$inFlight && !$isAvailable) continue;
$point['flight_status'] = $inFlight ? $flightStatuses[$point['zayavka_id']]['status'] : null;
$point['flight_id'] = $inFlight ? $flightStatuses[$point['zayavka_id']]['flight_id'] : null;
if ($inFlight) { $point['marker_color'] = $flightStatuses[$point['zayavka_id']]['color']; $point['in_flight'] = true; $point['custom_layer'] = null; $point['custom_color'] = null; }
else {
$point['in_flight'] = false; $data = $availableZayavki[$point['zayavka_id']];
if ($data['layer_name'] && $data['color']) { $point['marker_color'] = $data['color']; $point['custom_layer'] = $data['layer_name']; $point['custom_color'] = $data['color']; }
else { $point['marker_color'] = '#000'; $point['custom_layer'] = null; $point['custom_color'] = null; }
}
$filteredPoints[] = $point;
}
function canMergePoints($a, $b) {
if ($a['mno_sh'] != $b['mno_sh'] || $a['mno_d'] != $b['mno_d']) return false;
if ($a['in_flight'] !== $b['in_flight']) return false;
if (!$a['in_flight'] && ($a['custom_layer'] || $b['custom_layer'])) return $a['custom_layer'] === $b['custom_layer'];
if ($a['in_flight'] && $b['in_flight']) return $a['flight_id'] === $b['flight_id'];
return true;
}
$groupedPoints = []; $processed = [];
foreach ($filteredPoints as $i => $p) {
if (isset($processed[$i])) continue;
$key = "{$p['mno_sh']}|{$p['mno_d']}" . ($p['in_flight'] ? "|f|{$p['flight_status']}|{$p['flight_id']}" : "|n|" . ($p['custom_layer'] ?? 'def'));
$groupedPoints[$key] = ['mno_sh'=>$p['mno_sh'], 'mno_d'=>$p['mno_d'], 'mno_adres_pogruzki'=>$p['mno_adres_pogruzki'], 'naim_oo_gruzootpravitel'=>$p['naim_oo_gruzootpravitel'], 'flight_status'=>$p['flight_status'], 'flight_id'=>$p['flight_id'], 'marker_color'=>$p['marker_color'], 'in_flight'=>$p['in_flight'], 'custom_layer'=>$p['custom_layer'], 'custom_color'=>$p['custom_color'], 'points'=>[$p], 'total_mass'=>$p['mass_netto'], 'count'=>1, 'group_key'=>$key];
$processed[$i] = $key;
foreach ($filteredPoints as $j => $o) {
if ($j <= $i || isset($processed[$j])) continue;
if (canMergePoints($p, $o)) { $groupedPoints[$key]['points'][] = $o; $groupedPoints[$key]['total_mass'] += $o['mass_netto']; $groupedPoints[$key]['count']++; $processed[$j] = $key; }
}
}
$processedGroups = array_values($groupedPoints);
$coordMap = [];
foreach ($processedGroups as $idx => $g) { $coordMap["{$g['mno_sh']}|{$g['mno_d']}"][] = $idx; }
foreach ($coordMap as $arr) foreach ($arr as $i => $idx) $processedGroups[$idx]['base_index'] = $i;
$flightStatusList = []; $customLayers = []; $hasDefault = false;
foreach ($processedGroups as $g) {
if ($g['in_flight']) $flightStatusList[$g['flight_status']] = true;
else { if ($g['custom_layer']) $customLayers[$g['custom_layer']] = ['name'=>$g['custom_layer'], 'color'=>$g['custom_color']]; else $hasDefault = true; }
}
$flightStatusList = array_intersect_key(array_flip(array_keys($statusNames)), $flightStatusList);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Карта заявок + Транспорт</title>
<link rel="stylesheet" href="stylemap.css">
<script src="https://api-maps.yandex.ru/2.1/?apikey=<?= $apiKeyYandex ?>&lang=ru_RU" type="text/javascript"></script>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; } html, body { width: 100%; height: 100%; overflow: hidden; } #map { width: 100%; height: 100%; }
.layer-panel { position: absolute; top: 20px; right: 20px; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.2); padding: 15px; z-index: 1000; width: 280px; max-height: 95vh; overflow-y: auto; }
.layer-panel h3 { margin-bottom: 15px; color: #333; font-size: 16px; border-bottom: 2px solid #007bff; padding-bottom: 5px; }
.checkbox-item { display: flex; align-items: center; margin-bottom: 8px; padding: 4px 8px; background: #f8f9fa; border-radius: 4px; font-size: 13px; }
.checkbox-item input[type="checkbox"] { margin-right: 8px; cursor: pointer; } .checkbox-item label { cursor: pointer; flex: 1; } .color-indicator { width: 16px; height: 16px; border-radius: 4px; margin-left: 8px; }
.custom-badge { background: #e3f2fd; color: #1976d2; font-size: 10px; padding: 2px 6px; border-radius: 10px; margin-left: 5px; }
.custom-balloon { padding: 15px; max-width: 350px; font-family: Arial, sans-serif; }
.custom-balloon h4 { margin-bottom: 10px; color: #333; font-size: 14px; border-bottom: 1px solid #eee; padding-bottom: 5px; }
.custom-balloon .info-row { margin-bottom: 6px; font-size: 12px; line-height: 1.4; } .custom-balloon .info-label { font-weight: bold; color: #555; display: inline-block; min-width: 90px; }
.group-badge { background: #e3f2fd; color: #1976d2; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; margin-left: 8px; }
.points-list { margin-top: 10px; border-top: 1px solid #eee; padding-top: 8px; max-height: 250px; overflow-y: auto; }
.point-item { font-size: 11px; padding: 6px 8px; margin-bottom: 4px; background: #f8f9fa; border-radius: 4px; border-left: 3px solid #ddd; }
.point-item .point-number { font-weight: bold; color: #1976d2; } .point-item .point-weight { color: #d32f2f; font-weight: bold; margin-left: 5px; }
.layer-group { margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid #eee; } .layer-group:last-child { border-bottom: none; }
.layer-group-title { font-size: 12px; font-weight: bold; color: #666; margin-bottom: 8px; text-transform: uppercase; }
.routes-panel { position: absolute; top: 20px; left: 20px; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.2); padding: 15px; z-index: 1000; width: 280px; max-height: 95vh; overflow-y: auto; }
.routes-panel h3 { margin-bottom: 15px; font-size: 14px; border-bottom: 2px solid #9c27b0; padding-bottom: 5px; }
.route-item { padding: 8px 10px; margin-bottom: 8px; background: #f8f9fa; border-radius: 6px; border-left: 3px solid #9c27b0; cursor: pointer; transition: all 0.2s; position: relative; }
.route-item:hover { background: #e9ecef; } .route-item.active { background: #e3f2fd; border-left-color: #1976d2; }
.route-name { font-weight: bold; font-size: 13px; padding-right: 30px; } .route-meta { font-size: 11px; color: #6c757d; margin-top: 4px; }
.route-delete { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; font-size: 14px; color: #dc3545; opacity: 0.6; } .route-delete:hover { opacity: 1; }
.route-list-empty { text-align: center; color: #888; font-size: 12px; padding: 10px 0; }
.selection-panel { position: absolute; top: 20px; right: 310px; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.2); padding: 15px; z-index: 1000; width: 280px; max-height: 95vh; overflow-y: auto; display: none; }
.selection-panel h4 { margin: 0 0 10px; color: #333; border-bottom: 2px solid #28a745; padding-bottom: 5px; font-size: 15px; }
#selected-list { max-height: 350px; overflow-y: auto; margin-bottom: 10px; padding-right: 5px; }
.selected-item { display: flex; justify-content: space-between; align-items: center; padding: 6px 10px; background: #e8f5e9; border-radius: 5px; margin-bottom: 5px; font-size: 12px; border: 1px solid #c8e6c9; }
.selected-item button { background: none; border: none; color: #dc3545; cursor: pointer; font-size: 16px; font-weight: bold; padding: 0 5px; line-height: 1; } .selected-item button:hover { color: #a71d2a; }
#stats-box { font-weight: bold; margin: 10px 0; padding: 8px; background: #f0f8ff; border-radius: 5px; text-align: center; color: #1976d2; border: 1px solid #bbdefb; font-size: 13px; }
#total-weight { font-weight: bold; margin-bottom: 10px; padding: 8px; background: #fff8e1; border-radius: 5px; text-align: center; color: #f57c00; border: 1px solid #ffe0b2; font-size: 13px; }
#route-info-container { margin-bottom: 10px; } .route-info { background: #e3f2fd; border: 1px solid #90caf9; border-radius: 5px; padding: 10px; font-size: 12px; }
.route-info-item { margin: 5px 0; } .route-info-label { font-weight: bold; color: #1976d2; }
#route-edit-status { text-align: center; font-size: 12px; margin-bottom: 10px; padding: 6px; border-radius: 4px; background: #f8f9fa; border: 1px dashed #dee2e6; }
.cost-input-container { margin-bottom: 15px; background: #f8f9fa; padding: 8px; border-radius: 6px; border: 1px solid #dee2e6; }
.cost-input-container label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px; color: #495057; }
.cost-input { width: 100%; padding: 6px; border: 1px solid #ced4da; border-radius: 4px; font-size: 13px; }
.cost-input:focus { border-color: #9c27b0; outline: none; box-shadow: 0 0 0 2px rgba(156,39,176,0.2); }
.manual-input { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 5px; font-size: 12px; font-family: monospace; margin-bottom: 10px; resize: vertical; min-height: 60px; line-height: 1.4; } .manual-input:focus { outline: none; border-color: #28a745; box-shadow: 0 0 0 2px rgba(40,167,69,0.2); }
.route-buttons { display: flex; gap: 8px; margin-bottom: 10px; }
.route-btn { flex: 1; padding: 10px; border: none; border-radius: 5px; cursor: pointer; font-weight: 600; font-size: 13px; transition: all 0.2s; }
.calculate-route-btn { background: #007bff; color: white; } .calculate-route-btn:hover { background: #0056b3; }
.save-route-btn { background: #9c27b0; color: white; } .save-route-btn:hover { background: #7b1fa2; }
.clear-btn { flex: 1; padding: 10px; background: #6c757d; color: white; border: none; border-radius: 5px; cursor: pointer; font-weight: 500; transition: background 0.2s; } .clear-btn:hover { background: #5a6268; }
.hint { font-size: 10px; color: #888; margin-bottom: 5px; text-align: center; }
/* === СТИЛИ ДЛЯ КНОПКИ ОБНОВЛЕНИЯ ТРЕКЕРОВ === */
.refresh-btn {
position: absolute;
bottom: 20px;
right: 20px;
background: rgba(0, 0, 0, 0.6);
backdrop-filter: blur(8px);
color: white;
border: 1px solid rgba(255, 255, 255, 0.2);
border-radius: 40px;
padding: 8px 18px;
font-size: 13px;
font-weight: 500;
cursor: pointer;
z-index: 1100;
box-shadow: 0 2px 8px rgba(0,0,0,0.15);
transition: all 0.2s ease;
font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}
.refresh-btn:hover {
background: rgba(0, 0, 0, 0.8);
border-color: rgba(255, 255, 255, 0.4);
transform: scale(1.02);
}
.refresh-btn:active { transform: scale(0.98); }
.refresh-btn:disabled { opacity: 0.6; cursor: not-allowed; }
</style>
</head>
<body>
<?php include 'menu2.php'; ?>
<div id="map"></div>
<!-- Кнопка обновления трекеров -->
<button class="refresh-btn" id="refreshBtn" title="Обновить позиции транспорта">🔄 Обновить транспорт</button>
<div class="routes-panel">
<h3>🗺️ Планируемые маршруты</h3>
<div id="plannedRoutesList" class="routes-list"><div class="route-list-empty">Загрузка...</div></div>
</div>
<div class="layer-panel">
<h3>Управление слоями</h3>
<div class="layer-group">
<div class="layer-group-title">🚚 Рейсы</div>
<div class="checkbox-item"><input type="checkbox" id="no_flight_default" checked onchange="filterByCustomLayer('default', this.checked)"><label for="no_flight_default">Доступно к вывозу</label><div class="color-indicator" style="background: #000000"></div></div>
<?php foreach ($flightStatusList as $status => $v): ?>
<div class="checkbox-item"><input type="checkbox" id="flight_status_<?= $status ?>" <?= ($status == 'started' || $status == 'completed') ? '' : 'checked' ?> onchange="filterByFlightStatus('<?= $status ?>', this.checked)"><label for="flight_status_<?= $status ?>"><?= htmlspecialchars($statusNames[$status] ?? $status) ?></label><div class="color-indicator" style="background: <?= $statusColors[$status] ?? '#000000' ?>"></div></div>
<?php endforeach; ?>
</div>
<?php if ($hasDefault || !empty($customLayers)): ?>
<div class="layer-group"><div class="layer-group-title">📦 Дополнительно</div>
<?php foreach ($customLayers as $ln => $ld): ?>
<div class="checkbox-item"><input type="checkbox" id="custom_layer_<?= md5($ln) ?>" checked onchange="filterByCustomLayer('<?= addslashes($ln) ?>', this.checked)"><label for="custom_layer_<?= md5($ln) ?>"><?= htmlspecialchars($ln) ?> <span class="custom-badge">кастомный</span></label><div class="color-indicator" style="background: <?= $ld['color'] ?>"></div></div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<!-- === НОВЫЙ ФИЛЬТР: ТРАНСПОРТ === -->
<div class="layer-group" style="border-top: 1px solid #eee; padding-top: 10px; margin-top: 5px;">
<div class="layer-group-title">🚛 Транспорт (Slitex)</div>
<div class="checkbox-item">
<input type="checkbox" id="hide_transport" onchange="toggleTransportVisibility(this.checked)">
<label for="hide_transport">🚫 Не отображать транспорт</label>
<div class="color-indicator" style="background: linear-gradient(135deg, #2196F3, #4CAF50, #FF9800, #F44336)"></div>
</div>
<div style="font-size: 10px; color: #888; margin-left: 24px; margin-top: 4px;">
🔵 &lt;10м | 🟢 &lt;1ч | 🟠 &lt;3ч | 🔴 &gt;3ч
</div>
<!-- 🔹 НОВОЕ: ЧЕКБОКС "ПОКАЗАТЬ ВСЕ МАШИНЫ" -->
<div class="checkbox-item" style="margin-top: 8px; border-top: 1px dashed #ccc; padding-top: 8px;">
    <input type="checkbox" id="show_all_transport" onchange="toggleShowAllTransport(this.checked)">
    <label for="show_all_transport">🚛 Показать все машины (без фильтра)</label>
</div>
</div>
<div class="layer-group" style="border-bottom: none; margin-bottom: 0; padding-bottom: 0;"><div class="checkbox-item" style="background: #fff3cd; border: 1px solid #ffc107;"><input type="checkbox" id="disable-popups" checked onchange="togglePopups(this.checked)"><label for="disable-popups">🚫 Не показывать попапы</label></div></div>
</div>
<div class="selection-panel" id="selection-panel">
<h4>📝 Выделенные заявки</h4>
<div id="stats-box">Заявок: 0 | Адресов: 0</div>
<div id="total-weight">Общий вес: 0 кг</div>
<div id="route-edit-status"><span style="color:#28a745;">🆕 Создание нового маршрута</span></div>
<div class="cost-input-container">
<label for="route-cost-input">💰 Стоимость маршрута (₽):</label>
<input type="number" id="route-cost-input" class="cost-input" placeholder="0.00" step="0.01" min="0">
</div>
<div id="route-info-container"></div>
<div id="selected-list"></div>
<div class="hint">Вводите ID через запятую или Enter</div>
<textarea id="route-input" class="manual-input" placeholder="ID заявок..."></textarea>
<div class="route-buttons">
<button class="route-btn calculate-route-btn" id="calc-route-btn" onclick="calculateRoute()" disabled>🚚 Рассчитать</button>
<button class="route-btn save-route-btn" id="saveRouteBtn" onclick="promptSaveRoute()" disabled>📌 Сохранить как маршрут</button>
</div>
<button class="clear-btn" onclick="clearSelection()">Очистить выделение</button>
</div>
<script>
let map, placemarks = [], groupsData = [], flightStatusFilters = {}, customLayerFilters = {};
let selectedOrder = [];
let weightById = {};
let svgCache = {};
let popupsDisabled = true;
let multiRoute = null;
let editingRouteId = null;
const UNLOAD_COORDS = [55.3228, 38.6969];
const UNLOAD_ADDRESS = 'Московская обл, г Воскресенск, ул Кирова, д 3 в';
// === ПЕРЕМЕННЫЕ ДЛЯ ТРЕКЕРОВ И КОЛЛЕКЦИЙ ===
let trackerPlacemarks = [];
let trackerUpdateInterval = null;
let isUpdatingTrackers = false;
let showTransport = true;
let requestsCollection, trackersCollection;
// 🔹 НОВОЕ: ПЕРЕМЕННЫЕ ДЛЯ ФИЛЬТРАЦИИ ТРАНСПОРТА
let showAllTransport = false;
let currentTrackersData = [];
const allowedDriverIds = <?= $allowedDriverIdsJson ?>;

// 🔹 ФУНКЦИЯ ПРИМЕНЕНИЯ БИЗНЕС-ПРАВИЛ (1-3)
function filterTrackersByBusinessRules(trackers) {
    if (showAllTransport) return trackers; // Чекбокс "Все машины" активен
    return trackers.filter(t => {
        const uid = String(t.uniqueid).trim();
        // Гибкое сравнение: точное совпадение или вхождение (на случай префиксов типа TRK_123)
        return allowedDriverIds.some(did => uid === did || uid.includes(did) || did.includes(uid));
    });
}
// 🔹 КОНЕЦ НОВОГО БЛОКА

// === ДАННЫЕ ТРЕКЕРОВ ИЗ PHP ===
const initialTrackers = <?= $trackersJson ?>;
// === ФУНКЦИИ ДЛЯ ТРЕКЕРОВ ===
function getTrackerPreset(minutes) {
if (minutes < 10) return 'islands#blueStretchyIcon';
if (minutes < 60) return 'islands#greenStretchyIcon';
if (minutes < 180) return 'islands#orangeStretchyIcon';
return 'islands#redStretchyIcon';
}
function escapeHtml(str) {
if (!str) return '';
return str.replace(/[&<>"']/g, function(m) {
const map = {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'};
return map[m] || m;
});
}
function createTrackerBalloon(tracker) {
return `<div style="padding:12px; font-family:Arial,sans-serif; max-width:280px;">
<b>🚛 ${escapeHtml(tracker.name)}</b><br>
<span style="color:#666; font-size:12px;">ID: ${tracker.uniqueid}</span><br>
<span style="color:#888; font-size:11px;">⏱️ ${tracker.time_diff_text} назад</span><br>
<span style="color:#555; font-size:11px;">📍 ${tracker.lat.toFixed(5)}, ${tracker.lon.toFixed(5)}</span>
</div>`;
}
function addTrackerMarkers(trackers) {
// 🔹 СОХРАНЯЕМ КЭШ ДЛЯ МГНОВЕННОГО ПЕРЕКЛЮЧЕНИЯ ФИЛЬТРОВ
currentTrackersData = trackers;
// Удаляем старые маркеры трекеров из коллекции транспорта
trackerPlacemarks.forEach(pm => trackersCollection.remove(pm));
trackerPlacemarks = [];
if (!showTransport) return;

// 🔹 ПРИМЕНЯЕМ ФИЛЬТР ПЕРЕД ОТРИСОВКОЙ
const visibleTrackers = filterTrackersByBusinessRules(trackers);

visibleTrackers.forEach(tracker => {
const preset = getTrackerPreset(tracker.time_diff_minutes);
const placemark = new ymaps.Placemark(
[tracker.lat, tracker.lon],
{
iconContent: tracker.short_name,
hintContent: `${tracker.name} | ${tracker.time_diff_text} назад`,
balloonContent: createTrackerBalloon(tracker),
trackerData: tracker
},
{
preset: preset,
iconImageScale: 0.7,
iconContentOffset: [0, -7],
visible: showTransport,
zIndex: 1000 // 🔥 ТРАНСПОРТ ВСЕГДА ВЫШЕ ЗАЯВОК
}
);
trackerPlacemarks.push(placemark);
trackersCollection.add(placemark);
});
}
async function fetchTrackers() {
if (isUpdatingTrackers) return;
isUpdatingTrackers = true;
const btn = document.getElementById('refreshBtn');
const originalText = btn.innerHTML;
btn.innerHTML = '⏳ ...';
btn.disabled = true;
try {
const response = await fetch(window.location.href, {
method: 'GET',
headers: { 'X-Requested-With': 'XMLHttpRequest' }
});
const html = await response.text();
const match = html.match(/const initialTrackers = (\[[\s\S]*?\]);/);
if (match) {
const newTrackers = JSON.parse(match[1]);
addTrackerMarkers(newTrackers);
}
} catch(e) {
console.error('Ошибка обновления трекеров:', e);
} finally {
isUpdatingTrackers = false;
btn.innerHTML = originalText;
btn.disabled = false;
}
}
function toggleTransportVisibility(hide) {
showTransport = !hide;
trackerPlacemarks.forEach(pm => pm.options.set('visible', showTransport));
}
// 🔹 НОВОЕ: ОБРАБОТЧИК ЧЕКБОКСА "ПОКАЗАТЬ ВСЕ МАШИНЫ"
function toggleShowAllTransport(showAll) {
    showAllTransport = showAll;
    // Мгновенно перерисовываем карту с текущими данными, меняя режим фильтра
    if (currentTrackersData.length > 0) addTrackerMarkers(currentTrackersData);
}
// 🔹 КОНЕЦ НОВОГО БЛОКА

// === ОСНОВНЫЕ ФУНКЦИИ ===
function createMarkerSVG(totalMass, inFlight, markerColor = '#000000', flightId = null, hideTriangle = false, isSelected = false) {
const key = `${totalMass}_${inFlight}_${markerColor}_${flightId}_${hideTriangle}_${isSelected}`;
if (svgCache[key]) return svgCache[key];
const weightInKg = Math.round(totalMass * 1000);
let bgColor = isSelected ? '#e3f2fd' : '#ffffff';
if (weightInKg > 10000 && !isSelected) bgColor = '#fd4221';
else if (weightInKg > 5000 && !isSelected) bgColor = '#FF7400';
else if (weightInKg > 3000 && !isSelected) bgColor = '#FFA459';
else if (weightInKg > 1000 && !isSelected) bgColor = '#FFCBA1';
const strokeColor = isSelected ? '#0055ff' : markerColor;
const textColor = isSelected ? '#0055ff' : '#000000';
const strokeWidth = isSelected ? 4 : 2;
const filter = isSelected ? 'filter="drop-shadow(0 0 4px rgba(0,85,255,0.6))"' : '';
const h = 25, w = 40, bw = 18, bh = 12;
let svg = `<svg width="${w+10}" height="${h+20}" viewBox="0 0 ${w+10} ${h+20}" xmlns="http://www.w3.org/2000/svg">`;
svg += `<rect x="1.5" y="16.5" width="${w-3}" height="${h-3}" rx="6" ry="6" fill="${bgColor}" stroke="${strokeColor}" stroke-width="${strokeWidth}" ${filter}/>`;
svg += `<text x="${w/2}" y="${h/2 + 19}" font-family="Arial" font-size="11" fill="${textColor}" text-anchor="middle" font-weight="bold">${weightInKg}</text>`;
if (inFlight && flightId) { const bx = w - bw + 10, by = 8; svg += `<rect x="${bx}" y="${by}" width="${bw}" height="${bh}" rx="2" ry="2" fill="${isSelected ? '#0055ff' : strokeColor}"/><text x="${bx + bw/2}" y="${by + bh - 3}" font-family="Arial" font-size="8" fill="#fff" text-anchor="middle" font-weight="bold">#${flightId}</text>`; }
if (!hideTriangle) { svg += `<polygon points="${w/2-5},${h+13.5} ${w/2+5},${h+13.5} ${w/2},${h+18.5}" fill="${strokeColor}"/>`; }
svg += '</svg>';
const uri = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
svgCache[key] = uri; return uri;
}
function createBalloonContent(group) {
const totalWeightKg = Math.round(group.total_mass * 1000); const firstPoint = group.points[0]; const statusNames = <?= json_encode($statusNames) ?>;
let html = '<div class="custom-balloon">';
if (group.count > 1) {
html += `<h4>📍 Заявки <span class="group-badge">${group.count} шт.</span></h4>`;
html += `<div class="info-row"><span class="info-label">Общий вес:</span> <strong>${totalWeightKg} кг</strong></div>`;
html += `<div class="info-row"><span class="info-label">Грузоотправитель:</span> ${firstPoint.naim_oo_gruzootpravitel || 'Не указан'}</div>`;
html += `<div class="info-row"><span class="info-label">Адрес:</span> ${firstPoint.mno_adres_pogruzki || 'Не указан'}</div>`;
if (group.in_flight && group.flight_status) { html += `<div class="info-row"><span class="info-label">Статус рейса:</span> <span style="color: ${group.marker_color}; font-weight: bold;">${statusNames[group.flight_status] || group.flight_status}</span></div>`; if (group.flight_id) html += `<div class="info-row"><span class="info-label">ID рейса:</span> <strong>${group.flight_id}</strong></div>`; }
else if (!group.in_flight) { if (group.custom_layer) html += `<div class="info-row"><span class="info-label">Категория:</span> <span style="color: ${group.marker_color}; font-weight: bold;">${group.custom_layer}</span></div>`; else html += `<div class="info-row"><span class="info-label">Статус:</span> <span style="color: #000; font-weight: bold;">Доступно к вывозу</span></div>`; }
html += `<div class="points-list">`; group.points.forEach(p => html += `<div class="point-item"><span class="point-number">#${p.zayavka_id}</span><span class="point-weight">${Math.round(p.mass_netto * 1000)} кг</span></div>`); html += '</div>';
} else {
html += `<h4>📍 Заявка #${firstPoint.zayavka_id}</h4>`;
html += `<div class="info-row"><span class="info-label">Вес:</span> <strong>${totalWeightKg} кг</strong></div>`;
html += `<div class="info-row"><span class="info-label">Грузоотправитель:</span> ${firstPoint.naim_oo_gruzootpravitel || 'Не указан'}</div>`;
html += `<div class="info-row"><span class="info-label">Адрес:</span> ${firstPoint.mno_adres_pogruzki || 'Не указан'}</div>`;
html += `<div class="info-row"><span class="info-label">Основной статус:</span> ${firstPoint.zakaz_s_ot_status || 'Не указан'}</div>`;
if (group.in_flight && group.flight_status) { html += `<div class="info-row"><span class="info-label">Статус рейса:</span> <span style="color: ${group.marker_color}; font-weight: bold;">${statusNames[group.flight_status] || group.flight_status}</span></div>`; if (group.flight_id) html += `<div class="info-row"><span class="info-label">ID рейса:</span> <strong>${group.flight_id}</strong></div>`; }
else if (!group.in_flight) { if (group.custom_layer) html += `<div class="info-row"><span class="info-label">Категория:</span> <span style="color: ${group.marker_color}; font-weight: bold;">${group.custom_layer}</span></div>`; else html += `<div class="info-row"><span class="info-label">Статус:</span> <span style="color: #000; font-weight: bold;">Доступно к вывозу</span></div>`; }
}
html += '</div>'; return html;
}
function updateSelectionUI() {
const panel = document.getElementById('selection-panel');
const list = document.getElementById('selected-list');
const totalEl = document.getElementById('total-weight');
const statsEl = document.getElementById('stats-box');
const input = document.getElementById('route-input');
const calcBtn = document.getElementById('calc-route-btn');
const saveBtn = document.getElementById('saveRouteBtn');
if (selectedOrder.length === 0) {
panel.style.display = 'none';
if(calcBtn) calcBtn.disabled = true;
if(saveBtn) saveBtn.disabled = true;
return;
}
panel.style.display = 'block';
list.innerHTML = '';
let totalW = 0;
let uniqueAddresses = new Set();
selectedOrder.forEach(id => {
const w = weightById[id] || 0;
totalW += w;
groupsData.forEach(g => g.points.forEach(p => {
if (String(p.zayavka_id) === String(id) && p.mno_adres_pogruzki) uniqueAddresses.add(p.mno_adres_pogruzki);
}));
const item = document.createElement('div');
item.className = 'selected-item';
item.innerHTML = `<span>#${id} – ${w} кг</span><button onclick="removeSelectedId('${id}')">×</button>`;
list.appendChild(item);
});
statsEl.textContent = `Заявок: ${selectedOrder.length} | Адресов: ${uniqueAddresses.size}`;
totalEl.textContent = `Общий вес: ${totalW.toLocaleString('ru-RU')} кг`;
input.value = selectedOrder.join(', ');
calcBtn.disabled = selectedOrder.length < 2;
saveBtn.disabled = false;
}
function refreshMarkerStyles() {
placemarks.forEach(pm => {
if (!pm.options.get('visible')) return;
const g = pm.properties.get('groupData');
const hideTriangle = pm.properties.get('hideTriangle');
const isSelected = g.points.some(p => selectedOrder.includes(String(p.zayavka_id)));
pm.options.set('iconImageHref', createMarkerSVG(g.total_mass, g.in_flight, g.marker_color, g.flight_id, hideTriangle, isSelected));
});
}
function handleRouteInput() {
const input = document.getElementById('route-input');
const raw = input.value;
const ids = raw.split(/[,;\s\n]+/).map(s => s.trim()).filter(s => /^\d+$/.test(s));
selectedOrder = ids;
refreshMarkerStyles();
updateSelectionUI();
}
document.addEventListener('DOMContentLoaded', () => {
const input = document.getElementById('route-input');
if (input) {
input.addEventListener('keydown', e => {
if (e.key === 'Enter' && !e.shiftKey) {
e.preventDefault();
handleRouteInput();
}
});
input.addEventListener('paste', () => setTimeout(handleRouteInput, 10));
input.addEventListener('blur', handleRouteInput);
}
});
function toggleGroupSelection(group) {
const ids = group.points.map(p => String(p.zayavka_id));
const allSelected = ids.every(id => selectedOrder.includes(id));
if (allSelected) {
ids.forEach(id => {
const index = selectedOrder.indexOf(id);
if (index !== -1) selectedOrder.splice(index, 1);
});
} else {
ids.forEach(id => {
if (!selectedOrder.includes(id)) selectedOrder.push(id);
});
}
refreshMarkerStyles();
updateSelectionUI();
}
function removeSelectedId(id) {
const index = selectedOrder.indexOf(String(id));
if (index !== -1) selectedOrder.splice(index, 1);
refreshMarkerStyles();
updateSelectionUI();
}
function clearSelection() {
selectedOrder = [];
editingRouteId = null;
document.getElementById('route-input').value = '';
document.getElementById('route-cost-input').value = '';
document.getElementById('route-info-container').innerHTML = '';
document.querySelectorAll('.route-item').forEach(el => el.classList.remove('active'));
document.getElementById('route-edit-status').innerHTML = '<span style="color:#28a745;">🆕 Создание нового маршрута</span>';
const saveBtn = document.getElementById('saveRouteBtn');
saveBtn.textContent = '📌 Сохранить как маршрут';
saveBtn.style.background = '#9c27b0';
if (multiRoute) { map.geoObjects.remove(multiRoute); multiRoute = null; }
refreshMarkerStyles();
updateSelectionUI();
}
function togglePopups(disabled) {
popupsDisabled = disabled;
placemarks.forEach(pm => { if (disabled) pm.balloon.close(); });
trackerPlacemarks.forEach(pm => { if (disabled) pm.balloon.close(); });
}
function updateMap() {
const visibleByCoord = {};
placemarks.forEach((pm, idx) => {
const g = pm.properties.get('groupData');
let visible = true;
if (g.in_flight) { const filterVal = flightStatusFilters[g.flight_status]; if (filterVal === false) visible = false; }
else { const layer = g.custom_layer || 'default'; const filterVal = customLayerFilters[layer]; if (filterVal === false) visible = false; }
if (!visible) { pm.options.set('visible', false); return; }
const key = `${g.mno_sh}|${g.mno_d}`;
if (!visibleByCoord[key]) visibleByCoord[key] = [];
visibleByCoord[key].push({ pm, idx });
});
for (const key in visibleByCoord) {
const items = visibleByCoord[key];
items.sort((a, b) => (groupsData[a.idx].base_index || 0) - (groupsData[b.idx].base_index || 0));
items.forEach((item, pos) => {
const pm = item.pm;
const g = groupsData[item.idx];
const isFirst = pos === 0;
const shiftY = pos * 25;
const isSelected = g.points.some(p => selectedOrder.includes(String(p.zayavka_id)));
pm.properties.set('hideTriangle', !isFirst);
pm.options.set({
iconImageHref: createMarkerSVG(g.total_mass, g.in_flight, g.marker_color, g.flight_id, !isFirst, isSelected),
iconImageOffset: [-25, -45 - shiftY],
balloonOffset: [0, -30 - shiftY],
visible: true
});
});
}
updateSelectionUI();
}
function filterByFlightStatus(status, visible) { flightStatusFilters[status] = visible; updateMap(); }
function filterByCustomLayer(layerName, visible) { customLayerFilters[layerName] = visible; updateMap(); }
function loadPlannedRoutes() {
const container = document.getElementById('plannedRoutesList');
container.innerHTML = '<div class="route-list-empty">Загрузка...</div>';
fetch('get_planned_routes.php').then(r => r.json()).then(data => {
if(!data.success || !data.routes || !data.routes.length) {
container.innerHTML = '<div class="route-list-empty">Нет сохраненных маршрутов</div>';
return;
}
container.innerHTML = data.routes.map(r => `
<div class="route-item" onclick="selectRoute('${r.zayavki_ids}', '${r.cost || ''}', this)" data-route-id="${r.id}">
<div class="route-name">#${r.id} ${r.name}</div>
<div class="route-meta">${r.zayavki_count} заявок ${r.cost ? ' • '+parseFloat(r.cost).toLocaleString('ru-RU')+' ₽' : ''}</div>
<button class="route-delete" onclick="event.stopPropagation(); deleteRoute(${r.id})">🗑️</button>
</div>
`).join('');
}).catch(() => container.innerHTML = '<div class="route-list-empty">Ошибка загрузки</div>');
}
function selectRoute(idsStr, costVal, element) {
selectedOrder = [];
document.querySelectorAll('.route-item').forEach(el => el.classList.remove('active'));
if(element) element.classList.add('active');
const ids = idsStr.split(',').map(s=>s.trim()).filter(s=>s);
selectedOrder = ids;
editingRouteId = element ? element.dataset.routeId : null;
document.getElementById('route-cost-input').value = costVal;
const statusEl = document.getElementById('route-edit-status');
const saveBtn = document.getElementById('saveRouteBtn');
if (editingRouteId) {
statusEl.innerHTML = `<span style="color:#9c27b0; font-weight:bold;">✏️ Редактирование маршрута #${editingRouteId}</span>`;
saveBtn.textContent = '💾 Обновить маршрут';
saveBtn.style.background = '#7b1fa2';
} else {
statusEl.innerHTML = '<span style="color:#28a745;">🆕 Создание нового маршрута</span>';
saveBtn.textContent = '📌 Сохранить как маршрут';
saveBtn.style.background = '#9c27b0';
}
refreshMarkerStyles();
updateSelectionUI();
document.getElementById('selection-panel').scrollTop = 0;
}
function promptSaveRoute() {
if (selectedOrder.length < 1) return alert('Выберите хотя бы одну заявку');
let payload = { zayavki_ids: selectedOrder.join(',') };
const costVal = document.getElementById('route-cost-input').value.trim();
payload.cost = (costVal !== '' && !isNaN(costVal)) ? parseFloat(costVal) : null;
if (editingRouteId) {
const activeNameEl = document.querySelector('.route-item.active .route-name');
const currentName = activeNameEl ? activeNameEl.textContent.replace(/^#\d+\s/, '') : '';
payload.id = editingRouteId;
payload.name = currentName;
} else {
payload.name = prompt('Введите название маршрута:') || '';
}
if (!payload.name.trim()) return alert('Название маршрута не может быть пустым');
const btn = document.getElementById('saveRouteBtn');
btn.disabled = true;
btn.textContent = '⏳ Сохранение...';
fetch('save_planned_route.php', {
method: 'POST',
headers: {'Content-Type': 'application/json'},
body: JSON.stringify(payload)
})
.then(r => r.json())
.then(res => {
if(res.success) {
alert(res.message);
loadPlannedRoutes();
} else {
alert('Ошибка: ' + res.message);
}
})
.catch(e => alert('Ошибка сети'))
.finally(() => {
btn.disabled = false;
btn.textContent = editingRouteId ? '💾 Обновить маршрут' : '📌 Сохранить как маршрут';
});
}
function deleteRoute(id) {
if(!confirm('Удалить маршрут?')) return;
fetch('delete_planned_route.php', {
method:'POST',
headers:{'Content-Type':'application/json'},
body:JSON.stringify({id})
}).then(r=>r.json()).then(res => { if(res.success) loadPlannedRoutes(); });
}
function calculateRoute() {
if (selectedOrder.length < 2) return alert('Минимум 2 заявки');
let pointsOrder = [];
selectedOrder.forEach(id => {
groupsData.forEach(g => {
g.points.forEach(p => {
if (String(p.zayavka_id) === String(id)) {
pointsOrder.push({id: p.zayavka_id, coords: [parseFloat(p.mno_d), parseFloat(p.mno_sh)]});
}
});
});
});
if (pointsOrder.length < 2) return alert('Координаты не найдены');
if(multiRoute) map.geoObjects.remove(multiRoute);
let refs = pointsOrder.map(p => p.coords);
refs.push(UNLOAD_ADDRESS);
multiRoute = new ymaps.multiRouter.MultiRoute({
referencePoints: refs,
routingMode: 'auto'
}, {
boundsAutoApply: true,
routeActiveStrokeWidth: 5,
routeActiveStrokeColor: "#007bff",
wayPointStartVisible: false,
wayPointFinishVisible: false,
viaPointVisible: false
});
map.geoObjects.add(multiRoute);
multiRoute.model.events.add('requestsuccess', function() {
const route = multiRoute.getRoutes().get(0);
if(route) {
const dist = route.properties.get('distance').value;
const dur = route.properties.get('duration').value;
const h = Math.floor(dur/3600), m = Math.floor((dur%3600)/60);
document.getElementById('route-info-container').innerHTML = `<div class="route-info"><div class="route-info-item">📏 ${dist>1000?(dist/1000).toFixed(2)+' км':dist+' м'}</div><div class="route-info-item">⏱ ${h>0?h+'ч ':''}${m} мин</div></div>`;
}
});
}
ymaps.ready(init);
function init() {
map = new ymaps.Map('map', {center: [55.751574, 37.573856], zoom: 10, controls: ['zoomControl', 'fullscreenControl']});
groupsData = <?= json_encode($processedGroups) ?>;
<?php foreach ($flightStatusList as $s => $v): ?> flightStatusFilters['<?= $s ?>'] = <?= ($s=='started'||$s=='completed')?'false':'true' ?>; <?php endforeach; ?>
customLayerFilters['default'] = true;
<?php foreach ($customLayers as $ln => $ld): ?> customLayerFilters['<?= addslashes($ln) ?>'] = true; <?php endforeach; ?>
// === СОЗДАЕМ КОЛЛЕКЦИИ ===
requestsCollection = new ymaps.GeoObjectCollection();
trackersCollection = new ymaps.GeoObjectCollection();
// Строгий порядок добавления: сначала заявки, потом транспорт
map.geoObjects.add(requestsCollection);
map.geoObjects.add(trackersCollection);
groupsData.forEach(group => group.points.forEach(p => weightById[p.zayavka_id] = Math.round(p.mass_netto * 1000)));
// === ИНИЦИАЛИЗАЦИЯ МАРКЕРОВ ЗАЯВОК ===
groupsData.forEach(group => {
const placemark = new ymaps.Placemark(
[parseFloat(group.mno_d), parseFloat(group.mno_sh)],
{
balloonContent: createBalloonContent(group),
hintContent: group.count > 1 ? `Группа: ${Math.round(group.total_mass * 1000)} кг` : `Заявка: ${Math.round(group.total_mass * 1000)} кг`,
groupData: group
},
{
iconLayout: 'default#image',
iconImageHref: '',
iconImageSize: [50, 45],
iconImageOffset: [-25, -45],
balloonOffset: [0, -30],
visible: false,
zIndex: 500 // 📦 ЗАЯВКИ НИЖЕ ТРАНСПОРТА
}
);
placemark.events.add('click', function(e) {
if (popupsDisabled) e.preventDefault();
toggleGroupSelection(group);
});
placemarks.push(placemark);
requestsCollection.add(placemark);
});
// === ИНИЦИАЛИЗАЦИЯ МАРКЕРОВ ТРАНСПОРТА ===
addTrackerMarkers(initialTrackers);
// === НАСТРОЙКА КНОПКИ ОБНОВЛЕНИЯ ===
document.getElementById('refreshBtn').addEventListener('click', fetchTrackers);
// === АВТООБНОВЛЕНИЕ ТРЕКЕРОВ КАЖДЫЕ 60 СЕКУНД ===
trackerUpdateInterval = setInterval(fetchTrackers, 60000);
window.addEventListener('beforeunload', () => {
if (trackerUpdateInterval) clearInterval(trackerUpdateInterval);
});
updateMap();
loadPlannedRoutes();
if (placemarks.length > 0 || trackerPlacemarks.length > 0) {
const bounds = map.geoObjects.getBounds();
if (bounds) map.setBounds(bounds, { checkZoomRange: true, zoomMargin: 50 });
}
}
</script>
</body>
</html>