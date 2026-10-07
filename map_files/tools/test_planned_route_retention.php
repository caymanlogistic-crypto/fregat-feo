<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

require_once __DIR__ . '/../Support/planned_route_retention.php';

$cases = [
    ['2026-10-07 13:40:00', 72, '2026-10-12 13:40:00'],
    ['2026-10-09 13:40:00', 24, '2026-10-12 13:40:00'],
    ['2026-10-10 13:40:00', 24, '2026-10-13 13:40:00'],
];

$failed = 0;
foreach ($cases as [$startRaw, $hours, $expected]) {
    $actual = plannedRouteRetentionAddBusinessHours(plannedRouteRetentionDateTime($startRaw), $hours)->format('Y-m-d H:i:s');
    $ok = $actual === $expected;
    echo ($ok ? 'PASS' : 'FAIL') . " add {$hours}h: {$startRaw} => {$actual}; expected {$expected}\n";
    if (!$ok) {
        $failed++;
    }
}

$seconds = plannedRouteRetentionBusinessSecondsBetween(
    plannedRouteRetentionDateTime('2026-10-09 14:00:00'),
    plannedRouteRetentionDateTime('2026-10-12 13:00:00')
);
$ok = $seconds === 23 * 3600;
echo ($ok ? 'PASS' : 'FAIL') . " remaining across weekend: {$seconds}; expected " . (23 * 3600) . "\n";
if (!$ok) {
    $failed++;
}

exit($failed === 0 ? 0 : 1);
