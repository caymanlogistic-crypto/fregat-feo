<?php
declare(strict_types=1);

// CLI-only regression checks. Synthetic requests; no database or HTTP writes.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../Services/MapDataService.php';
require_once __DIR__ . '/../Repositories/FlightRepository.php';

function checkMixedDisplay(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$flights = new class {
    public function fetchStatusesForActual(): array
    {
        return [
            ['id' => 12, 'status' => 'planned_route', 'route_type' => 'mixed_to_utilizer', 'source_warehouse_id' => 10, 'zayavki_ids' => '1, 2,3', 'direct_zayavki_ids' => ' 2,3,3,999 '],
            ['id' => 11, 'status' => 'found', 'route_type' => 'warehouse_to_utilizer', 'source_warehouse_id' => 10, 'zayavki_ids' => '4', 'direct_zayavki_ids' => '4'],
            ['id' => 10, 'status' => 'started', 'route_type' => 'generator_to_warehouse', 'source_warehouse_id' => null, 'zayavki_ids' => '5'],
            ['id' => 9, 'status' => 'found', 'source_warehouse_id' => 10, 'zayavki_ids' => '7'],
            ['id' => 8, 'status' => 'completed', 'source_warehouse_id' => null, 'zayavki_ids' => '2,3'],
        ];
    }
};
$blocks = new class {
    public function fetchAvailableBlocks(): array { return [['zayavki_ids' => '6', 'comment' => '']]; }
};
$feo = new class {
    public function fetchPointsWithCoords(): array
    {
        return array_map(static fn(int $id): array => [
            'zayavka_id' => $id, 'mass_netto' => 0.1 * $id, 'mass_brutto' => 0.2 * $id,
            'mno_sh' => 37 + $id / 100, 'mno_d' => 55 + $id / 100,
            'mno_adres_pogruzki' => 'TEST address ' . $id,
        ], range(1, 7));
    }
};

foreach (['mass_brutto', 'mass_netto'] as $basis) {
    $service = new MapDataService($flights, $blocks, $feo, ['planned_route' => '#abc'], ['planned_route' => 'Planned'], $basis);
    $points = [];
    foreach ($service->build()['processedGroups'] as $group) {
        foreach ($group['points'] as $point) { $points[$point['zayavka_id']] = $point; }
    }
    checkMixedDisplay(isset($points[2], $points[3]), "{$basis}: direct requests in a mixed route retain their map points");
    checkMixedDisplay(!isset($points[1]) && !isset($points[4]) && !isset($points[7]), "{$basis}: warehouse requests stay off their former OO addresses");
    checkMixedDisplay(isset($points[5], $points[6]) && count($points) === 4, "{$basis}: inbound and available requests retain their points");
    checkMixedDisplay($points[2]['flight_id'] === 12 && $points[2]['flight_status'] === 'planned_route', "{$basis}: latest flight status and identity are preserved");
    checkMixedDisplay(abs($points[3]['display_mass'] - $points[3][$basis]) < 0.000001, "{$basis}: point weight uses the requested mass basis");
}

foreach ([true, false] as $hasDirectColumn) {
    $pdo = new class($hasDirectColumn) {
        public array $queries = [];
        public function __construct(private bool $hasDirectColumn) {}
        public function query(string $sql): object
        {
            $this->queries[] = $sql;
            return new class(str_starts_with($sql, 'SHOW'), $this->hasDirectColumn) {
                public function __construct(private bool $metadata, private bool $hasColumn) {}
                public function fetch($mode): array|false { return $this->hasColumn ? ['Field' => 'direct_zayavki_ids'] : false; }
                public function fetchAll($mode): array { return []; }
            };
        }
    };
    (new FlightRepository($pdo))->fetchStatusesForActual();
    $sql = end($pdo->queries);
    checkMixedDisplay(str_contains($sql, 'route_type') && str_contains($sql, $hasDirectColumn ? ', direct_zayavki_ids ' : "'' AS direct_zayavki_ids"), 'Flight lookup carries the mixed split; legacy schema remains supported');
}
