<?php
session_start();
require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/common.php';
require_once dirname(__DIR__) . '/Support/warehouse_movements.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'Database connection error';
    exit;
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function whDate(?string $v): string {
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00') return '—';
    return date('d.m.Y', strtotime($v));
}

function whDateTime(?string $v): string {
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00 00:00:00') return '—';
    return date('d.m.Y H:i', strtotime($v));
}

function whWarehouseName(PDO $pdo, ?int $id): string {
    if (!$id || $id <= 0) return '—';
    static $cache = [];
    if (isset($cache[$id])) return $cache[$id];
    try {
        $stmt = $pdo->prepare('SELECT name FROM warehouses WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $cache[$id] = $row ? (string)$row['name'] : "ID#$id";
    } catch (Throwable $e) {
        $cache[$id] = "ID#$id";
    }
    return $cache[$id];
}

function whRouteTypeLabel(?string $rt): string {
    $map = [
        ROUTE_TYPE_GENERATOR_TO_UTILIZER => 'ОО → Утилизатор',
        ROUTE_TYPE_GENERATOR_TO_WAREHOUSE => 'ОО → Временный склад',
        ROUTE_TYPE_WAREHOUSE_TO_WAREHOUSE => 'Склад → Склад',
        ROUTE_TYPE_WAREHOUSE_TO_UTILIZER => 'Склад → Утилизатор',
        ROUTE_TYPE_MIXED_TO_UTILIZER => 'ОО + Склад → Утилизатор',
    ];
    return $map[$rt ?? ''] ?? ($rt ?? '—');
}

function whStatusLabel(string $s): string {
    $map = [
        'planned_route' => 'Планируемый',
        'found' => 'Сформирован',
        'started' => 'Вывоз начался',
        'completed' => 'Груз сдан',
    ];
    return $map[$s] ?? $s;
}

function whUnloadTypeByRt(string $rt): string {
    return in_array($rt, [ROUTE_TYPE_GENERATOR_TO_UTILIZER, ROUTE_TYPE_WAREHOUSE_TO_UTILIZER, ROUTE_TYPE_MIXED_TO_UTILIZER], true) ? 'OO' : 'SKLAD';
}

function whLoadWarehouses(PDO $pdo): array {
    try {
        $stmt = $pdo->query('SELECT id, name FROM warehouses ORDER BY name ASC');
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        return [];
    }
}

function whCountWM(PDO $pdo, int $flightId): int {
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM warehouse_movements WHERE flight_id = :fid');
        $stmt->execute([':fid' => $flightId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function whLoadFeoRows(PDO $pdo, array $zayavkiIds): array {
    if (empty($zayavkiIds)) return [];
    try {
        $ph = implode(',', array_fill(0, count($zayavkiIds), '?'));
        $stmt = $pdo->prepare("SELECT zayavka_id, naim_otkhoda_fkko, mass_netto, mass_brutto, summarnyy_obem, mno_region, mno_adres_pogruzki FROM feo WHERE zayavka_id IN ({$ph})");
        $stmt->execute($zayavkiIds);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function whLoadWMRows(PDO $pdo, int $flightId): array {
    try {
        $stmt = $pdo->prepare('SELECT * FROM warehouse_movements WHERE flight_id = :fid ORDER BY id');
        $stmt->execute([':fid' => $flightId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

// ── State ────────────────────────────────────────────────────────────────────

$flash = '';
$flashType = 'ok';
$flight = null;
$preview = null;
$wmResult = null;
$wmRowsAfter = null;
$searchResults = [];
$allWarehouses = whLoadWarehouses($pdo);

// ── Auth ─────────────────────────────────────────────────────────────────────

if (!maxAdminIsAuthed() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)maxAdminPost('action') === 'login') {
    if (maxAdminPasswordConst() !== '' && hash_equals(maxAdminPasswordConst(), (string)maxAdminPost('password'))) {
        $_SESSION['max_admin_auth'] = 1;
        header('Location: warehouse_route_reclassifier.php');
        exit;
    }
    $flash = 'Неверный пароль';
    $flashType = 'err';
}

if (maxAdminIsAuthed() && (string)($_GET['logout'] ?? '') === '1') {
    unset($_SESSION['max_admin_auth']);
    session_destroy();
    header('Location: warehouse_route_reclassifier.php');
    exit;
}

// ── POST handlers ────────────────────────────────────────────────────────────

if (maxAdminIsAuthed() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)maxAdminPost('action');

    // ── DELETE FLIGHT SAFELY ────────────────────────────────────────────
    if ($action === 'delete_flight_safely') {
        $delFlightId = (int)maxAdminPost('flight_id', '0');
        $confirmed = maxAdminPost('confirm_delete', '0') === '1';
        $confirmText = trim((string)maxAdminPost('confirm_text', ''));
        if ($delFlightId <= 0) {
            $flash = 'Некорректный ID рейса';
            $flashType = 'err';
        } elseif (!$confirmed) {
            $flash = 'Подтвердите операцию чекбоксом';
            $flashType = 'err';
        } else {
            try {
                $stmt = $pdo->prepare('SELECT * FROM flights WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $delFlightId]);
                $delFlight = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!is_array($delFlight)) {
                    $flash = 'Рейс не найден';
                    $flashType = 'err';
                } else {
                    $comment = (string)($delFlight['comment'] ?? '');
                    $isTest = (stripos($comment, 'TEST') !== false || stripos($comment, 'ТЕСТ') !== false);
                    $requiredText = $isTest ? ('DELETE TEST ' . $delFlightId) : ('DELETE FLIGHT ' . $delFlightId);
                    if ($confirmText !== $requiredText) {
                        $flash = 'Текст подтверждения не совпадает. Ожидалось: «' . $requiredText . '»';
                        $flashType = 'err';
                    } else {
                        $wmCount = whCountWM($pdo, $delFlightId);
                        $pdo->prepare('DELETE FROM warehouse_movements WHERE flight_id = :id')->execute([':id' => $delFlightId]);
                        $pdo->prepare('DELETE FROM flights WHERE id = :id')->execute([':id' => $delFlightId]);
                        $wmAfter = (int)$pdo->query("SELECT COUNT(*) FROM warehouse_movements WHERE flight_id = {$delFlightId}")->fetchColumn();
                        $flAfter = (int)$pdo->query("SELECT COUNT(*) FROM flights WHERE id = {$delFlightId}")->fetchColumn();
                        $flash = "Рейс #{$delFlightId} удалён. Складских движений удалено: {$wmCount}. Проверка: WM={$wmAfter}, FL={$flAfter}.";
                        $flashType = 'ok';
                        $flight = null;
                    }
                }
            } catch (Throwable $e) {
                $flash = 'Ошибка удаления: ' . $e->getMessage();
                $flashType = 'err';
            }
        }
    }

    // ── OPEN BY ID ──────────────────────────────────────────────────────
    elseif ($action === 'open') {
        $flightId = (int)maxAdminPost('flight_id', '0');
        if ($flightId <= 0) {
            $flash = 'Введите корректный ID рейса';
            $flashType = 'err';
        } else {
            try {
                $stmt = $pdo->prepare('SELECT * FROM flights WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $flightId]);
                $flight = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!is_array($flight)) {
                    $flash = 'Рейс #' . $flightId . ' не найден';
                    $flashType = 'err';
                }
            } catch (Throwable $e) {
                $flash = 'Ошибка: ' . $e->getMessage();
                $flashType = 'err';
            }
        }
    }

    // ── SEARCH ──────────────────────────────────────────────────────────
    elseif ($action === 'search') {
        $searchId = (int)maxAdminPost('search_id', '0');
        $searchStatus = (string)maxAdminPost('search_status', '');
        $limit = (int)maxAdminPost('search_limit', '200');
        if ($limit < 1) $limit = 200;
        if ($limit > 1000) $limit = 1000;

        $where = ['1=1'];
        $params = [];
        if ($searchId > 0) {
            $where[] = 'f.id = :sid';
            $params[':sid'] = $searchId;
        }
        if ($searchStatus !== '') {
            $where[] = 'f.status = :sst';
            $params[':sst'] = $searchStatus;
        }

        try {
            $sql = 'SELECT f.id, f.status, f.route_type, f.unload_type, f.source_warehouse_id, f.destination_warehouse_id,
                           f.zayavki_ids, f.zayavki_count, f.total_mass_tonn,
                           f.planned_start_date, f.actual_start_date, f.actual_end_date, f.comment
                    FROM flights f
                    WHERE ' . implode(' AND ', $where) . '
                    ORDER BY f.id DESC
                    LIMIT ' . $limit;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $flash = 'Ошибка поиска: ' . $e->getMessage();
            $flashType = 'err';
        }
    }

    // ── PREVIEW ─────────────────────────────────────────────────────────
    elseif ($action === 'preview') {
        $flightId = (int)maxAdminPost('flight_id', '0');
        $newRt = (string)maxAdminPost('new_route_type', '');
        $srcWh = (int)maxAdminPost('new_source_warehouse', '0');
        $dstWh = (int)maxAdminPost('new_destination_warehouse', '0');

        if ($flightId <= 0 || $newRt === '') {
            $flash = 'Заполните обязательные поля';
            $flashType = 'err';
        } else {
            $stmt = $pdo->prepare('SELECT * FROM flights WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $flightId]);
            $flight = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($flight)) {
                $flash = 'Рейс не найден';
                $flashType = 'err';
            } else {
                // Build preview
                $preview = ['rows' => [], 'total' => 0, 'will_create' => 0, 'already_exist' => 0];
                $zids = [];
                foreach (explode(',', (string)($flight['zayavki_ids'] ?? '')) as $id) {
                    $id = (int)trim($id);
                    if ($id > 0) $zids[] = $id;
                }
                if (!empty($zids)) {
                    try {
                        $ph = implode(',', array_fill(0, count($zids), '?'));
                        $stmtF = $pdo->prepare("SELECT zayavka_id, naim_otkhoda_fkko, mass_netto, mass_brutto, summarnyy_obem FROM feo WHERE zayavka_id IN ({$ph})");
                        $stmtF->execute($zids);
                        $feo = [];
                        while ($r = $stmtF->fetch(PDO::FETCH_ASSOC)) $feo[(int)$r['zayavka_id']] = $r;
                        foreach ($zids as $zid) {
                            $f = $feo[$zid] ?? null;
                            $rows = [];
                            if ($newRt === ROUTE_TYPE_GENERATOR_TO_WAREHOUSE && $dstWh > 0) $rows[] = [WM_MOVEMENT_RECEIPT, $dstWh];
                            elseif ($newRt === ROUTE_TYPE_WAREHOUSE_TO_UTILIZER && $srcWh > 0) $rows[] = [WM_MOVEMENT_ISSUE, $srcWh];
                            elseif ($newRt === ROUTE_TYPE_WAREHOUSE_TO_WAREHOUSE && $srcWh > 0 && $dstWh > 0) {
                                $rows[] = [WM_MOVEMENT_TRANSFER_OUT, $srcWh];
                                $rows[] = [WM_MOVEMENT_TRANSFER_IN, $dstWh];
                            }
                            foreach ($rows as [$mt, $wh]) {
                                $ex = wmMovementExists($pdo, $flightId, $mt, $wh, $zid);
                                $preview['rows'][] = [
                                    'movement_type' => $mt, 'warehouse_id' => $wh,
                                    'warehouse_name' => whWarehouseName($pdo, $wh),
                                    'zayavka_id' => $zid,
                                    'fkko_code' => $f ? (string)($f['naim_otkhoda_fkko'] ?? '') : '',
                                    'mass_netto' => $f ? round((float)($f['mass_netto'] ?? 0), 3) : 0,
                                    'mass_brutto' => $f ? round((float)($f['mass_brutto'] ?? 0), 3) : 0,
                                    'volume' => $f ? round((float)($f['summarnyy_obem'] ?? 0), 3) : 0,
                                    'exists' => $ex,
                                ];
                                $preview['total']++;
                                $ex ? $preview['already_exist']++ : $preview['will_create']++;
                            }
                        }
                    } catch (Throwable $e) {}
                }
            }
        }
    }

    // ── APPLY ───────────────────────────────────────────────────────────
    elseif ($action === 'apply') {
        $flightId = (int)maxAdminPost('flight_id', '0');
        $newRt = (string)maxAdminPost('new_route_type', '');
        $srcWh = (int)maxAdminPost('new_source_warehouse', '0');
        $dstWh = (int)maxAdminPost('new_destination_warehouse', '0');
        $confirmed = maxAdminPost('confirm_apply', '0') === '1';

        if (!$confirmed) {
            $flash = 'Подтвердите операцию';
            $flashType = 'err';
        } elseif ($flightId <= 0 || $newRt === '') {
            $flash = 'Заполните обязательные поля';
            $flashType = 'err';
        } else {
            try {
                $stmt = $pdo->prepare('SELECT * FROM flights WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $flightId]);
                $liveFlight = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!is_array($liveFlight)) {
                    $flash = 'Рейс не найден';
                    $flashType = 'err';
                } else {
                    $newUnload = whUnloadTypeByRt($newRt);
                    $upd = $pdo->prepare('UPDATE flights SET route_type = :rt, unload_type = :ut, source_warehouse_id = :sw, destination_warehouse_id = :dw WHERE id = :id');
                    $upd->execute([
                        ':rt' => $newRt, ':ut' => $newUnload,
                        ':sw' => $srcWh > 0 ? $srcWh : null, ':dw' => $dstWh > 0 ? $dstWh : null,
                        ':id' => $flightId,
                    ]);

                    $flash = "Рейс #{$flightId}: разметка обновлена.";
                    $flashType = 'ok';

                    if ((string)$liveFlight['status'] === 'completed' && $newRt !== ROUTE_TYPE_GENERATOR_TO_UTILIZER) {
                        $wmResult = createWarehouseMovementsForCompletedFlight($pdo, $flightId);
                        $wmRowsAfter = whLoadWMRows($pdo, $flightId);
                    }

                    $stmt->execute([':id' => $flightId]);
                    $flight = $stmt->fetch(PDO::FETCH_ASSOC);
                }
            } catch (Throwable $e) {
                $flash = 'Ошибка: ' . $e->getMessage();
                $flashType = 'err';
            }
        }
    }
}

$currentFlightId = isset($flight['id']) ? (int)$flight['id'] : (int)maxAdminPost('flight_id', '0');

// ── FEO & WM data for opened flight ──────────────────────────────────────────

$feoRows = [];
$wmRows = [];
$zayavkiIds = [];
if (is_array($flight)) {
    foreach (explode(',', (string)($flight['zayavki_ids'] ?? '')) as $id) {
        $id = (int)trim($id);
        if ($id > 0) $zayavkiIds[] = $id;
    }
    $feoRows = whLoadFeoRows($pdo, $zayavkiIds);
    $wmRows = whLoadWMRows($pdo, (int)$flight['id']);
}

?><!doctype html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Складская разметка рейсов</title>
<style>
body{margin:0;background:#f4f6f8;color:#1e293b;font-family:Arial,sans-serif}
.wrap{max-width:calc(100vw - 48px);margin:0 auto;padding:12px}
.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;gap:8px;flex-wrap:wrap}
.admin-nav{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.admin-nav .btn{padding:5px 10px;font-size:12px}
.admin-nav .admin-nav-active{background:#0ea5b7;color:#fff;border-color:#0b7285;font-weight:600}
.card{background:#fff;border:1px solid #d9e0e7;border-radius:8px;padding:14px;margin-bottom:10px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
label{font-size:13px;color:#334155}
input[type=text],input[type=number],select{border:1px solid #cbd5e1;border-radius:6px;padding:6px 8px;font-size:13px;background:#fff;color:#1e293b}
input[type=number],input[type=text]{width:120px}
.btn{border:1px solid #94a3b8;background:#eef2f7;padding:6px 12px;border-radius:6px;cursor:pointer;font-size:13px;height:32px;display:inline-flex;align-items:center;text-decoration:none;color:#1e293b}
.btn.primary{background:#0ea5b7;color:#fff;border-color:#0b7285}
.btn.success{background:#10b981;color:#fff;border-color:#059669}
.btn:disabled{opacity:.5;cursor:not-allowed}
.small{font-size:12px;color:#64748b}
.ok{background:#ecfdf3;border-color:#b7e4c7;color:#0f766e}
.err{background:#fef2f2;border-color:#fecaca;color:#b42318}
.warn-bg{background:#fffbeb;border-color:#fde68a;color:#92400e}
table{width:100%;border-collapse:collapse}
th,td{font-size:12px;border-bottom:1px solid #e2e8f0;padding:6px 8px;text-align:left;vertical-align:top}
th{background:#f8fafc;color:#475569;font-weight:600}
tr:hover{background:#f8fafc}
.mono{font-family:Consolas,monospace;font-size:12px}
.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600}
.badge-ok{background:#dcfce7;color:#166534}
.badge-warn{background:#fef3c7;color:#92400e}
.badge-info{background:#dbeafe;color:#1e40af}
.badge-blue{background:#dbeafe;color:#1e40af}
.hint{font-size:12px;color:#334155;background:#f8fafc;border-left:3px solid #0ea5b7;padding:8px 10px;border-radius:4px;margin:6px 0}
.field-group{margin-bottom:4px}
.field-group label{display:block;margin-bottom:1px;font-weight:600;font-size:12px;color:#64748b}
.field-group .value{font-size:14px}
.login{max-width:420px;margin:80px auto}
.section-title{font-size:15px;font-weight:600;color:#0f172a;margin:0 0 8px 0;padding-bottom:4px;border-bottom:2px solid #0ea5b7}
.table-wrap{max-height:600px;overflow-x:auto}
.zayavki-col{max-width:800px;min-width:360px;white-space:normal;overflow-wrap:anywhere;word-break:break-word;line-height:1.35}
.danger-zone{border:2px solid #fecaca;background:#fff5f5;border-radius:8px;padding:16px;margin-top:12px}
.danger-zone .section-title{color:#b42318;border-bottom-color:#fecaca}
.danger-zone .preview-table{width:100%;border-collapse:collapse;margin:8px 0}
.danger-zone .preview-table th,.danger-zone .preview-table td{font-size:11px;border-bottom:1px solid #fecaca;padding:4px 6px}
@media(max-width:900px){.grid,.grid-3{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">

<div class="top">
  <h2 style="margin:0;font-size:18px">Складская разметка рейсов</h2>
  <?php renderAdminNav('warehouse'); ?>
</div>

<?php if ($flash !== ''): ?>
<div class="card <?= $flashType === 'err' ? 'err' : ($flashType === 'warn' ? 'warn-bg' : 'ok') ?>"><?= maxAdminHtml($flash) ?></div>
<?php endif; ?>

<?php if (!maxAdminIsAuthed()): ?>
<div class="card login">
  <form method="post">
    <input type="hidden" name="action" value="login">
    <label>Пароль доступа</label>
    <input type="password" name="password" required style="width:100%">
    <div style="height:8px"></div>
    <button class="btn primary" type="submit">Войти</button>
  </form>
</div>

<?php else: ?>

<!-- ── ID SEARCH ──────────────────────────────────────────────────────────── -->
<div class="card">
  <form method="post">
    <input type="hidden" name="action" value="open">
    <div class="row">
      <label><strong>ID рейса</strong></label>
      <input type="number" name="flight_id" value="<?= $currentFlightId > 0 ? $currentFlightId : '' ?>" placeholder="например 187" min="1">
      <button class="btn primary" type="submit">Открыть рейс</button>
    </div>
  </form>
</div>

<!-- ── ALL ROUTES TABLE ───────────────────────────────────────────────────── -->
<div class="card">
  <div class="section-title">Все рейсы</div>
  <form method="post">
    <input type="hidden" name="action" value="search">
    <div class="row" style="margin-bottom:8px">
      <label>ID</label>
      <input type="number" name="search_id" value="<?= maxAdminHtml(maxAdminPost('search_id', '')) ?>" placeholder="ID" style="width:80px">
      <label>Статус</label>
      <select name="search_status" style="width:160px">
        <option value="">— Все —</option>
        <option value="planned_route" <?= maxAdminPost('search_status') === 'planned_route' ? 'selected' : '' ?>>Планируемый</option>
        <option value="found" <?= maxAdminPost('search_status') === 'found' ? 'selected' : '' ?>>Сформирован</option>
        <option value="started" <?= maxAdminPost('search_status') === 'started' ? 'selected' : '' ?>>Вывоз начался</option>
        <option value="completed" <?= maxAdminPost('search_status') === 'completed' ? 'selected' : '' ?>>Груз сдан</option>
      </select>
      <label>Лимит</label>
      <select name="search_limit" style="width:100px">
        <option value="100" <?= maxAdminPost('search_limit', '200') === '100' ? 'selected' : '' ?>>100</option>
        <option value="200" <?= maxAdminPost('search_limit', '200') === '200' ? 'selected' : '' ?>>200</option>
        <option value="500" <?= maxAdminPost('search_limit', '200') === '500' ? 'selected' : '' ?>>500</option>
      </select>
      <button class="btn primary" type="submit">Показать</button>
    </div>
  </form>

  <?php if (!empty($searchResults)): ?>
  <div class="table-wrap">
  <table>
    <thead><tr>
      <th>ID</th><th>Статус</th><th>Маршрут груза</th><th class="mono">unload</th>
      <th>Склад отпр.</th><th>Склад назн.</th><th>Заявки</th><th>Масса, т</th>
      <th>План</th><th>Факт нач.</th><th>Факт кон.</th><th>WM</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($searchResults as $row):
      $rt = (string)($row['route_type'] ?? '');
      $wmCnt = whCountWM($pdo, (int)$row['id']);
    ?>
      <tr>
        <td class="mono">#<?= (int)$row['id'] ?></td>
        <td><span class="badge badge-<?= $row['status'] === 'completed' ? 'ok' : 'info' ?>"><?= whStatusLabel((string)($row['status'] ?? '')) ?></span></td>
        <td><?= whRouteTypeLabel($rt) ?></td>
        <td class="mono" style="font-size:10px;color:#94a3b8"><?= maxAdminHtml($row['unload_type'] ?? '') ?></td>
        <td><?= maxAdminHtml(whWarehouseName($pdo, (int)($row['source_warehouse_id'] ?? 0))) ?></td>
        <td><?= maxAdminHtml(whWarehouseName($pdo, (int)($row['destination_warehouse_id'] ?? 0))) ?></td>
        <td class="zayavki-col"><?= maxAdminHtml($row['zayavki_ids'] ?? '') ?> (<?= (int)($row['zayavki_count'] ?? 0) ?>)</td>
        <td><?= round((float)($row['total_mass_tonn'] ?? 0), 3) ?></td>
        <td><?= whDate($row['planned_start_date'] ?? null) ?></td>
        <td><?= whDate($row['actual_start_date'] ?? null) ?></td>
        <td><?= whDate($row['actual_end_date'] ?? null) ?></td>
        <td><span class="badge badge-<?= $wmCnt > 0 ? 'ok' : 'warn' ?>"><?= $wmCnt ?></span></td>
        <td>
          <form method="post" style="display:inline">
            <input type="hidden" name="action" value="open">
            <input type="hidden" name="flight_id" value="<?= (int)$row['id'] ?>">
            <button class="btn" type="submit" style="height:26px;font-size:11px;padding:2px 8px">Открыть</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && maxAdminPost('action') === 'search'): ?>
  <p class="small">Ничего не найдено.</p>
  <?php else: ?>
  <p class="small">Нажмите «Показать» для загрузки списка рейсов.</p>
  <?php endif; ?>
</div>

<!-- ── FLIGHT CARD ───────────────────────────────────────────────────────── -->
<?php if (is_array($flight)):
  $currRt = (string)($flight['route_type'] ?? '');
  $currSrc = (int)($flight['source_warehouse_id'] ?? 0);
  $currDst = (int)($flight['destination_warehouse_id'] ?? 0);
  $currSt = (string)($flight['status'] ?? '');
  $isCompleted = $currSt === 'completed';
?>
<div class="card">
  <div class="section-title">Рейс #<?= (int)$flight['id'] ?> — <?= maxAdminHtml(whStatusLabel($currSt)) ?></div>
  <div class="grid-3">
    <div class="field-group"><label>Статус</label><div class="value"><span class="badge badge-<?= $isCompleted ? 'ok' : 'info' ?>"><?= whStatusLabel($currSt) ?></span></div></div>
    <div class="field-group"><label>Маршрут груза</label><div class="value"><span class="badge badge-blue"><?= whRouteTypeLabel($currRt) ?></span></div></div>
    <div class="field-group"><label>unload_type</label><div class="value mono" style="font-size:11px;color:#94a3b8"><?= maxAdminHtml($flight['unload_type'] ?? '—') ?></div></div>
    <div class="field-group"><label>Склад отправления</label><div class="value"><?= maxAdminHtml(whWarehouseName($pdo, $currSrc)) ?></div></div>
    <div class="field-group"><label>Склад назначения</label><div class="value"><?= maxAdminHtml(whWarehouseName($pdo, $currDst)) ?></div></div>
    <div class="field-group"><label>Заявки</label><div class="value mono"><?= maxAdminHtml($flight['zayavki_ids'] ?? '—') ?> (<?= (int)($flight['zayavki_count'] ?? 0) ?> шт.)</div></div>
    <div class="field-group"><label>Масса, т</label><div class="value"><?= round((float)($flight['total_mass_tonn'] ?? 0), 3) ?></div></div>
    <div class="field-group"><label>План. начало</label><div class="value"><?= whDate($flight['planned_start_date'] ?? null) ?></div></div>
    <div class="field-group"><label>План. от</label><div class="value"><?= whDate($flight['planned_start_date_from'] ?? null) ?></div></div>
    <div class="field-group"><label>План. до</label><div class="value"><?= whDate($flight['planned_start_date_to'] ?? null) ?></div></div>
    <div class="field-group"><label>Факт. начало</label><div class="value"><?= whDate($flight['actual_start_date'] ?? null) ?></div></div>
    <div class="field-group"><label>Факт. конец</label><div class="value"><?= whDate($flight['actual_end_date'] ?? null) ?></div></div>
    <div class="field-group"><label>Менеджер</label><div class="value"><?= (int)($flight['assigned_manager_id'] ?? 0) > 0 ? 'ID ' . (int)$flight['assigned_manager_id'] : '—' ?></div></div>
    <div class="field-group"><label>Водитель</label><div class="value"><?= (int)($flight['driver_id'] ?? 0) > 0 ? 'ID ' . (int)$flight['driver_id'] : '—' ?></div></div>
    <div class="field-group"><label>Подрядчик</label><div class="value"><?= (int)($flight['contractor_id'] ?? 0) > 0 ? 'ID ' . (int)$flight['contractor_id'] : '—' ?></div></div>
    <div class="field-group"><label>Стоимость</label><div class="value"><?= round((float)($flight['cost'] ?? 0), 2) ?> ₽</div></div>
    <div class="field-group"><label>Комментарий</label><div class="value"><?= maxAdminHtml($flight['comment'] ?? '—') ?></div></div>
    <div class="field-group"><label>Block date</label><div class="value mono"><?= maxAdminHtml($flight['block_date'] ?? '—') ?></div></div>
  </div>
</div>

<!-- ── FEO DATA ──────────────────────────────────────────────────────────── -->
<?php if (!empty($feoRows)): ?>
<div class="card">
  <div class="section-title">Данные заявок (ФЭО)</div>
  <table>
    <thead><tr><th>Заявка ID</th><th>ФККО</th><th>Нетто, т</th><th>Брутто, т</th><th>Объём, м³</th><th>Регион</th><th>Адрес погрузки</th></tr></thead>
    <tbody>
    <?php foreach ($feoRows as $fr): ?>
      <tr>
        <td class="mono"><?= (int)($fr['zayavka_id'] ?? 0) ?></td>
        <td class="mono"><?= maxAdminHtml($fr['naim_otkhoda_fkko'] ?? '') ?></td>
        <td><?= round((float)($fr['mass_netto'] ?? 0), 3) ?></td>
        <td><?= round((float)($fr['mass_brutto'] ?? 0), 3) ?></td>
        <td><?= round((float)($fr['summarnyy_obem'] ?? 0), 3) ?></td>
        <td><?= maxAdminHtml($fr['mno_region'] ?? '—') ?></td>
        <td><?= maxAdminHtml($fr['mno_adres_pogruzki'] ?? '—') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- ── WM DATA ───────────────────────────────────────────────────────────── -->
<?php if (!empty($wmRows)): ?>
<div class="card">
  <div class="section-title">Складские движения (warehouse_movements)</div>
  <table>
    <thead><tr><th>ID</th><th>Тип</th><th>Склад</th><th>Отпр.</th><th>Назн.</th><th>Заявка</th><th>ФККО</th><th>Нетто</th><th>Брутто</th><th>Объём</th><th>Статус</th><th>Создан</th></tr></thead>
    <tbody>
    <?php foreach ($wmRows as $wr): ?>
      <tr>
        <td class="mono"><?= (int)($wr['id'] ?? 0) ?></td>
        <td><span class="badge badge-blue"><?= $wr['movement_type'] === 'receipt' ? 'Приход' : ($wr['movement_type'] === 'issue' ? 'Расход' : ($wr['movement_type'] === 'transfer_out' ? 'Отправка' : 'Приёмка')) ?></span></td>
        <td><?= maxAdminHtml(whWarehouseName($pdo, (int)($wr['warehouse_id'] ?? 0))) ?></td>
        <td><?= maxAdminHtml(whWarehouseName($pdo, (int)($wr['source_warehouse_id'] ?? 0))) ?></td>
        <td><?= maxAdminHtml(whWarehouseName($pdo, (int)($wr['destination_warehouse_id'] ?? 0))) ?></td>
        <td class="mono"><?= (int)($wr['zayavka_id'] ?? 0) ?></td>
        <td class="mono"><?= maxAdminHtml($wr['fkko_code'] ?? '') ?></td>
        <td><?= round((float)($wr['mass_netto'] ?? 0), 3) ?></td>
        <td><?= round((float)($wr['mass_brutto'] ?? 0), 3) ?></td>
        <td><?= round((float)($wr['volume'] ?? 0), 3) ?></td>
        <td><?= maxAdminHtml($wr['status'] ?? '') ?></td>
        <td><?= whDateTime($wr['created_at'] ?? null) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- ── RECLASSIFICATION FORM ──────────────────────────────────────────────── -->
<div class="grid">
  <div class="card">
    <div class="section-title">Изменить складскую разметку</div>

    <!-- PREVIEW -->
    <form method="post" style="margin-bottom:12px">
      <input type="hidden" name="action" value="preview">
      <input type="hidden" name="flight_id" value="<?= (int)$flight['id'] ?>">

      <div class="field-group">
        <label>Маршрут груза <span style="color:#ef4444">*</span></label>
        <select name="new_route_type" id="pv_rt" onchange="whToggle('pv')" style="width:100%">
          <option value="">— Выберите —</option>
          <option value="<?= ROUTE_TYPE_GENERATOR_TO_UTILIZER ?>">ОО → Утилизатор</option>
          <option value="<?= ROUTE_TYPE_GENERATOR_TO_WAREHOUSE ?>">ОО → Временный склад</option>
          <option value="<?= ROUTE_TYPE_WAREHOUSE_TO_WAREHOUSE ?>">Склад → Склад</option>
          <option value="<?= ROUTE_TYPE_WAREHOUSE_TO_UTILIZER ?>">Склад → Утилизатор</option>
        </select>
      </div>

      <div class="grid">
        <div class="field-group" id="pv_src_grp" style="display:none">
          <label>Склад отправления</label>
          <select name="new_source_warehouse" style="width:100%">
            <option value="0">— Не выбран —</option>
            <?php foreach ($allWarehouses as $wh): ?>
            <option value="<?= (int)$wh['id'] ?>"><?= maxAdminHtml($wh['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field-group" id="pv_dst_grp" style="display:none">
          <label>Склад назначения</label>
          <select name="new_destination_warehouse" style="width:100%">
            <option value="0">— Не выбран —</option>
            <?php foreach ($allWarehouses as $wh): ?>
            <option value="<?= (int)$wh['id'] ?>"><?= maxAdminHtml($wh['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <button class="btn primary" type="submit">Предпросмотр</button>
      <span class="small">Показывает ожидаемые складские движения</span>
    </form>

    <!-- APPLY -->
    <form method="post" onsubmit="return confirm('Применить изменения разметки? Это действие нельзя отменить.')">
      <input type="hidden" name="action" value="apply">
      <input type="hidden" name="flight_id" value="<?= (int)$flight['id'] ?>">

      <div class="field-group">
        <label>Маршрут груза <span style="color:#ef4444">*</span></label>
        <select name="new_route_type" id="ap_rt" onchange="whToggle('ap')" style="width:100%">
          <option value="">— Выберите —</option>
          <option value="<?= ROUTE_TYPE_GENERATOR_TO_UTILIZER ?>">ОО → Утилизатор</option>
          <option value="<?= ROUTE_TYPE_GENERATOR_TO_WAREHOUSE ?>">ОО → Временный склад</option>
          <option value="<?= ROUTE_TYPE_WAREHOUSE_TO_WAREHOUSE ?>">Склад → Склад</option>
          <option value="<?= ROUTE_TYPE_WAREHOUSE_TO_UTILIZER ?>">Склад → Утилизатор</option>
        </select>
      </div>

      <div class="grid">
        <div class="field-group" id="ap_src_grp" style="display:none">
          <label>Склад отправления</label>
          <select name="new_source_warehouse" style="width:100%">
            <option value="0">— Не выбран —</option>
            <?php foreach ($allWarehouses as $wh): ?>
            <option value="<?= (int)$wh['id'] ?>"><?= maxAdminHtml($wh['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field-group" id="ap_dst_grp" style="display:none">
          <label>Склад назначения</label>
          <select name="new_destination_warehouse" style="width:100%">
            <option value="0">— Не выбран —</option>
            <?php foreach ($allWarehouses as $wh): ?>
            <option value="<?= (int)$wh['id'] ?>"><?= maxAdminHtml($wh['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div style="height:6px"></div>
      <div class="row">
        <label><input type="checkbox" name="confirm_apply" value="1" required> Подтверждаю изменение разметки рейса #<?= (int)$flight['id'] ?></label>
      </div>
      <div style="height:8px"></div>
      <button class="btn success" type="submit">Применить</button>
      <?php if ($isCompleted): ?>
      <span class="small">Рейс завершён. После применения будут созданы складские движения.</span>
      <?php else: ?>
      <span class="small">Рейс ещё не завершён. Складские движения будут созданы при завершении рейса.</span>
      <?php endif; ?>
    </form>
  </div>

  <!-- PREVIEW RESULT -->
  <?php if (is_array($preview) && $preview['total'] > 0): ?>
  <div class="card <?= $preview['already_exist'] > 0 ? 'warn-bg' : 'ok' ?>">
    <div class="section-title">Предпросмотр складских движений</div>
    <p class="small">Всего: <strong><?= $preview['total'] ?></strong> | Будет создано: <strong><?= $preview['will_create'] ?></strong> | Уже есть: <strong><?= $preview['already_exist'] ?></strong></p>
    <table>
      <thead><tr><th>Тип</th><th>Склад</th><th>Заявка</th><th>ФККО</th><th>Нетто</th><th>Брутто</th><th>Объём</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($preview['rows'] as $pr): ?>
        <tr>
          <td><span class="badge badge-blue"><?= $pr['movement_type'] === 'receipt' ? 'Приход' : ($pr['movement_type'] === 'issue' ? 'Расход' : ($pr['movement_type'] === 'transfer_out' ? 'Отправка' : 'Приёмка')) ?></span></td>
          <td><?= maxAdminHtml($pr['warehouse_name']) ?></td>
          <td class="mono"><?= (int)$pr['zayavka_id'] ?></td>
          <td class="mono"><?= maxAdminHtml($pr['fkko_code']) ?></td>
          <td><?= $pr['mass_netto'] ?></td>
          <td><?= $pr['mass_brutto'] ?></td>
          <td><?= $pr['volume'] ?></td>
          <td><?= $pr['exists'] ? '<span class="badge badge-warn">уже есть</span>' : '<span class="badge badge-ok">создать</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php elseif (is_array($preview) && $preview['total'] === 0): ?>
  <div class="card warn-bg">Для выбранного типа маршрута складские движения не требуются.</div>
  <?php endif; ?>
</div>

<!-- ── WM RESULT AFTER APPLY ──────────────────────────────────────────────── -->
<?php if (is_array($wmResult)): ?>
<div class="card <?= $wmResult['success'] ? 'ok' : 'err' ?>">
  <div class="section-title">Результат создания складских движений</div>
  <p>Создано: <strong><?= $wmResult['created'] ?></strong> | Пропущено: <strong><?= $wmResult['skipped'] ?></strong></p>
  <?php if (!empty($wmResult['messages'])): ?><p class="small"><?= maxAdminHtml(implode('; ', $wmResult['messages'])) ?></p><?php endif; ?>
  <?php if (!empty($wmResult['errors'])): ?><p class="small" style="color:#b42318"><?= maxAdminHtml(implode('; ', $wmResult['errors'])) ?></p><?php endif; ?>
  <?php if (is_array($wmRowsAfter) && count($wmRowsAfter) > 0): ?>
  <p class="small">Строк в warehouse_movements для рейса #<?= (int)$flight['id'] ?>: <strong><?= count($wmRowsAfter) ?></strong></p>
  <table>
    <thead><tr><th>ID</th><th>Тип</th><th>Склад</th><th>Заявка</th><th>ФККО</th><th>Нетто</th><th>Брутто</th><th>Объём</th><th>Дата</th></tr></thead>
    <tbody>
    <?php foreach ($wmRowsAfter as $wr): ?>
      <tr>
        <td class="mono"><?= (int)$wr['id'] ?></td>
        <td><?= $wr['movement_type'] === 'receipt' ? 'Приход' : ($wr['movement_type'] === 'issue' ? 'Расход' : ($wr['movement_type'] === 'transfer_out' ? 'Отправка' : 'Приёмка')) ?></td>
        <td><?= maxAdminHtml(whWarehouseName($pdo, (int)($wr['warehouse_id'] ?? 0))) ?></td>
        <td class="mono"><?= (int)($wr['zayavka_id'] ?? 0) ?></td>
        <td class="mono"><?= maxAdminHtml($wr['fkko_code'] ?? '') ?></td>
        <td><?= $wr['mass_netto'] ?? '—' ?></td>
        <td><?= $wr['mass_brutto'] ?? '—' ?></td>
        <td><?= $wr['volume'] ?? '—' ?></td>
        <td><?= whDate($wr['movement_date'] ?? null) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php endif; // flight ?>

<!-- ── DANGER ZONE ───────────────────────────────────────────────────────── -->
<?php if (is_array($flight)):
  $delFlightId = (int)$flight['id'];
  $delComment = (string)($flight['comment'] ?? '');
  $delIsTest = (stripos($delComment, 'TEST') !== false || stripos($delComment, 'ТЕСТ') !== false);
  $delRequiredText = $delIsTest ? ('DELETE TEST ' . $delFlightId) : ('DELETE FLIGHT ' . $delFlightId);
  $delWmCount = whCountWM($pdo, $delFlightId);
  $delWmRows = whLoadWMRows($pdo, $delFlightId);
?>
<div class="danger-zone">
  <div class="section-title">Опасная зона</div>
  <p class="small">Удаление рейса и ВСЕХ его складских движений. Операция необратима.</p>
  <table class="preview-table">
    <tr><td><strong>ID рейса</strong></td><td>#<?= $delFlightId ?></td></tr>
    <tr><td><strong>Статус</strong></td><td><?= whStatusLabel((string)($flight['status'] ?? '')) ?></td></tr>
    <tr><td><strong>Маршрут груза</strong></td><td><?= whRouteTypeLabel((string)($flight['route_type'] ?? '')) ?></td></tr>
    <tr><td><strong>Склад отпр.</strong></td><td><?= maxAdminHtml(whWarehouseName($pdo, (int)($flight['source_warehouse_id'] ?? 0))) ?></td></tr>
    <tr><td><strong>Склад назн.</strong></td><td><?= maxAdminHtml(whWarehouseName($pdo, (int)($flight['destination_warehouse_id'] ?? 0))) ?></td></tr>
    <tr><td><strong>Заявок</strong></td><td><?= (int)($flight['zayavki_count'] ?? 0) ?></td></tr>
    <tr><td><strong>Складских движений (будут удалены)</strong></td><td><?= $delWmCount ?></td></tr>
  </table>
  <?php if (!empty($delWmRows)): ?>
  <p class="small">Складские движения к удалению:</p>
  <table class="preview-table">
    <tr><th>ID</th><th>Тип</th><th>Склад</th><th>Заявка</th><th>Нетто</th></tr>
    <?php foreach ($delWmRows as $dwr): ?>
    <tr>
      <td><?= (int)$dwr['id'] ?></td>
      <td><?= $dwr['movement_type'] === 'receipt' ? 'Приход' : ($dwr['movement_type'] === 'issue' ? 'Расход' : ($dwr['movement_type'] === 'transfer_out' ? 'Отправка' : 'Приёмка')) ?></td>
      <td><?= maxAdminHtml(whWarehouseName($pdo, (int)($dwr['warehouse_id'] ?? 0))) ?></td>
      <td><?= (int)($dwr['zayavka_id'] ?? 0) ?></td>
      <td><?= round((float)($dwr['mass_netto'] ?? 0), 3) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
  <?php if (!$delIsTest): ?>
  <div class="card err" style="margin:8px 0;font-weight:600">ВНИМАНИЕ: Рейс НЕ тестовый. Удаление рабочего рейса! Подтверждение обязательно.</div>
  <?php endif; ?>
  <p class="small">Для подтверждения введите: <code><?= maxAdminHtml($delRequiredText) ?></code> и отметьте чекбокс.</p>
  <form method="post" onsubmit="return confirm('Удалить рейс #<?= $delFlightId ?> безвозвратно?')">
    <input type="hidden" name="action" value="delete_flight_safely">
    <input type="hidden" name="flight_id" value="<?= $delFlightId ?>">
    <div class="row">
      <input type="text" name="confirm_text" placeholder="<?= maxAdminHtml($delRequiredText) ?>" style="width:280px" required>
      <label><input type="checkbox" name="confirm_delete" value="1" required> Подтверждаю удаление</label>
      <button class="btn" type="submit" style="background:#fee2e2;border-color:#fca5a5;color:#b42318;font-weight:600">Удалить рейс и складские движения</button>
    </div>
  </form>
</div>
<?php endif; ?>

<?php endif; // authed ?>

</div>

<script>
function whToggle(prefix) {
    var rt = document.getElementById(prefix + '_rt');
    var srcGrp = document.getElementById(prefix + '_src_grp');
    var dstGrp = document.getElementById(prefix + '_dst_grp');
    if (!rt || !srcGrp || !dstGrp) return;
    var v = rt.value;
    srcGrp.style.display = (v === 'warehouse_to_warehouse' || v === 'warehouse_to_utilizer') ? '' : 'none';
    dstGrp.style.display = (v === 'generator_to_warehouse' || v === 'warehouse_to_warehouse') ? '' : 'none';
}
</script>

</body>
</html>
