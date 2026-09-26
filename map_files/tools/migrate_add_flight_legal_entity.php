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

$stmt = $pdo->query("SHOW COLUMNS FROM flights LIKE 'legal_entity_code'");
if ($stmt && $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "flights.legal_entity_code already exists.\n";
    exit(0);
}

$pdo->exec("ALTER TABLE flights ADD COLUMN legal_entity_code VARCHAR(32) NULL DEFAULT NULL AFTER route_type");
echo "Added flights.legal_entity_code.\n";
