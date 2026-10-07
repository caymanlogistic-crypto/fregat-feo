<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/max_notify.php';
require_once dirname(__DIR__) . '/Support/planned_route_retention.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Database connection is not initialized.\n");
    exit(1);
}

try {
    $result = plannedRouteRetentionProcess($pdo, true);
    echo json_encode(['success' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    mapError('planned route retention CLI failed', ['error' => $e->getMessage()]);
    fwrite(STDERR, "Planned route retention failed.\n");
    exit(1);
}
