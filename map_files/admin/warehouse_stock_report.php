<?php
session_start();
require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/common.php';
if (!isset($pdo) || !($pdo instanceof PDO)) { http_response_code(500); echo 'Database connection error'; exit; }

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function gi(string $k, $d=''){ return isset($_GET[$k])?trim((string)$_GET[$k]):$d; }
function gint(string $k, int $d=0){ $v=gi($k); return preg_match('/^\d+$/',$v)?(int)$v:$d; }

if (!maxAdminIsAuthed() && ($_SERVER['REQUEST_METHOD']??'GET')==='POST' && gi('action')==='login') {
    if (maxAdminPasswordConst() !== '' && hash_equals(maxAdminPasswordConst(), gi('password'))) { $_SESSION['max_admin_auth']=1; header('Location: warehouse_stock_report.php'); exit; }
    $flash='Неверный пароль'; $flashType='err';
}
if (maxAdminIsAuthed() && gi('logout')==='1') { unset($_SESSION['max_admin_auth']); session_destroy(); header('Location: warehouse_stock_report.php'); exit; }

$fWh = gint('warehouse_id');
$fFkko = gi('fkko');
$fZid = gi('zayavka_id');
$fFid = gint('flight_id');
$fFrom = gi('date_from'); if($fFrom!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$fFrom)) $fFrom='';
$fTo = gi('date_to'); if($fTo!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$fTo)) $fTo='';
$fShow = gi('show_mode','stock'); if(!in_array($fShow,['stock','all','moves'])) $fShow='stock';
$fLimit = gint('limit',200); if(!in_array($fLimit,[100,200,500])) $fLimit=200;
$fGroup = gi('group_by','flight'); if(!in_array($fGroup,['flight','request'])) $fGroup='flight';

$dWh = gint('detail_warehouse_id');
$dFid = gint('detail_flight_id');
$dZid = gi('detail_zayavka_id'); if($dZid!=='' && !preg_match('/^\d+$/',$dZid)) $dZid='';
$dFkko = gi('detail_fkko');
$dMove = gi('detail_movement_type'); if(!in_array($dMove,['receipt','transfer_out','transfer_in','issue'])) $dMove='';
$showDetails = ($dWh > 0);
$isAjax = (gi('ajax') === 'details');

$whList = [];
try { foreach($pdo->query('SELECT id,name FROM warehouses ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) as $r) $whList[(int)$r['id']]=$r['name']; } catch(Throwable $e){}

function whName(array $list, int $id): string { return isset($list[$id])?$list[$id]:('Склад #'.$id); }
function moveLabel(string $t): string { return ['receipt'=>'Приход на склад','transfer_out'=>'Перемещение: списание','transfer_in'=>'Перемещение: поступление','issue'=>'Вывоз со склада'][$t]??$t; }
function flightAction(string $t, int $fid): string {
    $m=['receipt'=>"Рейс #{$fid} привёз груз на склад",'transfer_out'=>"Рейс #{$fid} забрал груз со склада",'transfer_in'=>"Рейс #{$fid} привёз груз после перемещения",'issue'=>"Рейс #{$fid} забрал груз на утилизацию"];
    return $m[$t]??"Рейс #{$fid}";
}
function badge(float $v): string {
    if ($v>0.0001) return '<span class="badge badge-ok">'.rtrim(rtrim(number_format($v,4,'.',''),'0'),'.').'</span>';
    if ($v<-0.0001) return '<span class="badge badge-bad">⚠ '.rtrim(rtrim(number_format($v,4,'.',''),'0'),'.').'</span>';
    return '<span class="badge badge-zero">0</span>';
}
function fmt(float $v):string{return rtrim(rtrim(number_format($v,4,'.',''),'0'),'.');}
function buildUrl(array $overrides=[]):string{
    $p=$_GET; unset($p['logout']);
    foreach($overrides as $k=>$v){if($v===null||$v==='')unset($p[$k]);else $p[$k]=$v;}
    return '?'.http_build_query($p);
}

// ── AJAX details ─────────────────────────────────────────────────────────────
if ($isAjax && maxAdminIsAuthed()) {
    $dw=["wm.warehouse_id=:dwh"]; $dp=[':dwh'=>$dWh];
    if($dFid>0){$dw[]='wm.flight_id=:dfid';$dp[':dfid']=$dFid;}
    if($dZid!==''){$dw[]='wm.zayavka_id=:dzid';$dp[':dzid']=(int)$dZid;}
    if($dMove!==''){$dw[]='wm.movement_type=:dmove';$dp[':dmove']=$dMove;}
    if($dFkko==='__EMPTY__'){$dw[]="(wm.fkko_code IS NULL OR wm.fkko_code='')";}
    elseif($dFkko!==''){$dw[]='wm.fkko_code=:dfkko';$dp[':dfkko']=$dFkko;}
    $dw[]="wm.status='active'";
    $dRows=[];
    try{$s=$pdo->prepare("SELECT wm.*, COALESCE(feo.naim_otkhoda_fkko,'') AS feo_name FROM warehouse_movements wm LEFT JOIN feo ON feo.zayavka_id=wm.zayavka_id WHERE ".implode(' AND ',$dw)." ORDER BY wm.movement_date DESC, wm.id DESC LIMIT 200");$s->execute($dp);$dRows=$s->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable $e){}
    $dLbl=whName($whList,$dWh);
    if($dFid>0)$dLbl.=' / Рейс #'.$dFid.' ('.moveLabel($dMove).')';
    if($dZid!=='')$dLbl.=' / Заявка #'.$dZid;
    if($dFkko!=='')$dLbl.=' / '.(($dFkko==='__EMPTY__')?'ФККО не указан':$dFkko);
    echo '<div class="modal-panel"><div class="modal-header"><strong>Детали движений: '.h($dLbl).'</strong><span class="small">'.count($dRows).' записей</span><button class="modal-close" onclick="closeDetailsModal()" title="Закрыть (ESC)">✕</button></div><div class="modal-body"><div style="overflow-x:auto"><table class="detail-table"><thead><tr><th>Дата</th><th>Тип</th><th>Рейс</th><th>Действие рейса</th><th>Заявка</th><th>ФККО</th><th>Нетто</th><th>Брутто</th><th>Объём</th><th>Склад отпр.</th><th>Склад назн.</th><th>Комментарий</th></tr></thead><tbody>';
    if(empty($dRows)) echo '<tr><td colspan="12" class="small">Нет движений.</td></tr>';
    else foreach($dRows as $dr){$dfid=(int)$dr['flight_id'];$dmv=$dr['movement_type'];echo '<tr><td class="small">'.h($dr['movement_date']?date('d.m.Y H:i',strtotime($dr['movement_date'])):'—').'</td><td><span class="badge badge-info">'.moveLabel($dmv).'</span></td><td class="mono">'.$dfid.'</td><td class="small">'.h(flightAction($dmv,$dfid)).'</td><td class="mono">'.(int)$dr['zayavka_id'].'</td><td class="mono" style="font-size:10px">'.h($dr['fkko_code']?:'—').'</td><td>'.fmt((float)($dr['mass_netto']??0)).'</td><td>'.fmt((float)($dr['mass_brutto']??0)).'</td><td>'.fmt((float)($dr['volume']??0)).'</td><td>'.h(whName($whList,(int)($dr['source_warehouse_id']??0))).'</td><td>'.h(whName($whList,(int)($dr['destination_warehouse_id']??0))).'</td><td class="small">'.h($dr['comment']??'').'</td></tr>';}
    echo '</tbody></table></div></div></div>';
    exit;
}

// ── Stock query ──────────────────────────────────────────────────────────────
$stockRows=[]; $summary=['warehouses'=>0,'rows'=>0,'netto'=>0,'brutto'=>0,'vol'=>0,'neg'=>0];
if(maxAdminIsAuthed()){
    $where=["wm.status='active'"]; $params=[];
    if($fWh>0){$where[]='wm.warehouse_id=:wh';$params[':wh']=$fWh;}
    if($fFkko!==''){$where[]='wm.fkko_code LIKE :fk';$params[':fk']='%'.$fFkko.'%';}
    if($fZid!==''){$where[]='wm.zayavka_id=:zid';$params[':zid']=(int)$fZid;}
    if($fFid>0){$where[]='wm.flight_id=:fid';$params[':fid']=$fFid;}
    if($fFrom!==''){$where[]='wm.movement_date>=:df';$params[':df']=$fFrom.' 00:00:00';}
    if($fTo!==''){$where[]='wm.movement_date<=:dt';$params[':dt']=$fTo.' 23:59:59';}
    $whereSql=implode(' AND ',$where);
    if($fGroup==='flight'){$groupCols='wm.warehouse_id, wm.flight_id, wm.movement_type, wm.fkko_code, wm.zayavka_id';$orderCols=$groupCols;}
    else{$groupCols='wm.warehouse_id, wm.fkko_code, wm.zayavka_id';$orderCols=$groupCols;}
    $sql="SELECT {$groupCols},
                 SUM(CASE WHEN wm.movement_type IN ('receipt','transfer_in') THEN COALESCE(wm.mass_netto,0) ELSE 0 END) AS in_netto,
                 SUM(CASE WHEN wm.movement_type IN ('issue','transfer_out') THEN COALESCE(wm.mass_netto,0) ELSE 0 END) AS out_netto,
                 SUM(CASE WHEN wm.movement_type IN ('receipt','transfer_in') THEN COALESCE(wm.mass_brutto,0) ELSE 0 END) AS in_brutto,
                 SUM(CASE WHEN wm.movement_type IN ('issue','transfer_out') THEN COALESCE(wm.mass_brutto,0) ELSE 0 END) AS out_brutto,
                 SUM(CASE WHEN wm.movement_type IN ('receipt','transfer_in') THEN COALESCE(wm.volume,0) ELSE 0 END) AS in_vol,
                 SUM(CASE WHEN wm.movement_type IN ('issue','transfer_out') THEN COALESCE(wm.volume,0) ELSE 0 END) AS out_vol,
                 MAX(wm.movement_date) AS last_move
          FROM warehouse_movements wm WHERE {$whereSql} GROUP BY {$groupCols} ORDER BY {$orderCols} LIMIT {$fLimit}";
    try{$stmt=$pdo->prepare($sql);$stmt->execute($params);
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $r){$n=round((float)$r['in_netto']-(float)$r['out_netto'],4);$b=round((float)$r['in_brutto']-(float)$r['out_brutto'],4);$v=round((float)$r['in_vol']-(float)$r['out_vol'],4);$r['stock_netto']=$n;$r['stock_brutto']=$b;$r['stock_vol']=$v;if($fShow==='stock' && $n==0 && $b==0 && $v==0) continue;$stockRows[]=$r;}
        $whSet=[];foreach($stockRows as $r){$whSet[(int)$r['warehouse_id']]=true;}
        $summary['warehouses']=count($whSet);$summary['rows']=count($stockRows);
        foreach($stockRows as $r){$summary['netto']+=$r['stock_netto'];$summary['brutto']+=$r['stock_brutto'];$summary['vol']+=$r['stock_vol'];if($r['stock_netto']<0||$r['stock_brutto']<0||$r['stock_vol']<0)$summary['neg']++;}
        $summary['netto']=round($summary['netto'],4);$summary['brutto']=round($summary['brutto'],4);$summary['vol']=round($summary['vol'],4);
    }catch(Throwable $e){$flash='Ошибка: '.$e->getMessage();$flashType='err';}
}

// ── Detail rows (fallback) ───────────────────────────────────────────────────
$detailRows=[];
if($showDetails && maxAdminIsAuthed() && !$isAjax){
    $dw=["wm.warehouse_id=:dwh"]; $dp=[':dwh'=>$dWh];
    if($dFid>0){$dw[]='wm.flight_id=:dfid';$dp[':dfid']=$dFid;}
    if($dZid!==''){$dw[]='wm.zayavka_id=:dzid';$dp[':dzid']=(int)$dZid;}
    if($dMove!==''){$dw[]='wm.movement_type=:dmove';$dp[':dmove']=$dMove;}
    if($dFkko==='__EMPTY__'){$dw[]="(wm.fkko_code IS NULL OR wm.fkko_code='')";}
    elseif($dFkko!==''){$dw[]='wm.fkko_code=:dfkko';$dp[':dfkko']=$dFkko;}
    $dw[]="wm.status='active'";
    try{$stmt=$pdo->prepare("SELECT wm.*, COALESCE(feo.naim_otkhoda_fkko,'') AS feo_name FROM warehouse_movements wm LEFT JOIN feo ON feo.zayavka_id=wm.zayavka_id WHERE ".implode(' AND ',$dw)." ORDER BY wm.movement_date DESC, wm.id DESC LIMIT 200");$stmt->execute($dp);$detailRows=$stmt->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable $e){}
}
?><!doctype html>
<html lang="ru"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Складские остатки</title>
<style>
body{margin:0;background:#f4f6f8;color:#1e293b;font-family:Arial,sans-serif}
.wrap{max-width:calc(100vw - 48px);margin:0 auto;padding:12px}
.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;gap:8px;flex-wrap:wrap}
.card{background:#fff;border:1px solid #d9e0e7;border-radius:8px;padding:14px;margin-bottom:10px}
.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
label{font-size:13px;color:#334155}
input[type=text],input[type=number],input[type=date],select{border:1px solid #cbd5e1;border-radius:6px;padding:6px 8px;font-size:13px;background:#fff;color:#1e293b}
.btn{border:1px solid #94a3b8;background:#eef2f7;padding:6px 12px;border-radius:6px;cursor:pointer;font-size:13px;height:32px;display:inline-flex;align-items:center;text-decoration:none;color:#1e293b}
.btn.primary{background:#0ea5b7;color:#fff;border-color:#0b7285}
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
.badge-bad{background:#fee2e2;color:#b42318}
.badge-zero{background:#f1f5f9;color:#64748b}
.badge-info{background:#dbeafe;color:#1e40af}
.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:10px}
.summary-item{text-align:center;padding:12px;border-radius:8px;background:#fff;border:1px solid #e2e8f0}
.summary-item .num{font-size:24px;font-weight:700}
.summary-item .lbl{font-size:11px;color:#64748b;margin-top:2px}
.login{max-width:420px;margin:80px auto}
.action-col{min-width:220px;font-size:12px}
.detail-table td,.detail-table th{font-size:11px;padding:4px 8px}
/* Modal */
.modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:999;display:flex;align-items:center;justify-content:center}
.modal-panel{background:#fff;border-radius:10px;width:min(1200px,calc(100vw - 48px));max-height:calc(100vh - 64px);display:flex;flex-direction:column;box-shadow:0 4px 24px rgba(0,0,0,.18)}
.modal-header{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid #e2e8f0;gap:12px;flex-shrink:0}
.modal-body{overflow:auto;padding:8px 16px 16px;flex:1}
.modal-close{background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;padding:4px 8px;border-radius:6px;line-height:1}
.modal-close:hover{background:#f1f5f9;color:#1e293b}
@media(max-width:900px){.summary-grid{grid-template-columns:1fr 1fr}}
</style></head><body><div class="wrap">
<div class="top"><h2 style="margin:0;font-size:18px">Складские остатки</h2><?php renderAdminNav('stock'); ?></div>
<?php if(!empty($flash)): ?><div class="card <?=$flashType==='err'?'err':'ok'?>"><?=h($flash)?></div><?php endif; ?>
<?php if(!maxAdminIsAuthed()): ?>
<div class="card login"><form method="post"><input type="hidden" name="action" value="login"><label>Пароль доступа</label><input type="password" name="password" required style="width:100%"><div style="height:8px"></div><button class="btn primary" type="submit">Войти</button></form></div>
<?php else: ?>
<div class="card"><form method="get"><div class="row" style="flex-wrap:wrap;gap:6px">
<label>Склад <select name="warehouse_id"><option value="0">Все склады</option><?php foreach($whList as $wid=>$wname):?><option value="<?=$wid?>" <?=$fWh===$wid?'selected':''?>><?=h($wname)?></option><?php endforeach;?></select></label>
<label>ФККО <input type="text" name="fkko" value="<?=h($fFkko)?>" placeholder="поиск" style="width:100px"></label>
<label>Заявка <input type="text" name="zayavka_id" value="<?=h($fZid)?>" placeholder="ID" style="width:80px"></label>
<label>Рейс <input type="number" name="flight_id" value="<?=$fFid>0?$fFid:''?>" placeholder="ID" style="width:80px"></label>
<label>С <input type="date" name="date_from" value="<?=h($fFrom)?>" style="width:130px"></label>
<label>По <input type="date" name="date_to" value="<?=h($fTo)?>" style="width:130px"></label>
<label>Показ <select name="show_mode"><option value="stock" <?=$fShow==='stock'?'selected':''?>>Остатки > 0</option><option value="all" <?=$fShow==='all'?'selected':''?>>Все</option><option value="moves" <?=$fShow==='moves'?'selected':''?>>Движения</option></select></label>
<label>Групп. <select name="group_by"><option value="flight" <?=$fGroup==='flight'?'selected':''?>>По рейсам</option><option value="request" <?=$fGroup==='request'?'selected':''?>>По заявкам</option></select></label>
<label>Лимит <select name="limit"><option value="100" <?=$fLimit===100?'selected':''?>>100</option><option value="200" <?=$fLimit===200?'selected':''?>>200</option><option value="500" <?=$fLimit===500?'selected':''?>>500</option></select></label>
<button class="btn primary" type="submit">Показать</button></div></form></div>
<div class="summary-grid">
<div class="summary-item"><div class="num"><?=$summary['warehouses']?></div><div class="lbl">Складов</div></div>
<div class="summary-item"><div class="num"><?=$summary['rows']?></div><div class="lbl">Строк</div></div>
<div class="summary-item"><div class="num"><?=fmt($summary['netto'])?></div><div class="lbl">Нетто, т</div></div>
<div class="summary-item"><div class="num"><?=fmt($summary['brutto'])?></div><div class="lbl">Брутто, т</div></div>
<div class="summary-item"><div class="num"><?=fmt($summary['vol'])?></div><div class="lbl">Объём, м³</div></div>
<div class="summary-item" style="border-color:<?=$summary['neg']>0?'#fecaca':'#e2e8f0'?>"><div class="num" style="color:<?=$summary['neg']>0?'#b42318':'#1e293b'?>"><?=$summary['neg']?></div><div class="lbl">Отрицат.</div></div>
</div>
<?php if($summary['neg']>0):?><div class="card warn-bg">⚠ Есть отрицательные остатки (<?=$summary['neg']?> строк). Проверьте складские движения.</div><?php endif;?>
<div class="card"><div class="row" style="justify-content:space-between;margin-bottom:8px"><strong>Остатки по складам</strong><span class="small">Группировка: <?=$fGroup==='flight'?'по рейсам':'по заявкам'?>. Статус: active.</span></div>
<?php if(empty($stockRows)):?><p class="small">Нет данных.</p><?php else:?>
<div style="overflow-x:auto"><table><thead><tr><th>Склад</th><?php if($fGroup==='flight'):?><th>Рейс</th><th>Действие рейса</th><?php endif;?><th>ФККО</th><th>Заявка</th><th>Приход</th><th>Расход</th><th>Ост. нетто</th><th>Ост. брутто</th><th>Ост. объём</th><th>Дата</th><th></th></tr></thead><tbody>
<?php foreach($stockRows as $r):$fid=(int)($r['flight_id']??0);$mv=($r['movement_type']??'');$fkko=$r['fkko_code']??'';$fDisplay=$fkko!==''?$fkko:'ФККО не указан';$zid=(int)$r['zayavka_id'];$whId=(int)$r['warehouse_id'];
$dParams=['detail_warehouse_id'=>$whId,'detail_zayavka_id'=>$zid,'detail_fkko'=>($fkko!==''?$fkko:'__EMPTY__')];
if($fGroup==='flight' && $fid>0){$dParams['detail_flight_id']=$fid;$dParams['detail_movement_type']=$mv;}
$isActive=($showDetails && $dWh===$whId && ($dFid===0||$dFid===$fid) && $dZid===(string)$zid && $dMove===$mv);
$href=h(buildUrl($isActive?['detail_warehouse_id'=>null,'detail_flight_id'=>null,'detail_zayavka_id'=>null,'detail_fkko'=>null,'detail_movement_type'=>null,'ajax'=>null]:$dParams));
?>
<tr><td><?=h(whName($whList,$whId))?></td>
<?php if($fGroup==='flight'):?><td class="mono">#<?=$fid?></td><td class="action-col"><?=$fid>0?h(flightAction($mv,$fid)):'—'?></td><?php endif;?>
<td class="mono" style="font-size:10px"><?=h($fDisplay)?></td><td class="mono"><?=$zid?></td><td><?=fmt((float)$r['in_netto'])?></td><td><?=fmt((float)$r['out_netto'])?></td><td><?=badge($r['stock_netto'])?></td><td><?=badge($r['stock_brutto'])?></td><td><?=badge($r['stock_vol'])?></td><td class="small"><?=h($r['last_move']?date('d.m.Y',strtotime($r['last_move'])):'—')?></td>
<td><a class="btn" style="height:26px;font-size:11px;padding:2px 8px" href="<?=$href?>#details" data-details-popup="1"><?=$isActive?'Закрыть':'Детали'?></a></td></tr>
<?php endforeach;?></tbody></table></div><?php endif;?></div>
<div id="details">
<?php if($showDetails && !$isAjax): $dLabel=whName($whList,$dWh);
if($dFid>0)$dLabel.=' / Рейс #'.$dFid.' ('.moveLabel($dMove).')';if($dZid!=='')$dLabel.=' / Заявка #'.$dZid;if($dFkko!=='')$dLabel.=' / '.(($dFkko==='__EMPTY__')?'ФККО не указан':$dFkko);?>
<div class="card"><div class="row" style="justify-content:space-between;margin-bottom:8px"><strong>Детали: <?=h($dLabel)?></strong><span class="small"><?=count($detailRows)?> записей</span></div><div style="overflow-x:auto"><table class="detail-table"><thead><tr><th>Дата</th><th>Тип</th><th>Рейс</th><th>Действие рейса</th><th>Заявка</th><th>ФККО</th><th>Нетто</th><th>Брутто</th><th>Объём</th><th>Склад отпр.</th><th>Склад назн.</th><th>Комм.</th></tr></thead><tbody>
<?php if(empty($detailRows)):?><tr><td colspan="12" class="small">Нет движений.</td></tr><?php else: foreach($detailRows as $dr):$dfid=(int)$dr['flight_id'];$dmv=$dr['movement_type'];?>
<tr><td class="small"><?=h($dr['movement_date']?date('d.m.Y H:i',strtotime($dr['movement_date'])):'—')?></td><td><span class="badge badge-info"><?=moveLabel($dmv)?></span></td><td class="mono"><?=$dfid?></td><td class="small"><?=h(flightAction($dmv,$dfid))?></td><td class="mono"><?=(int)$dr['zayavka_id']?></td><td class="mono" style="font-size:10px"><?=h($dr['fkko_code']?:'—')?></td><td><?=fmt((float)($dr['mass_netto']??0))?></td><td><?=fmt((float)($dr['mass_brutto']??0))?></td><td><?=fmt((float)($dr['volume']??0))?></td><td><?=h(whName($whList,(int)($dr['source_warehouse_id']??0)))?></td><td><?=h(whName($whList,(int)($dr['destination_warehouse_id']??0)))?></td><td class="small"><?=h($dr['comment']??'')?></td></tr><?php endforeach;endif;?></tbody></table></div></div><?php endif;?></div>
<?php endif; ?>
</div>
<script>
(function(){
  var backdrop=null,modalActive=false;
  function close(){if(backdrop){backdrop.remove();backdrop=null;modalActive=false;}}
  function open(html){close();backdrop=document.createElement('div');backdrop.className='modal-backdrop';backdrop.innerHTML=html;backdrop.addEventListener('click',function(e){if(e.target===backdrop)close();});document.body.appendChild(backdrop);modalActive=true;}
  function loadDetails(url){
    var hash='',hashIdx=url.indexOf('#');
    if(hashIdx>=0){hash=url.slice(hashIdx);url=url.slice(0,hashIdx);}
    var sep=url.indexOf('?')>=0?'&':'?';var u=url+sep+'ajax=details'+hash;
    open('<div class="modal-panel"><div class="modal-body" style="text-align:center;padding:32px">Загрузка...</div></div>');
    fetch(u,{credentials:'same-origin'}).then(function(r){return r.text();}).then(function(html){
      if(html.indexOf('modal-panel')>=0){if(backdrop){backdrop.innerHTML=html;}}else{open('<div class="modal-panel"><div class="modal-header"><strong>Ошибка</strong><button class="modal-close" onclick="closeDetailsModal()">✕</button></div><div class="modal-body">'+html+'</div></div>');}
    }).catch(function(){open('<div class="modal-panel"><div class="modal-header"><strong>Ошибка</strong><button class="modal-close" onclick="closeDetailsModal()">✕</button></div><div class="modal-body">Не удалось загрузить данные.</div></div>');});
  }
  document.addEventListener('click',function(e){
    var a=e.target.closest('[data-details-popup]');if(!a)return;
    e.preventDefault();loadDetails(a.href);
  });
  document.addEventListener('keydown',function(e){if(e.key==='Escape')close();});
  window.closeDetailsModal=close;
})();
</script>
</body></html>
