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

echo "Creating overdue_blocks if it does not exist. No existing tables or rows will be changed.\n";

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS overdue_blocks (
    id INT(11) NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    zayavki_count INT(11) NOT NULL DEFAULT 0,
    total_mass_tonn DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    zayavki_ids TEXT NOT NULL COMMENT 'ID заявок через запятую',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

$columns = $pdo->query('SHOW COLUMNS FROM overdue_blocks')->fetchAll(PDO::FETCH_ASSOC);
echo "overdue_blocks columns:\n";
foreach ($columns as $column) {
    echo '- ' . (string)$column['Field'] . ' ' . (string)$column['Type'] . "\n";
}

echo "Migration completed.\n";
