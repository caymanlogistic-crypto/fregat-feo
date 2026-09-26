<?php
require_once 'config.php';
require_once 'include/price.php'; // Подключение price.php в самом начале
session_start();

// 🔧 Функция обрезки текста до 50 символов с многоточием
function trunc50($s, $limit) {
    $s = (string)$s;
    if (mb_strlen($s, 'UTF-8') > $limit) {
        return htmlspecialchars(mb_substr($s, 0, $limit, 'UTF-8'), ENT_QUOTES, 'UTF-8') . '...';
    }
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

const FLIGHT_LEGAL_ENTITIES = [
    'planex' => 'ООО «Планэкс»',
    'mg_logistic' => 'ООО «МГ Логистик»',
];
const LEGACY_FLIGHT_CUTOFF_ID = 244;

function normalizeFlightLegalEntity($value) {
    $code = strtolower(trim((string)$value));
    if (!array_key_exists($code, FLIGHT_LEGAL_ENTITIES)) {
        throw new Exception('Выберите юридическое лицо рейса');
    }
    return $code;
}

function flightLegalEntityLabel($code) {
    $normalized = strtolower(trim((string)$code));
    return FLIGHT_LEGAL_ENTITIES[$normalized] ?? 'Не указано';
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
// Создаем таблицу flights, если её нет
$pdo->exec("
CREATE TABLE IF NOT EXISTS `flights` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`block_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
`zayavki_ids` text NOT NULL COMMENT 'ID заявок через запятую',
`zayavki_count` int(11) NOT NULL DEFAULT 0,
`total_mass_tonn` decimal(10,3) DEFAULT NULL,
`unload_type` enum('OO','SKLAD') NOT NULL DEFAULT 'OO' COMMENT 'Выгрузка: ОО или СКЛАД',
`legal_entity_code` varchar(32) DEFAULT NULL COMMENT 'Юридическое лицо рейса',
`status` varchar(50) NOT NULL DEFAULT 'search' COMMENT 'Статус рейса',
`contractor_id` int(11) DEFAULT NULL COMMENT 'ID подрядчика',
`driver_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'ID водителя',
`cost` int(11) DEFAULT NULL COMMENT 'Стоимость',
`payment_terms` varchar(500) DEFAULT NULL COMMENT 'Условия оплаты',
`comment` varchar(500) DEFAULT NULL COMMENT 'Комментарий',
PRIMARY KEY (`id`),
KEY `block_date` (`block_date`),
KEY `status` (`status`),
KEY `contractor_id` (`contractor_id`),
KEY `driver_id` (`driver_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
// Добавляем новые столбцы для дополнительных параметров рейса
try {
// Планируемая дата начала (конкретная дата)
$stmt = $pdo->query("SHOW COLUMNS FROM flights LIKE 'planned_start_date'");
if ($stmt->rowCount() == 0) {
$pdo->exec("ALTER TABLE `flights` ADD COLUMN `planned_start_date` DATE NULL COMMENT 'Планируемая дата начала рейса (конкретная дата)' AFTER `comment`");
}
// Планируемая дата начала (начало диапазона)
$stmt = $pdo->query("SHOW COLUMNS FROM flights LIKE 'planned_start_date_from'");
if ($stmt->rowCount() == 0) {
$pdo->exec("ALTER TABLE `flights` ADD COLUMN `planned_start_date_from` DATE NULL COMMENT 'Планируемая дата начала рейса (начало диапазона)' AFTER `planned_start_date`");
}
// Планируемая дата начала (конец диапазона)
$stmt = $pdo->query("SHOW COLUMNS FROM flights LIKE 'planned_start_date_to'");
if ($stmt->rowCount() == 0) {
$pdo->exec("ALTER TABLE `flights` ADD COLUMN `planned_start_date_to` DATE NULL COMMENT 'Планируемая дата начала рейса (конец диапазона)' AFTER `planned_start_date_from`");
}
// Фактическая дата начала вывоза
$stmt = $pdo->query("SHOW COLUMNS FROM flights LIKE 'actual_start_date'");
if ($stmt->rowCount() == 0) {
$pdo->exec("ALTER TABLE `flights` ADD COLUMN `actual_start_date` DATE NULL COMMENT 'Фактическая дата начала вывоза' AFTER `planned_start_date_to`");
}
// Фактическая дата окончания вывоза
$stmt = $pdo->query("SHOW COLUMNS FROM flights LIKE 'actual_end_date'");
if ($stmt->rowCount() == 0) {
$pdo->exec("ALTER TABLE `flights` ADD COLUMN `actual_end_date` DATE NULL COMMENT 'Фактическая дата окончания вывоза' AFTER `actual_start_date`");
}
// Закреплённый менеджер
$stmt = $pdo->query("SHOW COLUMNS FROM flights LIKE 'assigned_manager_id'");
if ($stmt->rowCount() == 0) {
$pdo->exec("ALTER TABLE `flights` ADD COLUMN `assigned_manager_id` int(10) UNSIGNED NULL COMMENT 'ID закреплённого менеджера (ссылка на users.id)' AFTER `actual_end_date`");
}
// Проверяем структуру таблицы и добавляем недостающие колонки
$stmt = $pdo->query("SHOW COLUMNS FROM flights LIKE 'payment_terms'");
if ($stmt->rowCount() == 0) {
$pdo->exec("ALTER TABLE `flights` ADD COLUMN `payment_terms` varchar(500) DEFAULT NULL COMMENT 'Условия оплаты' AFTER `cost`");
}
$stmt = $pdo->query("SHOW COLUMNS FROM flights LIKE 'comment'");
if ($stmt->rowCount() == 0) {
$pdo->exec("ALTER TABLE `flights` ADD COLUMN `comment` varchar(500) DEFAULT NULL COMMENT 'Комментарий' AFTER `payment_terms`");
}
$stmt = $pdo->query("SHOW COLUMNS FROM flights WHERE Field = 'id'");
$id_column = $stmt->fetch();
if ($id_column && strpos($id_column['Extra'], 'auto_increment') === false) {
$pdo->exec("ALTER TABLE `flights` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT");
}
} catch (Exception $e) {
// Таблица может еще не существовать, игнорируем ошибку
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
// Генерация токена для защиты от повторной отправки
if (!isset($_SESSION['form_token'])) {
$_SESSION['form_token'] = bin2hex(random_bytes(32));
}
// Получаем список подрядчиков и водителей для выпадающих списков
$contractors = [];
$drivers = [];
$managers = [];
try {
$stmt = $pdo->query("SELECT id, name FROM contractors ORDER BY name");
$contractors = $stmt->fetchAll();
$stmt = $pdo->query("SELECT id, full_name, vehicle_make_plate FROM drivers ORDER BY full_name");
$drivers = $stmt->fetchAll();
// Получаем список менеджеров из таблицы users
$stmt = $pdo->query("SELECT id, Фамилия, Имя FROM users WHERE Роль = 'logist' OR Роль = 'admin' ORDER BY Фамилия, Имя");
$managers = $stmt->fetchAll();
} catch (Exception $e) {
// Таблицы могут быть пустыми или не существовать
}
// Обработка AJAX сохранения нового рейса
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_flight'])) {
header('Content-Type: application/json');
try {
if (!isset($_POST['form_token']) || $_POST['form_token'] !== $_SESSION['form_token']) {
throw new Exception('Ошибка безопасности. Пожалуйста, обновите страницу.');
}
$zayavki_input = trim($_POST['zayavki'] ?? '');
$unload_type = $_POST['unload_type'] ?? 'OO';
$legal_entity_code = normalizeFlightLegalEntity($_POST['legal_entity_code'] ?? '');
$status = $_POST['status'] ?? 'search';
$contractor_id = !empty($_POST['contractor_id']) ? (int)$_POST['contractor_id'] : null;
$driver_id = !empty($_POST['driver_id']) ? (int)$_POST['driver_id'] : null;
$cost = !empty($_POST['cost']) ? (int)$_POST['cost'] : null;
$payment_terms = trim($_POST['payment_terms'] ?? '');
$comment = trim($_POST['comment'] ?? '');
// Новые поля
$planned_start_date = !empty($_POST['planned_start_date']) ? $_POST['planned_start_date'] : null;
$planned_start_date_from = !empty($_POST['planned_start_date_from']) ? $_POST['planned_start_date_from'] : null;
$planned_start_date_to = !empty($_POST['planned_start_date_to']) ? $_POST['planned_start_date_to'] : null;
$actual_start_date = !empty($_POST['actual_start_date']) ? $_POST['actual_start_date'] : null;
$actual_end_date = !empty($_POST['actual_end_date']) ? $_POST['actual_end_date'] : null;
$assigned_manager_id = !empty($_POST['assigned_manager_id']) ? (int)$_POST['assigned_manager_id'] : null;
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
// Сохраняем рейс
if (!empty($existing_zayavki)) {
$zayavki_ids_string = implode(',', $existing_zayavki);
$stmt_flight = $pdo->prepare("
INSERT INTO flights (zayavki_ids, zayavki_count, total_mass_tonn, unload_type, legal_entity_code, status, contractor_id, driver_id, cost, payment_terms, comment, planned_start_date, planned_start_date_from, planned_start_date_to, actual_start_date, actual_end_date, assigned_manager_id)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt_flight->execute([
$zayavki_ids_string,
count($existing_zayavki),
$total_mass,
$unload_type,
$legal_entity_code,
$status,
$contractor_id,
$driver_id,
$cost,
$payment_terms,
$comment,
$planned_start_date,
$planned_start_date_from,
$planned_start_date_to,
$actual_start_date,
$actual_end_date,
$assigned_manager_id
]);
// Генерируем новый токен
$_SESSION['form_token'] = bin2hex(random_bytes(32));
echo json_encode([
'success' => true,
'message' => 'Рейс успешно сохранен',
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
// Отдельное безопасное заполнение юрлица для старых, в том числе исполненных, рейсов
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_flight_legal_entity'])) {
header('Content-Type: application/json');
try {
if (!isset($_POST['form_token']) || $_POST['form_token'] !== $_SESSION['form_token']) {
throw new Exception('Ошибка безопасности. Пожалуйста, обновите страницу.');
}
if (!isset($_POST['flight_id']) || !is_numeric($_POST['flight_id'])) {
throw new Exception('Неверный ID рейса');
}
$flight_id = (int)$_POST['flight_id'];
$legal_entity_code = normalizeFlightLegalEntity($_POST['legal_entity_code'] ?? '');
$stmt = $pdo->prepare('UPDATE flights SET legal_entity_code = ? WHERE id = ?');
$stmt->execute([$legal_entity_code, $flight_id]);
if ($stmt->rowCount() === 0) {
$check = $pdo->prepare('SELECT id FROM flights WHERE id = ?');
$check->execute([$flight_id]);
if (!$check->fetchColumn()) {
throw new Exception('Рейс не найден');
}
}
$_SESSION['form_token'] = bin2hex(random_bytes(32));
echo json_encode([
'success' => true,
'message' => 'Юридическое лицо сохранено',
'legal_entity_code' => $legal_entity_code,
'legal_entity_label' => flightLegalEntityLabel($legal_entity_code),
'new_token' => $_SESSION['form_token']
]);
} catch (Exception $e) {
echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit;
}
// Обработка удаления рейса
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_flight'])) {
header('Content-Type: application/json');
try {
if (!isset($_POST['flight_id']) || !is_numeric($_POST['flight_id'])) {
throw new Exception('Неверный ID рейса');
}
$flight_id = (int)$_POST['flight_id'];
$stmt = $pdo->prepare("DELETE FROM flights WHERE id = ?");
$result = $stmt->execute([$flight_id]);
if ($result) {
echo json_encode(['success' => true, 'message' => 'Рейс успешно удален']);
} else {
throw new Exception('Ошибка при удалении рейса');
}
} catch (Exception $e) {
echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit;
}
// Обработка получения данных рейса для редактирования
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_flight'])) {
header('Content-Type: application/json');
try {
if (!isset($_GET['flight_id']) || !is_numeric($_GET['flight_id'])) {
throw new Exception('Неверный ID рейса');
}
$flight_id = (int)$_GET['flight_id'];
$stmt = $pdo->prepare("SELECT * FROM flights WHERE id = ?");
$stmt->execute([$flight_id]);
$flight = $stmt->fetch();
if (!$flight) {
throw new Exception('Рейс не найден');
}
$zayavki_ids = array_filter(explode(',', $flight['zayavki_ids']));
if (!empty($zayavki_ids)) {
$placeholders = implode(',', array_fill(0, count($zayavki_ids), '?'));
$stmt = $pdo->prepare("SELECT zayavka_id, mass_netto, naim_oo_gruzootpravitel, mno_adres_pogruzki, mno_sh, mno_d FROM feo WHERE zayavka_id IN ({$placeholders})");
$stmt->execute($zayavki_ids);
$flight['zayavki'] = $stmt->fetchAll();
} else {
$flight['zayavki'] = [];
}
$flight['zayavki_text'] = implode("\n", $zayavki_ids);
echo json_encode(['success' => true, 'flight' => $flight]);
} catch (Exception $e) {
echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit;
}
// Обработка обновления рейса
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_flight'])) {
header('Content-Type: application/json');
try {
if (!isset($_POST['form_token']) || $_POST['form_token'] !== $_SESSION['form_token']) {
throw new Exception('Ошибка безопасности. Пожалуйста, обновите страницу.');
}
if (!isset($_POST['flight_id']) || !is_numeric($_POST['flight_id'])) {
throw new Exception('Неверный ID рейса');
}
$flight_id = (int)$_POST['flight_id'];
$zayavki_input = trim($_POST['zayavki'] ?? '');
$unload_type = $_POST['unload_type'] ?? 'OO';
$legal_entity_code = normalizeFlightLegalEntity($_POST['legal_entity_code'] ?? '');
$status = $_POST['status'] ?? 'search';
$contractor_id = !empty($_POST['contractor_id']) ? (int)$_POST['contractor_id'] : null;
$driver_id = !empty($_POST['driver_id']) ? (int)$_POST['driver_id'] : null;
$cost = !empty($_POST['cost']) ? (int)$_POST['cost'] : null;
$payment_terms = trim($_POST['payment_terms'] ?? '');
$comment = trim($_POST['comment'] ?? '');
// Новые поля
$planned_start_date = !empty($_POST['planned_start_date']) ? $_POST['planned_start_date'] : null;
$planned_start_date_from = !empty($_POST['planned_start_date_from']) ? $_POST['planned_start_date_from'] : null;
$planned_start_date_to = !empty($_POST['planned_start_date_to']) ? $_POST['planned_start_date_to'] : null;
$actual_start_date = !empty($_POST['actual_start_date']) ? $_POST['actual_start_date'] : null;
$actual_end_date = !empty($_POST['actual_end_date']) ? $_POST['actual_end_date'] : null;
$assigned_manager_id = !empty($_POST['assigned_manager_id']) ? (int)$_POST['assigned_manager_id'] : null;
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
UPDATE flights
SET zayavki_ids = ?, zayavki_count = ?, total_mass_tonn = ?,
unload_type = ?, legal_entity_code = ?, status = ?, contractor_id = ?, driver_id = ?,
cost = ?, payment_terms = ?, comment = ?,
planned_start_date = ?, planned_start_date_from = ?, planned_start_date_to = ?,
actual_start_date = ?, actual_end_date = ?, assigned_manager_id = ?
WHERE id = ?
");
$stmt->execute([
$zayavki_ids_string,
count($existing_zayavki),
$total_mass,
$unload_type,
$legal_entity_code,
$status,
$contractor_id,
$driver_id,
$cost,
$payment_terms,
$comment,
$planned_start_date,
$planned_start_date_from,
$planned_start_date_to,
$actual_start_date,
$actual_end_date,
$assigned_manager_id,
$flight_id
]);
$_SESSION['form_token'] = bin2hex(random_bytes(32));
echo json_encode([
'success' => true,
'message' => 'Рейс успешно обновлен',
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
// Получаем рейсы
$flights = [];
$total_unique_zayavok = 0;
$total_unique_mass_kg = 0;
try {
$stmt = $pdo->query("
SELECT f.*,
c.name as contractor_name,
d.full_name as driver_name,
d.vehicle_make_plate,
CONCAT(u.Фамилия, ' ', u.Имя) as manager_name
FROM flights f
LEFT JOIN contractors c ON f.contractor_id = c.id
LEFT JOIN drivers d ON f.driver_id = d.id
LEFT JOIN users u ON f.assigned_manager_id = u.id
ORDER BY f.id DESC
LIMIT 500
");
$flights = $stmt->fetchAll();
$all_unique_zayavki_ids = [];
$processedFlights = [];
foreach ($flights as $flight) {
$zayavki_ids = array_filter(explode(',', $flight['zayavki_ids']));
$flight['zayavki_ids_array'] = $zayavki_ids;
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
$flight['zayavki'] = $stmt2->fetchAll();
$actual_mass_kg = 0;
foreach ($flight['zayavki'] as $zayavka) {
$actual_mass_kg += floatval($zayavka['mass_netto'] ?? 0) * 1000;
}
$flight['total_mass_kg'] = $actual_mass_kg;
$actual_mass_tonn = $actual_mass_kg / 1000;
if (abs($actual_mass_tonn - floatval($flight['total_mass_tonn'])) > 0.001) {
$update_stmt = $pdo->prepare("UPDATE flights SET total_mass_tonn = ? WHERE id = ?");
$update_stmt->execute([$actual_mass_tonn, $flight['id']]);
$flight['total_mass_tonn'] = $actual_mass_tonn;
}
} else {
$flight['zayavki'] = [];
$flight['total_mass_kg'] = 0;
}
$processedFlights[] = $flight;
}
$flights = $processedFlights;
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
$flights = [];
}
$current_token = $_SESSION['form_token'];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Управление рейсами</title>
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
.form-row {
display: flex;
gap: 15px;
margin-bottom: 15px;
}
.form-row .form-group {
flex: 1;
margin-bottom: 0;
}
.date-range-group {
display: flex;
gap: 10px;
align-items: center;
}
.date-range-group .form-group {
flex: 1;
margin-bottom: 0;
}
.date-range-group span {
color: #666;
font-weight: 500;
}
.legal-entity-field {
border: 1px solid #d9dee7;
border-radius: 6px;
padding: 12px 14px;
}
.legal-entity-options {
display: flex;
flex-wrap: wrap;
gap: 12px 24px;
margin-top: 8px;
}
.legal-entity-options label {
display: inline-flex;
align-items: center;
gap: 7px;
font-weight: 500;
cursor: pointer;
}
.legal-entity-missing {
color: #b42318;
font-weight: 600;
}
.flights-list-header {
display: flex;
align-items: center;
justify-content: space-between;
gap: 16px;
margin-bottom: 15px;
}
.flights-list-header h2 {
margin: 0;
}
.legacy-flights-toggle {
display: inline-flex;
align-items: center;
gap: 8px;
margin-left: auto;
font-size: 14px;
font-weight: 500;
white-space: nowrap;
cursor: pointer;
}
.legacy-flights-toggle input {
margin: 0;
}
body:not(.show-legacy-flights) tr.legacy-flight-row {
display: none !important;
}
@media (max-width: 720px) {
.flights-list-header {
align-items: flex-start;
flex-direction: column;
}
.legacy-flights-toggle {
margin-left: 0;
white-space: normal;
}
}
/* Стиль для столбца ЗАКАЗЧИК */
.zakazchiki-cell {
max-width: 150px;
font-size: 12px;
line-height: 1.3;
text-align: right !important;
}
.zakazchiki-cell strong {
color: #000000;
}
/* Стиль для столбца СЕБЕС, РУБ */
.sebes-cell {
max-width: 150px;
font-size: 12px;
line-height: 1.3;
text-align: right !important;
}
.sebes-cell strong {
color: 	#000000;
}
.text-right {
text-align: right !important;}
}
</style>
</head>
<body>
<?php include 'menuadmin.php'; ?>
<div class="app">
<div class="toolbar">
<h1>Управление рейсами</h1>
</div>
<?php if (!empty($flights)): ?>
<div class="stats">
<div class="stat-item">
<div class="stat-value"><?= count($flights) ?></div>
<div class="stat-label">Всего рейсов</div>
</div>
<div class="stat-item">
<div class="stat-value"><?= $total_unique_zayavok ?></div>
<div class="stat-label">Уникальных заявок</div>
</div>
<div class="stat-item">
<div class="stat-value"><?= number_format($total_unique_mass_kg, 0, '.', ' ') ?> кг</div>
<div class="stat-label">Общий вес уникальных</div>
</div>
</div>
<?php endif; ?>
<div class="card">
<h2>Добавить новый рейс</h2>
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
<div class="form-row">
<div class="form-group">
<label for="unload_type">Тип выгрузки:</label>
<select name="unload_type" id="unload_type" required>
<option value="OO">ОО (Организация-Отправитель)</option>
<option value="SKLAD">СКЛАД</option>
</select>
</div>
<div class="form-group">
<label for="status">Статус рейса:</label>
<select name="status" id="status" required>
<?php foreach ($statuses as $s): ?>
<option value="<?= htmlspecialchars($s['статус']) ?>">
<?= htmlspecialchars($s['наименование']) ?>
</option>
<?php endforeach; ?>
</select>
</div>
</div>
<fieldset class="form-group legal-entity-field">
<legend>Юридическое лицо рейса *</legend>
<div class="legal-entity-options">
<label><input type="radio" name="legal_entity_code" value="planex" required> ООО «Планэкс»</label>
<label><input type="radio" name="legal_entity_code" value="mg_logistic" required> ООО «МГ Логистик»</label>
</div>
</fieldset>
<div class="form-group">
<label>Планируемая дата начала:</label>
<div class="date-range-group">
<div class="form-group">
<input type="date" name="planned_start_date" id="planned_start_date" style="width: 100%;">
<small style="color: #666;">Конкретная дата</small>
</div>
<span>или</span>
<div class="form-group">
<input type="date" name="planned_start_date_from" id="planned_start_date_from" style="width: 100%;">
<small style="color: #666;">С</small>
</div>
<div class="form-group">
<input type="date" name="planned_start_date_to" id="planned_start_date_to" style="width: 100%;">
<small style="color: #666;">По</small>
</div>
</div>
</div>
<div class="form-row">
<div class="form-group">
<label for="actual_start_date">Дата начала вывоза:</label>
<input type="date" name="actual_start_date" id="actual_start_date" style="width: 100%;">
</div>
<div class="form-group">
<label for="actual_end_date">Дата окончания вывоза:</label>
<input type="date" name="actual_end_date" id="actual_end_date" style="width: 100%;">
</div>
</div>
<div class="form-row">
<div class="form-group">
<label for="contractor_id">Подрядчик:</label>
<select name="contractor_id" id="contractor_id">
<option value="">-- Выберите подрядчика --</option>
<?php foreach ($contractors as $contractor): ?>
<option value="<?= $contractor['id'] ?>"><?= htmlspecialchars($contractor['name']) ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="form-group">
<label for="driver_id">Водитель:</label>
<select name="driver_id" id="driver_id">
<option value="">-- Выберите водителя --</option>
<?php foreach ($drivers as $driver): ?>
<option value="<?= $driver['id'] ?>">
<?= htmlspecialchars($driver['full_name']) ?>
</option>
<?php endforeach; ?>
</select>
</div>
</div>
<div class="form-row">
<div class="form-group">
<label for="assigned_manager_id">Закреплённый менеджер:</label>
<select name="assigned_manager_id" id="assigned_manager_id">
<option value="">-- Выберите менеджера --</option>
<?php foreach ($managers as $manager): ?>
<option value="<?= $manager['id'] ?>">
<?= htmlspecialchars($manager['Фамилия'] . ' ' . $manager['Имя']) ?>
</option>
<?php endforeach; ?>
</select>
</div>
<div class="form-group">
<label for="cost">Себес (руб):</label>
<input
type="number"
id="cost"
name="cost"
placeholder="Введите себестоимость (необязательно)"
step="1"
min="0"
style="width: 100%;"
>
</div>
</div>
<div class="form-group">
<label for="payment_terms">Условия оплаты:</label>
<input
type="text"
id="payment_terms"
name="payment_terms"
placeholder="Например: постоплата 30 дней"
maxlength="500"
style="width: 100%;"
>
</div>
<div class="form-group">
<label for="comment">Описание:</label>
<input
type="text"
id="comment"
name="comment"
placeholder="Введите описание рейса (необязательно)"
maxlength="500"
style="width: 100%;"
>
</div>
<div class="modal-actions">
<button type="submit" class="btn btn-primary" id="submitBtn">Сохранить рейс</button>
<button type="button" class="btn" onclick="clearForm()">Очистить</button>
</div>
</form>
</div>
<?php if (!empty($flights)): ?>
<div class="card">
<div class="flights-list-header">
<h2>Список рейсов</h2>
<label class="legacy-flights-toggle">
<input type="checkbox" id="showLegacyFlights" aria-controls="flightsTable">
Показать старые рейсы (ID &lt; <?= LEGACY_FLIGHT_CUTOFF_ID ?>)
</label>
</div>
<div class="table-wrapper">
<table class="data-table" id="flightsTable">
<thead>
<tr>
<th style="width: 30px;"></th>
<th style="width: 50px;">ID</th>
<th style="width: 200px;">ОПИСАНИЕ РЕЙСА</th>
<th style="width: 80px;"></th>
<th style="text-align: center;">СТАТУС</th>
<th style="text-align: center;">ПОДРЯД. / ВОД.</th>
<th style="text-align: center;">МЕНЕДЖЕР</th>
<th style="text-align: center;">ПЛАНИРУЕМАЯ ДАТА</th>
<th style="text-align: center;">ДАТА ВЫВОЗА</th>
<th style="width: 100px;text-align: center;">ЗАЯВКИ</th>
<th style="width: 80px;text-align: center;">ВЕС, КГ</th>
<th style="width: 100px;text-align: center;">ПРОДАЖА</th>
<th style="width: 100px;text-align: center;">СЕБЕС, РУБ</th>
<th style="width: 100px;text-align: center;">ДОХОДНОСТЬ</th>
<th style="width: 100px; text-align: center;">ДЕЙСТВИЯ</th>
</tr>
</thead>
<tbody>
<?php foreach ($flights as $flight): ?>
<?php $isLegacyFlight = (int)$flight['id'] < LEGACY_FLIGHT_CUTOFF_ID; ?>
<tr class="group-header<?= $isLegacyFlight ? ' legacy-flight-row' : '' ?>" data-flight-id="<?= (int)$flight['id'] ?>" onclick="toggleFlight('details_<?= $flight['id'] ?>', event)">
<td style="text-align: center;">▶</td>
<td><strong>#<?= $flight['id'] ?></strong></td>
<td>
<?= !empty($flight['comment']) ? htmlspecialchars($flight['comment']) : '<span style="color:#999;">—</span>' ?>
<br><small class="<?= empty($flight['legal_entity_code']) ? 'legal-entity-missing' : '' ?>">
<?= htmlspecialchars(flightLegalEntityLabel($flight['legal_entity_code'] ?? '')) ?>
</small>
</td>
<td style="text-align: center;">
<?php if ($flight['unload_type'] === 'OO'): ?>
<span style="color:#999;">—</span>
<?php else: ?>
СКЛАД
<?php endif; ?>
</td>
<td style="text-align: center;">
<?php
$statusCode = $flight['status'];
$statusData = $statusMap[$statusCode] ?? null;
if ($statusData):
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
<?php if (!empty($flight['contractor_name'])): ?>
<br><small style="color:#666;"><?= htmlspecialchars($flight['contractor_name']) ?></small>
<?php endif; ?>
<?php else: ?>
<span style="color:#999;">—</span>
<?php endif; ?>
</td>
<td style="text-align: center;"><?= !empty($flight['manager_name']) ? htmlspecialchars($flight['manager_name']) : '<span style="color:#999;">—</span>' ?></td>
<td style="text-align: center;">
<?php
if (!empty($flight['planned_start_date'])) {
echo date('d.m.Y', strtotime($flight['planned_start_date']));
} elseif (!empty($flight['planned_start_date_from']) && !empty($flight['planned_start_date_to'])) {
echo date('d.m.Y', strtotime($flight['planned_start_date_from'])) . ' - ' . date('d.m.Y', strtotime($flight['planned_start_date_to']));
} elseif (!empty($flight['planned_start_date_from'])) {
echo 'с ' . date('d.m.Y', strtotime($flight['planned_start_date_from']));
} elseif (!empty($flight['planned_start_date_to'])) {
echo 'по ' . date('d.m.Y', strtotime($flight['planned_start_date_to']));
} else {
echo '<span style="color:#999;">—</span>';
}
?>
</td>
<td style="text-align: center;">
<?php
$dates = [];
if (!empty($flight['actual_start_date'])) {
$dates[] = 'с ' . date('d.m.Y', strtotime($flight['actual_start_date']));
}
if (!empty($flight['actual_end_date'])) {
$dates[] = 'по ' . date('d.m.Y', strtotime($flight['actual_end_date']));
}
echo !empty($dates) ? implode(' ', $dates) : '<span style="color:#999;">—</span>';
?>
</td>
<!-- Столбец ЗАКАЗЧИК - сумма стоимости всех заявок -->
<td style="text-align: center;"><?= $flight['zayavki_count'] ?></td>
<td style="text-align: right;"><?= number_format($flight['total_mass_kg'] ?? 0, 0, '.', ' ') ?> кг</td>
<td style="text-align: right;" class="zakazchiki-cell">
<strong><?php
// Если выгрузка на СКЛАД, продажа = 0
if ($flight['unload_type'] === 'SKLAD') {
echo '0 ₽';
} else {
$ids = explode(',', $flight['zayavki_ids']);
$total = 0;
$all_have_cost = true;
foreach ($ids as $id) {
$id = trim($id);
if (!empty($id)) {
if (!isset($price[$id]) || $price[$id] === null) {
$all_have_cost = false;
break;
}
$total += $price[$id];
}
}
if ($all_have_cost && $total > 0) {
echo number_format($total, 0, '.', ' ') . ' ₽';
} else {
echo '<span style="color:#999;">—</span>';
}
}
?></strong>
<td style="text-align: right;" class="sebes-cell">
<strong>
<?php if (!empty($flight['cost'])): ?>
<?= number_format($flight['cost'], 0, '.', ' ') ?> ₽
<?php else: ?>
<span style="color:#999;">—</span>
<?php endif; ?>
</strong>
</td>
<td style="text-align: right;">
<strong><?php
// Если выгрузка на СКЛАД
if ($flight['unload_type'] === 'SKLAD') {
$sebes = !empty($flight['cost']) ? $flight['cost'] : null;
if ($sebes !== null && $sebes > 0) {
$difference = 0 - $sebes; // Продажа = 0, значит доходность = -себестоимость
$color = '#dc3545'; // Красный цвет для убытка
echo '<span style="color: ' . $color . '; font-weight: bold;">' . number_format($difference, 0, '.', ' ') . ' ₽</span>';
} else {
echo '<span style="color:#999;">—</span>';
}
} else {
// Обычная логика для OO
$ids = explode(',', $flight['zayavki_ids']);
$total_zayavki = 0;
$all_have_cost = true;
foreach ($ids as $id) {
$id = trim($id);
if (!empty($id)) {
if (!isset($price[$id]) || $price[$id] === null) {
$all_have_cost = false;
break;
}
$total_zayavki += $price[$id];
}
}
$sebes = !empty($flight['cost']) ? $flight['cost'] : null;
if ($all_have_cost && $total_zayavki > 0 && $sebes !== null && $sebes > 0) {
$difference = $total_zayavki - $sebes;
$color = $difference >= 0 ? '#28a745' : '#FFA459';
echo '<span style="color: ' . $color . '; font-weight: bold;">' . number_format($difference, 0, '.', ' ') . ' ₽</span>';
} else {
echo '<span style="color:#999;">—</span>';
}
}
?></strong>
</td>
<td style="text-align: center; white-space: nowrap;">
<button class="btn btn-sm" onclick="editFlight(<?= $flight['id'] ?>, event)">✎</button>
<button class="btn btn-sm btn-delete" onclick="deleteFlight(<?= $flight['id'] ?>, event)">✖</button>
</td>
</tr>
<tr id="details_<?= $flight['id'] ?>" class="flight-details<?= $isLegacyFlight ? ' legacy-flight-row' : '' ?>" data-flight-id="<?= (int)$flight['id'] ?>" style="display: none;">
<td colspan="15" style="padding: 0;">
<div class="block-details">
<table class="details-table">
<thead>
<tr>
<th style="width: 80px;text-align: center;">ID ЗАЯВКИ</th>
<th style="width: 90px;">МАССА, КГ</th>
<th style="width: 250px;">ГРУЗООТПРАВИТЕЛЬ</th>
<th>АДРЕС ПОГРУЗКИ</th>
<th style="width: 100px;text-align: center;">МАРШРУТ</th>
<th style="width: 100px;text-align: center;">₽/кг</th>
<th style="width: 100px;">СТОИМОСТЬ</th>
</tr>
</thead>
<tbody>
<?php if (!empty($flight['zayavki'])): ?>
<?php foreach ($flight['zayavki'] as $zayavka):
$mass_kg = floatval($zayavka['mass_netto'] ?? 0) * 1000;
?>
<tr>
<td><strong><?= htmlspecialchars($zayavka['zayavka_id']) ?></strong></td>
<td class="text-right"><?= number_format($mass_kg, 0, '.', ' ') ?></td>
<td style="text-align: left;" title="<?= htmlspecialchars($zayavka['naim_oo_gruzootpravitel'] ?? '') ?>"><?= trunc50($zayavka['naim_oo_gruzootpravitel'] ?? '', 50) ?></td>
<td style="text-align: left;" title="<?= htmlspecialchars($zayavka['mno_adres_pogruzki'] ?? '') ?>"><?= trunc50($zayavka['mno_adres_pogruzki'] ?? '', 150) ?></td>
<td class="text-center">
<strong><?= isset($price_with_details[$zayavka['zayavka_id']]['route_id']) && !empty($price_with_details[$zayavka['zayavka_id']]['route_id']) ? '#' . htmlspecialchars($price_with_details[$zayavka['zayavka_id']]['route_id']) : '<span style="color:#999;">—</span>' ?></strong>
</td>
<td class="text-center">
<strong><?= isset($price_per_kg[$zayavka['zayavka_id']]) && $price_per_kg[$zayavka['zayavka_id']] !== null ? number_format($price_per_kg[$zayavka['zayavka_id']], 2, '.', ' ') . '' : '<span style="color:#999;">—</span>' ?></strong>
</td>
<td class="text-right">
<strong><?= isset($price[$zayavka['zayavka_id']]) && $price[$zayavka['zayavka_id']] !== null ? number_format($price[$zayavka['zayavka_id']], 0, '.', ' ') . ' ₽' : '<span style="color:#999;">—</span>' ?></strong>
</td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr>
<td colspan="5" class="text-center text-muted">Данные заявок не найдены</td>
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
<div class="modal-content modal-content-wide">
<div class="modal-header">
<h3>✏️ Редактирование рейса</h3>
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
const legacyFlightsToggle = document.getElementById('showLegacyFlights');
if (legacyFlightsToggle) {
legacyFlightsToggle.addEventListener('change', function() {
document.body.classList.toggle('show-legacy-flights', this.checked);
});
}
function clearForm() {
document.getElementById('zayavki').value = '';
document.getElementById('unload_type').value = 'OO';
document.getElementById('status').value = 'search';
document.querySelectorAll('#addForm input[name="legal_entity_code"]').forEach(input => { input.checked = false; });
document.getElementById('contractor_id').value = '';
document.getElementById('driver_id').value = '';
document.getElementById('assigned_manager_id').value = '';
document.getElementById('cost').value = '';
document.getElementById('payment_terms').value = '';
document.getElementById('comment').value = '';
document.getElementById('planned_start_date').value = '';
document.getElementById('planned_start_date_from').value = '';
document.getElementById('planned_start_date_to').value = '';
document.getElementById('actual_start_date').value = '';
document.getElementById('actual_end_date').value = '';
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
formData.append('save_flight', '1');
formData.append('form_token', currentFormToken);
formData.append('zayavki', document.getElementById('zayavki').value);
formData.append('unload_type', document.getElementById('unload_type').value);
formData.append('status', document.getElementById('status').value);
const legalEntity = document.querySelector('#addForm input[name="legal_entity_code"]:checked');
formData.append('legal_entity_code', legalEntity ? legalEntity.value : '');
formData.append('contractor_id', document.getElementById('contractor_id').value);
formData.append('driver_id', document.getElementById('driver_id').value);
formData.append('assigned_manager_id', document.getElementById('assigned_manager_id').value);
formData.append('cost', document.getElementById('cost').value);
formData.append('payment_terms', document.getElementById('payment_terms').value);
formData.append('comment', document.getElementById('comment').value);
formData.append('planned_start_date', document.getElementById('planned_start_date').value);
formData.append('planned_start_date_from', document.getElementById('planned_start_date_from').value);
formData.append('planned_start_date_to', document.getElementById('planned_start_date_to').value);
formData.append('actual_start_date', document.getElementById('actual_start_date').value);
formData.append('actual_end_date', document.getElementById('actual_end_date').value);
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
function toggleFlight(flightId, event) {
if (event) event.stopPropagation();
const flightRow = document.getElementById(flightId);
const headerRow = flightRow.previousElementSibling;
const arrow = headerRow.querySelector('td:first-child');
if (flightRow.style.display === 'none') {
flightRow.style.display = 'table-row';
arrow.textContent = '▼';
} else {
flightRow.style.display = 'none';
arrow.textContent = '▶';
}
}
function deleteFlight(flightId, event) {
if (event) event.stopPropagation();
if (!confirm('Вы уверены, что хотите удалить этот рейс?')) return;
const deleteBtn = event.target;
deleteBtn.classList.add('btn-loading');
deleteBtn.disabled = true;
const formData = new FormData();
formData.append('delete_flight', '1');
formData.append('flight_id', flightId);
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
function editFlight(flightId, event) {
if (event) event.stopPropagation();
const editBtn = event.target;
editBtn.classList.add('btn-loading');
editBtn.disabled = true;
fetch('?get_flight=1&flight_id=' + flightId)
.then(response => response.json())
.then(data => {
editBtn.classList.remove('btn-loading');
editBtn.disabled = false;
if (data.success) {
showEditModal(data.flight);
} else {
alert('Ошибка: ' + data.message);
}
})
.catch(error => {
alert('Ошибка при загрузке данных');
editBtn.classList.remove('btn-loading');
editBtn.disabled = false;
});
}
function showEditModal(flight) {
const modal = document.getElementById('editModal');
const content = document.getElementById('editModalContent');
let html = `
<form id="editForm" onsubmit="return submitEdit(event, ${flight.id})">
<input type="hidden" name="form_token" value="${currentFormToken}">
<input type="hidden" name="update_flight" value="1">
<input type="hidden" name="flight_id" value="${flight.id}">
<div class="form-group">
<label for="edit_zayavki">Номера заявок:</label>
<textarea
id="edit_zayavki"
name="zayavki"
rows="3"
style="resize: vertical;"
required
oninput="validateEditZayavkiOnTheFly()"
>${escapeHtml(flight.zayavki_text || '')}</textarea>
<div id="editFilterInfo" class="filter-stats" style="margin-top: 5px;">
<span id="editFilterStats"></span>
</div>
<div class="help-text">Разделители: запятая, пробел, перенос строки. Дубликаты удаляются.</div>
</div>
<div class="form-row">
<div class="form-group">
<label for="edit_unload_type">Тип выгрузки:</label>
<select name="unload_type" id="edit_unload_type" required>
<option value="OO" ${flight.unload_type === 'OO' ? 'selected' : ''}>ОО (Организация-Отправитель)</option>
<option value="SKLAD" ${flight.unload_type === 'SKLAD' ? 'selected' : ''}>СКЛАД</option>
</select>
</div>
<div class="form-group">
<label for="edit_status">Статус рейса:</label>
<select name="status" id="edit_status" required>
<?php foreach ($statuses as $s): ?>
<option value="<?= htmlspecialchars($s['статус']) ?>"
${flight.status === '<?= $s['статус'] ?>' ? 'selected' : ''}>
<?= htmlspecialchars($s['наименование']) ?>
</option>
<?php endforeach; ?>
</select>
</div>
</div>
<fieldset class="form-group legal-entity-field">
<legend>Юридическое лицо рейса *</legend>
<div class="legal-entity-options">
<label><input type="radio" name="legal_entity_code" value="planex" required ${flight.legal_entity_code === 'planex' ? 'checked' : ''}> ООО «Планэкс»</label>
<label><input type="radio" name="legal_entity_code" value="mg_logistic" required ${flight.legal_entity_code === 'mg_logistic' ? 'checked' : ''}> ООО «МГ Логистик»</label>
</div>
${flight.legal_entity_code ? '' : '<div class="legal-entity-missing" style="margin-top:8px;">Для старого рейса юридическое лицо ещё не указано. Выберите его перед сохранением.</div>'}
</fieldset>
<div class="form-group">
<label>Планируемая дата начала:</label>
<div class="date-range-group">
<div class="form-group">
<input type="date" name="planned_start_date" id="edit_planned_start_date" value="${flight.planned_start_date || ''}" style="width: 100%;">
<small style="color: #666;">Конкретная дата</small>
</div>
<span>или</span>
<div class="form-group">
<input type="date" name="planned_start_date_from" id="edit_planned_start_date_from" value="${flight.planned_start_date_from || ''}" style="width: 100%;">
<small style="color: #666;">С</small>
</div>
<div class="form-group">
<input type="date" name="planned_start_date_to" id="edit_planned_start_date_to" value="${flight.planned_start_date_to || ''}" style="width: 100%;">
<small style="color: #666;">По</small>
</div>
</div>
</div>
<div class="form-row">
<div class="form-group">
<label for="edit_actual_start_date">Дата начала вывоза:</label>
<input type="date" name="actual_start_date" id="edit_actual_start_date" value="${flight.actual_start_date || ''}" style="width: 100%;">
</div>
<div class="form-group">
<label for="edit_actual_end_date">Дата окончания вывоза:</label>
<input type="date" name="actual_end_date" id="edit_actual_end_date" value="${flight.actual_end_date || ''}" style="width: 100%;">
</div>
</div>
<div class="form-row">
<div class="form-group">
<label for="edit_contractor_id">Подрядчик:</label>
<select name="contractor_id" id="edit_contractor_id">
<option value="">-- Выберите подрядчика --</option>
<?php foreach ($contractors as $contractor): ?>
<option value="<?= $contractor['id'] ?>" ${flight.contractor_id == <?= $contractor['id'] ?> ? 'selected' : ''}>
<?= htmlspecialchars($contractor['name']) ?>
</option>
<?php endforeach; ?>
</select>
</div>
<div class="form-group">
<label for="edit_driver_id">Водитель:</label>
<select name="driver_id" id="edit_driver_id">
<option value="">-- Выберите водителя --</option>
<?php foreach ($drivers as $driver): ?>
<option value="<?= $driver['id'] ?>" ${flight.driver_id == <?= $driver['id'] ?> ? 'selected' : ''}>
<?= htmlspecialchars($driver['full_name']) ?>
</option>
<?php endforeach; ?>
</select>
</div>
</div>
<div class="form-row">
<div class="form-group">
<label for="edit_assigned_manager_id">Закреплённый менеджер:</label>
<select name="assigned_manager_id" id="edit_assigned_manager_id">
<option value="">-- Выберите менеджера --</option>
<?php foreach ($managers as $manager): ?>
<option value="<?= $manager['id'] ?>" ${flight.assigned_manager_id == <?= $manager['id'] ?> ? 'selected' : ''}>
<?= htmlspecialchars($manager['Фамилия'] . ' ' . $manager['Имя']) ?>
</option>
<?php endforeach; ?>
</select>
</div>
<div class="form-group">
<label for="edit_cost">Себес (руб):</label>
<input
type="number"
id="edit_cost"
name="cost"
value="${flight.cost || ''}"
step="1"
min="0"
placeholder="Введите себестоимость (необязательно)"
style="width: 100%;"
>
</div>
</div>
<div class="form-group">
<label for="edit_payment_terms">Условия оплаты:</label>
<input
type="text"
id="edit_payment_terms"
name="payment_terms"
value="${escapeHtml(flight.payment_terms || '')}"
maxlength="500"
placeholder="Например: постоплата 30 дней"
style="width: 100%;"
>
</div>
<div class="form-group">
<label for="edit_comment">Комментарий:</label>
<input
type="text"
id="edit_comment"
name="comment"
value="${escapeHtml(flight.comment || '')}"
maxlength="500"
placeholder="Введите комментарий (необязательно)"
style="width: 100%;"
>
</div>
<div class="modal-actions">
<button type="submit" class="btn btn-primary" id="editSubmitBtn">Сохранить изменения</button>
<button type="button" class="btn" id="legalEntityOnlyBtn" onclick="saveFlightLegalEntity(${flight.id})">Сохранить только юрлицо</button>
<button type="button" class="btn btn-secondary" onclick="closeEditModal()">Отмена</button>
</div>
</form>
`;
content.innerHTML = html;
modal.classList.add('show');
setTimeout(validateEditZayavkiOnTheFly, 100);
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
function submitEdit(event, flightId) {
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
let message = 'Рейс обновлен, но есть проблемы:';
if (data.not_found && data.not_found.length > 0) {
message += '\nНе найдены в БД: ' + data.not_found.join(', ');
}
if (data.invalid_format && data.invalid_format.length > 0) {
message += '\nНекорректный формат: ' + data.invalid_format.join(', ');
}
alert(message);
} else {
alert('Рейс успешно обновлен');
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
function saveFlightLegalEntity(flightId) {
const selected = document.querySelector('#editForm input[name="legal_entity_code"]:checked');
if (!selected) {
alert('Выберите юридическое лицо рейса');
return;
}
const button = document.getElementById('legalEntityOnlyBtn');
button.classList.add('btn-loading');
button.disabled = true;
const formData = new FormData();
formData.append('set_flight_legal_entity', '1');
formData.append('form_token', currentFormToken);
formData.append('flight_id', String(flightId));
formData.append('legal_entity_code', selected.value);
fetch('', { method: 'POST', body: formData })
.then(response => response.json())
.then(data => {
if (!data.success) throw new Error(data.message || 'Не удалось сохранить юридическое лицо');
if (data.new_token) currentFormToken = data.new_token;
alert(data.message);
location.reload();
})
.catch(error => {
alert('Ошибка: ' + error.message);
button.classList.remove('btn-loading');
button.disabled = false;
});
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
// Очистка поля конкретной даты при заполнении диапазона и наоборот
const plannedStartDate = document.getElementById('planned_start_date');
const plannedStartDateFrom = document.getElementById('planned_start_date_from');
const plannedStartDateTo = document.getElementById('planned_start_date_to');
if (plannedStartDate) {
plannedStartDate.addEventListener('change', function() {
if (this.value) {
plannedStartDateFrom.value = '';
plannedStartDateTo.value = '';
}
});
}
if (plannedStartDateFrom && plannedStartDateTo) {
plannedStartDateFrom.addEventListener('change', function() {
if (this.value || plannedStartDateTo.value) {
if (plannedStartDate) plannedStartDate.value = '';
}
});
plannedStartDateTo.addEventListener('change', function() {
if (this.value || plannedStartDateFrom.value) {
if (plannedStartDate) plannedStartDate.value = '';
}
});
}
};
</script>
</body>
</html>
