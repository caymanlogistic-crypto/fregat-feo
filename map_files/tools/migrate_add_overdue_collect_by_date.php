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

$table = $pdo->query("SHOW TABLES LIKE 'overdue_blocks'");
if (!$table || !$table->fetchColumn()) {
    fwrite(STDERR, "overdue_blocks does not exist. Run migrate_create_overdue_blocks.php first.\n");
    exit(1);
}

$column = $pdo->query("SHOW COLUMNS FROM overdue_blocks LIKE 'collect_by_date'");
if (!$column || !$column->fetchColumn()) {
    echo "Adding nullable collect_by_date column. Existing rows will not be changed.\n";
    $pdo->exec(
        "ALTER TABLE overdue_blocks "
        . "ADD COLUMN collect_by_date DATE NULL COMMENT 'Дата, до которой нужно собрать заявки блока' AFTER name"
    );
} else {
    echo "collect_by_date already exists. No schema change required.\n";
}

$proof = $pdo->query("SHOW COLUMNS FROM overdue_blocks LIKE 'collect_by_date'")->fetch(PDO::FETCH_ASSOC);
echo "Column proof:\n";
echo json_encode($proof, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

$rows = $pdo->query(
    'SELECT id, name, collect_by_date, zayavki_count, total_mass_tonn, zayavki_ids, created_at '
    . 'FROM overdue_blocks ORDER BY id ASC'
)->fetchAll(PDO::FETCH_ASSOC);
echo "Row proof:\n";
echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
