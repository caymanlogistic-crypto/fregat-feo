<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function overdueValidationRespond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function overdueValidationParseIds(string $raw): array
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    overdueValidationRespond(405, ['success' => false, 'message' => 'Метод не поддерживается.']);
}

$sessionToken = (string)($_SESSION['overdue_form_token'] ?? '');
$requestToken = (string)($_POST['form_token'] ?? '');
if ($sessionToken === '' || !hash_equals($sessionToken, $requestToken)) {
    overdueValidationRespond(403, ['success' => false, 'message' => 'Ошибка безопасности. Обновите страницу.']);
}

$ids = overdueValidationParseIds((string)($_POST['zayavki_ids'] ?? ''));
if (!$ids) {
    overdueValidationRespond(200, [
        'success' => true,
        'valid_ids' => [],
        'duplicates' => [],
        'missing_ids' => [],
    ]);
}

try {
    require_once __DIR__ . '/config.php';
    $pdo = new PDO(
        "mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset=utf8mb4",
        $dbConfig['username'],
        $dbConfig['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $feoStmt = $pdo->prepare("SELECT zayavka_id FROM feo WHERE zayavka_id IN ({$placeholders})");
    $feoStmt->execute($ids);
    $found = [];
    foreach ($feoStmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $found[(string)$id] = true;
    }
    $missingIds = array_values(array_filter($ids, static function (string $id) use ($found): bool {
        return !isset($found[$id]);
    }));

    $excludeBlockId = max(0, (int)($_POST['block_id'] ?? 0));
    $sql = 'SELECT id, name, zayavki_ids FROM overdue_blocks';
    $params = [];
    if ($excludeBlockId > 0) {
        $sql .= ' WHERE id <> ?';
        $params[] = $excludeBlockId;
    }
    $blockStmt = $pdo->prepare($sql);
    $blockStmt->execute($params);

    $duplicateBlocksById = [];
    foreach ($blockStmt->fetchAll(PDO::FETCH_ASSOC) as $block) {
        $blockIds = overdueValidationParseIds((string)($block['zayavki_ids'] ?? ''));
        foreach (array_intersect($ids, $blockIds) as $id) {
            $duplicateBlocksById[$id][] = [
                'id' => (int)$block['id'],
                'name' => (string)$block['name'],
            ];
        }
    }

    $duplicates = [];
    foreach ($ids as $id) {
        if (isset($duplicateBlocksById[$id])) {
            $duplicates[] = ['id' => $id, 'blocks' => $duplicateBlocksById[$id]];
        }
    }
    $validIds = array_values(array_filter($ids, static function (string $id) use ($found, $duplicateBlocksById): bool {
        return isset($found[$id]) && !isset($duplicateBlocksById[$id]);
    }));

    overdueValidationRespond(200, [
        'success' => true,
        'valid_ids' => $validIds,
        'duplicates' => $duplicates,
        'missing_ids' => $missingIds,
    ]);
} catch (Throwable $e) {
    error_log('overdue_validate failed: ' . $e->getMessage());
    overdueValidationRespond(500, ['success' => false, 'message' => 'Не удалось проверить заявки. Повторите попытку.']);
}
