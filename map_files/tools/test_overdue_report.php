<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
require_once __DIR__ . '/../Support/overdue_report.php';

function checkOverdueReport(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$blocks = [
    ['id' => 1, 'name' => 'ТЕСТ 1', 'collect_by_date' => '2026-10-25', 'zayavki_ids' => '123, 124, 125, 123'],
    ['id' => 2, 'name' => 'ТЕСТ 2', 'collect_by_date' => null, 'zayavki_ids' => '126'],
];
$flights = [
    ['id' => 10, 'zayavki_ids' => '123,124', 'status' => 'completed', 'actual_start_date' => '2026-10-20', 'actual_end_date' => '2026-10-21'],
    ['id' => 11, 'zayavki_ids' => '123', 'status' => 'started', 'actual_start_date' => '2026-10-22', 'actual_end_date' => null],
    ['id' => 12, 'zayavki_ids' => '1234', 'status' => 'found', 'actual_start_date' => null, 'actual_end_date' => null],
    ['id' => 13, 'zayavki_ids' => '126,126', 'status' => 'planned_route', 'actual_start_date' => null, 'actual_end_date' => null],
];
$rows = overdueReportBuildRows($blocks, $flights);
checkOverdueReport(count($rows) === 5, 'All requests, including every flight leg and requests without flights');
checkOverdueReport(array_column($rows, 'request_id') === ['123', '123', '124', '125', '126'], 'Stable order and no duplicate request tokens');
checkOverdueReport(array_map(static fn(array $row) => $row['flight']['id'] ?? null, $rows) === [10, 11, 10, null, 13], 'Exact ID matching and completed flights retained');
checkOverdueReport($rows[0]['flight']['actual_end_date'] === '2026-10-21' && $rows[1]['flight']['actual_end_date'] === null, 'Dates stay paired with their own flight');
$filtered = overdueReportBuildRows([$blocks[1]], $flights);
checkOverdueReport(count($filtered) === 1 && $filtered[0]['request_id'] === '126', 'Selected block excludes other requests');
checkOverdueReport(overdueReportBuildRows([], $flights) === [], 'Empty blocks');
checkOverdueReport(count(overdueReportBuildRows($blocks, [])) === 4, 'All requests remain visible when there are no flights');
checkOverdueReport(overdueReportDate(null) === 'Не указана' && overdueReportDate('0000-00-00') === 'Не указана' && overdueReportDate('2026-02-30') === 'Не указана', 'Missing and invalid dates do not become epoch dates');
checkOverdueReport(overdueReportDate('2026-10-25') === '25.10.2026', 'Russian date format');
checkOverdueReport(overdueReportStatusLabel('completed') === 'Груз сдан' && overdueReportStatusLabel('unknown') === 'Статус не определен', 'Readable statuses');
