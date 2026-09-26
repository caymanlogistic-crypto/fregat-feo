<?php
require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/common.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('Database connection error');
}

$flash = '';
$flashType = 'ok';
$tableExists = false;
$colorColumnsExist = false;
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'utilizers'");
    $tableExists = $stmt && (bool)$stmt->fetchColumn();
    if ($tableExists) {
        $columns = $pdo->query('SHOW COLUMNS FROM utilizers')->fetchAll(PDO::FETCH_COLUMN);
        $colorColumnsExist = in_array('color_main', $columns, true)
            && in_array('color_dark', $columns, true)
            && in_array('color_text', $columns, true);
    }
} catch (Throwable $e) {
    $tableExists = false;
}

function utilizerAdminColor($value, string $fallback): string
{
    $value = strtolower(trim((string)$value));
    return preg_match('/^#[0-9a-f]{6}$/', $value) ? $value : $fallback;
}

if ($tableExists && $colorColumnsExist && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)maxAdminPost('action');
    try {
        if ($action === 'save') {
            $id = (int)maxAdminPost('id', 0);
            $fullName = trim((string)maxAdminPost('full_name'));
            $shortName = trim((string)maxAdminPost('short_name'));
            $address = trim((string)maxAdminPost('address'));
            $fkkoType = trim((string)maxAdminPost('fkko_type'));
            $dangerous = (string)maxAdminPost('dangerous_cargo') === 'Да' ? 'Да' : 'Нет';
            $lat = maxAdminPost('latitude');
            $lon = maxAdminPost('longitude');
            $colorMain = utilizerAdminColor(maxAdminPost('color_main'), '#f4b183');
            $colorDark = utilizerAdminColor(maxAdminPost('color_dark'), '#7f4125');
            $colorText = utilizerAdminColor(maxAdminPost('color_text'), $colorDark);
            $active = (int)maxAdminPost('is_active', 0) === 1 ? 1 : 0;
            $sortOrder = (int)maxAdminPost('sort_order', 0);

            if ($fullName === '' || $shortName === '' || $address === '') throw new RuntimeException('Заполните обязательные поля.');
            if (!is_numeric($lat) || (float)$lat < -90 || (float)$lat > 90) throw new RuntimeException('Некорректная широта.');
            if (!is_numeric($lon) || (float)$lon < -180 || (float)$lon > 180) throw new RuntimeException('Некорректная долгота.');

            $params = [
                ':full_name'=>$fullName, ':short_name'=>$shortName, ':address'=>$address, ':fkko_type'=>$fkkoType,
                ':dangerous_cargo'=>$dangerous, ':latitude'=>(float)$lat, ':longitude'=>(float)$lon,
                ':color_main'=>$colorMain, ':color_dark'=>$colorDark, ':color_text'=>$colorText,
                ':is_active'=>$active, ':sort_order'=>$sortOrder,
            ];
            if ($id > 0) {
                $params[':id'] = $id;
                $sql = 'UPDATE utilizers SET full_name=:full_name, short_name=:short_name, address=:address,
                    fkko_type=:fkko_type, dangerous_cargo=:dangerous_cargo, latitude=:latitude, longitude=:longitude,
                    color_main=:color_main, color_dark=:color_dark, color_text=:color_text,
                    is_active=:is_active, sort_order=:sort_order WHERE id=:id';
            } else {
                $sql = 'INSERT INTO utilizers
                    (full_name, short_name, address, fkko_type, dangerous_cargo, latitude, longitude, color_main, color_dark, color_text, is_active, sort_order)
                    VALUES (:full_name,:short_name,:address,:fkko_type,:dangerous_cargo,:latitude,:longitude,:color_main,:color_dark,:color_text,:is_active,:sort_order)';
            }
            $pdo->prepare($sql)->execute($params);
            $flash = $id > 0 ? 'Данные и цвета утилизатора обновлены.' : 'Утилизатор добавлен.';
        } elseif ($action === 'delete') {
            $id = (int)maxAdminPost('id', 0);
            if ($id <= 0) throw new RuntimeException('Некорректный ID.');
            $pdo->prepare('DELETE FROM utilizers WHERE id=:id')->execute([':id'=>$id]);
            $flash = 'Утилизатор удалён.';
        }
    } catch (Throwable $e) {
        $flash = $e->getMessage();
        $flashType = 'err';
    }
}

$rows = [];
if ($tableExists && $colorColumnsExist) {
    try {
        $rows = $pdo->query('SELECT * FROM utilizers ORDER BY sort_order ASC, short_name ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $flash = $e->getMessage();
        $flashType = 'err';
    }
}
?><!doctype html>
<html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Управление утилизаторами</title>
<style>
body{margin:0;background:#101820;color:#d9e2ec;font-family:Segoe UI,Arial,sans-serif}.wrap{max-width:1550px;margin:0 auto;padding:12px}.top{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:10px}.panel{background:#162331;border:1px solid #274056;border-radius:8px;padding:12px}.btn{height:32px;border:1px solid #3f6079;background:#23384a;color:#e6edf3;border-radius:6px;padding:0 10px;cursor:pointer}.btn.primary{background:#0f7f75;border-color:#0f9d90}.btn.danger{background:#6f2e2e;border-color:#a94f4f}.admin-nav{display:flex;gap:6px;flex-wrap:wrap}.admin-nav-active{background:#0f7f75!important}.grid{display:grid;grid-template-columns:430px 1fr;gap:12px}.field{display:grid;gap:4px;margin-bottom:9px}.field label{font-size:12px;color:#9ab3c5}input,textarea,select{width:100%;box-sizing:border-box;border:1px solid #35536b;background:#0f1a24;color:#e6edf3;border-radius:6px;padding:7px 8px;font-size:13px}textarea{min-height:72px}.two{display:grid;grid-template-columns:1fr 1fr;gap:8px}.color-line{display:grid;grid-template-columns:52px 1fr;gap:8px;align-items:center}.color-line input[type=color]{height:38px;padding:2px}.table-wrap{overflow:auto;max-height:78vh}table{width:100%;border-collapse:collapse;font-size:12px}th,td{border-bottom:1px solid #2b4356;padding:7px;text-align:left;vertical-align:top}tr[data-row]{cursor:pointer}tr[data-row]:hover{background:#1f3545}.flash{margin-bottom:10px}.ok{border-color:#3e7d67}.err{border-color:#a94f4f}.small{font-size:12px;color:#9ab3c5}.marker-preview{display:inline-flex;align-items:center;justify-content:center;min-width:74px;height:30px;padding:2px 8px 0;box-sizing:border-box;font-weight:700;border-radius:6px}.swatches{display:flex;gap:5px;margin-top:4px}.swatch{width:18px;height:18px;border:1px solid #000;border-radius:3px}@media(max-width:1050px){.grid{grid-template-columns:1fr}.table-wrap{max-height:none}}
</style></head><body><div class="wrap">
<div class="top"><h2 style="margin:0">Утилизаторы</h2><?php renderAdminNav('utilizers'); ?></div>
<?php if ($flash !== ''): ?><div class="panel flash <?= $flashType === 'err' ? 'err' : 'ok' ?>"><?= maxAdminHtml($flash) ?></div><?php endif; ?>
<?php if (!$tableExists || !$colorColumnsExist): ?>
<div class="panel"><h3>Нужно обновить таблицу utilizers</h3><p>Добавьте индивидуальные поля цветов для каждого утилизатора.</p><p><a style="color:#8fd3ff" href="setup_utilizers_table.php">Открыть файл создания/обновления таблицы</a></p></div>
<?php else: ?>
<div class="grid"><div class="panel"><h3 style="margin-top:0">Редактирование</h3><form method="post" id="utilizerForm"><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="f_id">
<div class="field"><label>Полное наименование *</label><input type="text" name="full_name" id="f_full_name" required></div>
<div class="field"><label>Сокращённое наименование для маркера *</label><input type="text" name="short_name" id="f_short_name" maxlength="80" required></div>
<div class="field"><label>Адрес *</label><textarea name="address" id="f_address" required></textarea></div>
<div class="field"><label>Вид ФККО</label><textarea name="fkko_type" id="f_fkko_type"></textarea></div>
<div class="two"><div class="field"><label>Опасный груз</label><select name="dangerous_cargo" id="f_dangerous_cargo"><option>Нет</option><option>Да</option></select></div><div class="field"><label>Порядок</label><input type="number" name="sort_order" id="f_sort_order" value="0"></div></div>
<div class="two"><div class="field"><label>Широта *</label><input type="number" step="any" name="latitude" id="f_latitude" required></div><div class="field"><label>Долгота *</label><input type="number" step="any" name="longitude" id="f_longitude" required></div></div>
<div class="field"><label>Цвет утилизатора Основной</label><div class="color-line"><input type="color" id="picker_main" value="#f4b183"><input type="text" name="color_main" id="f_color_main" value="#f4b183" maxlength="7" required></div></div>
<div class="field"><label>Цвет утилизатора Тёмный</label><div class="color-line"><input type="color" id="picker_dark" value="#7f4125"><input type="text" name="color_dark" id="f_color_dark" value="#7f4125" maxlength="7" required></div></div>
<div class="field"><label>Цвет надписи</label><div class="color-line"><input type="color" id="picker_text" value="#7f4125"><input type="text" name="color_text" id="f_color_text" value="#7f4125" maxlength="7" required></div></div>
<div class="field"><label><input style="width:auto" type="checkbox" name="is_active" id="f_is_active" value="1" checked> Показывать на карте</label></div>
<div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn primary" type="submit">Сохранить</button><button class="btn" type="button" id="clearBtn">Новая запись</button></div></form>
<div style="margin-top:12px"><div class="small">Предпросмотр маркера</div><span class="marker-preview" id="markerPreview">УТИЛ</span></div></div>
<div class="panel table-wrap"><table><thead><tr><th>ID</th><th>Маркер</th><th>Организация</th><th>Адрес</th><th>ФККО</th><th>Опасный</th><th>Активен</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $row): $main=utilizerAdminColor($row['color_main'] ?? '', '#f4b183'); $dark=utilizerAdminColor($row['color_dark'] ?? '', '#7f4125'); $text=utilizerAdminColor($row['color_text'] ?? '', $dark); ?>
<tr data-row='<?= maxAdminHtml(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'><td><?= (int)$row['id'] ?></td><td><span class="marker-preview" style="background:<?= maxAdminHtml($main) ?>;border:2px solid <?= maxAdminHtml($dark) ?>;color:<?= maxAdminHtml($text) ?>"><?= maxAdminHtml($row['short_name']) ?></span><div class="swatches"><span class="swatch" title="Основной" style="background:<?= maxAdminHtml($main) ?>"></span><span class="swatch" title="Тёмный" style="background:<?= maxAdminHtml($dark) ?>"></span><span class="swatch" title="Надпись" style="background:<?= maxAdminHtml($text) ?>"></span></div></td><td><strong><?= maxAdminHtml($row['full_name']) ?></strong><div class="small"><?= maxAdminHtml($row['latitude']) ?>, <?= maxAdminHtml($row['longitude']) ?></div></td><td><?= maxAdminHtml($row['address']) ?></td><td><?= maxAdminHtml($row['fkko_type']) ?></td><td><?= maxAdminHtml($row['dangerous_cargo']) ?></td><td><?= (int)$row['is_active'] === 1 ? 'Да' : 'Нет' ?></td><td><form method="post" onsubmit="return confirm('Удалить утилизатора?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn danger" type="submit">Удалить</button></form></td></tr>
<?php endforeach; ?></tbody></table></div></div>
<script>
const ids=['full_name','short_name','address','fkko_type','dangerous_cargo','latitude','longitude','sort_order','color_main','color_dark','color_text'];
const mainText=document.getElementById('f_color_main'),darkText=document.getElementById('f_color_dark'),textText=document.getElementById('f_color_text'),mainPicker=document.getElementById('picker_main'),darkPicker=document.getElementById('picker_dark'),textPicker=document.getElementById('picker_text'),preview=document.getElementById('markerPreview');
function validColor(v,f){return /^#[0-9a-fA-F]{6}$/.test(v||'')?v:f;}
function updatePreview(){const main=validColor(mainText.value,'#f4b183'),dark=validColor(darkText.value,'#7f4125'),text=validColor(textText.value,dark);preview.textContent=document.getElementById('f_short_name').value.trim()||'УТИЛ';preview.style.background=main;preview.style.border='2px solid '+dark;preview.style.color=text;mainPicker.value=main;darkPicker.value=dark;textPicker.value=text;}
mainPicker.addEventListener('input',()=>{mainText.value=mainPicker.value;updatePreview();});darkPicker.addEventListener('input',()=>{darkText.value=darkPicker.value;updatePreview();});textPicker.addEventListener('input',()=>{textText.value=textPicker.value;updatePreview();});mainText.addEventListener('input',updatePreview);darkText.addEventListener('input',updatePreview);textText.addEventListener('input',updatePreview);document.getElementById('f_short_name').addEventListener('input',updatePreview);
function clearForm(){document.getElementById('utilizerForm').reset();document.getElementById('f_id').value='';document.getElementById('f_is_active').checked=true;mainText.value='#f4b183';darkText.value='#7f4125';textText.value='#7f4125';updatePreview();}
document.getElementById('clearBtn').addEventListener('click',clearForm);
document.querySelectorAll('tr[data-row]').forEach(tr=>tr.addEventListener('click',e=>{if(e.target.closest('form'))return;const r=JSON.parse(tr.dataset.row);document.getElementById('f_id').value=r.id||'';ids.forEach(k=>{const el=document.getElementById('f_'+k);if(el)el.value=r[k]??'';});document.getElementById('f_is_active').checked=Number(r.is_active)===1;updatePreview();window.scrollTo({top:0,behavior:'smooth'});}));updatePreview();
</script>
<?php endif; ?></div></body></html>
