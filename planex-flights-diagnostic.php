<?php
/**
 * Place next to flights.php and config.php, then open in a browser.
 * Uses the same $dbConfig as flights.php. Does not include flights.php.
 * All diagnostic queries are SELECT, in a read-only transaction.
 * PHP 7.4+ with PDO MySQL.
 */
ini_set('display_errors', '0');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

$diagnosticAccessKey = trim((string) (getenv('DIAGNOSTIC_ACCESS_KEY') ?: ''));

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
    || !is_string($_POST['access_key'] ?? null)
    || $diagnosticAccessKey === '' || !hash_equals($diagnosticAccessKey, $_POST['access_key'])) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ru"><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Диагностика рейсов PLANEX</title>'
        . '<h1>Диагностика рейсов старой БД</h1>'
        . '<p>Рейсы с ID больше 225. Результат будет скачан в JSON-файл.</p>'
        . '<form method="post"><label>Ключ доступа '
        . '<input type="password" name="access_key" required autocomplete="off"></label> '
        . '<button type="submit">Скачать отчёт</button></form></html>';
    exit;
}

function diagnosticQuery(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return ['ok' => true, 'row_count' => count($rows), 'rows' => $rows];
    } catch (PDOException $error) {
        // No DSN, credentials, stack traces or raw database error messages.
        return [
            'ok' => false,
            'sqlstate' => (string) $error->getCode(),
            'mysql_error_code' => isset($error->errorInfo[1]) ? (int) $error->errorInfo[1] : null,
        ];
    }
}

$report = [
    'diagnostic_version' => 1,
    'generated_at_utc' => gmdate('c'),
    'scope' => 'flights.id > 225',
    'read_only_transaction_started' => false,
    'sections' => [],
];
$pdo = null;
$stage = 'load_config';
$initialBufferLevel = ob_get_level();
try {
    if (!is_file(__DIR__ . '/config.php')) {
        throw new RuntimeException('config_missing');
    }
    // Discard any incidental output from the existing configuration file.
    ob_start();
    require __DIR__ . '/config.php';
    while (ob_get_level() > $initialBufferLevel) ob_end_clean();

    if (!isset($dbConfig) || !is_array($dbConfig)) {
        throw new RuntimeException('db_config_missing');
    }
    foreach (['host', 'dbname', 'username', 'password'] as $requiredKey) {
        if (!array_key_exists($requiredKey, $dbConfig)) {
            throw new RuntimeException('db_config_incomplete');
        }
    }

    $stage = 'connect';
    $dsn = 'mysql:host=' . $dbConfig['host'] . ';dbname=' . $dbConfig['dbname'] . ';charset=utf8mb4';
    if (!empty($dbConfig['port'])) $dsn .= ';port=' . (int) $dbConfig['port'];
    $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $stage = 'read_only_transaction';
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    $report['read_only_transaction_started'] = true;
    $stage = 'diagnostic_queries';

    $report['sections']['database'] = diagnosticQuery($pdo, 'SELECT DATABASE() AS selected_database, VERSION() AS mysql_version');
    $report['sections']['columns'] = diagnosticQuery($pdo,
        "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ('flights','drivers','status')
         ORDER BY TABLE_NAME, ORDINAL_POSITION", [(string) $dbConfig['dbname']]);

    $report['sections']['status_counts'] = diagnosticQuery($pdo,
        'SELECT status AS legacy_status, COUNT(*) AS flight_count
         FROM flights WHERE id > 225 GROUP BY status ORDER BY status');
    $report['sections']['status_dictionary'] = diagnosticQuery($pdo,
        'SELECT `статус` AS legacy_status, `наименование` AS status_name FROM `status` ORDER BY `статус`');

    $report['sections']['cost_and_driver_summary'] = diagnosticQuery($pdo,
        'SELECT COUNT(*) AS flight_count, MIN(f.id) AS min_id, MAX(f.id) AS max_id,
         COALESCE(SUM(f.cost IS NULL),0) AS cost_missing,
         COALESCE(SUM(f.cost = 0),0) AS cost_zero,
         COALESCE(SUM(f.cost < 0),0) AS cost_negative,
         MIN(f.cost) AS min_cost, MAX(f.cost) AS max_cost,
         COALESCE(SUM(f.driver_id IS NULL OR f.driver_id = 0),0) AS driver_not_set,
         COALESCE(SUM(f.driver_id IS NOT NULL AND f.driver_id <> 0 AND d.id IS NULL),0) AS driver_link_broken
         FROM flights f LEFT JOIN drivers d ON d.id = f.driver_id WHERE f.id > 225');

    $report['sections']['drivers'] = diagnosticQuery($pdo,
        'SELECT d.id AS legacy_driver_id, d.full_name, d.vehicle_make_plate, COUNT(*) AS flight_count
         FROM flights f JOIN drivers d ON d.id = f.driver_id WHERE f.id > 225
         GROUP BY d.id, d.full_name, d.vehicle_make_plate ORDER BY d.full_name, d.id');

    $report['sections']['payment_terms'] = diagnosticQuery($pdo,
        'SELECT payment_terms, COUNT(*) AS flight_count FROM flights WHERE id > 225
         GROUP BY payment_terms ORDER BY flight_count DESC, payment_terms');

    $report['sections']['flights'] = diagnosticQuery($pdo,
        'SELECT f.id AS legacy_flight_id, f.status, f.cost AS carrier_cost_rub,
         f.payment_terms, f.driver_id AS legacy_driver_id, d.full_name AS legacy_driver_name,
         d.vehicle_make_plate, f.actual_start_date, f.actual_end_date
         FROM flights f LEFT JOIN drivers d ON d.id = f.driver_id WHERE f.id > 225 ORDER BY f.id');
    $report['ok'] = true;
    foreach ($report['sections'] as $section) {
        if (!$section['ok']) $report['ok'] = false;
    }
} catch (Throwable $error) {
    while (ob_get_level() > $initialBufferLevel) ob_end_clean();
    $report['ok'] = false;
    $report['error_stage'] = $stage;
    $report['error_type'] = get_class($error);
    $report['error_code'] = (string) $error->getCode();
    $report['error_hint'] = $stage === 'load_config'
        ? 'Поместите скрипт рядом с config.php, который содержит массив dbConfig как в flights.php.'
        : 'Передайте этот отчёт для проверки. Настройки подключения и пароли в отчёт не включены.';
} finally {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        try {
            $pdo->rollBack();
            $report['transaction_closed'] = true;
        } catch (Throwable $error) {
            $report['transaction_closed'] = false;
            $report['ok'] = false;
        }
    }
}

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="planex-flights-diagnostic.json"');
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
