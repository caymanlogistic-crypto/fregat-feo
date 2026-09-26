<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); echo "CLI only.\n"; exit(1); }
require_once __DIR__ . '/../bootstrap.php';
echo "=== WM #190 ===\n";
$c = (int)$pdo->query("SELECT COUNT(*) FROM warehouse_movements WHERE flight_id=190")->fetchColumn();
echo "Count: {$c}\n";
echo ($c === 0) ? "PASS: 0 rows for generator_to_utilizer.\n" : "FAIL: {$c} rows!\n";
echo "DONE\n";
