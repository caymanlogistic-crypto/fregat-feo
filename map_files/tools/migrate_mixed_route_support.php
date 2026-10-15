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

// Из старых завершённых рейсов в новый формат переводится только #269.
// #351 и #353 — текущие рейсы, ради которых добавлена смешанная схема.
$targets = [
    269 => [306474, 309026, 309808, 310330, 310865, 310875, 310884, 312762, 312821, 313029, 315058],
    351 => [291382],
    353 => [312015],
];

$columnStmt = $pdo->query("SHOW COLUMNS FROM flights LIKE 'direct_zayavki_ids'");
$columnExists = $columnStmt && $columnStmt->fetch(PDO::FETCH_ASSOC);

$flightIds = array_keys($targets);
$placeholders = implode(',', array_fill(0, count($flightIds), '?'));
$backupStmt = $pdo->prepare("SELECT * FROM flights WHERE id IN ({$placeholders}) ORDER BY id");
$backupStmt->execute($flightIds);
$beforeRows = $backupStmt->fetchAll(PDO::FETCH_ASSOC);

if (count($beforeRows) !== count($targets)) {
    $found = array_map(static fn(array $row): int => (int)$row['id'], $beforeRows);
    $missing = array_values(array_diff($flightIds, $found));
    fwrite(STDERR, 'Migration stopped: flights not found: ' . implode(', ', $missing) . PHP_EOL);
    exit(1);
}

foreach ($beforeRows as $row) {
    $flightId = (int)$row['id'];
    $allIds = array_values(array_filter(array_map('intval', explode(',', (string)($row['zayavki_ids'] ?? '')))));
    $outside = array_values(array_diff($targets[$flightId], $allIds));
    if ($outside) {
        fwrite(STDERR, "Migration stopped: flight #{$flightId} does not contain requests: " . implode(', ', $outside) . PHP_EOL);
        exit(1);
    }
    if ((int)($row['source_warehouse_id'] ?? 0) <= 0) {
        fwrite(STDERR, "Migration stopped: flight #{$flightId} has no source warehouse.\n");
        exit(1);
    }
}

$backupDir = __DIR__ . '/backups';
if (!is_dir($backupDir) && !mkdir($backupDir, 0770, true) && !is_dir($backupDir)) {
    fwrite(STDERR, "Migration stopped: cannot create backup directory.\n");
    exit(1);
}
$backupPath = $backupDir . '/mixed_route_' . date('Ymd_His') . '.json';
$backupPayload = [
    'created_at' => date(DATE_ATOM),
    'flights' => $beforeRows,
];
if (file_put_contents($backupPath, json_encode($backupPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
    fwrite(STDERR, "Migration stopped: cannot write backup.\n");
    exit(1);
}
echo "Backup: {$backupPath}\n";

if (!$columnExists) {
    $pdo->exec("ALTER TABLE flights ADD COLUMN direct_zayavki_ids TEXT NULL DEFAULT NULL AFTER zayavki_ids");
    echo "Added flights.direct_zayavki_ids.\n";
}

$pdo->beginTransaction();
try {
    $update = $pdo->prepare(
        "UPDATE flights
         SET route_type = 'mixed_to_utilizer',
             unload_type = 'OO',
             direct_zayavki_ids = :direct_ids
         WHERE id = :id
         LIMIT 1"
    );
    foreach ($targets as $flightId => $directIds) {
        $update->execute([
            ':direct_ids' => implode(',', $directIds),
            ':id' => $flightId,
        ]);
        echo "Updated flight #{$flightId}: direct requests " . implode(',', $directIds) . ".\n";
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$verify = $pdo->prepare(
    "SELECT id, status, route_type, unload_type, source_warehouse_id, zayavki_ids, direct_zayavki_ids
     FROM flights
     WHERE id IN ({$placeholders})
     ORDER BY id"
);
$verify->execute($flightIds);
echo 'SELECT verification: ' . json_encode($verify->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
