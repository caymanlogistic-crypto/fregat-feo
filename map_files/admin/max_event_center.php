<?php
session_start();
require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/common.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'Database connection error';
    exit;
}

$flash = '';
$flashType = 'ok';

if (!maxAdminIsAuthed() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)maxAdminPost('action') === 'login') {
    if (maxAdminPasswordConst() !== '' && hash_equals(maxAdminPasswordConst(), (string)maxAdminPost('password'))) {
        $_SESSION['max_admin_auth'] = 1;
        header('Location: max_event_center.php');
        exit;
    }
    $flash = 'Неверный пароль';
    $flashType = 'err';
}

if (maxAdminIsAuthed() && (string)($_GET['logout'] ?? '') === '1') {
    unset($_SESSION['max_admin_auth']);
    session_destroy();
    header('Location: max_event_center.php');
    exit;
}
?><!doctype html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MAX Event Center</title>
<style>
body{margin:0;background:#101820;color:#d9e2ec;font-family:Segoe UI,Arial,sans-serif}
.wrap{max-width:1380px;margin:0 auto;padding:12px}
.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;gap:8px}
.panel{background:#162331;border:1px solid #274056;border-radius:8px;padding:10px}
.grid-main{display:grid;grid-template-columns:260px 1fr;gap:10px}
.list{display:grid;gap:6px;max-height:70vh;overflow:auto}
.cat-title{font-size:12px;color:#8fb3c9;margin:6px 0 2px}
.event-item{padding:7px 8px;border:1px solid #2d4b62;border-radius:6px;cursor:pointer;background:#1a2a39}
.event-item.active{border-color:#4db6ac;background:#1e3746}
.editor{display:grid;gap:8px}
.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
input[type=text],input[type=time],select,textarea{width:100%;box-sizing:border-box;border:1px solid #35536b;background:#0f1a24;color:#e6edf3;border-radius:6px;padding:7px 8px;font-size:13px}
textarea{min-height:150px}
.btn{height:32px;border:1px solid #3f6079;background:#23384a;color:#e6edf3;border-radius:6px;padding:0 10px;cursor:pointer}
.btn.primary{background:#0f7f75;border-color:#0f9d90}
.btn.warn{background:#5b3b1e;border-color:#8c5a2b}
.btn:disabled{opacity:.6;cursor:not-allowed}
.small{font-size:12px;color:#96acbf}
.status{font-size:12px}
.sticky{position:sticky;bottom:0;background:#132334;border-top:1px solid #2f4b61;padding-top:8px}
.table{width:100%;border-collapse:collapse}
.table th,.table td{font-size:12px;padding:6px;border-bottom:1px solid #2f4b61;vertical-align:top;text-align:left}
.table th{color:#b8d2e5;background:#172738}
.mono{font-family:Consolas,monospace;white-space:pre-wrap}
.login{max-width:420px;margin:80px auto}
.linkbar{display:flex;gap:8px;align-items:center}
.render-box{background:#102030;border:1px dashed #39607b;border-radius:6px;padding:8px;min-height:70px;white-space:pre-wrap}
@media (max-width:1100px){.grid-main{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div class="linkbar">
      <h2 style="margin:0">MAX Event Center</h2>
    </div>
    <?php renderAdminNav('max_event_center'); ?>
  </div>

  <?php if ($flash !== ''): ?><div class="panel" style="border-color:<?= $flashType === 'err' ? '#a94f4f' : '#3e7d67' ?>"><?= maxAdminHtml($flash) ?></div><?php endif; ?>

  <?php if (!maxAdminIsAuthed()): ?>
    <div class="panel login">
      <form method="post">
        <input type="hidden" name="action" value="login">
        <label class="small">Пароль доступа</label>
        <input type="password" name="password" required>
        <div style="height:8px"></div>
        <button class="btn primary" type="submit">Войти</button>
      </form>
    </div>
  <?php else: ?>
  <div class="panel" style="margin-bottom:10px">
    <div class="row" style="justify-content:space-between">
      <div class="row">
        <strong>MAX уведомления</strong>
        <label><input id="rt-enabled" type="checkbox"> ВКЛ</label>
        <label><input id="rt-quiet" type="checkbox"> Не отправлять ночью</label>
        <span class="small">Время:</span>
        <input id="rt-start" type="time" value="22:00" style="width:100px">
        <span>→</span>
        <input id="rt-end" type="time" value="08:00" style="width:100px">
        <span class="small">Группа по умолчанию:</span>
        <select id="rt-group" style="min-width:280px"></select>
      </div>
      <div class="row">
        <button class="btn" id="btn-seed" type="button">Синхронизировать события</button>
        <button class="btn primary" id="btn-runtime" type="button">Сохранить runtime</button>
        <span class="status" id="runtime-status"></span>
      </div>
    </div>
  </div>

  <div class="grid-main">
    <div class="panel">
      <div class="small" style="margin-bottom:6px">Категории событий</div>
      <div class="list" id="event-list"></div>
    </div>

    <div class="panel editor">
      <div class="row" style="justify-content:space-between">
        <div>
          <div id="evt-title" style="font-weight:600">—</div>
          <div class="small" id="evt-when">—</div>
          <div class="small mono" id="evt-key">—</div>
        </div>
        <div class="row">
          <label><input id="evt-enabled" type="checkbox"> Включено</label>
          <label><input id="evt-quiet" type="checkbox"> Quiet hours</label>
          <select id="evt-group" style="min-width:220px"></select>
        </div>
      </div>
      <div class="row">
        <input id="evt-quiet-start" type="time" value="22:00" style="width:100px">
        <span>→</span>
        <input id="evt-quiet-end" type="time" value="08:00" style="width:100px">
      </div>
      <textarea id="evt-template" rows="8" placeholder="Шаблон сообщения"></textarea>
      <div class="small" id="evt-placeholders"></div>
      <div class="sticky row" style="justify-content:space-between">
        <div class="row">
          <button class="btn primary" id="btn-save-event" type="button">Сохранить событие</button>
          <button class="btn" id="btn-render" type="button">Показать render</button>
          <button class="btn" id="btn-test" type="button">Тест</button>
          <button class="btn warn" id="btn-restore" type="button">Restore default</button>
          <span class="status" id="event-status"></span>
        </div>
      </div>
      <div class="render-box" id="render-box">Render preview...</div>
    </div>
  </div>

  <div class="panel" style="margin-top:10px">
    <div class="row" style="justify-content:space-between">
      <strong>MAX Logs</strong>
      <div class="row">
        <select id="log-filter" style="width:170px">
          <option value="">Все</option>
          <option value="success">success</option>
          <option value="error">error</option>
          <option value="queued">queued</option>
        </select>
        <button class="btn" id="btn-refresh-logs" type="button">Обновить</button>
      </div>
    </div>
    <table class="table" id="log-table">
      <thead><tr><th>Время</th><th>Событие</th><th>Статус</th><th>Группа</th><th>Текст</th><th>Действия</th></tr></thead>
      <tbody></tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php if (maxAdminIsAuthed()): ?>
<script>
(() => {
  const endpoint = 'max_event_center_actions.php';
  const state = {events: [], groups: [], runtime: {}, selectedId: 0};

  const el = (id) => document.getElementById(id);
  const lockBtn = (btn, lock, text='') => {
    if (!btn) return;
    if (lock) {
      btn.dataset.original = btn.textContent;
      btn.disabled = true;
      btn.textContent = text || 'Выполняется...';
    } else {
      btn.disabled = false;
      if (btn.dataset.original) btn.textContent = btn.dataset.original;
    }
  };

  async function api(action, extra = {}, submitter = null) {
    lockBtn(submitter, true);
    const fd = new FormData();
    fd.set('action', action);
    Object.keys(extra).forEach((k) => fd.set(k, extra[k]));
    try {
      const res = await fetch(endpoint, {method: 'POST', body: fd, credentials: 'same-origin'});
      const data = await res.json();
      if (!data.success) throw new Error(data.error || 'Ошибка');
      return data;
    } finally {
      lockBtn(submitter, false);
    }
  }

  function groupedEvents() {
    const groups = {};
    state.events.forEach((evt) => {
      const cat = evt.category || 'system';
      if (!groups[cat]) groups[cat] = [];
      groups[cat].push(evt);
    });
    return groups;
  }

  function renderList() {
    const holder = el('event-list');
    const map = groupedEvents();
    const order = [
      ['routes', 'Рейсы'], ['drivers', 'Водители'], ['warehouses', 'Склады'], ['slitex', 'SLITEX'], ['system', 'Системные']
    ];
    holder.innerHTML = '';
    order.forEach(([key, title]) => {
      if (!map[key] || map[key].length === 0) return;
      const cap = document.createElement('div');
      cap.className = 'cat-title';
      cap.textContent = title;
      holder.appendChild(cap);
      map[key].forEach((evt) => {
        const item = document.createElement('div');
        item.className = 'event-item' + (evt.id === state.selectedId ? ' active' : '');
        item.textContent = evt.title || evt.event_key;
        item.onclick = () => { state.selectedId = evt.id; renderList(); renderEditor(); };
        holder.appendChild(item);
      });
    });
  }

  function fillGroupSelect(selectEl, selected) {
    if (!selectEl) return;
    selectEl.innerHTML = '<option value="0">Default runtime group</option>';
    state.groups.forEach((g) => {
      const opt = document.createElement('option');
      opt.value = String(g.id);
      opt.textContent = `${g.title} (${g.group_id})`;
      if (Number(selected) === Number(g.id)) opt.selected = true;
      selectEl.appendChild(opt);
    });
  }

  function currentEvent() {
    return state.events.find((e) => Number(e.id) === Number(state.selectedId)) || null;
  }

  function renderEditor() {
    const evt = currentEvent();
    if (!evt) return;
    el('evt-title').textContent = evt.title || evt.event_key;
    el('evt-when').textContent = evt.when_sent || evt.description || 'Описание отсутствует';
    el('evt-key').textContent = evt.event_key;
    el('evt-enabled').checked = Number(evt.is_enabled) === 1;
    el('evt-quiet').checked = Number(evt.quiet_hours_enabled) === 1;
    el('evt-quiet-start').value = evt.quiet_hours_start || state.runtime.quiet_hours_start || '22:00';
    el('evt-quiet-end').value = evt.quiet_hours_end || state.runtime.quiet_hours_end || '08:00';
    fillGroupSelect(el('evt-group'), evt.group_ref_id || 0);
    el('evt-template').value = evt.template_text || '';
    el('evt-placeholders').textContent = evt.placeholders
      ? `Плейсхолдеры: ${evt.placeholders}`
      : 'Плейсхолдеры: {route_id}, {route_title}, {planned_range}, {actual_range}, {driver}, {manager}, {route_type_line}, {warehouse_line}, {meta_line}, {requests_count}, {weight}, {cost}, {status_from}, {status_to}, {changed_fields_text}';
    el('render-box').textContent = 'Render preview...';
    el('event-status').textContent = '';
  }

  function renderRuntime() {
    const r = state.runtime || {};
    el('rt-enabled').checked = String(r.max_enabled || '1') === '1';
    el('rt-quiet').checked = String(r.quiet_hours_enabled || '0') === '1';
    el('rt-start').value = r.quiet_hours_start || '22:00';
    el('rt-end').value = r.quiet_hours_end || '08:00';
    fillGroupSelect(el('rt-group'), Number(r.default_group_id || 0));
  }

  function renderLogs(logs) {
    const tbody = el('log-table').querySelector('tbody');
    tbody.innerHTML = '';
    logs.forEach((log) => {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${(log.created_at || '').replace(/</g,'&lt;')}</td>
        <td class="mono">${(log.event_key || '').replace(/</g,'&lt;')}</td>
        <td>${(log.status || '').replace(/</g,'&lt;')}</td>
        <td class="mono">${(log.group_id || '').replace(/</g,'&lt;')}</td>
        <td class="mono">${(log.message_text || '').replace(/</g,'&lt;')}</td>
        <td><button class="btn" data-retry="${log.id}">Повторить</button></td>
      `;
      tbody.appendChild(tr);
    });
    tbody.querySelectorAll('button[data-retry]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        try {
          const data = await api('retry_log', {log_id: btn.dataset.retry}, btn);
          alert(data.message || 'Повторная отправка выполнена');
          await reloadState();
        } catch (e) {
          alert(e.message || 'Ошибка повтора');
        }
      });
    });
  }

  async function reloadState() {
    const filter = el('log-filter').value;
    const data = await api('load_state', {status_filter: filter});
    state.runtime = data.data.runtime || {};
    state.groups = data.data.groups || [];
    state.events = data.data.events || [];
    if (!state.selectedId && state.events.length > 0) state.selectedId = state.events[0].id;
    if (state.selectedId && !state.events.some((e) => Number(e.id) === Number(state.selectedId))) {
      state.selectedId = state.events.length > 0 ? state.events[0].id : 0;
    }
    renderRuntime();
    renderList();
    renderEditor();
    renderLogs(data.data.logs || []);
  }

  el('btn-runtime').addEventListener('click', async (e) => {
    const status = el('runtime-status');
    status.textContent = 'Сохранение...';
    status.style.color = '#96acbf';
    try {
      const data = await api('save_runtime', {
        max_enabled: el('rt-enabled').checked ? '1' : '0',
        quiet_hours_enabled: el('rt-quiet').checked ? '1' : '0',
        quiet_hours_start: el('rt-start').value,
        quiet_hours_end: el('rt-end').value,
        default_group_id: el('rt-group').value || '0',
      }, e.currentTarget);
      status.textContent = data.message || 'Сохранено';
      status.style.color = '#64d2b3';
      await reloadState();
    } catch (err) {
      status.textContent = err.message || 'Ошибка';
      status.style.color = '#ff9b9b';
    }
  });

  el('btn-seed').addEventListener('click', async (e) => {
    const status = el('runtime-status');
    status.textContent = 'Синхронизация...';
    try {
      const data = await api('seed_events', {}, e.currentTarget);
      status.textContent = data.message || 'Готово';
      status.style.color = '#64d2b3';
      await reloadState();
    } catch (err) {
      status.textContent = err.message || 'Ошибка';
      status.style.color = '#ff9b9b';
    }
  });

  el('btn-save-event').addEventListener('click', async (e) => {
    const evt = currentEvent();
    if (!evt) return;
    const status = el('event-status');
    status.textContent = 'Сохранение...';
    try {
      const data = await api('save_event', {
        id: evt.id,
        title: el('evt-title').textContent,
        description: el('evt-when').textContent,
        when_sent: el('evt-when').textContent,
        category: evt.category || 'system',
        template_text: el('evt-template').value,
        is_enabled: el('evt-enabled').checked ? '1' : '0',
        group_ref_id: el('evt-group').value || '0',
        quiet_hours_enabled: el('evt-quiet').checked ? '1' : '0',
        quiet_hours_start: el('evt-quiet-start').value,
        quiet_hours_end: el('evt-quiet-end').value,
        placeholders: el('evt-placeholders').textContent.replace('Плейсхолдеры: ', ''),
      }, e.currentTarget);
      status.textContent = data.message || 'Сохранено';
      status.style.color = '#64d2b3';
      await reloadState();
    } catch (err) {
      status.textContent = err.message || 'Ошибка';
      status.style.color = '#ff9b9b';
    }
  });

  el('btn-render').addEventListener('click', async (e) => {
    const evt = currentEvent();
    if (!evt) return;
    try {
      const data = await api('render_event', {event_key: evt.event_key, template_text: el('evt-template').value}, e.currentTarget);
      el('render-box').textContent = data.data.render || '';
    } catch (err) {
      el('render-box').textContent = 'Ошибка render: ' + (err.message || 'unknown');
    }
  });

  el('btn-test').addEventListener('click', async (e) => {
    const evt = currentEvent();
    if (!evt) return;
    const status = el('event-status');
    status.textContent = 'Отправка...';
    try {
      const data = await api('test_event', {event_key: evt.event_key, template_text: el('evt-template').value}, e.currentTarget);
      status.textContent = data.message || 'Тест отправлен';
      status.style.color = '#64d2b3';
      await reloadState();
    } catch (err) {
      status.textContent = err.message || 'Ошибка';
      status.style.color = '#ff9b9b';
    }
  });

  el('btn-restore').addEventListener('click', async (e) => {
    const evt = currentEvent();
    if (!evt) return;
    const status = el('event-status');
    status.textContent = 'Восстановление...';
    try {
      const data = await api('restore_default', {event_key: evt.event_key}, e.currentTarget);
      if (data.data && typeof data.data.template_text === 'string') {
        el('evt-template').value = data.data.template_text;
      }
      status.textContent = data.message || 'Шаблон восстановлен';
      status.style.color = '#64d2b3';
      await reloadState();
    } catch (err) {
      status.textContent = err.message || 'Ошибка';
      status.style.color = '#ff9b9b';
    }
  });

  el('log-filter').addEventListener('change', () => { reloadState().catch(() => null); });
  el('btn-refresh-logs').addEventListener('click', () => { reloadState().catch(() => null); });

  reloadState().catch((err) => {
    console.error(err);
    alert('Ошибка загрузки MAX Event Center: ' + (err.message || 'unknown'));
  });
})();
</script>
<?php endif; ?>
</body>
</html>
