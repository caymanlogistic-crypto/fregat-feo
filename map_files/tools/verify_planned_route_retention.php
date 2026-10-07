<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/planned_route_retention.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Database connection is not initialized.\n");
    exit(1);
}
if (!plannedRouteRetentionTableExists($pdo)) {
    fwrite(STDERR, "planned_route_retention table is missing.\n");
    exit(1);
}

$columnsStmt = $pdo->query(<<<'SQL'
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'planned_route_retention'
ORDER BY ORDINAL_POSITION
SQL);
$columns = $columnsStmt ? $columnsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
echo 'SELECT retention_columns: ' . json_encode($columns, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

$now = plannedRouteRetentionDateTime()->format('Y-m-d H:i:s');
$statsStmt = $pdo->prepare(<<<'SQL'
SELECT
    (SELECT COUNT(*) FROM flights WHERE status = 'planned_route') AS planned_flights,
    (SELECT COUNT(*) FROM planned_route_retention prr INNER JOIN flights f ON f.id = prr.flight_id WHERE f.status = 'planned_route') AS retention_rows,
    (SELECT COUNT(*) FROM flights f LEFT JOIN planned_route_retention prr ON prr.flight_id = f.id WHERE f.status = 'planned_route' AND prr.flight_id IS NULL) AS missing_rows,
    (SELECT COUNT(*) FROM planned_route_retention prr INNER JOIN flights f ON f.id = prr.flight_id WHERE f.status = 'planned_route' AND prr.expires_at <= :now) AS expired_rows
SQL);
$statsStmt && $statsStmt->execute([':now' => $now]);
$stats = $statsStmt ? $statsStmt->fetch(PDO::FETCH_ASSOC) : false;
if (!$stats) {
    fwrite(STDERR, "Retention stats SELECT failed.\n");
    exit(1);
}
echo 'SELECT retention_stats: ' . json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
if ((int)$stats['planned_flights'] !== (int)$stats['retention_rows'] || (int)$stats['missing_rows'] !== 0 || (int)$stats['expired_rows'] !== 0) {
    fwrite(STDERR, "Retention coverage verification failed.\n");
    exit(1);
}

$sampleStmt = $pdo->query("SELECT prr.flight_id, prr.created_at, prr.expires_at, prr.warning_sent_at, prr.extended_count FROM planned_route_retention prr INNER JOIN flights f ON f.id = prr.flight_id WHERE f.status = 'planned_route' ORDER BY prr.expires_at, prr.flight_id LIMIT 20");
$sample = $sampleStmt ? $sampleStmt->fetchAll(PDO::FETCH_ASSOC) : [];
echo 'SELECT retention_sample: ' . json_encode($sample, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

foreach (['max_event_templates', 'max_message_templates'] as $table) {
    $exists = $pdo->prepare('SHOW TABLES LIKE ?');
    if (!$exists || !$exists->execute([$table]) || !$exists->fetchColumn()) {
        continue;
    }
    $stmt = $pdo->prepare("SELECT event_key, template_text FROM `{$table}` WHERE event_key = :event_key LIMIT 1");
    $stmt->execute([':event_key' => 'route_auto_delete_warning']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "SELECT {$table}: " . json_encode($row ?: null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    if (!is_array($row) || strpos((string)($row['template_text'] ?? ''), '{requests}') === false || strpos((string)($row['template_text'] ?? ''), '{delete_at}') === false) {
        fwrite(STDERR, "MAX template verification failed for {$table}.\n");
        exit(1);
    }
}

echo "Verification PASS.\n";
