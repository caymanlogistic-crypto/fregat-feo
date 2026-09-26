<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

function jsonOut(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is not initialized');
    }

    $tableStmt = $pdo->query("SHOW TABLES LIKE 'tracker_first_activation'");
    if (!$tableStmt || !$tableStmt->fetch(PDO::FETCH_NUM)) {
        jsonOut([
            'success' => true,
            'trackers' => new stdClass(),
            'warning' => 'tracker_first_activation table not found'
        ]);
    }

    $sql = "
        SELECT uniqueid, first_activation_at
        FROM tracker_first_activation
        WHERE is_activated = 1
          AND first_activation_at IS NOT NULL
          AND first_activation_at >= (NOW() - INTERVAL 48 HOUR)
    ";
    $stmt = $pdo->prepare($sql);
    if (!$stmt || !$stmt->execute()) {
        throw new RuntimeException('Failed to load tracker_first_activation rows');
    }

    $trackers = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($row)) {
            continue;
        }
        $uniqueid = trim((string)($row['uniqueid'] ?? ''));
        if ($uniqueid === '') {
            continue;
        }
        $trackers[$uniqueid] = [
            'is_new_tracker' => true,
            'first_activation_at' => (string)($row['first_activation_at'] ?? ''),
        ];
    }

    jsonOut([
        'success' => true,
        'trackers' => $trackers,
    ]);
} catch (Throwable $e) {
    mapError('get_tracker_first_activation failed', ['error' => $e->getMessage()]);
    jsonOut([
        'success' => true,
        'trackers' => new stdClass(),
        'warning' => 'activation data unavailable'
    ]);
}

