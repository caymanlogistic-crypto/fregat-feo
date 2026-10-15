<?php
require_once 'config.php';
session_start();
include ("include/googleSheetsHelper.php");
$googleData = getGoogleSheetsData();

// Функция шифрования токена
function getFlightToken($flightId) {
    $method = 'AES-256-CBC';
    $key = getenv('TIMELINE_TOKEN_KEY') ?: 'local-development-only';
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($method));
    $encrypted = openssl_encrypt((string)$flightId, $method, $key, 0, $iv);
    $combined = base64_encode($iv . $encrypted);
    return rtrim(strtr($combined, '+/', '-_'), '=');
}

// Подключение к БД
try {
    $pdo = new PDO(
        "mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset=utf8mb4",
        $dbConfig['username'],
        $dbConfig['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    die("Ошибка подключения к БД: " . $e->getMessage());
}

// Загрузка статусов
$statuses = []; $statusMap = [];
try {
    $stmt = $pdo->query("SELECT * FROM status ORDER BY id");
    $statuses = $stmt->fetchAll();
    foreach ($statuses as $s) { $statusMap[$s['статус']] = $s; }
} catch (Exception $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `status` (`id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, `статус` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL, `наименование` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL, `color` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL, `style` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `статус` (`статус`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("INSERT INTO `status` (`статус`, `наименование`, `color`, `style`) VALUES ('search', 'Поиск подрядчика', '#FFA459', '.status-search'), ('found', 'Подрядчик определен', '#17a2b8', '.status-found'), ('started', 'Вывоз начался', '#28a745', '.status-started'), ('completed', 'Груз сдан', '#6c757d', '.status-completed') ON DUPLICATE KEY UPDATE `наименование` = VALUES(`наименование`)");
    $stmt = $pdo->query("SELECT * FROM status ORDER BY id");
    $statuses = $stmt->fetchAll();
    foreach ($statuses as $s) { $statusMap[$s['статус']] = $s; }
}

$dynamicStatusCSS = '';
foreach ($statuses as $s) {
    $styleClass = ltrim($s['style'], '.');
    $dynamicStatusCSS .= ".{$styleClass}{display:inline-block;width:140px;text-align:center;padding:4px 12px;background:{$s['color']};color:#fff;border-radius:4px;font-size:12px;font-weight:600}\n";
}

$zayavkiInfo = [];
foreach ($googleData as $index => $row) {
    if ($index > 0 && isset($row[0]) && is_numeric(trim($row[0]))) {
        $rn = trim($row[0]);
        $zayavkiInfo[$rn] = ['route'=>isset($row[8])?trim($row[8]):'', 'contacts'=>isset($row[9])?trim($row[9]):'', 'comments'=>isset($row[11])?trim($row[11]):'', 'driver_info'=>isset($row[12])?trim($row[12]):'', 'prep_time'=>isset($row[13])?trim($row[13]):''];
    }
}

function uniqueContacts($items) { $u=[]; foreach($items as $i){$i=trim($i);if(!empty($i)&&!in_array($i,$u))$u[]=$i;} return $u; }

function getWarehouseNameColumn(PDO $pdo) {
    static $checked = false;
    static $column = null;

    if ($checked) {
        return $column;
    }

    $checked = true;
    $candidates = ['name', 'title', 'warehouse_name', 'nazvanie', 'наименование'];

    try {
        $columns = $pdo->query("SHOW COLUMNS FROM warehouses")->fetchAll();
        $existing = [];
        foreach ($columns as $col) {
            if (!empty($col['Field'])) {
                $existing[] = $col['Field'];
            }
        }

        foreach ($candidates as $candidate) {
            if (in_array($candidate, $existing, true)) {
                $column = '`' . str_replace('`', '``', $candidate) . '`';
                return $column;
            }
        }
    } catch (Exception $e) {
        $column = null;
    }

    return $column;
}

function getWarehouseNamesForFlight(array $flight) {
    $routeType = $flight['route_type'] ?? 'generator_to_utilizer';
    $sourceWarehouseName = trim((string)($flight['source_warehouse_name'] ?? ''));
    $destinationWarehouseName = trim((string)($flight['destination_warehouse_name'] ?? ''));

    $loadingWarehouseName = '';
    $unloadingWarehouseName = '';

    switch ($routeType) {
        case 'generator_to_warehouse':
            $unloadingWarehouseName = $destinationWarehouseName;
            break;

        case 'warehouse_to_warehouse':
            $loadingWarehouseName = $sourceWarehouseName;
            $unloadingWarehouseName = $destinationWarehouseName;
            break;

        case 'warehouse_to_utilizer':
            $loadingWarehouseName = $sourceWarehouseName;
            break;

        case 'mixed_to_utilizer':
            $loadingWarehouseName = $sourceWarehouseName !== ''
                ? 'Прямой забор + ' . $sourceWarehouseName
                : 'Прямой забор + склад';
            break;

        case 'generator_to_utilizer':
        default:
            break;
    }

    return [
        'loading' => $loadingWarehouseName,
        'unloading' => $unloadingWarehouseName,
    ];
}

function getFlightsWithFilter($pdo,$statusMap,$zayavkiInfo,$filterType='all'){
    $flights=[];
    try{
        $warehouseNameColumn = getWarehouseNameColumn($pdo);
        $warehouseSelect = "";
        $warehouseJoin = "";
        if ($warehouseNameColumn !== null) {
            $warehouseSelect = ", source_w.{$warehouseNameColumn} as source_warehouse_name, destination_w.{$warehouseNameColumn} as destination_warehouse_name";
            $warehouseJoin = " LEFT JOIN warehouses source_w ON f.source_warehouse_id=source_w.id LEFT JOIN warehouses destination_w ON f.destination_warehouse_id=destination_w.id";
        } else {
            $warehouseSelect = ", '' as source_warehouse_name, '' as destination_warehouse_name";
        }

        $sql="SELECT f.*,c.name as contractor_name,d.full_name as driver_name,d.vehicle_make_plate,CONCAT(u.Фамилия,' ',u.Имя) as manager_name{$warehouseSelect} FROM flights f LEFT JOIN contractors c ON f.contractor_id=c.id LEFT JOIN drivers d ON f.driver_id=d.id LEFT JOIN users u ON f.assigned_manager_id=u.id{$warehouseJoin} WHERE 1=1";
        switch($filterType){
            case'planned':$sql.=" AND f.status IN ('planned_route','found')";break;
            case'in_transit':$sql.=" AND f.status = 'started'";break;
            case'unloaded':$sql.=" AND f.status = 'completed'";break;
        }
        switch($filterType){
            case'all':$sql.=" ORDER BY FIELD(f.status,'planned_route','found','started','completed'), GREATEST(COALESCE(f.planned_start_date,'1970-01-01'),COALESCE(f.planned_start_date_from,'1970-01-01'),COALESCE(f.planned_start_date_to,'1970-01-01'),COALESCE(f.actual_start_date,'1970-01-01'),COALESCE(f.actual_end_date,'1970-01-01')) DESC, f.id DESC";break;
            case'in_transit':case'unloaded':$sql.=" ORDER BY GREATEST(COALESCE(f.actual_start_date,'1970-01-01'),COALESCE(f.actual_end_date,'1970-01-01')) DESC, f.id DESC";break;
            default:$sql.=" ORDER BY COALESCE(f.planned_start_date,f.planned_start_date_from,f.planned_start_date_to,f.actual_start_date,f.id) ASC";break;
        }
        $sql.=" LIMIT 500";
        $stmt=$pdo->query($sql);$flightsRaw=$stmt->fetchAll();$processedFlights=[];
        foreach($flightsRaw as $flight){
            $zayavki_ids=array_filter(explode(',',$flight['zayavki_ids']));$flight['zayavki_ids_array']=$zayavki_ids;
            if(!empty($zayavki_ids)){
                $pl=implode(',',array_fill(0,count($zayavki_ids),'?'));
                $stmt2=$pdo->prepare("SELECT zayavka_id,mass_netto,mass_brutto,naim_oo_gruzootpravitel,mno_adres_pogruzki FROM feo WHERE zayavka_id IN({$pl})");
                $stmt2->execute($zayavki_ids);$zayavkiData=$stmt2->fetchAll();
                $enriched=[];$uniqAddr=[];$prepCnt=0;
                foreach($zayavkiData as $z){
                    $zid=$z['zayavka_id'];$ez=$z;
                    if(!empty($z['mno_adres_pogruzki'])){ $a=trim($z['mno_adres_pogruzki']); if(!in_array($a,$uniqAddr))$uniqAddr[]=$a; }
                    if(isset($zayavkiInfo[$zid])){ $ez['prep_time']=$zayavkiInfo[$zid]['prep_time'];$ez['contacts']=$zayavkiInfo[$zid]['contacts'];$ez['comments']=$zayavkiInfo[$zid]['comments'];$ez['driver_info']=$zayavkiInfo[$zid]['driver_info'];$ez['route']=$zayavkiInfo[$zid]['route']; if(!empty($zayavkiInfo[$zid]['prep_time']))$prepCnt++; }
                    else{$ez['prep_time']='';$ez['contacts']='';$ez['comments']='';$ez['driver_info']='';$ez['route']='';}
                    $enriched[]=$ez;
                }
                $flight['zayavki']=$enriched;$flight['unique_addresses_count']=count($uniqAddr);$flight['prep_time_filled_count']=$prepCnt;$flight['total_zayavki_count']=count($zayavkiData);
                $mass=0;foreach($flight['zayavki'] as $zz)$mass+=floatval($zz['mass_brutto']??0)*1000;$flight['total_mass_kg']=$mass;
            }else{$flight['zayavki']=[];$flight['total_mass_kg']=0;$flight['unique_addresses_count']=0;$flight['prep_time_filled_count']=0;$flight['total_zayavki_count']=0;}
            $processedFlights[]=$flight;
        }
        return $processedFlights;
    }catch(Exception $e){return [];}
}

$allowedTabs=['all','planned','in_transit','unloaded'];
$selectedTab='all';
if(isset($_GET['tab'])&&in_array($_GET['tab'],$allowedTabs,true)){$selectedTab=$_GET['tab'];$_SESSION['timeline_selected_tab']=$selectedTab;}
elseif(isset($_SESSION['timeline_selected_tab'])&&in_array($_SESSION['timeline_selected_tab'],$allowedTabs,true)){$selectedTab=$_SESSION['timeline_selected_tab'];}

$flights=getFlightsWithFilter($pdo,$statusMap,$zayavkiInfo,$selectedTab);
$countAll=count(getFlightsWithFilter($pdo,$statusMap,$zayavkiInfo,'all'));$countPlanned=count(getFlightsWithFilter($pdo,$statusMap,$zayavkiInfo,'planned'));$countInTransit=count(getFlightsWithFilter($pdo,$statusMap,$zayavkiInfo,'in_transit'));$countUnloaded=count(getFlightsWithFilter($pdo,$statusMap,$zayavkiInfo,'unloaded'));

function getDateIcon($flight,$selectedTab){
    if($selectedTab!=='all')return'';
    switch($flight['status']??''){
        case'completed':return'✅ ';
        case'started':return'🚚 ';
        case'planned_route':case'found':return'📅 ';
        default:return'';
    }
}
include('include/price.php');
?>
<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>График вывоза</title><link rel="stylesheet" href="style.css"><style>
.status-badge{display:inline-block;width:140px;text-align:center;padding:4px 12px;border-radius:4px;font-size:12px;font-weight:600;color:#fff}
<?= $dynamicStatusCSS ?>
.status-waiting-driver{display:inline-block;width:140px;text-align:center;padding:4px 12px;border-radius:4px;font-size:12px;font-weight:600;color:#fff;background:#ffc107}
.warehouse-address{max-width:170px;white-space:normal;word-break:break-word;line-height:1.25;font-size:12px;color:#24292f}
.warehouse-address.empty{color:#999;font-style:italic;text-align:center}
.toggle-arrow{cursor:pointer;user-select:none;transition:transform .2s}.toggle-arrow:hover{transform:scale(1.2)}
.details-table{width:100%;border-collapse:collapse;background:#f9f9f9;table-layout:fixed}
.details-table th{background:#e9ecef;padding:8px 12px;font-size:12px;font-weight:600;border-bottom:1px solid #dee2e6;text-align:center;word-wrap:break-word}
.details-table td{padding:8px 12px;font-size:13px;border-bottom:1px solid #e9ecef;vertical-align:top;word-wrap:break-word;word-break:break-word;white-space:normal}
.block-details{padding:12px 20px;background:#f5f5f5;border-top:1px solid #dee2e6;border-bottom:1px solid #dee2e6}
.days-badge{display:inline-block;padding:4px 10px;border-radius:12px;font-weight:600;font-size:12px;background:#e8f5e9;color:#2e7d32;text-align:center}.days-badge.high{background:#ffebee;color:#c62828}
.comments-cell,.contacts-cell{white-space:pre-wrap;word-wrap:break-word;word-break:break-word;font-size:12px;line-height:1.4;text-align:left}
.route-badge{display:inline-block;padding:4px 8px;border-radius:4px;background:#e3f2fd;color:#1565c0;font-weight:600;font-size:11px}
.text-right{text-align:right}.text-center{text-align:center}.text-muted{color:#6c757d}.empty-value{color:#9e9e9e;font-style:italic;font-size:11px}
.details-table th:first-child,.details-table td:first-child{text-align:center}
.details-table th:nth-child(1),.details-table td:nth-child(1){width:80px}.details-table th:nth-child(2),.details-table td:nth-child(2){width:80px}
.details-table th:nth-child(3),.details-table td:nth-child(3){width:300px}.details-table th:nth-child(4),.details-table td:nth-child(4){width:400px}
.details-table th:nth-child(5),.details-table td:nth-child(5){width:150px}.details-table th:nth-child(6),.details-table td:nth-child(6){width:100px}
.details-table th:nth-child(7),.details-table td:nth-child(7){width:100px}.details-table th:nth-child(8),.details-table td:nth-child(8){width:200px}
.prozvon-badge{display:inline-block;padding:4px 10px;border-radius:12px;font-weight:600;font-size:12px;background:#e3f2fd;color:#1565c0;text-align:center}
.prozvon-badge.complete{background:#e8f5e9;color:#2e7d32}.prozvon-badge.incomplete{background:#fff3e0;color:#ef6c00}
.tabs-container{margin-bottom:16px;background:#fff;border:1px solid #d0d7de;border-radius:6px;padding:4px;display:flex;flex-wrap:wrap;gap:4px}
.tab-button{padding:8px 20px;font-size:13px;font-weight:500;background:transparent;border:none;border-radius:4px;cursor:pointer;transition:all .2s ease;color:#57606a}
.tab-button:hover{background:#f6f8fa;color:#0969da}.tab-button.active{background:#0969da;color:#fff}
.tab-badge{display:inline-block;margin-left:6px;padding:2px 6px;font-size:11px;font-weight:600;border-radius:12px;background:rgba(0,0,0,.1)}
.tab-button.active .tab-badge{background:rgba(255,255,255,.2);color:#fff}.tab-button:not(.active) .tab-badge{background:#e9ecef;color:#57606a}
.action-buttons{display:flex;gap:5px;justify-content:center}
.action-btn{cursor:pointer;padding:6px 8px;border:none;border-radius:4px;display:inline-flex;align-items:center;justify-content:center;transition:opacity 0.2s}
.action-btn:hover{opacity:0.8}
.btn-requests{background:#0066cc;color:#fff}
.btn-route{background:#28a745;color:#fff}
@media(max-width:768px){.tab-button{padding:6px 12px;font-size:11px}.tab-badge{margin-left:4px;padding:1px 4px;font-size:10px}}
</style></head><body>
<?php include'menu2.php';?>
<div class="app"><div class="toolbar"><h1>График вывоза</h1></div>
<div class="tabs-container">
<button class="tab-button <?= $selectedTab=='all'?'active':'' ?>" data-tab="all">📋 Все рейсы <span class="tab-badge"><?= $countAll ?></span></button>
<button class="tab-button <?= $selectedTab=='planned'?'active':'' ?>" data-tab="planned">📅 Планы на вывоз <span class="tab-badge"><?= $countPlanned ?></span></button>
<button class="tab-button <?= $selectedTab=='in_transit'?'active':'' ?>" data-tab="in_transit">🚚 Рейсы в пути <span class="tab-badge"><?= $countInTransit ?></span></button>
<button class="tab-button <?= $selectedTab=='unloaded'?'active':'' ?>" data-tab="unloaded">✅ Выгруженные рейсы <span class="tab-badge"><?= $countUnloaded ?></span></button>
</div>
<?php if(!empty($flights)):?><div class="card"><div class="table-wrapper"><table class="data-table" id="flightsTable"><thead>
<tr>
<th style="width:30px;"></th><th style="width:150px;">ПЛАНИРУЕМАЯ ДАТА</th><th style="width:50px;">ID</th><th style="width:200px;">ОПИСАНИЕ РЕЙСА</th><th style="width:170px;text-align:center;">СКЛАД ЗАГРУЗКИ</th><th style="width:170px;text-align:center;">СКЛАД ВЫГРУЗКИ</th>
<th style="text-align:center;">СТАТУС</th><th style="text-align:center;">ВОДИТЕЛЬ / МАШИНА</th><th style="text-align:center;">МЕНЕДЖЕР</th>
<th style="text-align:center;">ПРОЗВОН</th><th style="text-align:center;">КОЛ-ВО АДРЕСОВ / ЗАЯВОК</th><th style="text-align:center;">ОБЩИЙ ВЕС БРУТТО, КГ</th><th style="text-align:center;">ДЕЙСТВИЯ</th>
</tr>
</thead><tbody>
<?php foreach($flights as $flight):
$warehouseNames = getWarehouseNamesForFlight($flight);
$warehouseLoadingName = $warehouseNames['loading'];
$warehouseUnloadingName = $warehouseNames['unloading'];
$zd=[];foreach($flight['zayavki'] as $z){$w=number_format(floatval($z['mass_netto']??0),3,'.','');$w=str_replace('.',',',$w);$zd[]=['id'=>$z['zayavka_id'],'weight'=>$w];}
$zj=htmlspecialchars(json_encode($zd,JSON_UNESCAPED_UNICODE),ENT_QUOTES);
$token = getFlightToken($flight['id']); // Генерируем зашифрованный токен
?>
<tr class="group-header" onclick="toggleFlight('details_<?= $flight['id'] ?>',event)">
<td class="toggle-arrow" style="text-align:center;">▶</td>
<td><?php $di=getDateIcon($flight,$selectedTab);
if(!empty($flight['planned_start_date']))echo $di.date('d.m.Y',strtotime($flight['planned_start_date']));
elseif(!empty($flight['planned_start_date_from'])&&!empty($flight['planned_start_date_to']))echo $di.date('d.m.Y',strtotime($flight['planned_start_date_from'])).' - '.date('d.m.Y',strtotime($flight['planned_start_date_to']));
elseif(!empty($flight['planned_start_date_from']))echo $di.'с '.date('d.m.Y',strtotime($flight['planned_start_date_from']));
elseif(!empty($flight['planned_start_date_to']))echo $di.'по '.date('d.m.Y',strtotime($flight['planned_start_date_to']));
elseif(!empty($flight['actual_start_date']))echo $di.date('d.m.Y',strtotime($flight['actual_start_date']));
else echo $di.'<span style="color:#999;">—</span>';
?>
</td>
<td><strong>#<?= $flight['id'] ?></strong></td>
<td><?= !empty($flight['comment'])?htmlspecialchars($flight['comment']):'<span style="color:#999;">—</span>' ?></td>
<td class="warehouse-address <?= $warehouseLoadingName === '' ? 'empty' : '' ?>"><?= $warehouseLoadingName !== '' ? htmlspecialchars($warehouseLoadingName) : '—' ?></td>
<td class="warehouse-address <?= $warehouseUnloadingName === '' ? 'empty' : '' ?>"><?= $warehouseUnloadingName !== '' ? htmlspecialchars($warehouseUnloadingName) : '—' ?></td>
<td style="text-align:center;"><?php $sc=$flight['status'];$sd=$statusMap[$sc]??null;$swd=($sc==='found'&&empty($flight['driver_id']));
if($swd):?><span class="status-badge status-waiting-driver">данные водителя ?</span><?php elseif($sd):$scl=ltrim($sd['style'],'.');?>
<span class="status-badge <?= htmlspecialchars($scl) ?>"><?= htmlspecialchars($sd['наименование']) ?></span><?php else:?>
<span class="status-badge" style="background:#6c757d;"><?= htmlspecialchars($flight['status']) ?></span><?php endif;?></td>
<td><?php if(!empty($flight['driver_name'])):?><?= htmlspecialchars($flight['driver_name']) ?><?php if(!empty($flight['vehicle_make_plate'])):?><br><small style="color:#666;"><?= htmlspecialchars($flight['vehicle_make_plate']) ?></small><?php endif;?><?php else:?><span style="color:#999;">—</span><?php endif;?></td>
<td><?= !empty($flight['manager_name'])?htmlspecialchars($flight['manager_name']):'<span style="color:#999;">—</span>' ?></td>
<td style="text-align:center;"><?php $pf=$flight['prep_time_filled_count']??0;$tz=$flight['total_zayavki_count']??0;$pc=($tz>0&&$pf==$tz)?'complete':'incomplete';?>
<span class="prozvon-badge <?= $pc ?>"><?= $pf ?> / <?= $tz ?></span></td>
<td style="text-align:center;"><?= $flight['unique_addresses_count']??0 ?> / <?= $flight['total_zayavki_count']??0 ?></td>
<td style="text-align:right;"><?= number_format($flight['total_mass_kg']??0,0,'.',' ') ?> кг</td>
<td style="text-align:center;">
<div class="action-buttons">
    <!-- Кнопка списка заявок -->
    <button type="button" class="action-btn btn-requests" data-requests='<?= $zj ?>' onclick="showRequests(this,event)" title="Список заявок">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
            <polyline points="22,6 12,13 2,6"></polyline>
        </svg>
    </button>
    
    <!-- Кнопка маршрута с зашифрованным токеном -->
    <button type="button" class="action-btn btn-route" data-flight-token="<?= $token ?>" onclick="openRoute(this,event)" title="Показать маршрут">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
            <circle cx="12" cy="10" r="3"></circle>
        </svg>
    </button>
</div>
</td>
</tr>
<tr id="details_<?= $flight['id'] ?>" style="display:none;"><td colspan="13" style="padding:0;"><div class="block-details"><table class="details-table"><thead><tr><th>ID ЗАЯВКИ</th><th>МАССА БРУТТО, КГ</th><th>ГРУЗООТПРАВИТЕЛЬ</th><th>АДРЕС ПОГРУЗКИ</th><th>КОНТАКТЫ</th><th>ДОГОВОР</th><th>ПРОПУСК</th><th>КОММЕНТАРИИ</th></tr></thead><tbody>
<?php if(!empty($flight['zayavki'])):foreach($flight['zayavki'] as $zi):$mk=floatval($zi['mass_brutto']??0)*1000;$pt=$zi['prep_time']??'';$cm=$zi['comments']??'';$dc=(is_numeric($pt)&&$pt>3)?'high':'';$contacts=trim((string)($zi['contacts']??''));
$ch=$contacts!==''?nl2br(htmlspecialchars($contacts)):'<span class="empty-value">—</span>';?>
<tr>
<td class="text-center"><strong><?= htmlspecialchars($zi['zayavka_id']) ?></strong></td>
<td class="text-right"><?= number_format($mk,0,'.',' ') ?></td>
<td style="text-align:left;"><?= htmlspecialchars($zi['naim_oo_gruzootpravitel']??'') ?></td>
<td style="text-align:left;"><?= htmlspecialchars($zi['mno_adres_pogruzki']??'') ?></td>
<td class="contacts-cell"><?= $ch ?></td>
<td class="text-center"><strong><?php $pp=$price_with_details[$zi['zayavka_id']]['price_per_kg']??null;echo ($pp==40||$pp==36)?'Ф-25':'Д-26';?></strong></td>
<td class="text-center"><?php if(!empty($pt)):?><span class="days-badge <?= $dc ?>"><?= htmlspecialchars($pt) ?></span><?php else:?><span class="empty-value">—</span><?php endif;?></td>
<td class="comments-cell"><?php if(!empty($cm)):?><?= nl2br(htmlspecialchars($cm)) ?><?php else:?><span class="empty-value">—</span><?php endif;?></td>
</tr>
<?php endforeach;else:?><tr><td colspan="8" class="text-center text-muted">Данные заявок не найдены</td></tr><?php endif;?></tbody></table></div></td></tr>
<?php endforeach;?></tbody></table></div></div><?php else:?><div class="card"><p style="text-align:center;color:#666;"><?php switch($selectedTab){case'planned':echo'Рейсы со статусами planned_route/found не найдены';break;case'in_transit':echo'Рейсы в пути не найдены';break;case'unloaded':echo'Выгруженные рейсы не найдены';break;default:echo'Рейсы не найдены';}?></p></div><?php endif;?></div>
<script>
function toggleFlight(flightId,event){
    if(event&&event.target.closest('.action-btn'))return;
    const r=document.getElementById(flightId),h=r.previousElementSibling,a=h.querySelector('.toggle-arrow');
    if(r.style.display==='none'){r.style.display='table-row';a.textContent='▼';}else{r.style.display='none';a.textContent='▶';}
}
function showRequests(btn,ev){
    ev.stopPropagation();ev.preventDefault();
    try{
        const d=JSON.parse(btn.dataset.requests);
        const w=window.open('','DeleteWindow','width=300,height=300,scrollbars=yes,resizable=yes');
        if(!w){alert('Разрешите всплывающие окна');return;}
        let h='<html><head><meta charset="UTF-8"><title>Заявки</title><style>body{font-family:monospace;font-size:14px;margin:10px}table{width:100%;border-collapse:collapse}th,td{padding:3px 8px;text-align:left}</style></head><body><table><thead><tr><th>Заявка</th><th>Масса нетто, т</th></tr></thead><tbody>';
        for(let i=0;i<d.length;i++)h+='<tr><td>'+d[i].id+'</td><td>'+d[i].weight+'</td></tr>';
        h+='</tbody></table></body></html>';w.document.write(h);w.document.close();w.focus();
    }catch(e){console.error(e);alert('Ошибка загрузки данных');}
}
function openRoute(btn, ev){
    ev.stopPropagation();
    ev.preventDefault();
    const token = btn.getAttribute('data-flight-token');
    if(token){
        window.open('../mapmini.php?token=' + token, '_blank');
    }else{
        alert('Токен рейса не найден');
    }
}
document.addEventListener('DOMContentLoaded',function(){
    const tb=document.querySelectorAll('.tab-button'),ct='<?= $selectedTab ?>';
    function st(tid){localStorage.setItem('timeline_selected_tab',tid);window.location.href=window.location.pathname+'?tab='+tid;}
    tb.forEach(b=>b.addEventListener('click',function(e){e.preventDefault();const tid=this.getAttribute('data-tab');if(tid&&tid!==ct)st(tid);}));
});
</script>
</body></html>
