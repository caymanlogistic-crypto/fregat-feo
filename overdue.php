<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/config.php';

$pdo = new PDO(
    "mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset=utf8mb4",
    $dbConfig['username'],
    $dbConfig['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

if (!isset($_SESSION['overdue_form_token'])) {
    $_SESSION['overdue_form_token'] = bin2hex(random_bytes(32));
}

function overdueTableExists(PDO $pdo): bool
{
    $stmt = $pdo->query("SHOW TABLES LIKE 'overdue_blocks'");
    return $stmt && (bool)$stmt->fetchColumn();
}

function overdueCollectDateColumnExists(PDO $pdo): bool
{
    $stmt = $pdo->query("SHOW COLUMNS FROM overdue_blocks LIKE 'collect_by_date'");
    return $stmt && (bool)$stmt->fetchColumn();
}

function overdueValidateCollectDate(string $value): string
{
    $value = trim($value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (
        !$date
        || ($errors !== false && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0))
        || $date->format('Y-m-d') !== $value
    ) {
        throw new RuntimeException('Укажите корректную дату «Собрать до».');
    }
    return $value;
}

function overdueParseIds(string $raw): array
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

function overdueRequestSummary(PDO $pdo, array $ids): array
{
    if (!$ids) {
        throw new RuntimeException('Введите хотя бы одну заявку.');
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT zayavka_id, mass_netto FROM feo WHERE zayavka_id IN ({$placeholders})");
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $massById = [];
    foreach ($rows as $row) {
        $massById[(string)$row['zayavka_id']] = (float)($row['mass_netto'] ?? 0);
    }
    $missing = array_values(array_diff($ids, array_keys($massById)));
    if ($missing) {
        throw new RuntimeException('Заявки не найдены в БД: ' . implode(', ', $missing));
    }
    $totalMass = 0.0;
    foreach ($ids as $id) {
        $totalMass += $massById[$id];
    }
    return ['count' => count($ids), 'total_mass_tonn' => $totalMass];
}

function overdueAssertUnique(PDO $pdo, array $ids, int $excludeBlockId = 0): void
{
    $sql = 'SELECT id, zayavki_ids FROM overdue_blocks';
    $params = [];
    if ($excludeBlockId > 0) {
        $sql .= ' WHERE id <> ?';
        $params[] = $excludeBlockId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $block) {
        $duplicates = array_values(array_intersect($ids, overdueParseIds((string)$block['zayavki_ids'])));
        if ($duplicates) {
            throw new RuntimeException(
                'Заявки уже находятся в блоке ' . (int)$block['id'] . ': ' . implode(', ', $duplicates)
            );
        }
    }
}

$tableExists = overdueTableExists($pdo);
$collectDateColumnExists = $tableExists && overdueCollectDateColumnExists($pdo);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$tableExists) {
            throw new RuntimeException('Таблица overdue_blocks ещё не создана.');
        }
        if (!$collectDateColumnExists) {
            throw new RuntimeException('Поле «Собрать до» ещё не добавлено. Выполните проектную миграцию.');
        }
        if (!hash_equals((string)$_SESSION['overdue_form_token'], (string)($_POST['form_token'] ?? ''))) {
            throw new RuntimeException('Ошибка безопасности. Обновите страницу.');
        }

        if (isset($_POST['delete_block'])) {
            $blockId = (int)($_POST['block_id'] ?? 0);
            if ($blockId <= 0) {
                throw new RuntimeException('Некорректный ID блока.');
            }
            $stmt = $pdo->prepare('DELETE FROM overdue_blocks WHERE id = ?');
            $stmt->execute([$blockId]);
            header('Location: overdue.php?status=deleted');
            exit;
        }

        $name = trim((string)($_POST['name'] ?? ''));
        $collectByDate = overdueValidateCollectDate((string)($_POST['collect_by_date'] ?? ''));
        $ids = overdueParseIds((string)($_POST['zayavki_ids'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Введите название блока.');
        }
        $summary = overdueRequestSummary($pdo, $ids);

        if (isset($_POST['update_block'])) {
            $blockId = (int)($_POST['block_id'] ?? 0);
            if ($blockId <= 0) {
                throw new RuntimeException('Некорректный ID блока.');
            }
            overdueAssertUnique($pdo, $ids, $blockId);
            $stmt = $pdo->prepare(
                'UPDATE overdue_blocks SET name = ?, collect_by_date = ?, zayavki_count = ?, total_mass_tonn = ?, zayavki_ids = ? WHERE id = ?'
            );
            $stmt->execute([$name, $collectByDate, $summary['count'], $summary['total_mass_tonn'], implode(',', $ids), $blockId]);
        } else {
            overdueAssertUnique($pdo, $ids);
            $stmt = $pdo->prepare(
                'INSERT INTO overdue_blocks (name, collect_by_date, zayavki_count, total_mass_tonn, zayavki_ids) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$name, $collectByDate, $summary['count'], $summary['total_mass_tonn'], implode(',', $ids)]);
        }

        $_SESSION['overdue_form_token'] = bin2hex(random_bytes(32));
        header('Location: overdue.php?status=saved');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$editBlock = null;
if ($tableExists && isset($_GET['edit']) && ctype_digit((string)$_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM overdue_blocks WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $editBlock = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$blocks = [];
if ($tableExists) {
    $collectDateSelect = $collectDateColumnExists ? 'collect_by_date' : 'NULL AS collect_by_date';
    $blocks = $pdo->query(
        "SELECT id, name, {$collectDateSelect}, zayavki_count, total_mass_tonn, zayavki_ids, created_at FROM overdue_blocks ORDER BY id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
}

function overdueH(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Просроченные заявки</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { background: #f5f7fa; color: #1f2937; font-family: Arial, sans-serif; }
        .overdue-page { max-width: 1240px; margin: 28px auto; padding: 0 20px 40px; }
        .overdue-card { background: #fff; border: 1px solid #dfe3e8; border-radius: 10px; padding: 20px; margin-bottom: 18px; box-shadow: 0 2px 8px rgba(0,0,0,.04); }
        .overdue-card h1, .overdue-card h2 { margin-top: 0; }
        .overdue-grid { display: grid; grid-template-columns: minmax(220px, .65fr) minmax(180px, .45fr) minmax(360px, 1.3fr); gap: 16px; }
        .overdue-field label { display: block; margin-bottom: 6px; font-weight: 700; }
        .overdue-field input, .overdue-field textarea { box-sizing: border-box; width: 100%; padding: 10px; border: 1px solid #b9c1cc; border-radius: 6px; font: inherit; }
        .overdue-field textarea { min-height: 108px; resize: vertical; }
        .overdue-actions { display: flex; gap: 8px; margin-top: 14px; align-items: center; }
        .overdue-table-wrap { overflow-x: auto; }
        .overdue-table { width: 100%; border-collapse: collapse; }
        .overdue-table th, .overdue-table td { padding: 10px; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: top; }
        .overdue-table th { background: #f7f8fa; white-space: nowrap; }
        .overdue-source { max-width: 420px; overflow-wrap: anywhere; line-height: 1.45; }
        .overdue-alert { border-radius: 6px; padding: 12px 14px; margin-bottom: 16px; }
        .overdue-alert.error { background: #f8d7da; color: #721c24; border: 1px solid #f1aeb5; }
        .overdue-alert.success { background: #d1e7dd; color: #0f5132; border: 1px solid #a3cfbb; }
        .overdue-note { color: #586174; font-size: 13px; margin-top: 6px; }
        .overdue-inline { display: inline; }
        @media (max-width: 760px) { .overdue-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<?php include __DIR__ . '/menuadmin.php'; ?>
<main class="overdue-page">
    <section class="overdue-card">
        <h1>Просроченные заявки</h1>
        <p>Карта подсвечивает заявки, указанные в этих блоках. Количество и масса рассчитываются автоматически по таблице FEO.</p>

        <?php if (!$tableExists): ?>
            <div class="overdue-alert error">Таблица <code>overdue_blocks</code> не создана. Сначала выполните проектную миграцию.</div>
        <?php elseif (!$collectDateColumnExists): ?>
            <div class="overdue-alert error">Поле «Собрать до» ещё не добавлено. Выполните проектную миграцию.</div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="overdue-alert error"><?= overdueH($error) ?></div>
        <?php elseif (($_GET['status'] ?? '') === 'saved'): ?>
            <div class="overdue-alert success">Блок сохранён.</div>
        <?php elseif (($_GET['status'] ?? '') === 'deleted'): ?>
            <div class="overdue-alert success">Блок удалён.</div>
        <?php endif; ?>

        <?php if ($tableExists && $collectDateColumnExists): ?>
        <form method="post">
            <input type="hidden" name="form_token" value="<?= overdueH((string)$_SESSION['overdue_form_token']) ?>">
            <?php if ($editBlock): ?>
                <input type="hidden" name="update_block" value="1">
                <input type="hidden" name="block_id" value="<?= (int)$editBlock['id'] ?>">
            <?php else: ?>
                <input type="hidden" name="save_block" value="1">
            <?php endif; ?>
            <div class="overdue-grid">
                <div class="overdue-field">
                    <label for="name">Название</label>
                    <input id="name" name="name" maxlength="255" required value="<?= overdueH((string)($editBlock['name'] ?? '')) ?>">
                </div>
                <div class="overdue-field">
                    <label for="collect_by_date">Собрать до</label>
                    <input id="collect_by_date" name="collect_by_date" type="date" required value="<?= overdueH((string)($editBlock['collect_by_date'] ?? '')) ?>">
                    <div class="overdue-note">Плановая дата завершения сбора заявок блока.</div>
                </div>
                <div class="overdue-field">
                    <label for="zayavki_ids">Источник — заявки</label>
                    <textarea id="zayavki_ids" name="zayavki_ids" required placeholder="249071, 251448, 253319"><?= overdueH((string)($editBlock['zayavki_ids'] ?? '')) ?></textarea>
                    <div class="overdue-note">Можно вводить через запятую, пробел или с новой строки.</div>
                </div>
            </div>
            <div class="overdue-actions">
                <button class="btn btn-primary" type="submit"><?= $editBlock ? 'Сохранить изменения' : 'Создать блок' ?></button>
                <?php if ($editBlock): ?><a class="btn btn-secondary" href="overdue.php">Отмена</a><?php endif; ?>
            </div>
        </form>
        <?php endif; ?>
    </section>

    <section class="overdue-card">
        <h2>Блоки просрочки</h2>
        <div class="overdue-table-wrap">
            <table class="overdue-table">
                <thead><tr><th>ID</th><th>Название</th><th>Собрать до</th><th>Заявки</th><th>Общая масса</th><th>Источник</th><th>Дата добавления</th><th>Действия</th></tr></thead>
                <tbody>
                <?php if (!$blocks): ?>
                    <tr><td colspan="8">Блоков пока нет.</td></tr>
                <?php else: ?>
                    <?php foreach ($blocks as $block): ?>
                    <tr>
                        <td><?= (int)$block['id'] ?></td>
                        <td><strong><?= overdueH((string)$block['name']) ?></strong></td>
                        <td><?= $block['collect_by_date'] ? overdueH(date('d.m.Y', strtotime((string)$block['collect_by_date']))) : 'Не задано' ?></td>
                        <td><?= (int)$block['zayavki_count'] ?></td>
                        <td><?= number_format((float)$block['total_mass_tonn'] * 1000, 0, '.', ' ') ?> кг</td>
                        <td class="overdue-source"><?= overdueH(implode(', ', overdueParseIds((string)$block['zayavki_ids']))) ?></td>
                        <td><?= overdueH(date('d.m.Y H:i', strtotime((string)$block['created_at']))) ?></td>
                        <td>
                            <a class="btn btn-sm" href="overdue.php?edit=<?= (int)$block['id'] ?>">Изменить</a>
                            <form class="overdue-inline" method="post" onsubmit="return confirm('Удалить блок просрочки?');">
                                <input type="hidden" name="form_token" value="<?= overdueH((string)$_SESSION['overdue_form_token']) ?>">
                                <input type="hidden" name="delete_block" value="1">
                                <input type="hidden" name="block_id" value="<?= (int)$block['id'] ?>">
                                <button class="btn btn-sm btn-danger" type="submit">Удалить</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
