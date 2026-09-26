<?php
require_once 'config.php';
session_start();
// ID Google таблицы
define('GOOGLE_SHEETS_SPREADSHEET_ID', '1e-iA425O2mhvF12WDnH_lpd6Z3HbCgBOtYtWuF-F6Z4');
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

/**
 * Получение данных из Google Sheets
 */
function getGoogleSheetsData() {
$spreadsheetId = GOOGLE_SHEETS_SPREADSHEET_ID;
$csvUrl = "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/export?format=csv&gid=1200010467";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $csvUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; Google-Sync/1.0)');
$csvContent = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($httpCode !== 200 || empty($csvContent)) {
return false;
}
// Парсинг CSV
$rows = [];
$inQuote = false;
$currentRow = [];
$currentField = '';
$length = strlen($csvContent);
for ($i = 0; $i < $length; $i++) {
$char = $csvContent[$i];
$nextChar = ($i < $length - 1) ? $csvContent[$i + 1] : '';
if ($char === '"' && !$inQuote) {
$inQuote = true;
} elseif ($char === '"' && $inQuote && $nextChar === '"') {
$currentField .= '"';
$i++;
} elseif ($char === '"' && $inQuote) {
$inQuote = false;
} elseif ($char === ',' && !$inQuote) {
$currentRow[] = $currentField;
$currentField = '';
} elseif ($char === "\n" && !$inQuote) {
$currentRow[] = $currentField;
if (!empty(array_filter($currentRow))) {
$rows[] = $currentRow;
}
$currentRow = [];
$currentField = '';
} else {
$currentField .= $char;
}
}
if (!empty($currentField) || !empty($currentRow)) {
$currentRow[] = $currentField;
if (!empty(array_filter($currentRow))) {
$rows[] = $currentRow;
}
}
// Очищаем поля
foreach ($rows as &$row) {
foreach ($row as &$field) {
$field = trim($field);
}
}
return $rows;
}

/**
 * Проверка, является ли номер заявки валидным (исключаем содержащие '00000')
 */
function isValidZayavkaId($id) {
if (!is_numeric($id)) {
return false;
}
if (strpos($id, '00000') !== false) {
return false;
}
return true;
}

/**
 * Получение заявок из Google Sheets с детальной информацией
 */
function getGoogleSheetsZayavki() {
$data = getGoogleSheetsData();
if ($data === false || count($data) <= 1) {
return false;
}
$zayavki = [];
$skippedCount = 0;
for ($i = 1; $i < count($data); $i++) {
$row = $data[$i];
$zayavkaId = isset($row[0]) ? trim($row[0]) : '';
if (!empty($zayavkaId) && isValidZayavkaId($zayavkaId)) {
$zayavki[$zayavkaId] = [
'zayavka_id' => $zayavkaId,
'route' => isset($row[8]) ? trim($row[8]) : '',
'comments' => isset($row[11]) ? trim($row[11]) : '',
'driver_info' => isset($row[12]) ? trim($row[12]) : '',
'prep_days' => isset($row[13]) ? trim($row[13]) : ''
];
} elseif (!empty($zayavkaId) && !isValidZayavkaId($zayavkaId)) {
$skippedCount++;
}
}
if ($skippedCount > 0) {
error_log("Пропущено заявок с '00000' в номере: " . $skippedCount);
}
return $zayavki;
}

/**
 * Получение заявок из БД (блоки со статусом dostupno)
 */
function getBlocksZayavki($pdo) {
try {
$stmt = $pdo->prepare("
SELECT id, zayavki_ids, comment, additional_comment, status_type, status_value, block_date, cost
FROM status_blocks
WHERE status_type = 'dostupno'
ORDER BY block_date DESC
");
$stmt->execute();
$blocks = $stmt->fetchAll();
$allZayavki = [];
$blocksData = [];
$skippedCount = 0;
foreach ($blocks as $block) {
$zayavkiIds = array_filter(explode(',', $block['zayavki_ids']));
$validZayavkiIds = [];
foreach ($zayavkiIds as $id) {
$id = trim($id);
if (!empty($id) && isValidZayavkaId($id)) {
$validZayavkiIds[] = $id;
} else {
$skippedCount++;
}
}
$allZayavki = array_merge($allZayavki, $validZayavkiIds);
$blocksData[$block['id']] = [
'id' => $block['id'],
'zayavki' => $validZayavkiIds,
'comment' => $block['comment'],
'additional_comment' => $block['additional_comment'],
'status_type' => $block['status_type'],
'status_value' => $block['status_value'],
'block_date' => $block['block_date'],
'cost' => $block['cost']
];
}
if ($skippedCount > 0) {
error_log("Пропущено заявок с '00000' в номере из БД: " . $skippedCount);
}
return [
'all_zayavki' => array_unique($allZayavki),
'blocks' => $blocksData
];
} catch (Exception $e) {
return false;
}
}

/**
 * Получение детальной информации о заявках из таблицы feo
 */
function getZayavkiDetails($pdo, $zayavkiIds) {
if (empty($zayavkiIds)) {
return [];
}
$placeholders = implode(',', array_fill(0, count($zayavkiIds), '?'));
$stmt = $pdo->prepare("
SELECT zayavka_id, mass_netto, naim_oo_gruzootpravitel, mno_adres_pogruzki, mno_sh, mno_d,
kontakt_tel, tel_dopolnitelnyy, kontakt_email
FROM feo
WHERE zayavka_id IN ({$placeholders})
");
$stmt->execute($zayavkiIds);
$result = $stmt->fetchAll();
return array_column($result, null, 'zayavka_id');
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

// ==================== ПОЛУЧЕНИЕ ДАННЫХ ДЛЯ ОТОБРАЖЕНИЯ ====================
$googleData = getGoogleSheetsZayavki();
$blocksData = getBlocksZayavki($pdo);
$googleError = ($googleData === false);
$googleZayavki = $googleData ? array_keys($googleData) : [];
$blocksZayavki = $blocksData ? $blocksData['all_zayavki'] : [];
$onlyInGoogle = array_diff($googleZayavki, $blocksZayavki);
$onlyInBlocks = array_diff($blocksZayavki, $googleZayavki);
$common = array_intersect($googleZayavki, $blocksZayavki);

// Получаем детальную информацию о заявках
$allIds = array_merge($onlyInGoogle, $onlyInBlocks);
$details = getZayavkiDetails($pdo, $allIds);

$googleZayavkiCount = count($googleZayavki);
$blocksZayavkiCount = count($blocksZayavki);
$totalDiffCount = count($onlyInGoogle) + count($onlyInBlocks);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Проверка синхронизации заявок</title>
<link rel="stylesheet" href="style.css">
<style>
.sync-stats {
display: flex;
gap: 20px;
margin-bottom: 20px;
flex-wrap: wrap;
}
.sync-stat-item {
background: #fff;
border: 1px solid #d0d7de;
border-radius: 6px;
padding: 16px 24px;
text-align: center;
flex: 1;
min-width: 150px;
}
.sync-stat-value {
font-size: 28px;
font-weight: bold;
}
.sync-stat-value.green {
color: #28a745;
}
.sync-stat-value.red {
color: #dc3545;
}
.sync-stat-value.blue {
color: #0969da;
}
.sync-stat-label {
font-size: 12px;
color: #57606a;
margin-top: 5px;
}
.diff-section {
background: #fff;
border: 1px solid #d0d7de;
border-radius: 6px;
margin-bottom: 20px;
overflow: hidden;
}
.diff-header {
display: flex;
justify-content: space-between;
align-items: center;
padding: 12px 16px;
background: #f6f8fa;
border-bottom: 1px solid #eaeef2;
cursor: pointer;
}
.diff-header:hover {
background: #eaeef2;
}
.diff-header h3 {
font-size: 14px;
font-weight: 600;
margin: 0;
}
.diff-badge {
display: inline-block;
padding: 2px 8px;
border-radius: 12px;
font-size: 11px;
font-weight: 600;
margin-left: 10px;
}
.diff-badge.red {
background: #f8d7da;
color: #dc3545;
}
.diff-badge.green {
background: #d4edda;
color: #28a745;
}
.diff-content {
padding: 0;
display: none;
}
.diff-content.show {
display: block;
}
.google-error {
background: #f8d7da;
border: 1px solid #f5c6cb;
color: #721c24;
padding: 15px;
border-radius: 6px;
margin-bottom: 20px;
}
.empty-value {
color: #adb5bd;
font-style: italic;
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
.last-update {
font-size: 11px;
color: #6c757d;
margin-left: 10px;
}
.card-empty {
text-align: center;
padding: 40px;
background: #fff;
border: 1px solid #d0d7de;
border-radius: 6px;
}
.toolbar-actions {
display: flex;
gap: 10px;
margin-left: auto;
align-items: center;
}
.info-note {
margin-top: 20px;
padding: 12px;
background: #e7f3ff;
border-radius: 6px;
font-size: 12px;
color: #004085;
}
.info-note a {
color: #004085;
text-decoration: underline;
}
/* Стили таблицы как в work.php */
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
.toggle-arrow {
cursor: pointer;
user-select: none;
transition: transform 0.2s;
}
.toggle-arrow:hover {
transform: scale(1.2);
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
</style>
</head>
<body>
<?php include 'menu2.php'; ?>
<div class="app">
<div class="toolbar">
<h1>Проверка синхронизации заявок</h1>
<div class="toolbar-actions">
<button class="btn" onclick="location.reload()">Обновить</button>
</div>
</div>
<?php if ($googleError): ?>
<div class="google-error">
<strong>⚠️ Не удалось загрузить данные из Google Таблицы</strong><br>
Проверьте:
<ul style="margin: 10px 0 0 20px;">
<li>Доступность таблицы по ссылке: <a href="https://docs.google.com/spreadsheets/d/<?= GOOGLE_SHEETS_SPREADSHEET_ID ?>/edit" target="_blank">открыть таблицу</a></li>
<li>Настройки доступа (Файл → Опубликовать в интернете → Вся таблица → CSV)</li>
<li>Структура таблицы: колонка A - номера заявок</li>
</ul>
</div>
<?php endif; ?>
<!-- Статистика -->
<div class="sync-stats">
<div class="sync-stat-item">
<div class="sync-stat-value <?= $googleError ? 'red' : 'blue' ?>">
<?= $googleError ? '?' : $googleZayavkiCount ?>
</div>
<div class="sync-stat-label">📊 Заявок в Google Таблице</div>
<?php if (!$googleError): ?>
<div class="last-update">ID: <?= substr(GOOGLE_SHEETS_SPREADSHEET_ID, 0, 10) ?>...</div>
<?php endif; ?>
</div>
<div class="sync-stat-item">
<div class="sync-stat-value blue"><?= $blocksZayavkiCount ?></div>
<div class="sync-stat-label">📦 Заявок в БД (доступно к вывозу)</div>
</div>
<div class="sync-stat-item">
<div class="sync-stat-value green"><?= count($common) ?></div>
<div class="sync-stat-label">✅ Совпадают</div>
</div>
<div class="sync-stat-item">
<div class="sync-stat-value <?= $totalDiffCount > 0 ? 'red' : 'green' ?>">
<?= $totalDiffCount ?>
</div>
<div class="sync-stat-label">⚠️ Расхождений</div>
</div>
</div>
<?php if (!$googleError): ?>
<!-- Блок: Только в Google -->
<div class="diff-section">
<div class="diff-header" onclick="toggleDiff('diffGoogle')">
<h3>
➕ Только в Google Таблице (нет в БД)
<span class="diff-badge green"><?= count($onlyInGoogle) ?> заявок</span>
</h3>
<span class="toggle-arrow">▼</span>
</div>
<div id="diffGoogle" class="diff-content <?= count($onlyInGoogle) > 0 ? 'show' : '' ?>">
<?php if (count($onlyInGoogle) > 0): ?>
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
<?php foreach ($onlyInGoogle as $zayavkaId):
$info = $details[$zayavkaId] ?? null;
$googleInfo = $googleData[$zayavkaId] ?? [];
$massKg = $info ? floatval($info['mass_netto'] ?? 0) * 1000 : 0;
$prep_time = $googleInfo['prep_days'] ?? '';
$comments = $googleInfo['comments'] ?? '';
$daysClass = (is_numeric($prep_time) && $prep_time > 3) ? 'high' : '';
// Формируем контактную информацию с удалением дубликатов
$contacts = [];
if (!empty($info['kontakt_tel'])) {
$phones = explode('|', $info['kontakt_tel']);
$phones = uniqueContacts($phones);
foreach ($phones as $phone) {
$contacts[] = htmlspecialchars($phone);
}
}
if (!empty($info['tel_dopolnitelnyy'])) {
$extraPhones = explode('|', $info['tel_dopolnitelnyy']);
$extraPhones = uniqueContacts($extraPhones);
foreach ($extraPhones as $phone) {
$contacts[] = htmlspecialchars($phone);
}
}
if (!empty($info['kontakt_email'])) {
$emails = explode('|', $info['kontakt_email']);
$emails = uniqueContacts($emails);
foreach ($emails as $email) {
$contacts[] = htmlspecialchars($email);
}
}
$contactsHtml = !empty($contacts) ? implode('<br>', $contacts) : '<span class="empty-value">—</span>';
$route = $googleInfo['route'] ?? '';
?>
<tr>
<td class="text-center"><strong><?= htmlspecialchars($zayavkaId) ?></strong></td>
<td class="text-right"><?= $massKg > 0 ? number_format($massKg, 0, '.', ' ') : '<span class="empty-value">—</span>' ?></td>
<td style="text-align: left;"><?= htmlspecialchars($info['naim_oo_gruzootpravitel'] ?? '') ?: '<span class="empty-value">—</span>' ?></td>
<td style="text-align: left;"><?= htmlspecialchars($info['mno_adres_pogruzki'] ?? '') ?: '<span class="empty-value">—</span>' ?></td>
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
</tbody>
</table>
</div>
<?php else: ?>
<div class="block-details" style="text-align: center; padding: 20px; color: #28a745;">
✅ Все заявки из Google есть в БД
</div>
<?php endif; ?>
</div>
</div>
<!-- Блок: Только в БД -->
<div class="diff-section">
<div class="diff-header" onclick="toggleDiff('diffBlocks')">
<h3>
❌ Только в БД (нет в Google Таблице)
<span class="diff-badge red"><?= count($onlyInBlocks) ?> заявок</span>
</h3>
<span class="toggle-arrow">▼</span>
</div>
<div id="diffBlocks" class="diff-content <?= count($onlyInBlocks) > 0 ? 'show' : '' ?>">
<?php if (count($onlyInBlocks) > 0): ?>
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
<?php foreach ($onlyInBlocks as $zayavkaId):
$info = $details[$zayavkaId] ?? null;
$massKg = $info ? floatval($info['mass_netto'] ?? 0) * 1000 : 0;
// Формируем контактную информацию с удалением дубликатов
$contacts = [];
if (!empty($info['kontakt_tel'])) {
$phones = explode('|', $info['kontakt_tel']);
$phones = uniqueContacts($phones);
foreach ($phones as $phone) {
$contacts[] = htmlspecialchars($phone);
}
}
if (!empty($info['tel_dopolnitelnyy'])) {
$extraPhones = explode('|', $info['tel_dopolnitelnyy']);
$extraPhones = uniqueContacts($extraPhones);
foreach ($extraPhones as $phone) {
$contacts[] = htmlspecialchars($phone);
}
}
if (!empty($info['kontakt_email'])) {
$emails = explode('|', $info['kontakt_email']);
$emails = uniqueContacts($emails);
foreach ($emails as $email) {
$contacts[] = htmlspecialchars($email);
}
}
$contactsHtml = !empty($contacts) ? implode('<br>', $contacts) : '<span class="empty-value">—</span>';
?>
<tr>
<td class="text-center"><strong><?= htmlspecialchars($zayavkaId) ?></strong></td>
<td class="text-right"><?= $massKg > 0 ? number_format($massKg, 0, '.', ' ') : '<span class="empty-value">—</span>' ?></td>
<td style="text-align: left;"><?= htmlspecialchars($info['naim_oo_gruzootpravitel'] ?? '') ?: '<span class="empty-value">—</span>' ?></td>
<td style="text-align: left;"><?= htmlspecialchars($info['mno_adres_pogruzki'] ?? '') ?: '<span class="empty-value">—</span>' ?></td>
<td class="contacts-cell"><?= $contactsHtml ?></td>
<td class="text-center"><span class="empty-value">—</span></td>
<td class="comments-cell"><span class="empty-value">—</span></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php else: ?>
<div class="block-details" style="text-align: center; padding: 20px; color: #28a745;">
✅ Все заявки из БД есть в Google
</div>
<?php endif; ?>
</div>
</div>
<?php if ($totalDiffCount == 0): ?>
<div class="card-empty">
<h2 style="color: #28a745;">✅ Отлично!</h2>
<p>Заявки в БД полностью совпадают с данными в Google Таблице.</p>
<p>Расхождений не обнаружено.</p>
<a href="available.php" class="btn btn-primary" style="margin-top: 15px;">← Вернуться к списку</a>
</div>
<?php endif; ?>
<?php endif; ?>
</div>
<script>
function toggleDiff(id) {
const element = document.getElementById(id);
const header = element.previousElementSibling;
const arrow = header.querySelector('.toggle-arrow');
if (element.classList.contains('show')) {
element.classList.remove('show');
arrow.textContent = '▶';
} else {
element.classList.add('show');
arrow.textContent = '▼';
}
}
// Автоматически открываем секции если есть расхождения
document.addEventListener('DOMContentLoaded', function() {
const googleSection = document.getElementById('diffGoogle');
const blocksSection = document.getElementById('diffBlocks');
if (googleSection && googleSection.querySelector('tr')) {
googleSection.classList.add('show');
}
if (blocksSection && blocksSection.querySelector('tr')) {
blocksSection.classList.add('show');
}
});
</script>
</body>
</html>