<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once dirname(__DIR__) . '/Services/RouteCalculationCache.php';

$testRoot = dirname(__DIR__, 2) . '/storage/route-cache-tests';
$points = [[55.1, 37.1], [55.2, 37.2]];
if (($argv[1] ?? '') === '--worker') {
    $target = (string)($argv[2] ?? '');
    $resolvedRoot = realpath($testRoot);
    $resolvedTarget = realpath($target);
    if (!$resolvedRoot || !$resolvedTarget || !str_starts_with($resolvedTarget, $resolvedRoot . DIRECTORY_SEPARATOR)) exit(2);
    echo (new RouteCalculationCache($resolvedTarget))->handle('claim', $points)['status'];
    exit;
}

$passed = 0;
function verify(bool $condition, string $name): void {
    global $passed;
    if (!$condition) throw new RuntimeException('FAIL ' . $name);
    $passed++; echo 'PASS ' . $name . PHP_EOL;
}
function rejects(callable $callback, string $name): void {
    try { $callback(); } catch (InvalidArgumentException $error) { verify(true, $name); return; }
    throw new RuntimeException('FAIL ' . $name);
}
if (!is_dir($testRoot)) mkdir($testRoot, 0770, true);
$directory = $testRoot . '/test-' . bin2hex(random_bytes(6));
mkdir($directory, 0770);
$now = 1000000;
$clock = static function () use (&$now): int { return $now; };
try {
    $first = new RouteCalculationCache($directory, $clock);
    $second = new RouteCalculationCache($directory, $clock);
    $claim = $first->handle('claim', $points);
    verify($claim['status'] === 'compute', 'first user owns computation lease');
    verify($second->handle('claim', array_reverse($points))['status'] === 'pending', 'second user waits on the same unordered point set');
    rejects(fn() => $second->handle('save', $points, ['token' => 'wrong', 'result' => []]), 'another user cannot publish over the active lease');
    $result = ['origin' => [55, 37], 'order_keys' => ['55.1000000,37.1000000', '55.2000000,37.2000000'],
        'distance' => 3000, 'duration' => 1600, 'coordinates' => [[55, 37], [55.1, 37.1], [55.2, 37.2]]];
    $invalid = $result; $invalid['order_keys'] = ['55.1000000,37.1000000'];
    rejects(fn() => $first->handle('save', $points, ['token' => $claim['token'], 'result' => $invalid]), 'partial route cannot enter cache');
    verify($first->handle('save', $points, ['token' => $claim['token'], 'result' => $result])['status'] === 'saved', 'complete route is saved');
    $hit = $second->handle('claim', array_reverse($points));
    verify($hit['status'] === 'hit' && (float)$hit['result']['distance'] === 3000.0 && $hit['result']['order_keys'] === $result['order_keys'] && $hit['result']['coordinates'] === $result['coordinates'], 'independent user gets identical stored minimum and geometry');
    $worse = $result; $worse['distance'] = 9000;
    verify((float)$first->handle('save', $points, ['token' => $claim['token'], 'result' => $worse])['result']['distance'] === 3000.0, 'repeated save cannot degrade the ready minimum');
    verify($second->handle('claim', [[55.1, 37.1], [55.21, 37.2]])['status'] === 'compute', 'coordinate change has a separate cache key');
    $context = ['destination' => ['id' => 1, 'address' => 'Warehouse A', 'coords' => [55, 37]]];
    $destinationClaim = $first->handle('claim', $points, $context);
    verify($destinationClaim['status'] === 'compute', 'fixed destination never reuses the old fixed-origin result');
    $toWarehouse = $result;
    unset($toWarehouse['origin']);
    $toWarehouse['destination'] = [55, 37];
    $toWarehouse['coordinates'] = [[55.1, 37.1], [55.2, 37.2], [55, 37]];
    $wrongWarehouse = $toWarehouse; $wrongWarehouse['destination'] = [56, 38];
    rejects(fn() => $first->handle('save', $points, $context + ['token' => $destinationClaim['token'], 'result' => $wrongWarehouse]), 'result for a different warehouse is rejected');
    $first->handle('save', $points, $context + ['token' => $destinationClaim['token'], 'result' => $toWarehouse]);
    verify($second->handle('claim', array_reverse($points), $context)['status'] === 'hit', 'independent user reuses fixed-destination route');
    $different = $context; $different['destination']['id'] = 2;
    verify($second->handle('claim', $points, $different)['status'] === 'compute', 'different warehouse uses a separate cache');
    $different = $context; $different['destination']['coords'] = [55.01, 37];
    verify($second->handle('claim', $points, $different)['status'] === 'compute', 'changed warehouse coordinates invalidate its route cache');
    $now += 7 * 86400 + 1;
    $expired = $second->handle('claim', $points);
    verify($expired['status'] === 'compute', 'expired seven-day result is refreshed');
    $now += 301;
    $reclaimed = $first->handle('claim', $points);
    verify($reclaimed['status'] === 'compute' && $reclaimed['token'] !== $expired['token'], 'abandoned computation lease expires');
    rejects(fn() => $second->handle('save', $points, ['token' => $expired['token'], 'result' => $result]), 'stale browser cannot overwrite the new producer');
    $first->handle('release', $points, ['token' => $reclaimed['token']]);
    verify($second->handle('claim', $points)['status'] === 'compute', 'cancellation releases the producer lease');
    verify(trim((string)file_get_contents($directory . '/.htaccess')) === 'Require all denied', 'cache files have an HTTP access guard');
    rejects(fn() => $first->handle('claim', [[999, 37]]), 'invalid coordinates are rejected');

    $concurrent = $directory . '/concurrent'; mkdir($concurrent, 0770);
    $processes = [];
    for ($i = 0; $i < 2; $i++) {
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $concurrent], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start concurrency check');
        fclose($pipes[0]); $processes[] = [$process, $pipes];
    }
    $statuses = [];
    foreach ($processes as [$process, $pipes]) {
        $statuses[] = trim(stream_get_contents($pipes[1]));
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        verify(proc_close($process) === 0 && $error === '', 'concurrent PHP worker completed safely');
    }
    sort($statuses);
    verify($statuses === ['compute', 'pending'], 'real concurrent processes elect only one Yandex producer');
    echo $passed . ' checks passed' . PHP_EOL;
} finally {
    // Delete only this test's verified directory, never shared runtime cache data.
    $resolved = realpath($directory); $resolvedRoot = realpath($testRoot);
    if ($resolved && $resolvedRoot && str_starts_with($resolved, $resolvedRoot . DIRECTORY_SEPARATOR . 'test-')) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isDir()) rmdir($entry->getPathname()); else unlink($entry->getPathname());
        }
        rmdir($resolved);
    }
}
