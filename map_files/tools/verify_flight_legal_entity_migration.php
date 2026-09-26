<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

require_once __DIR__ . '/../bootstrap.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Database connection is not initialized.\n");
    exit(1);
}

function verificationTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    return $stmt && $stmt->execute([$table]) && (bool)$stmt->fetchColumn();
}

$columnStmt = $pdo->query(<<<'SQL'
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'flights'
  AND COLUMN_NAME = 'legal_entity_code'
SQL);
$column = $columnStmt ? $columnStmt->fetch(PDO::FETCH_ASSOC) : false;
if (!$column) {
    fwrite(STDERR, "SELECT flights_column: missing legal_entity_code.\n");
    exit(1);
}
echo 'SELECT flights_column: ' . json_encode($column, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

$statsStmt = $pdo->query(<<<'SQL'
SELECT
    COUNT(*) AS total_flights,
    COALESCE(SUM(legal_entity_code IS NULL OR legal_entity_code = ''), 0) AS without_legal_entity,
    COALESCE(SUM(legal_entity_code NOT IN ('planex', 'mg_logistic')), 0) AS invalid_legal_entity
FROM flights
SQL);
$stats = $statsStmt ? $statsStmt->fetch(PDO::FETCH_ASSOC) : false;
if (!$stats) {
    fwrite(STDERR, "SELECT flight_stats failed.\n");
    exit(1);
}
echo 'SELECT flight_stats: ' . json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
if ((int)$stats['invalid_legal_entity'] !== 0) {
    fwrite(STDERR, "Invalid legal entity values found.\n");
    exit(1);
}

$lifecycleEvents = [
    'route_planned_to_found',
    'planned_to_found',
    'route_found_to_started',
    'found_to_started',
    'route_started_to_found_rollback',
    'started_to_found_rollback',
    'route_found_to_planned_rollback',
    'found_to_planned_rollback',
    'route_deleted',
    'route_completed',
];
$diffEvents = ['route_found_updated', 'route_diff_found', 'route_started_updated', 'route_diff_started'];
$allEvents = array_merge($lifecycleEvents, $diffEvents);

if (verificationTableExists($pdo, 'max_event_templates')) {
    $placeholders = implode(',', array_fill(0, count($allEvents), '?'));
    $stmt = $pdo->prepare(<<<SQL
SELECT
    event_key,
    INSTR(COALESCE(placeholders, ''), '{legal_entity}') > 0 AS has_legal_entity,
    INSTR(COALESCE(placeholders, ''), '{legal_entity_code}') > 0 AS has_legal_entity_code,
    INSTR(COALESCE(placeholders, ''), '{legal_entity_line}') > 0 AS has_legal_entity_line
FROM max_event_templates
WHERE event_key IN ({$placeholders})
ORDER BY event_key
SQL);
    $stmt->execute($allEvents);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo 'SELECT max_event_templates: ' . json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    foreach ($rows as $row) {
        if (!(int)$row['has_legal_entity'] || !(int)$row['has_legal_entity_code'] || !(int)$row['has_legal_entity_line']) {
            fwrite(STDERR, "MAX event template verification failed.\n");
            exit(1);
        }
    }
}

if (verificationTableExists($pdo, 'max_message_templates')) {
    $placeholders = implode(',', array_fill(0, count($lifecycleEvents), '?'));
    $stmt = $pdo->prepare(<<<SQL
SELECT
    event_key,
    INSTR(COALESCE(template_text, ''), '{legal_entity_line}') > 0 AS has_legal_entity_line
FROM max_message_templates
WHERE event_key IN ({$placeholders})
ORDER BY event_key
SQL);
    $stmt->execute($lifecycleEvents);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo 'SELECT max_message_templates: ' . json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    foreach ($rows as $row) {
        if (!(int)$row['has_legal_entity_line']) {
            fwrite(STDERR, "Legacy MAX template verification failed.\n");
            exit(1);
        }
    }
}

echo "Verification PASS.\n";
