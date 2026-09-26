<?php
$Select = 'marshrut';
$Tittle = 'Формирование маршрутов и фиксирование стоимости';
$Stat = "ON";

/*
 * Локализованный контроль дублей для страницы.
 * ВАЖНО:
 * - не блокирует работу страницы;
 * - не показывает alert();
 * - ничего не изменяет в БД;
 * - красный блок выводится только при наличии проблем.
 *
 * Страница "Доступно":
 *   1) заявка более чем в одном блоке "Доступно";
 *   2) заявка одновременно в "Доступно" и "Маршрут".
 *
 * Страница "Маршруты":
 *   1) заявка более чем в одном блоке "Маршрут";
 *   2) заявка одновременно в "Маршрут" и "Доступно".
 */

ob_start();
include('mass_status.php');
$planexPageHtml = ob_get_clean();

function planexLocalizedDupParseIds($raw) {
    $parts = preg_split('/[,;\s]+/', (string)$raw, -1, PREG_SPLIT_NO_EMPTY);
    $result = [];

    foreach ($parts as $part) {
        $id = preg_replace('/[^0-9]/', '', trim((string)$part));
        if ($id !== '') {
            $result[$id] = true;
        }
    }

    return array_keys($result);
}

function planexLocalizedDupGetPdo() {
    global $pdo, $dbConfig;

    if (isset($pdo) && $pdo instanceof PDO) {
        return $pdo;
    }

    if (!isset($dbConfig) || !is_array($dbConfig)) {
        $configFile = __DIR__ . '/config.php';
        if (is_file($configFile)) {
            require_once $configFile;
        }
    }

    if (!isset($dbConfig) || !is_array($dbConfig)) {
        return null;
    }

    try {
        $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset=utf8mb4";
        $localPdo = new PDO(
            $dsn,
            $dbConfig['username'],
            $dbConfig['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
        return $localPdo;
    } catch (Throwable $e) {
        error_log('PLANEX localized duplicate check DB error: ' . $e->getMessage());
        return null;
    }
}

function planexLocalizedDupBuildProblems($scope) {
    $db = planexLocalizedDupGetPdo();
    if (!$db) {
        return [];
    }

    try {
        $stmt = $db->query("
            SELECT id, status_type, zayavki_ids
            FROM status_blocks
            WHERE status_type IN ('dostupno', 'marshrut')
            ORDER BY id ASC
        ");
        $blocks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('PLANEX localized duplicate check query error: ' . $e->getMessage());
        return [];
    }

    $maps = [
        'dostupno' => [],
        'marshrut' => [],
    ];

    foreach ($blocks as $block) {
        $type = (string)($block['status_type'] ?? '');
        if (!isset($maps[$type])) {
            continue;
        }

        $blockId = (int)($block['id'] ?? 0);
        foreach (planexLocalizedDupParseIds($block['zayavki_ids'] ?? '') as $zayavkaId) {
            if (!isset($maps[$type][$zayavkaId])) {
                $maps[$type][$zayavkaId] = [];
            }
            $maps[$type][$zayavkaId][] = $blockId;
        }
    }

    foreach ($maps as $type => $items) {
        foreach ($items as $zayavkaId => $blockIds) {
            $blockIds = array_values(array_unique(array_map('intval', $blockIds)));
            sort($blockIds, SORT_NUMERIC);
            $maps[$type][$zayavkaId] = $blockIds;
        }
    }

    $allIds = array_values(array_unique(array_merge(
        array_keys($maps['dostupno']),
        array_keys($maps['marshrut'])
    )));
    sort($allIds, SORT_NATURAL);

    $problems = [];

    foreach ($allIds as $zayavkaId) {
        $available = $maps['dostupno'][$zayavkaId] ?? [];
        $routes = $maps['marshrut'][$zayavkaId] ?? [];

        $duplicateAvailable = count($available) > 1;
        $duplicateRoute = count($routes) > 1;

        if ($scope === 'dostupno') {
            $isProblem = $duplicateAvailable;
        } else {
            $isProblem = $duplicateRoute;
        }

        if (!$isProblem) {
            continue;
        }

        $problems[] = [
            'zayavka_id' => $zayavkaId,
            'dostupno_blocks' => $available,
            'marshrut_blocks' => $routes,
            'duplicate_dostupno' => $duplicateAvailable,
            'duplicate_marshrut' => $duplicateRoute,
        ];
    }

    return $problems;
}

function planexLocalizedDupRender($scope, array $problems) {
    if (empty($problems)) {
        return '';
    }

    if ($scope === 'dostupno') {
        $title = '⚠ Ошибка: обнаружены дубли заявок в «Доступно» или одновременное нахождение заявки в «Доступно» и «Маршрут».';
    } else {
        $title = '⚠ Ошибка: одна или несколько заявок находятся более чем в одном блоке «Маршрут».';
    }

    $lines = [];

    foreach ($problems as $item) {
        $parts = [];

        $available = $item['dostupno_blocks'];
        $routes = $item['marshrut_blocks'];

        if ($scope === 'dostupno' && count($available) > 1) {
            $parts[] = 'Доступно: блоки ' . implode(', ', $available);
        }

        if ($scope === 'marshrut' && count($routes) > 1) {
            $parts[] = 'Маршрут: блоки ' . implode(', ', $routes);
        }

        $lines[] =
            '<div class="planex-local-duplicate-row">Заявка <strong>' .
            htmlspecialchars((string)$item['zayavka_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') .
            '</strong> — ' .
            htmlspecialchars(implode('; ', $parts), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') .
            '</div>';
    }

    return
        '<div id="planexLocalizedDuplicateNotice" class="planex-local-duplicate-notice">' .
            '<strong class="planex-local-duplicate-title">' .
                htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') .
            '</strong>' .
            implode('', $lines) .
        '</div>';
}

$planexLocalizedProblems = planexLocalizedDupBuildProblems($Select);
$planexLocalizedNotice = planexLocalizedDupRender($Select, $planexLocalizedProblems);

if ($planexLocalizedNotice !== '') {
    $planexLocalizedStyles = <<<'HTML'
<style id="planexLocalizedDuplicateStyles">
.planex-local-duplicate-notice {
    box-sizing: border-box;
    width: 100%;
    margin: 10px 0;
    padding: 12px 14px;
    border: 1px solid #dc3545;
    background: #f8d7da;
    color: #721c24;
    border-radius: 6px;
    font-size: 13px;
    line-height: 1.45;
}
.planex-local-duplicate-title {
    display: block;
    margin-bottom: 6px;
}
.planex-local-duplicate-row {
    margin-top: 3px;
}
.planex-local-duplicate-row strong {
    display: inline;
}
</style>
HTML;

    $planexMoveScript = <<<'HTML'
<script id="planexLocalizedDuplicateMoveScript">
(function () {
    function placePlanexLocalizedDuplicateNotice() {
        var notice = document.getElementById('planexLocalizedDuplicateNotice');
        if (!notice) return;

        /* Ставим блок непосредственно перед основной широкой таблицей страницы. */
        var tables = Array.prototype.slice.call(document.querySelectorAll('table'));
        var targetTable = null;

        for (var i = 0; i < tables.length; i++) {
            var rect = tables[i].getBoundingClientRect();
            if (rect.width >= 500) {
                targetTable = tables[i];
                break;
            }
        }

        if (targetTable) {
            var target = targetTable;
            var parent = targetTable.parentElement;

            /* Если таблица обёрнута в table-wrapper / responsive-контейнер — ставим перед ним. */
            if (parent && (
                parent.classList.contains('table-wrapper') ||
                parent.classList.contains('table-responsive') ||
                parent.classList.contains('table-container')
            )) {
                target = parent;
            }

            if (target.parentNode) {
                target.parentNode.insertBefore(notice, target);
                return;
            }
        }

        /* Fallback: после заголовка контентной области. */
        var headings = document.querySelectorAll('h1, h2, h3');
        if (headings.length > 0 && headings[0].parentNode) {
            headings[0].parentNode.insertBefore(notice, headings[0].nextSibling);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', placePlanexLocalizedDuplicateNotice);
    } else {
        placePlanexLocalizedDuplicateNotice();
    }
})();
</script>
HTML;

    /* Добавляем CSS в head, а сам блок+скрипт — перед закрывающим body. */
    if (stripos($planexPageHtml, '</head>') !== false) {
        $planexPageHtml = preg_replace(
            '/<\/head>/i',
            $planexLocalizedStyles . "\n</head>",
            $planexPageHtml,
            1
        );
    } else {
        $planexPageHtml = $planexLocalizedStyles . $planexPageHtml;
    }

    if (stripos($planexPageHtml, '</body>') !== false) {
        $planexPageHtml = preg_replace(
            '/<\/body>/i',
            $planexLocalizedNotice . "\n" . $planexMoveScript . "\n</body>",
            $planexPageHtml,
            1
        );
    } else {
        $planexPageHtml .= $planexLocalizedNotice . $planexMoveScript;
    }
}


/*
 * Дополнения страницы маршрутов.
 * Штатный механизм раскрытия из mass_status.php НЕ изменяется.
 */
$planexRouteSafeScript = <<<'HTML'
<script id="planexRouteSafeScript">
(function () {
    'use strict';

    var MIN_BLOCK_ID = 120;

    function norm(v) {
        return String(v == null ? '' : v)
            .replace(/\u00a0|\u202f/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function parseAmount(v) {
        var m = norm(v).match(/-?\d[\d\s]*(?:[.,]\d{1,2})?/);
        if (!m) return null;

        var n = Number(m[0].replace(/\s+/g, '').replace(',', '.'));
        return Number.isFinite(n) ? n : null;
    }

    function nearInteger(v) {
        return Math.abs(v - Math.round(v)) < 1e-9;
    }

    function backgroundFor(amount) {
        var v = Math.abs(amount);

        // 1. Оканчивается на 0000 -> голубой.
        if (nearInteger(v) && nearInteger(v / 10000)) {
            return '#dbeafe';
        }

        // 2. Делится без остатка на 34,5 или 39,5 -> зелёный.
        if (nearInteger((v * 2) / 69) || nearInteger((v * 2) / 79)) {
            return '#dcfce7';
        }

        // 3. Остальное -> красный.
        return '#fee2e2';
    }

    function findRouteTable() {
        var tables = document.querySelectorAll('table');

        for (var i = 0; i < tables.length; i++) {
            var headers = tables[i].querySelectorAll('thead th');
            if (!headers.length) continue;

            var hasId = false;
            var hasCost = false;

            for (var h = 0; h < headers.length; h++) {
                var title = norm(headers[h].textContent).toUpperCase();

                if (title === 'ID' || title.indexOf('ID ') === 0) {
                    hasId = true;
                }

                if (title.indexOf('СТОИМОСТ') !== -1) {
                    hasCost = true;
                }
            }

            if (hasId && hasCost) {
                return tables[i];
            }
        }

        return null;
    }

    function findCostColumn(table) {
        var headers = table.querySelectorAll('thead th');

        for (var i = 0; i < headers.length; i++) {
            if (norm(headers[i].textContent).toUpperCase().indexOf('СТОИМОСТ') !== -1) {
                return i;
            }
        }

        return -1;
    }

    function blockIdFromMainRow(row) {
        if (!row || !row.cells || !row.cells.length) return null;

        /*
         * Только основная строка маршрутного блока вида "#151".
         * Detail-строки раскрытия не подходят под этот шаблон.
         */
        var m = norm(row.cells[0].textContent).match(/^#\s*(\d+)/);
        if (!m) return null;

        var id = parseInt(m[1], 10);
        return Number.isFinite(id) ? id : null;
    }

    function priceTarget(cell) {
        if (!cell) return null;

        /*
         * Если внутри ячейки уже есть отдельный бокс стоимости — красим его.
         * DOM не меняем.
         */
        var nodes = cell.querySelectorAll('span, strong, b, div');

        for (var i = 0; i < nodes.length; i++) {
            var el = nodes[i];

            if (el.children.length === 0 && parseAmount(el.textContent) !== null) {
                return el;
            }
        }

        // Если отдельного бокса нет — меняем только фон самой ячейки.
        return cell;
    }

    function applyRoutePageRules() {
        var table = findRouteTable();
        if (!table || !table.tBodies || !table.tBodies.length) return;

        var costColumn = findCostColumn(table);
        var rows = Array.prototype.slice.call(table.tBodies[0].rows);

        rows.forEach(function (row) {
            var blockId = blockIdFromMainRow(row);

            /*
             * Detail-строки НЕ трогаем вообще.
             */
            if (blockId === null) return;

            /*
             * Скрываем только основную строку блока с ID < 120.
             * Никаких соседних строк не скрываем/не раскрываем.
             */
            if (blockId < MIN_BLOCK_ID) {
                row.style.display = 'none';
                return;
            }

            if (costColumn < 0 || row.cells.length <= costColumn) return;

            var cell = row.cells[costColumn];
            var amount = parseAmount(cell.textContent);
            if (amount === null) return;

            var target = priceTarget(cell);

            /*
             * МЕНЯЕМ ТОЛЬКО ЦВЕТ ФОНА.
             * Шрифт, размер, padding, border, color, line-height и т.д. не меняются.
             */
            target.style.backgroundColor = backgroundFor(amount);
        });
    }

    /*
     * Один запуск после загрузки.
     * Нет MutationObserver.
     * Нет обработчиков click.
     * Нет собственной логики collapse/expand.
     */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', applyRoutePageRules);
    } else {
        applyRoutePageRules();
    }
})();
</script>
HTML;

if (stripos($planexPageHtml, '</body>') !== false) {
    $planexPageHtml = preg_replace(
        '/<\/body>/i',
        $planexRouteSafeScript . "\n</body>",
        $planexPageHtml,
        1
    );
} else {
    $planexPageHtml .= $planexRouteSafeScript;
}

echo $planexPageHtml;
?>
