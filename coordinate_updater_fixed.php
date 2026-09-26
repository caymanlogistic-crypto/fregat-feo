<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/config.php';

/**
 * Разовое безопасное обновление координат для 89 заявок.
 * Новые координаты заданы в формате: широта, долгота.
 * В БД: mno_sh = широта, mno_d = долгота.
 */

const EXPECTED_COUNT = 89;

$coordinateRows = <<<'DATA'
184886	57.267186,79.404984
185030	57.903624,79.307427
185033	57.903624,79.307427
185772	60.745631,77.587083
215116	58.070343,75.612587
215119	57.744768,76.411221
215124	57.667811,78.930845
215130	58.161242,78.862484
215132	59.167947,76.243207
215137	59.672051,78.767911
215354	60.832992,77.157164
216650	60.733746,77.58867
219513	58.665642,81.474761
245546	58.440208,85.020286
269846	58.3188,82.985121
204355	60.719652,77.647611
281561	60.719652,77.647611
299138	60.733746,77.58867
299241	57.667811,78.930845
276870	69.432767,86.158949
279601	69.39237,86.216095
195430	69.422859,86.159484
272870	69.331753,88.178779
271342	69.375282,87.743725
272875	69.422859,86.159484
230810	70.426992,84.281161
232733	69.3173529,87.9856419
280582	69.30517,88.171024
280577	69.320428,88.200616
185036	67.791474,83.520199
194661	67.791474,83.520199
192947	67.284098,83.255165
224083	69.357459,88.135344
224388	69.328513,87.976915
224391	69.349655,88.161896
225266	69.317535,88.20514
233966	69.330358,88.203123
236001	69.315519,88.208274
261056	69.36124,88.088865
285420	69.330358,88.203123
248805	67.750428,83.481537
227493	67.791474,83.520199
227512	67.791474,83.520199
300680	69.327064,87.340181
290544	69.363908,88.162385
287269	69.349655,88.161896
287430	67.791474,83.520199
288985	67.791474,83.520199
287569	68.450357,83.63059
287583	67.284098,83.255165
202880	60.43625,96.264139
227041	60.43625,96.264139
260048	60.43625,96.264139
283773	60.43625,96.264139
241078	58.599511,92.096855
283279	59.890958,92.883913
208842	59.396784,93.360056
236730	58.45392,92.17511
294165	58.599511,92.096855
164961	60.13378,109.08274
166287	60.13378,109.08274
189203	60.13378,109.08274
264585	60.13378,109.08274
164755	56.126909,101.60426
187566	58.037067,102.80732
164969	56.30654,101.786536
167468	56.30654,101.786536
246438	56.30654,101.786536
268092	56.30654,101.786536
196575	56.320458,101.737593
196899	56.320458,101.737593
196900	56.320458,101.737593
196950	56.320458,101.737593
250983	51.720872,94.442922
201647	56.567109,104.13084
265478	56.122887,101.14964
281140	56.567109,104.13084
241459	57.98727222,106.79399444
194656	56.293495,101.94088
205614	56.150303,101.645584
205970	56.150303,101.645584
217287	57.975123,102.70892
226681	56.292855,101.78227
223480	51.706268,94.40858
199235	57.842261,108.3142
213272	57.841514,114.17205
221343	57.770198,108.05387
282965	57.864339,114.241249
290192	56.176068,107.458433
DATA;

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function parseCoordinates(string $source): array
{
    $result = [];
    $lines = preg_split('/\R/u', trim($source)) ?: [];

    foreach ($lines as $lineNumber => $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $parts = preg_split('/\s+/', $line, 2);
        if (!$parts || count($parts) !== 2) {
            throw new RuntimeException('Некорректная строка ' . ($lineNumber + 1) . ': ' . $line);
        }

        [$zayavkaId, $coordinatePair] = $parts;
        $coords = array_map('trim', explode(',', $coordinatePair, 2));

        if (!preg_match('/^\d{1,20}$/', $zayavkaId)) {
            throw new RuntimeException('Некорректный номер заявки в строке ' . ($lineNumber + 1));
        }
        if (count($coords) !== 2 || !is_numeric($coords[0]) || !is_numeric($coords[1])) {
            throw new RuntimeException('Некорректные координаты в строке ' . ($lineNumber + 1));
        }

        $latitude = (float)$coords[0];
        $longitude = (float)$coords[1];
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw new RuntimeException('Координаты вне допустимого диапазона в строке ' . ($lineNumber + 1));
        }
        if (isset($result[$zayavkaId])) {
            throw new RuntimeException('Номер заявки повторяется: ' . $zayavkaId);
        }

        // Сохраняем исходную точность строк, не преобразуя значения при записи.
        $result[$zayavkaId] = [
            'latitude' => $coords[0],
            'longitude' => $coords[1],
        ];
    }

    if (count($result) !== EXPECTED_COUNT) {
        throw new RuntimeException('Ожидалось ' . EXPECTED_COUNT . ' заявок, получено: ' . count($result));
    }

    return $result;
}

function connectDatabase(array $dbConfig): PDO
{
    $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset=utf8mb4";
    return new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function loadExistingRows(PDO $pdo, array $updates): array
{
    $ids = array_keys($updates);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT id, zayavka_id, mno_sh, mno_d FROM feo WHERE zayavka_id IN ($placeholders)"
    );
    $stmt->execute($ids);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[(string)$row['zayavka_id']] = $row;
    }
    return $rows;
}

try {
    $updates = parseCoordinates($coordinateRows);
    $pdo = connectDatabase($dbConfig);

    // Проверяем наличие необходимых полей до показа формы.
    $columns = $pdo->query('SHOW COLUMNS FROM feo')->fetchAll();
    $columnNames = array_column($columns, 'Field');
    foreach (['zayavka_id', 'mno_sh', 'mno_d'] as $requiredColumn) {
        if (!in_array($requiredColumn, $columnNames, true)) {
            throw new RuntimeException("В таблице feo отсутствует поле {$requiredColumn}");
        }
    }

    $existingRows = loadExistingRows($pdo, $updates);

    if (empty($_SESSION['coordinates_update_csrf'])) {
        $_SESSION['coordinates_update_csrf'] = bin2hex(random_bytes(32));
    }

    $successMessage = null;
    $errorMessage = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = (string)($_POST['csrf_token'] ?? '');
        $confirmed = isset($_POST['confirmed']) && $_POST['confirmed'] === '1';

        if (!hash_equals($_SESSION['coordinates_update_csrf'], $token)) {
            throw new RuntimeException('Сессия устарела или неверный защитный токен. Обновите страницу.');
        }
        if (!$confirmed) {
            throw new RuntimeException('Не установлено обязательное подтверждение.');
        }

        // Повторно читаем БД непосредственно перед обновлением.
        $existingRows = loadExistingRows($pdo, $updates);
        $updated = 0;
        $unchanged = 0;
        $missing = [];

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'UPDATE feo SET mno_sh = :latitude, mno_d = :longitude WHERE zayavka_id = :zayavka_id'
            );

            foreach ($updates as $zayavkaId => $newCoordinates) {
                if (!isset($existingRows[$zayavkaId])) {
                    $missing[] = $zayavkaId;
                    continue;
                }

                $oldLatitude = trim((string)($existingRows[$zayavkaId]['mno_sh'] ?? ''));
                $oldLongitude = trim((string)($existingRows[$zayavkaId]['mno_d'] ?? ''));
                if ($oldLatitude === $newCoordinates['latitude'] && $oldLongitude === $newCoordinates['longitude']) {
                    $unchanged++;
                    continue;
                }

                $stmt->execute([
                    ':latitude' => $newCoordinates['latitude'],
                    ':longitude' => $newCoordinates['longitude'],
                    ':zayavka_id' => $zayavkaId,
                ]);
                $updated++;
            }

            $pdo->commit();
            $_SESSION['coordinates_update_csrf'] = bin2hex(random_bytes(32));
            $successMessage = "Готово. Обновлено: {$updated}; уже совпадали: {$unchanged}; не найдены: " . count($missing) . '.';
            if ($missing) {
                $successMessage .= ' Не найдены заявки: ' . implode(', ', $missing) . '.';
            }
            $existingRows = loadExistingRows($pdo, $updates);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
} catch (Throwable $e) {
    $errorMessage = $e->getMessage();
    $updates = $updates ?? [];
    $existingRows = $existingRows ?? [];
}

$foundCount = count($existingRows);
$missingCount = count($updates) - $foundCount;
$changedCount = 0;
foreach ($updates as $zayavkaId => $newCoordinates) {
    if (!isset($existingRows[$zayavkaId])) {
        continue;
    }
    $oldLat = trim((string)($existingRows[$zayavkaId]['mno_sh'] ?? ''));
    $oldLon = trim((string)($existingRows[$zayavkaId]['mno_d'] ?? ''));
    if ($oldLat !== $newCoordinates['latitude'] || $oldLon !== $newCoordinates['longitude']) {
        $changedCount++;
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Обновление координат 89 заявок</title>
    <style>
        body { margin: 0; background: #f3f5f7; color: #1e293b; font-family: Arial, sans-serif; }
        .container { max-width: 1180px; margin: 32px auto; padding: 0 18px 48px; }
        .card { background: #fff; border: 1px solid #dbe2ea; border-radius: 10px; box-shadow: 0 4px 18px rgba(15, 23, 42, .07); overflow: hidden; }
        .header { padding: 22px 24px; border-bottom: 1px solid #e5e7eb; }
        h1 { margin: 0 0 8px; font-size: 25px; }
        .muted { color: #64748b; }
        .summary { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 16px; }
        .badge { padding: 7px 10px; background: #eef2f7; border-radius: 6px; font-weight: 700; }
        .badge.warning { background: #fff3cd; color: #7a5300; }
        .badge.ok { background: #dcfce7; color: #166534; }
        .message { margin: 18px 24px 0; padding: 13px 15px; border-radius: 7px; }
        .message.success { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .message.error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .table-wrap { max-height: 62vh; overflow: auto; border-top: 1px solid #e5e7eb; border-bottom: 1px solid #e5e7eb; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #e5e7eb; white-space: nowrap; }
        th { position: sticky; top: 0; z-index: 1; background: #f8fafc; }
        tr.missing td { background: #fff1f2; }
        tr.same td { color: #64748b; }
        .status { font-weight: 700; }
        .status.change { color: #b45309; }
        .status.same { color: #166534; }
        .status.missing { color: #b91c1c; }
        .actions { padding: 20px 24px 24px; }
        .confirm-line { display: flex; align-items: flex-start; gap: 9px; margin-bottom: 15px; }
        .confirm-line input { margin-top: 3px; }
        button { border: 0; border-radius: 7px; padding: 12px 18px; font-size: 15px; font-weight: 700; cursor: pointer; background: #b91c1c; color: white; }
        button:disabled { opacity: .45; cursor: not-allowed; }
        .note { margin-top: 12px; font-size: 13px; color: #64748b; }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <div class="header">
            <h1>Предварительный просмотр обновления координат</h1>
            <div class="muted">Поля БД: <strong>mno_sh = широта</strong>, <strong>mno_d = долгота</strong>. Никаких изменений до нажатия кнопки не выполняется.</div>
            <div class="summary">
                <span class="badge">Всего в списке: <?= count($updates) ?></span>
                <span class="badge ok">Найдено в БД: <?= $foundCount ?></span>
                <span class="badge">Будет изменено: <?= $changedCount ?></span>
                <span class="badge warning">Не найдено: <?= $missingCount ?></span>
            </div>
        </div>

        <?php if (!empty($successMessage)): ?>
            <div class="message success"><?= e($successMessage) ?></div>
        <?php endif; ?>
        <?php if (!empty($errorMessage)): ?>
            <div class="message error"><strong>Ошибка:</strong> <?= e($errorMessage) ?></div>
        <?php endif; ?>

        <?php if ($updates): ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>№</th>
                    <th>Заявка</th>
                    <th>Было</th>
                    <th>Стало</th>
                    <th>Результат</th>
                </tr>
                </thead>
                <tbody>
                <?php $number = 0; ?>
                <?php foreach ($updates as $zayavkaId => $newCoordinates): ?>
                    <?php
                    $number++;
                    $row = $existingRows[$zayavkaId] ?? null;
                    $oldCoordinates = $row
                        ? trim((string)($row['mno_sh'] ?? '')) . ',' . trim((string)($row['mno_d'] ?? ''))
                        : '—';
                    $newPair = $newCoordinates['latitude'] . ',' . $newCoordinates['longitude'];
                    $isSame = $row && trim((string)$row['mno_sh']) === $newCoordinates['latitude']
                        && trim((string)$row['mno_d']) === $newCoordinates['longitude'];
                    $rowClass = !$row ? 'missing' : ($isSame ? 'same' : '');
                    ?>
                    <tr class="<?= $rowClass ?>">
                        <td><?= $number ?></td>
                        <td><strong><?= e($zayavkaId) ?></strong></td>
                        <td><?= e($oldCoordinates === ',' ? 'не заполнено' : $oldCoordinates) ?></td>
                        <td><?= e($newPair) ?></td>
                        <td>
                            <?php if (!$row): ?>
                                <span class="status missing">Заявка не найдена — пропуск</span>
                            <?php elseif ($isSame): ?>
                                <span class="status same">Уже совпадает</span>
                            <?php else: ?>
                                <span class="status change">Будет заменено</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <form method="post" class="actions" id="updateForm">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['coordinates_update_csrf'] ?? '') ?>">
            <input type="hidden" name="confirmed" value="1">
            <label class="confirm-line">
                <input type="checkbox" id="confirmCheckbox">
                <span>Я проверил таблицу «было → стало» и подтверждаю замену координат найденных заявок.</span>
            </label>
            <button type="submit" id="submitButton" disabled>Подтвердить и обновить координаты</button>
            <div class="note">Обновление выполняется одной транзакцией. При любой ошибке все изменения будут отменены.</div>
        </form>
        <?php endif; ?>
    </div>
</div>
<script>
    const checkbox = document.getElementById('confirmCheckbox');
    const button = document.getElementById('submitButton');
    const form = document.getElementById('updateForm');

    if (checkbox && button && form) {
        checkbox.addEventListener('change', () => {
            button.disabled = !checkbox.checked;
        });

        form.addEventListener('submit', (event) => {
            const message = 'Подтвердить обновление координат?\n\n' +
                'Всего заявок в списке: <?= count($updates) ?>\n' +
                'Найдено в базе: <?= $foundCount ?>\n' +
                'Будет изменено: <?= $changedCount ?>\n' +
                'Не найдено: <?= $missingCount ?>';

            if (!window.confirm(message)) {
                event.preventDefault();
                return;
            }
            button.disabled = true;
            button.textContent = 'Обновление...';
        });
    }
</script>
</body>
</html>
