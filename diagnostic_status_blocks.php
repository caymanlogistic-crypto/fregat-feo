<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">';
echo '<title>PLANEX — диагностика блоков заявок</title>';
echo '<style>
body{font-family:Arial,sans-serif;margin:24px;color:#222;background:#f7f7f8}
h1,h2{margin:0 0 12px}
section{background:#fff;border:1px solid #ddd;border-radius:10px;padding:16px;margin:0 0 16px}
pre{white-space:pre-wrap;word-break:break-word;background:#111;color:#eee;padding:14px;border-radius:8px;overflow:auto}
.ok{color:#137333;font-weight:700}.bad{color:#b3261e;font-weight:700}.warn{color:#9a6700;font-weight:700}
table{border-collapse:collapse;width:100%;font-size:13px}
th,td{border:1px solid #ddd;padding:7px;vertical-align:top;text-align:left}
th{background:#f1f3f4}
code{background:#eee;padding:1px 4px;border-radius:4px}
</style></head><body>';

echo '<h1>PLANEX — диагностика «Доступно / Маршрут»</h1>';
echo '<p>Скрипт только читает данные. Никаких INSERT/UPDATE/DELETE не выполняется.</p>';

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function parseIds($raw): array {
    $parts = preg_split('/[,;\s]+/', (string)$raw, -1, PREG_SPLIT_NO_EMPTY);
    $out = [];
    foreach ($parts as $part) {
        $id = preg_replace('/[^0-9]/', '', trim((string)$part));
        if ($id !== '') $out[] = $id;
    }
    return array_values(array_unique($out));
}

function printTable(array $rows): void {
    if (!$rows) {
        echo '<p><em>Нет данных</em></p>';
        return;
    }
    $headers = array_keys($rows[0]);
    echo '<div style="overflow:auto"><table><thead><tr>';
    foreach ($headers as $head) echo '<th>' . h($head) . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($headers as $head) {
            $val = $row[$head] ?? '';
            if (is_array($val)) $val = implode(', ', $val);
            echo '<td>' . h($val) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

try {
    $configFile = __DIR__ . '/config.php';
    if (!is_file($configFile)) {
        throw new RuntimeException('Не найден config.php рядом со скриптом: ' . $configFile);
    }
    require $configFile;

    if (!isset($dbConfig) || !is_array($dbConfig)) {
        throw new RuntimeException('В config.php не найден массив $dbConfig');
    }

    $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    echo '<section><h2>1. Окружение</h2>';
    $env = [
        ['Параметр' => 'PHP', 'Значение' => PHP_VERSION],
        ['Параметр' => 'SAPI', 'Значение' => PHP_SAPI],
        ['Параметр' => 'Время сервера', 'Значение' => date('Y-m-d H:i:s')],
        ['Параметр' => 'Каталог', 'Значение' => __DIR__],
        ['Параметр' => 'База из config.php', 'Значение' => $dbConfig['dbname'] ?? ''],
        ['Параметр' => 'DATABASE()', 'Значение' => (string)$pdo->query('SELECT DATABASE()')->fetchColumn()],
        ['Параметр' => 'MySQL/MariaDB', 'Значение' => (string)$pdo->query('SELECT VERSION()')->fetchColumn()],
    ];
    printTable($env);
    echo '</section>';

    echo '<section><h2>2. Файлы рядом с диагностикой</h2>';
    $files = [];
    foreach (glob(__DIR__ . '/*.php') ?: [] as $file) {
        $base = basename($file);
        if (
            stripos($base, 'index22') !== false ||
            stripos($base, 'status') !== false ||
            stripos($base, 'route') !== false ||
            stripos($base, 'marsh') !== false
        ) {
            $files[] = [
                'Файл' => $base,
                'Размер' => filesize($file),
                'Изменён' => date('Y-m-d H:i:s', filemtime($file)),
                'SHA256' => hash_file('sha256', $file),
            ];
        }
    }
    printTable($files);
    echo '<p>Это позволит проверить, какой именно файл реально лежит на сервере.</p>';
    echo '</section>';

    echo '<section><h2>3. Структура status_blocks</h2>';
    $desc = $pdo->query('DESCRIBE status_blocks')->fetchAll();
    printTable($desc);
    echo '</section>';

    echo '<section><h2>4. Какие status_type реально существуют</h2>';
    $types = $pdo->query("
        SELECT status_type, COUNT(*) AS blocks_count,
               MIN(id) AS min_id, MAX(id) AS max_id
        FROM status_blocks
        GROUP BY status_type
        ORDER BY blocks_count DESC, status_type
    ")->fetchAll();
    printTable($types);
    echo '</section>';

    echo '<section><h2>5. Карта заявка → блоки для «Доступно» и «Маршрут»</h2>';

    $stmt = $pdo->query("
        SELECT id, status_type, zayavki_ids
        FROM status_blocks
        WHERE status_type IN ('dostupno', 'marshrut')
        ORDER BY id ASC
    ");
    $blocks = $stmt->fetchAll();

    $map = [
        'dostupno' => [],
        'marshrut' => [],
    ];
    $blockParsed = [];

    foreach ($blocks as $block) {
        $type = (string)$block['status_type'];
        $blockId = (int)$block['id'];
        $ids = parseIds($block['zayavki_ids'] ?? '');
        $blockParsed[] = [
            'block_id' => $blockId,
            'status_type' => $type,
            'parsed_count' => count($ids),
            'sample_ids' => implode(', ', array_slice($ids, 0, 25)) . (count($ids) > 25 ? ' ...' : ''),
        ];
        foreach ($ids as $zid) {
            $map[$type][$zid] ??= [];
            $map[$type][$zid][] = $blockId;
        }
    }

    foreach ($map as $type => &$items) {
        foreach ($items as &$ids) {
            $ids = array_values(array_unique(array_map('intval', $ids)));
            sort($ids, SORT_NUMERIC);
        }
        unset($ids);
    }
    unset($items);

    $problemIds = [];
    foreach (['dostupno', 'marshrut'] as $type) {
        foreach ($map[$type] as $zid => $ids) {
            if (count($ids) > 1) $problemIds[$zid] = true;
        }
    }

    $problems = [];
    foreach (array_keys($problemIds) as $zid) {
        $d = $map['dostupno'][$zid] ?? [];
        $m = $map['marshrut'][$zid] ?? [];
        $problems[] = [
            'Заявка' => $zid,
            'Доступно, блоки' => $d ? implode(', ', $d) : '—',
            'Кол-во доступно' => count($d),
            'Маршрут, блоки' => $m ? implode(', ', $m) : '—',
            'Кол-во маршрут' => count($m),
            'Проблема' => implode(' + ', array_filter([
                count($d) > 1 ? 'дубль Доступно' : '',
                count($m) > 1 ? 'дубль Маршрут' : '',
            ])),
        ];
    }

    usort($problems, fn($a, $b) => ((int)$a['Заявка']) <=> ((int)$b['Заявка']));

    echo '<p><strong>Всего блоков:</strong> ' . count($blocks) .
         ' &nbsp; <strong>Проблемных заявок:</strong> <span class="' .
         ($problems ? 'bad' : 'ok') . '">' . count($problems) . '</span></p>';
    printTable($problems);
    echo '</section>';

    echo '<section><h2>6. Отдельно дубли «Доступно»</h2>';
    $dupD = [];
    foreach ($map['dostupno'] as $zid => $ids) {
        if (count($ids) > 1) {
            $dupD[] = ['Заявка' => $zid, 'Блоки' => implode(', ', $ids), 'Количество' => count($ids)];
        }
    }
    printTable($dupD);
    echo '</section>';

    echo '<section><h2>7. Отдельно дубли «Маршрут»</h2>';
    $dupM = [];
    foreach ($map['marshrut'] as $zid => $ids) {
        if (count($ids) > 1) {
            $dupM[] = ['Заявка' => $zid, 'Блоки' => implode(', ', $ids), 'Количество' => count($ids)];
        }
    }
    printTable($dupM);
    echo '</section>';

    echo '<section><h2>8. Проверка наличия заявок в feo</h2>';
    $feoExists = (bool)$pdo->query("SHOW TABLES LIKE 'feo'")->fetchColumn();
    if (!$feoExists) {
        echo '<p class="warn">Таблица feo не найдена.</p>';
    } else {
        $missing = [];
        foreach (array_keys($problemIds) as $zid) {
            $q = $pdo->prepare('SELECT COUNT(*) FROM feo WHERE zayavka_id = ?');
            $q->execute([$zid]);
            if ((int)$q->fetchColumn() === 0) $missing[] = $zid;
        }
        echo '<p>Проблемных заявок, которых нет в <code>feo</code>: <strong>' . count($missing) . '</strong></p>';
        if ($missing) echo '<pre>' . h(implode(', ', $missing)) . '</pre>';
    }
    echo '</section>';

    echo '<section><h2>9. Сводка для отправки мне</h2>';
    $summary = [
        'php' => PHP_VERSION,
        'database' => (string)$pdo->query('SELECT DATABASE()')->fetchColumn(),
        'status_types' => $types,
        'blocks_selected' => count($blocks),
        'duplicate_dostupno_count' => count($dupD),
        'duplicate_marshrut_count' => count($dupM),
        'problem_count' => count($problems),
        'problems' => $problems,
        'candidate_files' => $files,
    ];
    echo '<p>Скопируйте весь JSON ниже и пришлите мне. Паролей БД здесь нет.</p>';
    echo '<pre id="diagJson">' . h(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>';
    echo '</section>';

} catch (Throwable $e) {
    echo '<section><h2 class="bad">Критическая ошибка диагностики</h2>';
    echo '<pre>' . h(get_class($e) . ': ' . $e->getMessage()) . "\n\n" . h($e->getTraceAsString()) . '</pre>';
    echo '</section>';
}

echo '</body></html>';
