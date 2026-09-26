<?php
// plan_diagnostic.php
// READ-ONLY diagnostic. This script DOES NOT write to the database.
require_once 'config.php';

header('Content-Type: text/html; charset=utf-8');

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function dateOnly($v) {
    if ($v === null || $v === '') return null;
    return substr((string)$v, 0, 10);
}
function parseIds($s) {
    if ($s === null || trim((string)$s) === '') return [];
    $parts = preg_split('/[\s,;]+/', trim((string)$s));
    $out = [];
    foreach ($parts as $p) {
        if ($p === '') continue;
        $out[] = (string)$p;
    }
    return $out;
}

$targetStart = '2026-09-07';
$targetEnd   = '2026-09-13';
$currentStart = '2026-09-14';
$currentEnd   = '2026-09-20';
$targetIds = [
    299726,
    306918,
    308710,
    251448,
    255394,
    257039,
    259552,
    260103,
    260233,
    260994,
    275446,
    282050,
    285114,
    286177,
    291707,
    291724,
    291937,
    293067,
    293081,
    293239,
    293371,
    293476,
    293684,
    294120,
    294422,
    294446,
    294754,
    294760,
    288220,
    296701,
    299861,
    304278,
    304299,
    304640,
    304851,
    305081,
    306018,
    306145,
    307608,
    308896,
    309110,
    309162,
    309642,
    309767,
    310309,
    310364,
    310468,
    310977,
    297801,
    298600,
    301711,
    308208,
    287549,
    302389,
    302397,
    304621,
    305268,
    306474,
    306716,
    308496,
    308516,
    309026,
    309672,
    309808,
    309953,
    310838,
    310858,
    310865,
    310867,
    310875,
    310879,
    310884,
    311169,
    311707,
    312353,
    312376,
    312488,
    312500,
    312690,
    312762
];

$report = [];
$errors = [];

try {
    $pdo = new PDO(
        "mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset=utf8mb4",
        $dbConfig['username'],
        $dbConfig['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    $report[] = ['OK', 'Подключение к БД', 'Успешно'];
} catch (Throwable $e) {
    die('<h2>Ошибка подключения к БД</h2><pre>'.h($e->getMessage()).'</pre>');
}

$planColumns = [];
try {
    $rows = $pdo->query("SHOW COLUMNS FROM plan")->fetchAll();
    foreach ($rows as $r) $planColumns[$r['Field']] = $r;
    $report[] = ['OK', 'Таблица plan', 'Найдена, колонок: '.count($planColumns)];
} catch (Throwable $e) {
    $errors[] = 'SHOW COLUMNS plan: '.$e->getMessage();
}

$requiredUnexpected = [];
foreach ($planColumns as $name => $c) {
    $extra = strtolower((string)($c['Extra'] ?? ''));
    $isAuto = str_contains($extra, 'auto_increment');
    $nullable = strtoupper((string)($c['Null'] ?? '')) === 'YES';
    $hasDefault = array_key_exists('Default', $c) && $c['Default'] !== null;
    if (!$isAuto && !$nullable && !$hasDefault && !in_array($name, ['дата_с','дата_по','Заявки'], true)) {
        $requiredUnexpected[] = $name;
    }
}
if ($requiredUnexpected) {
    $report[] = ['WARN', 'INSERT-блокер', 'Есть обязательные колонки без default: '.implode(', ', $requiredUnexpected)];
} else {
    $report[] = ['OK', 'INSERT-схема', 'Явных обязательных дополнительных колонок не найдено'];
}

$existing = [];
try {
    $st = $pdo->prepare("SELECT * FROM plan WHERE дата_с = ? AND дата_по = ? ORDER BY id");
    $st->execute([$targetStart, $targetEnd]);
    $existing = $st->fetchAll();
    $report[] = [$existing ? 'WARN' : 'OK', 'План 07.09–13.09', $existing ? 'Уже есть строк: '.count($existing) : 'Записи нет'];
} catch (Throwable $e) {
    $errors[] = 'Проверка периода: '.$e->getMessage();
}

$recentPlans = [];
try {
    $recentPlans = $pdo->query("SELECT id, дата_с, дата_по, Заявки FROM plan ORDER BY дата_с DESC, id DESC LIMIT 12")->fetchAll();
} catch (Throwable $e) {
    $errors[] = 'Последние планы: '.$e->getMessage();
}

$targetUnique = array_values(array_unique(array_map('strval', $targetIds)));
$report[] = [count($targetUnique) === count($targetIds) ? 'OK' : 'WARN', 'Эталонный список', count($targetIds).' строк / '.count($targetUnique).' уникальных'];

$feoFound = [];
$feoMissing = [];
try {
    $ph = implode(',', array_fill(0, count($targetUnique), '?'));
    $st = $pdo->prepare("SELECT zayavka_id FROM feo WHERE zayavka_id IN ($ph)");
    $st->execute($targetUnique);
    $feoFound = array_map('strval', array_column($st->fetchAll(), 'zayavka_id'));
    $feoMissing = array_values(array_diff($targetUnique, $feoFound));
    $report[] = [$feoMissing ? 'WARN' : 'OK', 'Заявки в feo', count($feoFound).' найдено; '.count($feoMissing).' отсутствует'];
} catch (Throwable $e) {
    $errors[] = 'Проверка feo: '.$e->getMessage();
}

$flightColumns = [];
try {
    foreach ($pdo->query("SHOW COLUMNS FROM flights")->fetchAll() as $r) {
        $flightColumns[$r['Field']] = $r;
    }
} catch (Throwable $e) {
    $errors[] = 'SHOW COLUMNS flights: '.$e->getMessage();
}

$currentCandidates = [];
$boundaryDatetimeCount = 0;
try {
    $selectCols = ['id', 'zayavki_ids'];
    foreach (['planned_start_date','planned_start_date_from','planned_start_date_to'] as $c) {
        if (isset($flightColumns[$c])) $selectCols[] = $c;
    }

    if (count($selectCols) > 2) {
        $rows = $pdo->query("SELECT ".implode(',', $selectCols)." FROM flights")->fetchAll();
        $seen = [];
        foreach ($rows as $flight) {
            $matches = false;

            foreach (['planned_start_date','planned_start_date_from'] as $field) {
                if (!isset($flightColumns[$field])) continue;
                $raw = $flight[$field] ?? null;
                $d = dateOnly($raw);
                if ($raw && strlen((string)$raw) > 10 && $d === $currentEnd) $boundaryDatetimeCount++;
                if ($d !== null && $d >= $currentStart && $d <= $currentEnd) {
                    $matches = true;
                    break;
                }
            }

            // Diagnostic only: if a from/to window overlaps the current week, mark it too.
            if (!$matches && isset($flightColumns['planned_start_date_from'], $flightColumns['planned_start_date_to'])) {
                $from = dateOnly($flight['planned_start_date_from'] ?? null);
                $to   = dateOnly($flight['planned_start_date_to'] ?? null);
                if ($from && $to && $from <= $currentEnd && $to >= $currentStart) $matches = true;
            }

            if (!$matches) continue;

            foreach (parseIds($flight['zayavki_ids'] ?? '') as $id) {
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $currentCandidates[] = $id;
                }
            }
        }
        $report[] = ['OK', 'Кандидаты плана 14.09–20.09', count($currentCandidates).' уникальных заявок'];
    } else {
        $report[] = ['WARN', 'Генерация', 'В flights не найдены planned_start_date / planned_start_date_from'];
    }
} catch (Throwable $e) {
    $errors[] = 'Диагностика flights: '.$e->getMessage();
}

if ($boundaryDatetimeCount > 0) {
    $report[] = ['WARN', 'DATETIME на границе недели', $boundaryDatetimeCount.' значений на 20.09 содержат время; исходное строковое сравнение может исключать их'];
}

$existingDiff = null;
if ($existing) {
    $dbIds = parseIds($existing[0]['Заявки'] ?? '');
    $dbIds = array_values(array_unique(array_map('strval', $dbIds)));
    $existingDiff = [
        'missing_from_db' => array_values(array_diff($targetUnique, $dbIds)),
        'extra_in_db' => array_values(array_diff($dbIds, $targetUnique)),
        'db_count' => count($dbIds),
    ];
}

?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Диагностика фиксации планов</title>
<style>
body{font-family:Arial,sans-serif;max-width:1200px;margin:30px auto;padding:0 20px;color:#1f2937}
table{border-collapse:collapse;width:100%;margin:14px 0 28px}
th,td{border:1px solid #d1d5db;padding:8px;vertical-align:top;text-align:left}
th{background:#f3f4f6}
.OK{color:#067647;font-weight:700} .WARN{color:#b54708;font-weight:700} .ERR{color:#b42318;font-weight:700}
pre{white-space:pre-wrap;word-break:break-word;background:#f8fafc;padding:12px;border:1px solid #e5e7eb}
code{background:#f3f4f6;padding:2px 4px}
</style>
</head>
<body>
<h1>Диагностика фиксации планов</h1>
<p><strong>Режим:</strong> только чтение. Этот файл ничего не меняет в БД.</p>

<h2>Итоговые проверки</h2>
<table>
<tr><th>Статус</th><th>Проверка</th><th>Результат</th></tr>
<?php foreach ($report as $r): ?>
<tr><td class="<?=h($r[0])?>"><?=h($r[0])?></td><td><?=h($r[1])?></td><td><?=h($r[2])?></td></tr>
<?php endforeach; ?>
</table>

<?php if ($errors): ?>
<h2>Ошибки диагностики</h2>
<pre><?=h(implode("\n", $errors))?></pre>
<?php endif; ?>

<h2>Схема plan</h2>
<table>
<tr><th>Field</th><th>Type</th><th>Null</th><th>Default</th><th>Extra</th></tr>
<?php foreach ($planColumns as $c): ?>
<tr>
<td><?=h($c['Field'] ?? '')?></td><td><?=h($c['Type'] ?? '')?></td><td><?=h($c['Null'] ?? '')?></td>
<td><?=h(var_export($c['Default'] ?? null, true))?></td><td><?=h($c['Extra'] ?? '')?></td>
</tr>
<?php endforeach; ?>
</table>

<h2>Последние планы</h2>
<table>
<tr><th>ID</th><th>дата_с</th><th>дата_по</th><th>Заявок</th></tr>
<?php foreach ($recentPlans as $p): ?>
<tr><td><?=h($p['id'])?></td><td><?=h($p['дата_с'])?></td><td><?=h($p['дата_по'])?></td><td><?=count(parseIds($p['Заявки'] ?? ''))?></td></tr>
<?php endforeach; ?>
</table>

<h2>Период 07.09.2026–13.09.2026</h2>
<?php if (!$existing): ?>
<p class="OK">Запись отсутствует — её можно восстанавливать отдельным recovery-скриптом.</p>
<?php else: ?>
<p class="WARN">Запись уже существует. Автоматически ничего не исправляется.</p>
<pre><?=h(json_encode($existingDiff, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT))?></pre>
<?php endif; ?>

<h2>Заявки эталонного плана</h2>
<p>Всего: <strong><?=count($targetUnique)?></strong></p>
<?php if ($feoMissing): ?>
<p class="WARN">Не найдены в feo:</p><pre><?=h(implode(', ', $feoMissing))?></pre>
<?php else: ?>
<p class="OK">Все 80 заявок найдены в feo.</p>
<?php endif; ?>

<h2>Что исходная страница сможет сгенерировать на текущую неделю 14.09.2026–20.09.2026</h2>
<p>Диагностический подсчёт: <strong><?=count($currentCandidates)?></strong> уникальных заявок.</p>
<pre><?=h(implode(', ', $currentCandidates))?></pre>

<h2>Ключевой вывод</h2>
<p>Исходная страница строит «следующую неделю» только от <code>дата_по</code> выбранного сохранённого плана.
Поэтому отсутствие плана 07.09–13.09 разрывает цепочку для 14.09–20.09.</p>
</body>
</html>
