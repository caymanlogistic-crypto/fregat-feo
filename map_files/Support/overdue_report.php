<?php
declare(strict_types=1);

function overdueReportParseIds(string $raw): array
{
    $ids = [];
    foreach (preg_split('/[,;\s]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $value) {
        if (ctype_digit($value)) {
            $id = ltrim($value, '0');
            if ($id !== '') {
                $ids[$id] = $id;
            }
        }
    }
    return array_values($ids);
}

function overdueReportBuildRows(array $blocks, iterable $flights): array
{
    $requested = [];
    foreach ($blocks as $block) {
        foreach (overdueReportParseIds((string)$block['zayavki_ids']) as $id) {
            $requested[$id] = true;
        }
    }

    $flightsByRequest = [];
    foreach ($flights as $flight) {
        foreach (overdueReportParseIds((string)$flight['zayavki_ids']) as $id) {
            if (isset($requested[$id])) {
                $flightsByRequest[$id][] = $flight;
            }
        }
    }

    $rows = [];
    foreach ($blocks as $block) {
        foreach (overdueReportParseIds((string)$block['zayavki_ids']) as $id) {
            foreach ($flightsByRequest[$id] ?? [null] as $flight) {
                $rows[] = ['block' => $block, 'request_id' => $id, 'flight' => $flight];
            }
        }
    }
    return $rows;
}

function overdueReportLoadRows(PDO $pdo, array $blocks): array
{
    if (!$blocks) {
        return [];
    }
    // One read-only query, including completed flights and every leg of a request.
    // Match complete request numbers in PHP, never substrings such as 123 in 1234.
    $stmt = $pdo->query(
        'SELECT id, zayavki_ids, status, actual_start_date, actual_end_date FROM flights ORDER BY id ASC'
    );
    $stmt->setFetchMode(PDO::FETCH_ASSOC);
    try {
        return overdueReportBuildRows($blocks, $stmt);
    } finally {
        $stmt->closeCursor();
    }
}

function overdueReportStatusLabel(string $status): string
{
    return [
        'planned_route' => 'Планируемый маршрут',
        'search' => 'Поиск подрядчика',
        'found' => 'Подрядчик определен',
        'started' => 'Вывоз начался',
        'completed' => 'Груз сдан',
    ][$status] ?? 'Статус не определен';
}

function overdueReportDate(?string $value): string
{
    $value = trim((string)$value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
        return 'Не указана';
    }
    return $date->format('d.m.Y');
}
