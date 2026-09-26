<?php
require_once 'config.php';
session_start();

// 🔧 Функция обрезки текста до 50 символов с многоточием
function trunc50($s, $limit) {
    $s = (string)$s;
    if (mb_strlen($s, 'UTF-8') > $limit) {
        return htmlspecialchars(mb_substr($s, 0, $limit, 'UTF-8'), ENT_QUOTES, 'UTF-8') . '...';
    }
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

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
// Проверяем структуру таблицы
try {
$stmt = $pdo->query("SHOW COLUMNS FROM status_blocks LIKE 'comment'");
if ($stmt->rowCount() == 0) {
$pdo->exec("ALTER TABLE `status_blocks` ADD COLUMN `comment` varchar(255) DEFAULT NULL COMMENT 'Комментарий к блоку' AFTER `block_date`");
}
$stmt = $pdo->query("SHOW COLUMNS FROM status_blocks LIKE 'zayavki_ids'");
if ($stmt->rowCount() == 0) {
$pdo->exec("ALTER TABLE `status_blocks` ADD COLUMN `zayavki_ids` text NOT NULL COMMENT 'ID заявок через запятую' AFTER `total_mass_tonn`");
}
$stmt = $pdo->query("SHOW COLUMNS FROM status_blocks LIKE 'zayavki_list'");
if ($stmt->rowCount() > 0) {
$pdo->exec("ALTER TABLE `status_blocks` DROP COLUMN `zayavki_list`");
}
// Проверяем наличие новых колонок и добавляем их если нет
$stmt = $pdo->query("SHOW COLUMNS FROM status_blocks LIKE 'cost'");
if ($stmt->rowCount() == 0) {
$pdo->exec("ALTER TABLE `status_blocks` ADD COLUMN `cost` DECIMAL(12,2) DEFAULT NULL COMMENT 'Стоимость' AFTER `total_mass_tonn`");
} else {
$costColumn = $stmt->fetch(PDO::FETCH_ASSOC);
$costType = strtolower((string)($costColumn['Type'] ?? ''));
if (strpos($costType, 'decimal') !== 0) {
$pdo->exec("ALTER TABLE `status_blocks` MODIFY COLUMN `cost` DECIMAL(12,2) DEFAULT NULL COMMENT 'Стоимость'");
}
}
$stmt = $pdo->query("SHOW COLUMNS FROM status_blocks LIKE 'additional_comment'");
if ($stmt->rowCount() == 0) {
$pdo->exec("ALTER TABLE `status_blocks` ADD COLUMN `additional_comment` varchar(500) DEFAULT NULL COMMENT 'Дополнительный комментарий' AFTER `comment`");
}
$stmt = $pdo->query("SHOW COLUMNS FROM status_blocks WHERE Field = 'id'");
$id_column = $stmt->fetch();
if ($id_column && strpos($id_column['Extra'], 'auto_increment') === false) {
$pdo->exec("ALTER TABLE `status_blocks` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT");
}
} catch (Exception $e) {
// Таблица может еще не существовать, игнорируем ошибку
}
// Создаем таблицу для блоков, если её нет
$pdo->exec("
CREATE TABLE IF NOT EXISTS `status_blocks` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`block_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
`comment` varchar(255) DEFAULT NULL COMMENT 'Комментарий к блоку',
`additional_comment` varchar(500) DEFAULT NULL COMMENT 'Дополнительный комментарий',
`status_type` enum('dostupno','marshrut') NOT NULL,
`status_value` varchar(100) NOT NULL COMMENT 'Доступно к вывозу или номер маршрута',
`zayavki_count` int(11) NOT NULL DEFAULT 0,
`total_mass_tonn` decimal(10,3) DEFAULT NULL,
`cost` decimal(12,2) DEFAULT NULL COMMENT 'Стоимость',
`zayavki_ids` text NOT NULL COMMENT 'ID заявок через запятую',
PRIMARY KEY (`id`),
KEY `block_date` (`block_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
// Генерация токена для защиты от повторной отправки
if (!isset($_SESSION['form_token'])) {
$_SESSION['form_token'] = bin2hex(random_bytes(32));
}
// Обработка AJAX сохранения нового блока
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_block'])) {
header('Content-Type: application/json');
try {
if (!isset($_POST['form_token']) || $_POST['form_token'] !== $_SESSION['form_token']) {
throw new Exception('Ошибка безопасности. Пожалуйста, обновите страницу.');
}
$zayavki_input = trim($_POST['zayavki'] ?? '');
$comment = trim($_POST['comment'] ?? '');
$additional_comment = trim($_POST['additional_comment'] ?? '');
$cost_raw = trim((string)($_POST['cost'] ?? ''));
$cost = null;
if ($cost_raw !== '') {
$cost_normalized = str_replace([' ', ','], ['', '.'], $cost_raw);
if (!is_numeric($cost_normalized)) {
throw new Exception('Некорректная стоимость');
}
$cost = round((float)$cost_normalized, 2);
}
$status_type = $_POST['status_type'] ?? 'dostupno';
$marshrut_number = trim($_POST['marshrut_number'] ?? '');
if (empty($zayavki_input)) {
throw new Exception('Введите номера заявок');
}
// Парсинг номеров заявок
$zayavki = [];
$invalid_format = [];
$parts = preg_split('/[,;\s]+/', $zayavki_input);
foreach ($parts as $part) {
$part = trim($part);
if (empty($part)) continue;
$clean_part = preg_replace('/[^0-9]/', '', $part);
if (!empty($clean_part)) {
$zayavki[] = $clean_part;
} else {
$invalid_format[] = $part;
}
}
$zayavki = array_unique($zayavki);
if (empty($zayavki)) {
throw new Exception('Не найдено корректных номеров заявок');
}
if ($status_type === 'marshrut' && empty($marshrut_number)) {
throw new Exception('Введите номер маршрута');
}
$status_text = ($status_type === 'dostupno') ? 'Доступно к вывозу' : $marshrut_number;
// Проверяем существование заявок в таблице feo
$placeholders = implode(',', array_fill(0, count($zayavki), '?'));
$stmt = $pdo->prepare("SELECT zayavka_id, mass_netto FROM feo WHERE zayavka_id IN ({$placeholders})");
$stmt->execute($zayavki);
$existing_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$existing_zayavki = array_column($existing_rows, 'zayavka_id');
$not_found = array_diff($zayavki, $existing_zayavki);
// Подсчитываем общую массу
$total_mass = 0;
foreach ($existing_rows as $row) {
$total_mass += floatval($row['mass_netto'] ?? 0);
}
// Сохраняем блок
if (!empty($existing_zayavki)) {
$zayavki_ids_string = implode(',', $existing_zayavki);
$stmt_block = $pdo->prepare("
INSERT INTO status_blocks (comment, additional_comment, status_type, status_value, zayavki_count, total_mass_tonn, cost, zayavki_ids)
VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt_block->execute([
$comment,
$additional_comment,
$status_type,
$status_text,
count($existing_zayavki),
$total_mass,
$cost,
$zayavki_ids_string
]);
// Генерируем новый токен
$_SESSION['form_token'] = bin2hex(random_bytes(32));
echo json_encode([
'success' => true,
'message' => 'Блок успешно сохранен',
'not_found' => array_values($not_found),
'invalid_format' => $invalid_format,
'total' => count($zayavki),
'found' => count($existing_zayavki),
'new_token' => $_SESSION['form_token']
]);
} else {
throw new Exception('Ни одна из заявок не найдена в БД');
}
} catch (Exception $e) {
echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit;
}
// Обработка удаления блока
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_block'])) {
header('Content-Type: application/json');
try {
if (!isset($_POST['block_id']) || !is_numeric($_POST['block_id'])) {
throw new Exception('Неверный ID блока');
}
$block_id = (int)$_POST['block_id'];
$stmt = $pdo->prepare("DELETE FROM status_blocks WHERE id = ?");
$result = $stmt->execute([$block_id]);
if ($result) {
echo json_encode(['success' => true, 'message' => 'Блок успешно удален']);
} else {
throw new Exception('Ошибка при удалении блока');
}
} catch (Exception $e) {
echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit;
}
// Обработка получения данных блока для редактирования
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_block'])) {
header('Content-Type: application/json');
try {
if (!isset($_GET['block_id']) || !is_numeric($_GET['block_id'])) {
throw new Exception('Неверный ID блока');
}
$block_id = (int)$_GET['block_id'];
$stmt = $pdo->prepare("SELECT * FROM status_blocks WHERE id = ?");
$stmt->execute([$block_id]);
$block = $stmt->fetch();
if (!$block) {
throw new Exception('Блок не найден');
}
$zayavki_ids = array_filter(explode(',', $block['zayavki_ids']));
if (!empty($zayavki_ids)) {
$placeholders = implode(',', array_fill(0, count($zayavki_ids), '?'));
$stmt = $pdo->prepare("SELECT zayavka_id, mass_netto, naim_oo_gruzootpravitel, mno_adres_pogruzki, mno_sh, mno_d FROM feo WHERE zayavka_id IN ({$placeholders})");
$stmt->execute($zayavki_ids);
$block['zayavki'] = $stmt->fetchAll();
} else {
$block['zayavki'] = [];
}
$block['zayavki_text'] = implode("\n", $zayavki_ids);
echo json_encode(['success' => true, 'block' => $block]);
} catch (Exception $e) {
echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit;
}
// Обработка обновления блока
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_block'])) {
header('Content-Type: application/json');
try {
if (!isset($_POST['form_token']) || $_POST['form_token'] !== $_SESSION['form_token']) {
throw new Exception('Ошибка безопасности. Пожалуйста, обновите страницу.');
}
if (!isset($_POST['block_id']) || !is_numeric($_POST['block_id'])) {
throw new Exception('Неверный ID блока');
}
$block_id = (int)$_POST['block_id'];
$zayavki_input = trim($_POST['zayavki'] ?? '');
$comment = trim($_POST['comment'] ?? '');
$additional_comment = trim($_POST['additional_comment'] ?? '');
$cost_raw = trim((string)($_POST['cost'] ?? ''));
$cost = null;
if ($cost_raw !== '') {
$cost_normalized = str_replace([' ', ','], ['', '.'], $cost_raw);
if (!is_numeric($cost_normalized)) {
throw new Exception('Некорректная стоимость');
}
$cost = round((float)$cost_normalized, 2);
}
$status_type = $_POST['status_type'] ?? 'dostupno';
$marshrut_number = trim($_POST['marshrut_number'] ?? '');
if (empty($zayavki_input)) {
throw new Exception('Введите номера заявок');
}
$zayavki = [];
$invalid_format = [];
$parts = preg_split('/[,;\s]+/', $zayavki_input);
foreach ($parts as $part) {
$part = trim($part);
if (empty($part)) continue;
$clean_part = preg_replace('/[^0-9]/', '', $part);
if (!empty($clean_part)) {
$zayavki[] = $clean_part;
} else {
$invalid_format[] = $part;
}
}
$zayavki = array_unique($zayavki);
if (empty($zayavki)) {
throw new Exception('Не найдено корректных номеров заявок');
}
if ($status_type === 'marshrut' && empty($marshrut_number)) {
throw new Exception('Введите номер маршрута');
}
$status_text = ($status_type === 'dostupno') ? 'Доступно к вывозу' : $marshrut_number;
$placeholders = implode(',', array_fill(0, count($zayavki), '?'));
$stmt = $pdo->prepare("SELECT zayavka_id, mass_netto FROM feo WHERE zayavka_id IN ({$placeholders})");
$stmt->execute($zayavki);
$existing_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$existing_zayavki = array_column($existing_rows, 'zayavka_id');
$not_found = array_diff($zayavki, $existing_zayavki);
$total_mass = 0;
foreach ($existing_rows as $row) {
$total_mass += floatval($row['mass_netto'] ?? 0);
}
$zayavki_ids_string = implode(',', $existing_zayavki);
$stmt = $pdo->prepare("
UPDATE status_blocks
SET comment = ?, additional_comment = ?, status_type = ?, status_value = ?,
zayavki_count = ?, total_mass_tonn = ?, cost = ?, zayavki_ids = ?
WHERE id = ?
");
$stmt->execute([
$comment,
$additional_comment,
$status_type,
$status_text,
count($existing_zayavki),
$total_mass,
$cost,
$zayavki_ids_string,
$block_id
]);
$_SESSION['form_token'] = bin2hex(random_bytes(32));
echo json_encode([
'success' => true,
'message' => 'Блок успешно обновлен',
'not_found' => array_values($not_found),
'invalid_format' => $invalid_format,
'new_token' => $_SESSION['form_token']
]);
} catch (Exception $e) {
echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit;
}
// Обработка AJAX запроса для проверки заявок на лету
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['validate_zayavki'])) {
header('Content-Type: application/json');
try {
$zayavki_input = trim($_POST['zayavki'] ?? '');
if (empty($zayavki_input)) {
echo json_encode(['success' => true, 'found_count' => 0, 'total_count' => 0, 'not_found' => [], 'invalid_format' => []]);
exit;
}
$zayavki = [];
$invalid_format = [];
$parts = preg_split('/[,;\s]+/', $zayavki_input);
foreach ($parts as $part) {
$part = trim($part);
if (empty($part)) continue;
$clean_part = preg_replace('/[^0-9]/', '', $part);
if (!empty($clean_part)) {
$zayavki[] = $clean_part;
} else {
$invalid_format[] = $part;
}
}
$zayavki = array_unique($zayavki);
$total_count = count($zayavki);
if (empty($zayavki)) {
echo json_encode(['success' => true, 'found_count' => 0, 'total_count' => 0, 'not_found' => [], 'invalid_format' => $invalid_format]);
exit;
}
$placeholders = implode(',', array_fill(0, count($zayavki), '?'));
$stmt = $pdo->prepare("SELECT zayavka_id FROM feo WHERE zayavka_id IN ({$placeholders})");
$stmt->execute($zayavki);
$existing_zayavki = $stmt->fetchAll(PDO::FETCH_COLUMN);
$not_found = array_diff($zayavki, $existing_zayavki);
$found_count = count($existing_zayavki);
echo json_encode([
'success' => true,
'found_count' => $found_count,
'total_count' => $total_count,
'not_found' => array_values($not_found),
'invalid_format' => $invalid_format
]);
} catch (Exception $e) {
echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit;
}
// Получаем блоки статусов
// Получаем блоки статусов (только маршруты, исключаем "Доступно к вывозу")
$blocks = [];
$total_unique_zayavok = 0;
$total_unique_mass_kg = 0;
$total_cost_sum = 0;
try {
$minBlockIdCondition = ($Select === 'marshrut') ? " AND id >= 118" : "";

$stmt = $pdo->query("
SELECT * FROM status_blocks
WHERE status_type = '".$Select."'".$minBlockIdCondition."
ORDER BY id DESC
LIMIT 50
");
$blocks = $stmt->fetchAll();
$all_unique_zayavki_ids = [];
$processedBlocks = [];
foreach ($blocks as $block) {
$zayavki_ids = array_filter(explode(',', $block['zayavki_ids']));
$block['zayavki_ids_array'] = $zayavki_ids;
foreach ($zayavki_ids as $id) {
$all_unique_zayavki_ids[$id] = true;
}
if (!empty($zayavki_ids)) {
$placeholders = implode(',', array_fill(0, count($zayavki_ids), '?'));
$stmt2 = $pdo->prepare("
SELECT zayavka_id, mass_netto, naim_oo_gruzootpravitel, mno_adres_pogruzki, mno_sh, mno_d
FROM feo
WHERE zayavka_id IN ({$placeholders})
");
$stmt2->execute($zayavki_ids);
$block['zayavki'] = $stmt2->fetchAll();
$actual_mass_kg = 0;
foreach ($block['zayavki'] as $zayavka) {
$actual_mass_kg += floatval($zayavka['mass_netto'] ?? 0) * 1000;
}
$block['total_mass_kg'] = $actual_mass_kg;
$actual_mass_tonn = $actual_mass_kg / 1000;
if (abs($actual_mass_tonn - floatval($block['total_mass_tonn'])) > 0.001) {
$update_stmt = $pdo->prepare("UPDATE status_blocks SET total_mass_tonn = ? WHERE id = ?");
$update_stmt->execute([$actual_mass_tonn, $block['id']]);
$block['total_mass_tonn'] = $actual_mass_tonn;
}
} else {
$block['zayavki'] = [];
$block['total_mass_kg'] = 0;
}
// Подсчет суммы стоимостей
if (!empty($block['cost'])) {
$total_cost_sum += (float)$block['cost'];
}
$processedBlocks[] = $block;
}
$blocks = $processedBlocks;
if (!empty($all_unique_zayavki_ids)) {
$unique_ids = array_keys($all_unique_zayavki_ids);
$placeholders = implode(',', array_fill(0, count($unique_ids), '?'));
$stmt = $pdo->prepare("SELECT zayavka_id, mass_netto FROM feo WHERE zayavka_id IN ({$placeholders})");
$stmt->execute($unique_ids);
$all_zayavki_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
$indexed_zayavki_mass = [];
foreach ($all_zayavki_data as $zayavka) {
$indexed_zayavki_mass[$zayavka['zayavka_id']] = floatval($zayavka['mass_netto'] ?? 0);
}
$total_unique_mass_kg = 0;
foreach ($indexed_zayavki_mass as $mass_tonn) {
$total_unique_mass_kg += $mass_tonn * 1000;
}
}
$total_unique_zayavok = count($all_unique_zayavki_ids);
} catch (Exception $e) {
$blocks = [];
}
// Подсчет суммы стоимостей только для блоков со статусом "маршрут"
$total_cost_sum = 0;
foreach ($blocks as $block) {
if ($block['status_type'] === $Select && !empty($block['cost'])) {
$total_cost_sum += (float)$block['cost'];
}
}

// Жёлтый контроль: заявки без стоимости.
// Учитываем только блоки «Доступно» с ID >= 118.
// Показываем проблемные заявки сгруппированными по их блоку «Доступно».
$available_blocks_without_price = [];
$routes_without_price = [];

try {
    // Все блоки «Доступно» только ID >= 118.
    $stmtAvailable = $pdo->query("
        SELECT id, zayavki_ids
        FROM status_blocks
        WHERE status_type = 'dostupno'
          AND id >= 118
        ORDER BY id ASC
    ");
    $availableBlocksForPriceCheck = $stmtAvailable->fetchAll(PDO::FETCH_ASSOC);

    // Все маршрутные блоки ID >= 118.
    $stmtRoutes = $pdo->query("
        SELECT id, status_value, comment, cost, zayavki_ids
        FROM status_blocks
        WHERE status_type = 'marshrut'
          AND id >= 118
        ORDER BY id DESC
    ");
    $routeBlocksForPriceCheck = $stmtRoutes->fetchAll(PDO::FETCH_ASSOC);

    // Заявки, которым уже назначена стоимость через маршрутный блок.
    $pricedRouteRequestIds = [];

    foreach ($routeBlocksForPriceCheck as $routeBlock) {
        $ids = preg_split('/[,;\s]+/', (string)($routeBlock['zayavki_ids'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $cleanIds = [];

        foreach ($ids as $id) {
            $id = preg_replace('/[^0-9]/', '', trim((string)$id));
            if ($id !== '') {
                $cleanIds[$id] = true;
            }
        }

        $cleanIds = array_keys($cleanIds);
        sort($cleanIds, SORT_NUMERIC);

        $hasPrice = $routeBlock['cost'] !== null
            && $routeBlock['cost'] !== ''
            && (float)$routeBlock['cost'] > 0;

        if ($hasPrice) {
            foreach ($cleanIds as $id) {
                $pricedRouteRequestIds[$id] = true;
            }
        } else {
            $routes_without_price[] = [
                'id' => (int)$routeBlock['id'],
                'status_value' => (string)($routeBlock['status_value'] ?? ''),
                'comment' => (string)($routeBlock['comment'] ?? ''),
                'zayavki_ids' => $cleanIds,
            ];
        }
    }

    // Для каждого блока «Доступно» ID >= 118 оставляем только заявки без стоимости.
    foreach ($availableBlocksForPriceCheck as $availableBlock) {
        $ids = preg_split('/[,;\s]+/', (string)($availableBlock['zayavki_ids'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $withoutPrice = [];

        foreach ($ids as $id) {
            $id = preg_replace('/[^0-9]/', '', trim((string)$id));
            if ($id === '') {
                continue;
            }

            if (!isset($pricedRouteRequestIds[$id])) {
                $withoutPrice[$id] = true;
            }
        }

        $withoutPrice = array_keys($withoutPrice);
        sort($withoutPrice, SORT_NUMERIC);

        if (!empty($withoutPrice)) {
            $available_blocks_without_price[] = [
                'id' => (int)$availableBlock['id'],
                'zayavki_ids' => $withoutPrice,
            ];
        }
    }
} catch (Throwable $e) {
    error_log('PLANEX yellow price check error: ' . $e->getMessage());
    $available_blocks_without_price = [];
    $routes_without_price = [];
}

// Рейсы, в которых присутствуют заявки из отображаемых блоков.
// Для одной заявки сохраняем все найденные рейсы.
$flight_refs_by_zayavka = [];
try {
    $stmtFlights = $pdo->query("
        SELECT
            f.id,
            f.comment,
            f.unload_type,
            f.status,
            f.actual_start_date,
            f.actual_end_date,
            f.zayavki_ids,
            s.`наименование` AS status_name,
            s.color AS status_color
        FROM flights f
        LEFT JOIN status s ON f.status = s.`статус`
        ORDER BY f.id ASC
    ");
    $flightRowsForRequests = $stmtFlights->fetchAll(PDO::FETCH_ASSOC);

    foreach ($flightRowsForRequests as $flightRow) {
        $flightRequestIds = preg_split('/[,;\s]+/', (string)($flightRow['zayavki_ids'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $flightName = trim((string)($flightRow['comment'] ?? ''));

        if (($flightRow['unload_type'] ?? '') === 'SKLAD') {
            $flightName = $flightName !== '' ? $flightName . ' (склад)' : 'Склад';
        }

        foreach ($flightRequestIds as $flightRequestId) {
            $flightRequestId = preg_replace('/[^0-9]/', '', trim((string)$flightRequestId));
            if ($flightRequestId === '') {
                continue;
            }

            $flight_refs_by_zayavka[$flightRequestId][] = [
                'id' => (int)$flightRow['id'],
                'name' => $flightName,
                'status' => (string)($flightRow['status'] ?? ''),
                'status_name' => (string)($flightRow['status_name'] ?? ''),
                'status_color' => (string)($flightRow['status_color'] ?? ''),
                'actual_start_date' => (string)($flightRow['actual_start_date'] ?? ''),
                'actual_end_date' => (string)($flightRow['actual_end_date'] ?? ''),
            ];
        }
    }
} catch (Throwable $e) {
    error_log('PLANEX flight refs error: ' . $e->getMessage());
    $flight_refs_by_zayavka = [];
}

$current_token = $_SESSION['form_token'];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Массовое присвоение статусов заявкам</title>
<link rel="stylesheet" href="style.css">
<style>
.flight-status-badge {
    display: inline-block;
    width: 140px;
    box-sizing: border-box;
    text-align: center;
    padding: 4px 12px;
    color: #ffffff;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
}
</style>
</head>
<body>
<?php include 'menuadmin.php'; ?>
<div class="app">
<div class="toolbar">
<h1><?=$Tittle?></h1>
</div>
<?php if (!empty($blocks) && $Stat === "ON"): ?>
<div class="stats">
<div class="stat-item">
<div class="stat-value"><?= count($blocks) ?></div>
<div class="stat-label">Всего блоков</div>
</div>
<div class="stat-item">
<div class="stat-value"><?= $total_unique_zayavok ?></div>
<div class="stat-label">Уникальных заявок</div>
</div>
<div class="stat-item">
<div class="stat-value"><?= number_format($total_unique_mass_kg, 0, '.', ' ') ?> кг</div>
<div class="stat-label">Общий вес уникальных</div>
</div>
<div class="stat-item">
<div class="stat-value"><?= number_format(
(float)$total_cost_sum,
(abs((float)$total_cost_sum - round((float)$total_cost_sum)) > 0.00001 ? 2 : 0),
',',
' '
) ?> ₽</div>
<div class="stat-label">Сумма по рейсам</div>
</div>
</div>
<?php endif; ?>

<?php if (!empty($available_blocks_without_price) || !empty($routes_without_price)): ?>
<div style="
    box-sizing:border-box;
    margin:12px 0;
    padding:12px 14px;
    border:1px solid #f0c36d;
    background:#fff7d6;
    border-radius:7px;
    color:#6b5200;
    font-size:13px;
    line-height:1.45;
">
    <div style="font-weight:700; font-size:14px; margin-bottom:8px;">
        ⚠ Заявки без стоимости
    </div>

    <?php if (!empty($available_blocks_without_price)): ?>
    <div style="margin-bottom:<?= !empty($routes_without_price) ? '10px' : '0' ?>;">
        <div style="font-weight:600; margin-bottom:5px;">
            Блоки «Доступно» без назначенной стоимости:
        </div>

        <?php foreach ($available_blocks_without_price as $availableNoPrice): ?>
        <div style="margin-top:5px; padding-top:5px; border-top:1px solid rgba(107,82,0,0.15);">
            <strong>Доступно #<?= (int)$availableNoPrice['id'] ?></strong>
            — <?= count($availableNoPrice['zayavki_ids']) ?> заявок без стоимости:
            <span style="word-break:break-word;">
                <?= htmlspecialchars(implode(', ', $availableNoPrice['zayavki_ids']), ENT_QUOTES, 'UTF-8') ?>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($routes_without_price)): ?>
    <div>
        <div style="font-weight:600; margin-bottom:5px;">
            Маршруты без стоимости:
        </div>

        <?php foreach ($routes_without_price as $routeNoPrice): ?>
        <div style="margin-top:5px; padding-top:5px; border-top:1px solid rgba(107,82,0,0.15);">
            <strong>
                Маршрут #<?= (int)$routeNoPrice['id'] ?>
                <?php if ($routeNoPrice['status_value'] !== ''): ?>
                    (<?= htmlspecialchars($routeNoPrice['status_value'], ENT_QUOTES, 'UTF-8') ?>)
                <?php endif; ?>
            </strong>
            — отсутствует стоимость.
            <?php if (!empty($routeNoPrice['zayavki_ids'])): ?>
                Заявки:
                <?= htmlspecialchars(implode(', ', $routeNoPrice['zayavki_ids']), ENT_QUOTES, 'UTF-8') ?>
            <?php else: ?>
                Заявки отсутствуют.
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
<h2>Добавить новый блок</h2>
<div id="formResult" class="result-box" style="display: none;"></div>
<form method="POST" id="addForm">
<input type="hidden" name="form_token" value="<?= $current_token ?>">
<div class="form-group">
<label for="zayavki">Номера заявок (можно через запятую, пробел или с новой строки):</label>
<textarea
id="zayavki"
name="zayavki"
rows="3"
placeholder="Пример: 263225, 263204, 263192"
style="resize: vertical;"
required
></textarea>
<div id="filterInfo" class="filter-stats" style="margin-top: 5px;">
<span id="filterStats"></span>
</div>
</div>
<div class="form-group">
<label for="comment">Название:</label>
<input
type="text"
id="comment"
name="comment"
placeholder="Введите название, не заешь что писать оставь пустым!"
maxlength="255"
>
</div>
<div class="form-group">
<label for="additional_comment">Комментарий:</label>
<input
type="text"
id="additional_comment"
name="additional_comment"
placeholder="Введите комментарий (необязательно)"
maxlength="500"
>
</div>
<div class="form-group">
<label for="cost">Стоимость (руб):</label>
<input
type="number"
id="cost"
name="cost"
placeholder="Введите стоимость (необязательно)"
step="0.01"
min="0"
>
</div>
<div class="form-group">
<label for="status_type">Тип статуса:</label>
<select name="status_type" id="status_type" onchange="toggleMarshrutInput()" required>
<option value="dostupno">Доступно к вывозу</option>
<option value="marshrut">Номер маршрута (Фр...)</option>
</select>
</div>
<div class="form-group" id="marshrut_group" style="display: none;">
<label for="marshrut_number">Номер маршрута:</label>
<input
type="text"
id="marshrut_number"
name="marshrut_number"
placeholder="Например: Фр123"
>
</div>
<div class="modal-actions">
<button type="submit" class="btn btn-primary" id="submitBtn">Сохранить блок</button>
<button type="button" class="btn" onclick="clearForm()">Очистить</button>
</div>
</form>
</div>
<?php if (!empty($blocks)): ?>
<div class="card">
<h2>Заявки доступные к вывозу и согласование стоимости рейсов</h2>
<div class="table-wrapper">
<table class="data-table" id="blocksTable">
<thead>
<tr>
<th style="width: 30px;"></th>
<th style="width: 50px;">ID</th>
<th>НАЗВАНИЕ</th>
<th>ДАТА И ВРЕМЯ БЛОКА</th>
<th>СТАТУС</th>
<th><?= $Select === 'marshrut' ? 'ЗАЯВКИ' : 'КОЛИЧЕСТВО ЗАЯВОК' ?></th>
<th>ОБЩИЙ ВЕС, КГ</th>
<th>СТОИМОСТЬ, РУБ</th>
<?php if ($Select === 'marshrut'): ?>
<th style="width: 95px;">Р/Т</th>
<?php endif; ?>
<th style="width: 500px;">КОММЕНТАРИЙ</th>
<th style="width: 100px; text-align: center;">ДЕЙСТВИЯ</th>
</tr>
</thead>
<tbody>
<?php foreach ($blocks as $block): ?>
<tr class="group-header" onclick="toggleBlock('details_<?= $block['id'] ?>', event)">
<td style="text-align: center;">▶</td>
<td><strong>#<?= $block['id'] ?></strong></td>
<td><?= !empty($block['comment']) ? htmlspecialchars($block['comment']) : '<span style="color:#999;">—</span>' ?></td>
<td><strong><?= date('d.m.Y H:i', strtotime($block['block_date'])) ?></strong></td>
<td>
<?php if ($block['status_type'] === 'dostupno'): ?>
<span class="status-dostupno"><?= htmlspecialchars($block['status_value']) ?></span>
<?php else: ?>
<span class="status-marshrut"><?= htmlspecialchars($block['status_value']) ?></span>
<?php endif; ?>
</td>
<td><?= $block['zayavki_count'] ?></td>
<td style="text-align: right;"><?= number_format($block['total_mass_kg'] ?? 0, 0, '.', ' ') ?> кг</td>
<td style="text-align: right;">
<?php if (!empty($block['cost'])): ?>
<?php
$costValue = round((float)$block['cost'], 2);
$costBg = '';

if ($Select === 'marshrut') {
    // Условие "заканчивается на 000" относится к целым рублям, кратным 1 000.
    $isWholeRubles = abs($costValue - round($costValue)) < 0.00001;
    if ($isWholeRubles && ((int)round($costValue)) % 1000 === 0) {
        $costBg = '#dbeafe'; // голубой
    } else {
        // Зеленый только когда стоимость соответствует фактическому весу блока
        // по стандартной ставке 34,5 ₽/кг или 39 ₽/кг.
        $costKopecks = (int)round($costValue * 100);
        $blockMassKgForCost = (float)($block['total_mass_kg'] ?? 0);
        $standard345Kopecks = (int)round($blockMassKgForCost * 34.5 * 100);
        $standard39Kopecks = (int)round($blockMassKgForCost * 39 * 100);

        if ($blockMassKgForCost > 0
            && ($costKopecks === $standard345Kopecks || $costKopecks === $standard39Kopecks)) {
            $costBg = '#dcfce7'; // зеленый
        } else {
            $costBg = '#fee2e2'; // красный
        }
    }
}
?>
<span class="status-cost"<?= $costBg !== '' ? ' style="background-color:' . $costBg . '; min-width:110px; white-space:nowrap;"' : ' style="min-width:110px; white-space:nowrap;"' ?>>
<?= number_format(
(float)$block['cost'],
(abs((float)$block['cost'] - round((float)$block['cost'])) > 0.00001 ? 2 : 0),
',',
' '
) ?> ₽
</span>
<?php else: ?>
<span style="color:#999;">—</span>
<?php endif; ?>
</td>
<?php if ($Select === 'marshrut'): ?>
<td style="text-align: right; white-space: nowrap;">
<?php
$costPerTon = null;
$blockMassTonn = (float)($block['total_mass_tonn'] ?? 0);
$blockCostValue = (float)($block['cost'] ?? 0);

if ($blockMassTonn > 0 && $blockCostValue > 0) {
    $costPerTon = $blockCostValue / $blockMassTonn;
}
?>
<?php if ($costPerTon !== null): ?>
<?= number_format(
    $costPerTon,
    (abs($costPerTon - round($costPerTon)) > 0.00001 ? 2 : 0),
    ',',
    ' '
) ?>
<?php else: ?>
<span style="color:#999;">—</span>
<?php endif; ?>
</td>
<?php endif; ?>
<td><b><?= !empty($block['additional_comment']) ? htmlspecialchars($block['additional_comment']) : '<span style="color:#999;">—</span>' ?></b></td>
<td style="text-align: center; white-space: nowrap;">
<button type="button" class="btn btn-sm" onclick="return window.editBlock(<?= $block['id'] ?>, event)">✎</button>
<button class="btn btn-sm btn-delete" onclick="deleteBlock(<?= $block['id'] ?>, event)">✖</button>
</td>
</tr>
<tr id="details_<?= $block['id'] ?>" style="display: none;">
<td colspan="<?= $Select === 'marshrut' ? 11 : 10 ?>" style="padding: 0;">
<div class="block-details">
<table class="details-table">
<thead>
<tr>
<th style="width: 80px;">ID ЗАЯВКИ</th>
<th style="width: 90px;">МАССА, КГ</th>
<th style="width: 250px;">ГРУЗООТПРАВИТЕЛЬ</th>
<th>АДРЕС ПОГРУЗКИ</th>
<th style="width: 90px; text-align: center;">ID РЕЙСА</th>
<th style="width: 220px;">НАИМЕНОВАНИЕ</th>
<th style="width: 160px; text-align: center;">СТАТУС РЕЙСА</th>
<th style="width: 105px; text-align: center;">ЗАБОР</th>
<th style="width: 105px; text-align: center;">ВЫГРУЗКА</th>
</tr>
</thead>
<tbody>
<?php if (!empty($block['zayavki'])): ?>
<?php foreach ($block['zayavki'] as $zayavka):
$mass_kg = floatval($zayavka['mass_netto'] ?? 0) * 1000;
$zayavkaFlightRefs = $flight_refs_by_zayavka[(string)$zayavka['zayavka_id']] ?? [];
?>
<tr>
<td><strong><?= htmlspecialchars($zayavka['zayavka_id']) ?></strong></td>
<td class="text-right"><?= number_format($mass_kg, 0, '.', ' ') ?></td>
<td style="text-align: left;" title="<?= htmlspecialchars($zayavka['naim_oo_gruzootpravitel'] ?? '') ?>"><?= trunc50($zayavka['naim_oo_gruzootpravitel'] ?? '', 50) ?></td>
<td style="text-align: left;" title="<?= htmlspecialchars($zayavka['mno_adres_pogruzki'] ?? '') ?>"><?= trunc50($zayavka['mno_adres_pogruzki'] ?? '', 75) ?></td>
<td class="text-center">
<?php if (!empty($zayavkaFlightRefs)): ?>
<?php foreach ($zayavkaFlightRefs as $flightRef): ?>
<div><strong>#<?= (int)$flightRef['id'] ?></strong></div>
<?php endforeach; ?>
<?php else: ?>
<span style="color:#999;">—</span>
<?php endif; ?>
</td>
<td style="text-align: left;">
<?php if (!empty($zayavkaFlightRefs)): ?>
<?php foreach ($zayavkaFlightRefs as $flightRef): ?>
<div><?= $flightRef['name'] !== '' ? htmlspecialchars($flightRef['name'], ENT_QUOTES, 'UTF-8') : '<span style="color:#999;">—</span>' ?></div>
<?php endforeach; ?>
<?php else: ?>
<span style="color:#999;">—</span>
<?php endif; ?>
</td>
<td class="text-center">
<?php if (!empty($zayavkaFlightRefs)): ?>
<?php foreach ($zayavkaFlightRefs as $flightRef): ?>
<?php
$flightStatusLabel = $flightRef['status_name'] !== '' ? $flightRef['status_name'] : $flightRef['status'];
$flightStatusColor = $flightRef['status_color'] !== '' ? $flightRef['status_color'] : '#6c757d';
?>
<div style="margin-bottom:2px;">
<span class="flight-status-badge" style="background:<?= htmlspecialchars($flightStatusColor, ENT_QUOTES, 'UTF-8') ?>;">
<?= $flightStatusLabel !== '' ? htmlspecialchars($flightStatusLabel, ENT_QUOTES, 'UTF-8') : '—' ?>
</span>
</div>
<?php endforeach; ?>
<?php else: ?>
<span style="color:#999;">—</span>
<?php endif; ?>
</td>
<td class="text-center">
<?php if (!empty($zayavkaFlightRefs)): ?>
<?php foreach ($zayavkaFlightRefs as $flightRef): ?>
<div><?= $flightRef['actual_start_date'] !== '' ? date('d.m.Y', strtotime($flightRef['actual_start_date'])) : '<span style="color:#999;">—</span>' ?></div>
<?php endforeach; ?>
<?php else: ?>
<span style="color:#999;">—</span>
<?php endif; ?>
</td>
<td class="text-center">
<?php if (!empty($zayavkaFlightRefs)): ?>
<?php foreach ($zayavkaFlightRefs as $flightRef): ?>
<div><?= $flightRef['actual_end_date'] !== '' ? date('d.m.Y', strtotime($flightRef['actual_end_date'])) : '<span style="color:#999;">—</span>' ?></div>
<?php endforeach; ?>
<?php else: ?>
<span style="color:#999;">—</span>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr>
<td colspan="9" class="text-center text-muted">Данные заявок не найдены</td>
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
<?php endif; ?>
</div>
<div id="editModal" class="modal">
<div class="modal-content">
<div class="modal-header">
<h3>✏️ Редактирование блока</h3>
<span class="close" onclick="closeEditModal()">&times;</span>
</div>
<div id="editModalContent"></div>
</div>
</div>
<script>
let isSubmitting = false;
let currentFormToken = '<?= $current_token ?>';
let validationTimeout = null;
let lastValidationResult = { found_count: 0, total_count: 0, not_found: [], invalid_format: [] };
function toggleMarshrutInput() {
const select = document.getElementById('status_type');
const marshrutGroup = document.getElementById('marshrut_group');
marshrutGroup.style.display = select.value === 'marshrut' ? 'block' : 'none';
}
function clearForm() {
document.getElementById('zayavki').value = '';
document.getElementById('comment').value = '';
document.getElementById('additional_comment').value = '';
document.getElementById('cost').value = '';
document.getElementById('marshrut_number').value = '';
document.getElementById('status_type').value = 'dostupno';
document.getElementById('marshrut_group').style.display = 'none';
document.getElementById('filterStats').innerHTML = '';
document.getElementById('zayavki').classList.remove('input-error');
document.getElementById('submitBtn').disabled = false;
document.getElementById('formResult').style.display = 'none';
lastValidationResult = { found_count: 0, total_count: 0, not_found: [], invalid_format: [] };
}
function showResult(success, message, data = null) {
const resultDiv = document.getElementById('formResult');
resultDiv.style.display = 'block';
if (success) {
resultDiv.className = 'result-box result-success';
let html = '<strong>✅ ' + message + '</strong><br>';
if (data) {
html += 'Всего заявок: ' + data.total + '<br>';
html += 'Найдено в БД: ' + data.found + '<br>';
if (data.not_found && data.not_found.length > 0) {
html += '<div class="not-found-list">';
html += '<strong>⚠️ Не найдены в БД (' + data.not_found.length + '):</strong>';
html += '<div style="margin-top: 5px; font-family: monospace; color: #856404;">';
html += data.not_found.join(', ');
html += '</div></div>';
}
if (data.invalid_format && data.invalid_format.length > 0) {
html += '<div class="not-found-list">';
html += '<strong>⚠️ Некорректный формат (' + data.invalid_format.length + '):</strong>';
html += '<div style="margin-top: 5px; font-family: monospace; color: #856404;">';
html += data.invalid_format.join(', ');
html += '</div></div>';
}
}
resultDiv.innerHTML = html;
setTimeout(() => {
location.reload();
}, 2000);
} else {
resultDiv.className = 'result-box result-error';
resultDiv.innerHTML = '<strong>❌ Ошибка:</strong><br>' + message;
setTimeout(() => {
resultDiv.style.display = 'none';
}, 5000);
}
}
function handleFormSubmit(event) {
event.preventDefault();
if (isSubmitting) {
alert('Форма уже отправляется. Пожалуйста, подождите.');
return false;
}
if (lastValidationResult.not_found && lastValidationResult.not_found.length > 0) {
alert('Есть заявки, не найденные в базе данных: ' + lastValidationResult.not_found.join(', '));
return false;
}
if (lastValidationResult.invalid_format && lastValidationResult.invalid_format.length > 0) {
alert('Есть заявки с некорректным форматом: ' + lastValidationResult.invalid_format.join(', '));
return false;
}
const submitBtn = document.getElementById('submitBtn');
submitBtn.classList.add('btn-loading');
submitBtn.disabled = true;
isSubmitting = true;
const formData = new FormData();
formData.append('save_block', '1');
formData.append('form_token', currentFormToken);
formData.append('zayavki', document.getElementById('zayavki').value);
formData.append('comment', document.getElementById('comment').value);
formData.append('additional_comment', document.getElementById('additional_comment').value);
formData.append('cost', document.getElementById('cost').value);
formData.append('status_type', document.getElementById('status_type').value);
formData.append('marshrut_number', document.getElementById('marshrut_number').value);
fetch('', {
method: 'POST',
body: formData
})
.then(response => response.json())
.then(data => {
submitBtn.classList.remove('btn-loading');
submitBtn.disabled = false;
isSubmitting = false;
if (data.success) {
if (data.new_token) {
currentFormToken = data.new_token;
}
showResult(true, data.message, {
total: data.total,
found: data.found,
not_found: data.not_found,
invalid_format: data.invalid_format
});
} else {
showResult(false, data.message);
}
})
.catch(error => {
console.error('Ошибка:', error);
submitBtn.classList.remove('btn-loading');
submitBtn.disabled = false;
isSubmitting = false;
showResult(false, 'Ошибка при сохранении: ' + error.message);
});
return false;
}
function updateFilterStats(data) {
const filterStats = document.getElementById('filterStats');
if (data.total_count > 0) {
let message = '🔍 Найдено заявок: ' + data.found_count + ' из ' + data.total_count + ' введенных';
if (data.not_found && data.not_found.length > 0) {
message += ' <span class="filter-stats-error">⚠️ Не найдены: ' + data.not_found.join(', ') + '</span>';
}
if (data.invalid_format && data.invalid_format.length > 0) {
message += ' <span class="filter-stats-error">❌ Некорректный формат: ' + data.invalid_format.join(', ') + '</span>';
}
filterStats.innerHTML = message;
} else if (data.total_count === 0 && (data.invalid_format && data.invalid_format.length > 0)) {
filterStats.innerHTML = '⚠️ Не найдено корректных номеров заявок. Некорректный формат: ' + data.invalid_format.join(', ');
} else {
filterStats.innerHTML = '';
}
}
function validateZayavkiOnTheFly() {
const textarea = document.getElementById('zayavki');
const zayavkiValue = textarea.value.trim();
if (validationTimeout) {
clearTimeout(validationTimeout);
}
if (zayavkiValue === '') {
document.getElementById('filterStats').innerHTML = '';
document.getElementById('submitBtn').disabled = false;
lastValidationResult = { found_count: 0, total_count: 0, not_found: [], invalid_format: [] };
return;
}
document.getElementById('filterStats').innerHTML = '<span class="validating">Проверка заявок</span>';
validationTimeout = setTimeout(() => {
const formData = new FormData();
formData.append('validate_zayavki', '1');
formData.append('zayavki', zayavkiValue);
fetch('', {
method: 'POST',
body: formData
})
.then(response => response.json())
.then(data => {
if (data.success) {
lastValidationResult = {
found_count: data.found_count,
total_count: data.total_count,
not_found: data.not_found || [],
invalid_format: data.invalid_format || []
};
updateFilterStats(lastValidationResult);
const hasErrors = (lastValidationResult.not_found && lastValidationResult.not_found.length > 0) ||
(lastValidationResult.invalid_format && lastValidationResult.invalid_format.length > 0);
document.getElementById('submitBtn').disabled = hasErrors;
} else {
document.getElementById('filterStats').innerHTML = '<span class="filter-stats-error">Ошибка при проверке</span>';
}
})
.catch(error => {
console.error('Ошибка валидации:', error);
document.getElementById('filterStats').innerHTML = '<span class="filter-stats-error">Ошибка соединения</span>';
});
}, 500);
}
function toggleBlock(blockId, event) {
if (event) event.stopPropagation();
const blockRow = document.getElementById(blockId);
const headerRow = blockRow.previousElementSibling;
const arrow = headerRow.querySelector('td:first-child');
if (blockRow.style.display === 'none') {
blockRow.style.display = 'table-row';
arrow.textContent = '▼';
} else {
blockRow.style.display = 'none';
arrow.textContent = '▶';
}
}
function deleteBlock(blockId, event) {
if (event) event.stopPropagation();
if (!confirm('Вы уверены, что хотите удалить этот блок?')) return;
const deleteBtn = event.target;
deleteBtn.classList.add('btn-loading');
deleteBtn.disabled = true;
const formData = new FormData();
formData.append('delete_block', '1');
formData.append('block_id', blockId);
fetch('', {
method: 'POST',
body: formData
})
.then(response => response.json())
.then(data => {
if (data.success) {
location.reload();
} else {
alert('Ошибка: ' + data.message);
deleteBtn.classList.remove('btn-loading');
deleteBtn.disabled = false;
}
})
.catch(error => {
alert('Ошибка при удалении');
deleteBtn.classList.remove('btn-loading');
deleteBtn.disabled = false;
});
}
window.editBlock = function editBlock(blockId, event) {
if (event) {
    event.preventDefault();
    event.stopPropagation();
}

const editBtn = event && event.currentTarget ? event.currentTarget : null;

if (editBtn) {
    editBtn.classList.add('btn-loading');
    editBtn.disabled = true;
}

const url = window.location.pathname + '?get_block=1&block_id=' + encodeURIComponent(blockId);

fetch(url, {
    method: 'GET',
    credentials: 'same-origin',
    cache: 'no-store',
    headers: {
        'X-Requested-With': 'XMLHttpRequest'
    }
})
.then(async response => {
    const raw = await response.text();

    if (!response.ok) {
        throw new Error('HTTP ' + response.status + ': ' + raw.slice(0, 300));
    }

    let data;
    try {
        data = JSON.parse(raw);
    } catch (parseError) {
        throw new Error('Сервер вернул некорректный JSON: ' + raw.slice(0, 300));
    }

    return data;
})
.then(data => {
    if (!data || data.success !== true || !data.block) {
        throw new Error(data && data.message ? data.message : 'Данные блока не получены');
    }

    showEditModal(data.block);
})
.catch(error => {
    console.error('Ошибка открытия блока #' + blockId + ':', error);
    alert('Не удалось открыть редактирование блока #' + blockId + '.\\n' + error.message);
})
.finally(() => {
    if (editBtn) {
        editBtn.classList.remove('btn-loading');
        editBtn.disabled = false;
    }
});

return false;
};
function showEditModal(block) {
const modal = document.getElementById('editModal');
const content = document.getElementById('editModalContent');
let statusTypeSelected = block.status_type;
let marshrutDisplay = statusTypeSelected === 'marshrut' ? 'block' : 'none';

let editTotalMassKg = 0;

// Основной источник — сохранённый общий вес блока в тоннах.
const blockMassTonn = parseFloat(String(block.total_mass_tonn ?? '').replace(',', '.'));
if (Number.isFinite(blockMassTonn) && blockMassTonn > 0) {
    editTotalMassKg = blockMassTonn * 1000;
}

// Fallback — сумма актуальных масс всех заявок блока.
if (!(editTotalMassKg > 0) && Array.isArray(block.zayavki)) {
    editTotalMassKg = block.zayavki.reduce((sum, z) => {
        const massTonn = parseFloat(String(z.mass_netto ?? '0').replace(',', '.'));
        return sum + (Number.isFinite(massTonn) ? massTonn * 1000 : 0);
    }, 0);
}

editTotalMassKg = Math.round(editTotalMassKg * 1000) / 1000;

const editSuggestedCost345 = Math.round(editTotalMassKg * 34.5 * 100) / 100;
const editSuggestedCost39 = Math.round(editTotalMassKg * 39 * 100) / 100;

let html = `
<form id="editForm" onsubmit="return submitEdit(event, ${block.id})">
<input type="hidden" name="form_token" value="${currentFormToken}">
<input type="hidden" name="update_block" value="1">
<input type="hidden" name="block_id" value="${block.id}">
<div class="form-group">
<label for="edit_zayavki">Номера заявок:</label>
<textarea
id="edit_zayavki"
name="zayavki"
rows="3"
style="resize: vertical;"
required
oninput="validateEditZayavkiOnTheFly()"
>${escapeHtml(block.zayavki_text || '')}</textarea>
<div id="editFilterInfo" class="filter-stats" style="margin-top: 5px;">
<span id="editFilterStats"></span>
</div>
<div class="help-text">Разделители: запятая, пробел, перенос строки. Дубликаты удаляются.</div>
</div>
<div class="form-group">
<label for="edit_comment">Название:</label>
<input
type="text"
id="edit_comment"
name="comment"
value="${escapeHtml(block.comment || '')}"
maxlength="255"
>
</div>
<div class="form-group">
<label for="edit_additional_comment">Комментарий:</label>
<input
type="text"
id="edit_additional_comment"
name="additional_comment"
value="${escapeHtml(block.additional_comment || '')}"
maxlength="500"
>
</div>
<div class="form-group">
<label for="edit_cost">Стоимость (руб):</label>
<input
type="number"
id="edit_cost"
name="cost"
value="${block.cost || ''}"
step="0.01"
min="0"
placeholder="Введите стоимость (необязательно)"
oninput="updateEditCostHint()"
>
<div id="editCostHint"
     data-total-mass-kg="${editTotalMassKg}"
     data-cost-345="${editSuggestedCost345}"
     data-cost-39="${editSuggestedCost39}"
     style="display:none; box-sizing:border-box; width:100%; margin-top:8px; padding:10px 12px; border:1px solid #f3b4b8; background:#fff5f5; border-radius:7px; color:#b4232b; font-size:12px; line-height:1.35; overflow:hidden;"></div>
</div>
<div class="form-group">
<label for="edit_status_type">Тип статуса:</label>
<select name="status_type" id="edit_status_type" onchange="toggleEditMarshrutInput()" required>
<option value="dostupno" ${statusTypeSelected === 'dostupno' ? 'selected' : ''}>Доступно к вывозу</option>
<option value="marshrut" ${statusTypeSelected === 'marshrut' ? 'selected' : ''}>Номер маршрута</option>
</select>
</div>
<div class="form-group" id="edit_marshrut_group" style="display: ${marshrutDisplay};">
<label for="edit_marshrut_number">Номер маршрута:</label>
<input
type="text"
id="edit_marshrut_number"
name="marshrut_number"
value="${statusTypeSelected === 'marshrut' ? escapeHtml(block.status_value) : ''}"
placeholder="Например: Фр123"
>
</div>
<div class="modal-actions">
<button type="submit" class="btn btn-primary" id="editSubmitBtn">Сохранить изменения</button>
<button type="button" class="btn btn-secondary" onclick="closeEditModal()">Отмена</button>
</div>
</form>
`;
content.innerHTML = html;
modal.classList.add('show');
setTimeout(() => {
    validateEditZayavkiOnTheFly();
    updateEditCostHint();
}, 100);
}
function escapeHtml(str) {
if (!str) return '';
return str.replace(/[&<>]/g, function(m) {
if (m === '&') return '&amp;';
if (m === '<') return '&lt;';
if (m === '>') return '&gt;';
return m;
});
}

function formatRubCost(value) {
const num = Number(value || 0);
const hasKopecks = Math.abs(num - Math.round(num)) > 0.00001;
return new Intl.NumberFormat('ru-RU', {
minimumFractionDigits: hasKopecks ? 2 : 0,
maximumFractionDigits: 2
}).format(num).replace(/\u00a0|\u202f/g, ' ') + ' ₽';
}
function formatKg(value) {
const num = Number(value || 0);
return new Intl.NumberFormat('ru-RU', {
minimumFractionDigits: 0,
maximumFractionDigits: 3
}).format(num).replace(/\u00a0|\u202f/g, ' ') + ' кг';
}


function isEditCostRed(value, cost345, cost39) {
const num = Number(String(value ?? '').replace(',', '.'));
if (!Number.isFinite(num) || num <= 0) return false;

const rounded = Math.round(num * 100) / 100;

// Голубой имеет приоритет: целая сумма, кратная 1 000.
const isWholeRubles = Math.abs(rounded - Math.round(rounded)) < 0.00001;
if (isWholeRubles && Math.round(rounded) % 1000 === 0) {
return false;
}

// Зеленый: стоимость должна соответствовать фактическому весу блока
// по ставке 34,5 ₽/кг или 39 ₽/кг.
const kopecks = Math.round(rounded * 100);
const cost345Kopecks = Math.round(Number(cost345 || 0) * 100);
const cost39Kopecks = Math.round(Number(cost39 || 0) * 100);
if ((cost345Kopecks > 0 && kopecks === cost345Kopecks) ||
    (cost39Kopecks > 0 && kopecks === cost39Kopecks)) {
return false;
}

return true;
}

function applySuggestedEditCost(rate) {
const input = document.getElementById('edit_cost');
const hint = document.getElementById('editCostHint');
if (!input || !hint) return;

let value = 0;
if (String(rate) === '34.5') {
    value = Number(hint.getAttribute('data-cost-345') || 0);
} else if (String(rate) === '39') {
    value = Number(hint.getAttribute('data-cost-39') || 0);
}

if (!Number.isFinite(value) || value <= 0) return;

// Для input[type=number] используем точку как десятичный разделитель.
input.value = value.toFixed(2).replace(/\.00$/, '');

// Сразу обновляем состояние: выбранная стандартная сумма больше не должна
// оставаться отмеченной как ошибка.
updateEditCostHint();
input.dispatchEvent(new Event('change', { bubbles: true }));
}

function updateEditCostHint() {
const input = document.getElementById('edit_cost');
const hint = document.getElementById('editCostHint');
if (!input || !hint) return;

const currentValue = Number(String(input.value ?? '').replace(',', '.'));
const totalMassKg = Number(hint.getAttribute('data-total-mass-kg') || 0);
const cost345 = Number(hint.getAttribute('data-cost-345') || 0);
const cost39 = Number(hint.getAttribute('data-cost-39') || 0);

if (!isEditCostRed(input.value, cost345, cost39)) {
    hint.style.display = 'none';
    hint.innerHTML = '';
    return;
}

if (!(totalMassKg > 0) || !(cost345 > 0) || !(cost39 > 0)) {
    hint.innerHTML =
        '<div style="font-weight:700;">Не удалось рассчитать стандартную стоимость: отсутствуют корректные данные о весе блока.</div>';
    hint.style.display = 'block';
    return;
}

let nearestLabel = '';
let nearestCost = 0;
let diff = 0;

if (Number.isFinite(currentValue)) {
    const diff345 = Math.abs(currentValue - cost345);
    const diff39 = Math.abs(currentValue - cost39);

    if (diff345 <= diff39) {
        nearestLabel = '34,5 ₽/кг';
        nearestCost = cost345;
    } else {
        nearestLabel = '39 ₽/кг';
        nearestCost = cost39;
    }

    diff = currentValue - nearestCost;
}

let diffHtml = '';
if (Number.isFinite(currentValue) && nearestCost > 0) {
    const sign = diff > 0 ? '+' : '';
    diffHtml =
        '<div style="margin-top:7px; padding-top:7px; border-top:1px solid #f1c5c8; color:#7f1d1d;">' +
        'Ближайшая стандартная сумма: <strong>' + nearestLabel + '</strong>. ' +
        'Отклонение: <strong>' + sign + formatRubCost(diff) + '</strong>' +
        '</div>';
}

hint.innerHTML =
'<div style="font-weight:700; font-size:12.5px; margin-bottom:3px;">Стоимость не соответствует стандартной ставке</div>' +
'<div style="color:#6b7280; margin-bottom:7px;">Общий вес блока: <strong style="color:#374151;">' +
formatKg(totalMassKg) + '</strong></div>' +

'<div style="display:flex; flex-direction:column; gap:6px; width:100%;">' +

'<label style="box-sizing:border-box; display:grid; grid-template-columns:18px 92px minmax(0,1fr); align-items:center; column-gap:8px; width:100%; margin:0; padding:7px 8px; border:1px solid #f6d4d6; border-radius:6px; background:#fff; cursor:pointer;">' +
'<input type="radio" name="edit_cost_rate_choice" value="34.5" ' +
'style="box-sizing:border-box; width:16px !important; height:16px !important; min-width:16px !important; max-width:16px !important; margin:0 !important; padding:0 !important;" onchange="applySuggestedEditCost(\'34.5\')">' +
'<span style="white-space:nowrap;"><strong>34,5 ₽/кг</strong></span>' +
'<strong style="min-width:0; text-align:right; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">' + formatRubCost(cost345) + '</strong>' +
'</label>' +

'<label style="box-sizing:border-box; display:grid; grid-template-columns:18px 92px minmax(0,1fr); align-items:center; column-gap:8px; width:100%; margin:0; padding:7px 8px; border:1px solid #f6d4d6; border-radius:6px; background:#fff; cursor:pointer;">' +
'<input type="radio" name="edit_cost_rate_choice" value="39" ' +
'style="box-sizing:border-box; width:16px !important; height:16px !important; min-width:16px !important; max-width:16px !important; margin:0 !important; padding:0 !important;" onchange="applySuggestedEditCost(\'39\')">' +
'<span style="white-space:nowrap;"><strong>39 ₽/кг</strong></span>' +
'<strong style="min-width:0; text-align:right; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">' + formatRubCost(cost39) + '</strong>' +
'</label>' +

'</div>' +

diffHtml;

hint.style.display = 'block';
}

function toggleEditMarshrutInput() {
const select = document.getElementById('edit_status_type');
const marshrutGroup = document.getElementById('edit_marshrut_group');
if (marshrutGroup) {
marshrutGroup.style.display = select.value === 'marshrut' ? 'block' : 'none';
}
}
let editValidationTimeout = null;
let editLastValidationResult = { found_count: 0, total_count: 0, not_found: [], invalid_format: [] };
function updateEditFilterStats(data) {
const filterStats = document.getElementById('editFilterStats');
if (!filterStats) return;
if (data.total_count > 0) {
let message = '🔍 Найдено заявок: ' + data.found_count + ' из ' + data.total_count + ' введенных';
if (data.not_found && data.not_found.length > 0) {
message += ' <span class="filter-stats-error">⚠️ Не найдены: ' + data.not_found.join(', ') + '</span>';
}
if (data.invalid_format && data.invalid_format.length > 0) {
message += ' <span class="filter-stats-error">❌ Некорректный формат: ' + data.invalid_format.join(', ') + '</span>';
}
filterStats.innerHTML = message;
} else if (data.total_count === 0 && (data.invalid_format && data.invalid_format.length > 0)) {
filterStats.innerHTML = '⚠️ Не найдено корректных номеров заявок. Некорректный формат: ' + data.invalid_format.join(', ');
} else {
filterStats.innerHTML = '';
}
}
function validateEditZayavkiOnTheFly() {
const textarea = document.getElementById('edit_zayavki');
if (!textarea) return;
const zayavkiValue = textarea.value.trim();
if (editValidationTimeout) {
clearTimeout(editValidationTimeout);
}
const filterStats = document.getElementById('editFilterStats');
if (zayavkiValue === '') {
if (filterStats) filterStats.innerHTML = '';
const submitBtn = document.getElementById('editSubmitBtn');
if (submitBtn) submitBtn.disabled = false;
editLastValidationResult = { found_count: 0, total_count: 0, not_found: [], invalid_format: [] };
return;
}
if (filterStats) filterStats.innerHTML = '<span class="validating">Проверка заявок</span>';
editValidationTimeout = setTimeout(() => {
const formData = new FormData();
formData.append('validate_zayavki', '1');
formData.append('zayavki', zayavkiValue);
fetch('', {
method: 'POST',
body: formData
})
.then(response => response.json())
.then(data => {
if (data.success) {
editLastValidationResult = {
found_count: data.found_count,
total_count: data.total_count,
not_found: data.not_found || [],
invalid_format: data.invalid_format || []
};
updateEditFilterStats(editLastValidationResult);
const hasErrors = (editLastValidationResult.not_found && editLastValidationResult.not_found.length > 0) ||
(editLastValidationResult.invalid_format && editLastValidationResult.invalid_format.length > 0);
const submitBtn = document.getElementById('editSubmitBtn');
if (submitBtn) submitBtn.disabled = hasErrors;
} else {
if (filterStats) filterStats.innerHTML = '<span class="filter-stats-error">Ошибка при проверке</span>';
}
})
.catch(error => {
console.error('Ошибка валидации:', error);
if (filterStats) filterStats.innerHTML = '<span class="filter-stats-error">Ошибка соединения</span>';
});
}, 500);
}
function submitEdit(event, blockId) {
event.preventDefault();
const submitBtn = document.getElementById('editSubmitBtn');
if (editLastValidationResult.not_found && editLastValidationResult.not_found.length > 0) {
alert('Есть заявки, не найденные в базе данных: ' + editLastValidationResult.not_found.join(', '));
return false;
}
if (editLastValidationResult.invalid_format && editLastValidationResult.invalid_format.length > 0) {
alert('Есть заявки с некорректным форматом: ' + editLastValidationResult.invalid_format.join(', '));
return false;
}
if (submitBtn.disabled) {
alert('Есть заявки, не найденные в базе данных. Исправьте ошибки перед отправкой.');
return false;
}
submitBtn.classList.add('btn-loading');
submitBtn.disabled = true;
const form = document.getElementById('editForm');
const formData = new FormData(form);
fetch('', {
method: 'POST',
body: formData
})
.then(response => response.json())
.then(data => {
submitBtn.classList.remove('btn-loading');
submitBtn.disabled = false;
if (data.success) {
if (data.new_token) {
currentFormToken = data.new_token;
const tokenInputs = document.querySelectorAll('input[name="form_token"]');
tokenInputs.forEach(input => {
input.value = currentFormToken;
});
}
if ((data.not_found && data.not_found.length > 0) || (data.invalid_format && data.invalid_format.length > 0)) {
let message = 'Блок обновлен, но есть проблемы:';
if (data.not_found && data.not_found.length > 0) {
message += '\nНе найдены в БД: ' + data.not_found.join(', ');
}
if (data.invalid_format && data.invalid_format.length > 0) {
message += '\nНекорректный формат: ' + data.invalid_format.join(', ');
}
alert(message);
} else {
alert('Блок успешно обновлен');
}
location.reload();
} else {
alert('Ошибка: ' + data.message);
}
})
.catch(error => {
alert('Ошибка при обновлении');
console.error(error);
submitBtn.classList.remove('btn-loading');
submitBtn.disabled = false;
});
return false;
}
function closeEditModal() {
document.getElementById('editModal').classList.remove('show');
}
window.onclick = function(event) {
const editModal = document.getElementById('editModal');
if (event.target === editModal) {
closeEditModal();
}
}
window.onload = function() {
const form = document.getElementById('addForm');
if (form) {
form.addEventListener('submit', handleFormSubmit);
}
const zayavkiTextarea = document.getElementById('zayavki');
if (zayavkiTextarea) {
zayavkiTextarea.addEventListener('input', validateZayavkiOnTheFly);
}
};
</script>
</body>
</html>