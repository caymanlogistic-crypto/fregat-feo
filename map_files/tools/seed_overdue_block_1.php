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

$blockId = 1;
$targetIds = ['249071', '251448', '253319'];

echo 'Target block: ' . $blockId . "\n";
echo 'Requests to add: ' . implode(', ', $targetIds) . "\n";
echo "Existing requests in block 1 will be preserved.\n";

$table = $pdo->query("SHOW TABLES LIKE 'overdue_blocks'");
if (!$table || !$table->fetchColumn()) {
    fwrite(STDERR, "overdue_blocks does not exist. Run migrate_create_overdue_blocks.php first.\n");
    exit(1);
}

function overdueSeedParseIds(string $raw): array
{
    $result = [];
    foreach (preg_split('/[,;\s]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $value) {
        $id = trim((string)$value);
        if ($id !== '' && preg_match('/^\d+$/', $id)) {
            $result[$id] = $id;
        }
    }
    return array_values($result);
}

try {
    $pdo->beginTransaction();

    $blockStmt = $pdo->prepare('SELECT id, name, zayavki_ids FROM overdue_blocks WHERE id = ? FOR UPDATE');
    $blockStmt->execute([$blockId]);
    $block = $blockStmt->fetch(PDO::FETCH_ASSOC);

    $otherStmt = $pdo->prepare('SELECT id, zayavki_ids FROM overdue_blocks WHERE id <> ? FOR UPDATE');
    $otherStmt->execute([$blockId]);
    foreach ($otherStmt->fetchAll(PDO::FETCH_ASSOC) as $otherBlock) {
        $duplicates = array_values(array_intersect($targetIds, overdueSeedParseIds((string)$otherBlock['zayavki_ids'])));
        if ($duplicates) {
            throw new RuntimeException(
                'Requests already exist in overdue block ' . (int)$otherBlock['id'] . ': ' . implode(', ', $duplicates)
            );
        }
    }

    $existingIds = $block ? overdueSeedParseIds((string)$block['zayavki_ids']) : [];
    $mergedIds = array_values(array_unique(array_merge($existingIds, $targetIds)));
    $placeholders = implode(',', array_fill(0, count($mergedIds), '?'));
    $requestsStmt = $pdo->prepare("SELECT zayavka_id, mass_netto FROM feo WHERE zayavka_id IN ({$placeholders})");
    $requestsStmt->execute($mergedIds);
    $requestRows = $requestsStmt->fetchAll(PDO::FETCH_ASSOC);

    $massById = [];
    foreach ($requestRows as $row) {
        $massById[(string)$row['zayavka_id']] = (float)($row['mass_netto'] ?? 0);
    }
    $missing = array_values(array_diff($mergedIds, array_keys($massById)));
    if ($missing) {
        throw new RuntimeException('Requests not found in feo: ' . implode(', ', $missing));
    }

    $totalMass = 0.0;
    foreach ($mergedIds as $id) {
        $totalMass += $massById[$id];
    }

    if ($block) {
        $name = trim((string)($block['name'] ?? ''));
        if ($name === '') {
            $name = 'Просроченные заявки';
        }
        $update = $pdo->prepare(
            'UPDATE overdue_blocks SET name = ?, zayavki_count = ?, total_mass_tonn = ?, zayavki_ids = ? WHERE id = ?'
        );
        $update->execute([$name, count($mergedIds), $totalMass, implode(',', $mergedIds), $blockId]);
    } else {
        $insert = $pdo->prepare(
            'INSERT INTO overdue_blocks (id, name, zayavki_count, total_mass_tonn, zayavki_ids) VALUES (?, ?, ?, ?, ?)'
        );
        $insert->execute([$blockId, 'Просроченные заявки', count($mergedIds), $totalMass, implode(',', $mergedIds)]);
    }

    $pdo->commit();

    $proofStmt = $pdo->prepare(
        'SELECT id, name, zayavki_count, total_mass_tonn, zayavki_ids, created_at FROM overdue_blocks WHERE id = ?'
    );
    $proofStmt->execute([$blockId]);
    echo "Result:\n";
    echo json_encode($proofStmt->fetch(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

    $proofRequests = $pdo->prepare(
        "SELECT zayavka_id, mass_netto FROM feo WHERE zayavka_id IN (?,?,?) ORDER BY zayavka_id"
    );
    $proofRequests->execute($targetIds);
    echo "Request proof:\n";
    echo json_encode($proofRequests->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Seed failed: ' . $e->getMessage() . "\n");
    exit(1);
}
