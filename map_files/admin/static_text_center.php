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
        header('Location: static_text_center.php');
        exit;
    }
    $flash = 'Неверный пароль';
    $flashType = 'err';
}
if (maxAdminIsAuthed() && (string)($_GET['logout'] ?? '') === '1') {
    unset($_SESSION['max_admin_auth']);
    session_destroy();
    header('Location: static_text_center.php');
    exit;
}
?><!doctype html>
<html lang="ru">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Static Text Center</title>
<style>
body{margin:0;background:#101820;color:#d9e2ec;font-family:Segoe UI,Arial,sans-serif}
.wrap{max-width:1400px;margin:0 auto;padding:12px}
.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;gap:8px}
.linkbar{display:flex;gap:8px;align-items:center}
.panel{background:#162331;border:1px solid #274056;border-radius:8px;padding:10px}
.btn{height:32px;border:1px solid #3f6079;background:#23384a;color:#e6edf3;border-radius:6px;padding:0 10px;cursor:pointer}
.btn.primary{background:#0f7f75;border-color:#0f9d90}
.btn.warn{background:#5b3b1e;border-color:#8c5a2b}
.btn:disabled{opacity:.6;cursor:not-allowed}
input[type=text],select,textarea{width:100%;box-sizing:border-box;border:1px solid #35536b;background:#0f1a24;color:#e6edf3;border-radius:6px;padding:7px 8px;font-size:13px}
textarea{min-height:120px}
.grid{display:grid;grid-template-columns:280px 1fr;gap:10px}
.list{display:grid;gap:6px;max-height:72vh;overflow:auto}
.cat{font-size:12px;color:#98b8cd;margin:6px 0 2px}
.item{padding:8px;border:1px solid #315066;border-radius:6px;background:#1a2a39;cursor:pointer}
.item.active{border-color:#4db6ac;background:#1f3545}
.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.small{font-size:12px;color:#9ab3c5}
.kv{display:grid;grid-template-columns:200px 1fr;gap:8px}
.sticky{position:sticky;bottom:0;background:#132334;border-top:1px solid #2f4b61;padding-top:8px}
.preview{white-space:pre-wrap;background:#102030;border:1px dashed #3c6078;border-radius:6px;padding:8px;min-height:90px}
.static-text-rendered{white-space:pre-wrap}
.unsaved{color:#ffcc80;font-size:12px;font-weight:600}
.login{max-width:420px;margin:80px auto}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div class="linkbar">
      <h2 style="margin:0">Static Text Center</h2>
    </div>
    <?php renderAdminNav('static_text'); ?>
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
        <div class="row" style="flex:1 1 auto">
          <input id="search" type="text" placeholder="Поиск по KEY, тексту, категории, описанию" style="max-width:420px">
          <select id="category" style="max-width:220px">
            <option value="">Все категории</option>
            <option value="routes">Рейсы</option>
            <option value="drivers">Водители</option>
            <option value="warehouses">Склады</option>
            <option value="slitex">SLITEX</option>
            <option value="max">MAX</option>
            <option value="buttons">Кнопки</option>
            <option value="popup">Popup</option>
            <option value="hints">Подсказки</option>
            <option value="errors">Ошибки</option>
            <option value="admin">Админка</option>
            <option value="system">Системные</option>
          </select>
          <button class="btn" id="btn-search" type="button">Искать</button>
        </div>
        <div class="row">
          <button class="btn" id="btn-seed" type="button">Загрузить ключевые тексты</button>
          <span id="status" class="small"></span>
        </div>
      </div>
    </div>

    <div class="grid">
      <div class="panel">
        <div class="small" style="margin-bottom:6px">Категории / ключи</div>
        <div class="list" id="text-list"></div>
      </div>
      <div class="panel">
        <div class="kv">
          <div class="small">KEY</div><input id="f-key" type="text" readonly>
          <div class="small">Категория</div><input id="f-category" type="text">
          <div class="small">Название</div><input id="f-title" type="text">
          <div class="small">Описание</div><textarea id="f-description" rows="2"></textarea>
          <div class="small">Текст</div><textarea id="f-text" rows="8"></textarea>
          <div class="small">Где используется</div><textarea id="f-usage" rows="2"></textarea>
        </div>

        <div style="height:8px"></div>
        <div class="small">Preview</div>
        <div class="preview" id="preview-box"></div>

        <div class="sticky row" style="justify-content:space-between">
          <div class="row">
            <button class="btn primary" id="btn-save" type="button">Сохранить</button>
            <button class="btn warn" id="btn-restore" type="button">Restore default</button>
            <span id="unsaved" class="unsaved" style="display:none;">● Есть несохранённые изменения</span>
          </div>
          <span class="small" id="editor-status"></span>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php if (maxAdminIsAuthed()): ?>
<script>
(() => {
  const endpoint = 'static_text_center_actions.php';
  const state = { rows: [], selectedKey: '', dirty: false };

  const el = (id) => document.getElementById(id);
  const editorIds = ['f-category','f-title','f-description','f-text','f-usage'];

  function lock(btn, v, text='') {
    if (!btn) return;
    if (v) {
      btn.dataset.original = btn.textContent;
      btn.disabled = true;
      btn.textContent = text || 'Выполняется...';
    } else {
      btn.disabled = false;
      if (btn.dataset.original) btn.textContent = btn.dataset.original;
    }
  }

  async function api(action, payload = {}, button = null) {
    lock(button, true);
    const fd = new FormData();
    fd.set('action', action);
    Object.entries(payload).forEach(([k,v]) => fd.set(k, v));
    try {
      const res = await fetch(endpoint, {method:'POST', body:fd, credentials:'same-origin'});
      const data = await res.json();
      if (!data.success) throw new Error(data.error || 'Ошибка');
      return data;
    } finally {
      lock(button, false);
    }
  }

  function setStatus(msg, isError=false) {
    const s = el('status');
    s.textContent = msg;
    s.style.color = isError ? '#ff9b9b' : '#7fd3b6';
  }

  function setEditorStatus(msg, isError=false) {
    const s = el('editor-status');
    s.textContent = msg;
    s.style.color = isError ? '#ff9b9b' : '#7fd3b6';
  }

  function setDirty(flag) {
    state.dirty = !!flag;
    el('unsaved').style.display = state.dirty ? 'inline' : 'none';
  }

  function groupedRows(rows) {
    const map = {};
    rows.forEach((r) => {
      const cat = r.category || 'system';
      if (!map[cat]) map[cat] = [];
      map[cat].push(r);
    });
    return map;
  }

  function renderList() {
    const holder = el('text-list');
    holder.innerHTML = '';
    const categories = groupedRows(state.rows);
    Object.keys(categories).sort().forEach((cat) => {
      const title = document.createElement('div');
      title.className = 'cat';
      title.textContent = cat;
      holder.appendChild(title);
      categories[cat].forEach((row) => {
        const item = document.createElement('div');
        item.className = 'item' + (row.text_key === state.selectedKey ? ' active' : '');
        item.innerHTML = `<div>${(row.title || row.text_key)}</div><div class="small mono">${row.text_key}</div>`;
        item.onclick = () => {
          state.selectedKey = row.text_key;
          renderList();
          renderEditor();
        };
        holder.appendChild(item);
      });
    });
  }

  function currentRow() {
    return state.rows.find((r) => r.text_key === state.selectedKey) || null;
  }

  function renderSafeStaticHtml(value) {
    const escaped = String(value || '').replace(/[&<>"']/g, (m) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;'
    }[m] || m));
    return escaped
      .replace(/&lt;br\s*\/?&gt;/gi, '<br>')
      .replace(/&lt;strong&gt;/gi, '<strong>')
      .replace(/&lt;\/strong&gt;/gi, '</strong>')
      .replace(/\r\n|\r|\n/g, '<br>');
  }

  function setPreviewHtml(value) {
    const box = el('preview-box');
    box.classList.add('static-text-rendered');
    box.innerHTML = renderSafeStaticHtml(value);
  }

  function renderEditor() {
    const row = currentRow();
    if (!row) return;
    el('f-key').value = row.text_key || '';
    el('f-category').value = row.category || '';
    el('f-title').value = row.title || '';
    el('f-description').value = row.description || '';
    el('f-text').value = row.text_value || '';
    el('f-usage').value = row.usage_path || '';
    setPreviewHtml(row.text_value || '');
    setEditorStatus('');
    setDirty(false);
  }

  async function loadRows(button = null) {
    setStatus('Загрузка...');
    try {
      const data = await api('load_texts', {search: el('search').value || ''}, button);
      const raw = data.data.rows || [];
      const category = el('category').value;
      state.rows = category ? raw.filter((r) => (r.category || '') === category) : raw;
      if (!state.rows.some((r) => r.text_key === state.selectedKey)) {
        state.selectedKey = state.rows.length ? state.rows[0].text_key : '';
      }
      renderList();
      renderEditor();
      setStatus('Загружено: ' + state.rows.length);
    } catch (e) {
      setStatus(e.message || 'Ошибка загрузки', true);
    }
  }

  async function saveCurrent(button = null) {
    const key = el('f-key').value;
    if (!key) return;
    try {
      const data = await api('save_text', {
        text_key: key,
        category: el('f-category').value,
        title: el('f-title').value,
        description: el('f-description').value,
        text_value: el('f-text').value,
        usage_path: el('f-usage').value,
      }, button);
      const row = currentRow();
      if (row) {
        row.category = el('f-category').value;
        row.title = el('f-title').value;
        row.description = el('f-description').value;
        row.text_value = el('f-text').value;
        row.usage_path = el('f-usage').value;
      }
      setPreviewHtml(el('f-text').value);
      setEditorStatus(data.message || 'Сохранено');
      setDirty(false);
      renderList();
    } catch (e) {
      setEditorStatus(e.message || 'Ошибка сохранения', true);
    }
  }

  async function restoreDefault(button = null) {
    const key = el('f-key').value;
    if (!key) return;
    try {
      const data = await api('restore_default', {text_key: key}, button);
      if (data.data && typeof data.data.text_value === 'string') {
        el('f-text').value = data.data.text_value;
      }
      await saveCurrent();
      setEditorStatus(data.message || 'Default восстановлен');
    } catch (e) {
      setEditorStatus(e.message || 'Ошибка восстановления', true);
    }
  }

  editorIds.forEach((id) => {
    el(id).addEventListener('input', () => {
      setDirty(true);
      setPreviewHtml(el('f-text').value);
    });
  });

  el('btn-search').addEventListener('click', (e) => loadRows(e.currentTarget));
  el('search').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      loadRows();
    }
  });
  el('category').addEventListener('change', () => loadRows());
  el('btn-seed').addEventListener('click', async (e) => {
    try {
      const data = await api('seed_texts', {}, e.currentTarget);
      setStatus(data.message || 'Ключевые тексты зарегистрированы');
      await loadRows();
    } catch (err) {
      setStatus(err.message || 'Ошибка', true);
    }
  });
  el('btn-save').addEventListener('click', (e) => saveCurrent(e.currentTarget));
  el('btn-restore').addEventListener('click', (e) => restoreDefault(e.currentTarget));

  loadRows();
})();
</script>
<?php endif; ?>
</body></html>
