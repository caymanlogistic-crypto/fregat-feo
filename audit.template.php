<?php
declare(strict_types=1);
// Standalone read-only audit. PHP 7.4+ with PDO MySQL and zlib. No application bootstrap.
const ACCESS_HASH = '__ACCESS_HASH__';
const REFERENCE_GZIP = '__REFERENCE_GZIP__';

function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function configLiteral($token): string {
    if(!is_array($token) || !in_array($token[0],[T_CONSTANT_ENCAPSED_STRING,T_LNUMBER],true)) throw new RuntimeException('Configuration is not literal');
    if($token[0]===T_LNUMBER) return $token[1];
    $value=substr($token[1],1,-1);
    return $token[1][0]==="'" ? str_replace(["\\'","\\\\"],["'","\\"],$value) : stripcslashes($value);
}
function parseConfig(string $source): array {
    // Tokenize, never include/eval config.php: arbitrary PHP in that file is not executed.
    $tokens=array_values(array_filter(token_get_all($source),static function($t): bool { return !is_array($t) || !in_array($t[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true); }));
    $result=[];
    for($i=0;$i<count($tokens);$i++) {
        if(!is_array($tokens[$i]) || $tokens[$i][0]!==T_VARIABLE || $tokens[$i][1]!=='$dbConfig') continue;
        if(($tokens[$i+1]??null)!=='=') continue;
        if($result!==[]) throw new RuntimeException('Ambiguous dbConfig assignments');
        $j=$i+2; $close=']';
        if(is_array($tokens[$j]??null) && $tokens[$j][0]===T_ARRAY) { $j++; $close=')'; }
        if(($tokens[$j]??null)!==($close===']'?'[':'(')) throw new RuntimeException('Unsupported dbConfig');
        $j++;
        while(($tokens[$j]??null)!==$close) {
            $key=configLiteral($tokens[$j++]??null);
            if(!is_array($tokens[$j]??null) || $tokens[$j][0]!==T_DOUBLE_ARROW) throw new RuntimeException('Unsupported dbConfig key');
            $j++;
            $value=configLiteral($tokens[$j++]??null);
            if(array_key_exists($key,$result)) throw new RuntimeException('Duplicate dbConfig key');
            $result[$key]=$value;
            if(($tokens[$j]??null)===',') $j++;
            elseif(($tokens[$j]??null)!==$close) throw new RuntimeException('Unsupported dbConfig expression');
        }
        if(($tokens[$j+1]??null)!==';') throw new RuntimeException('Unsupported dbConfig statement');
        $i=$j;
    }
    foreach(['host','dbname','username','password'] as $key) if(!array_key_exists($key,$result)) throw new RuntimeException('Incomplete dbConfig');
    return $result;
}
function numberValue($value): ?float {
    $value = str_replace(',', '.', trim((string)$value));
    if ($value === '' || !is_numeric($value)) return null;
    $n = (float)$value;
    return is_finite($n) && abs($n) <= 180 ? $n : null;
}
function samePair(array $a, array $b): bool {
    foreach ([0,1] as $i) {
        $x=numberValue($a[$i] ?? null); $y=numberValue($b[$i] ?? null);
        if ($x===null || $y===null || abs($x-$y)>0.0000001) return false;
    }
    return true;
}
function classify(array $rows, array $ref): string {
    if (count($rows)>1) return 'duplicate_id';
    if (!$rows) return 'missing';
    $pair=[$rows[0]['sh'],$rows[0]['d']];
    if (numberValue($pair[0])===null || numberValue($pair[1])===null) return 'invalid';
    $old=$ref['old']; $new=$ref['new'];
    $isOld=is_array($old) && samePair($pair,$old);
    $isNew=is_array($new) && samePair($pair,$new);
    if ($isOld && $isNew) return 'both';
    if ($isOld) return 'old_file';
    if ($isNew) return $old===null ? 'new_only_new_order' : 'new_file';
    if ($old===null && is_array($new) && samePair($pair,[$new[1],$new[0]])) return 'new_only_old_order';
    return 'different';
}
function labels(): array { return [
    'old_file'=>'Совпадает с файлом 15.09 (старый порядок)',
    'new_file'=>'Совпадает с файлом 18.09 (новый порядок)',
    'new_only_new_order'=>'Нет в файле 15.09; совпадает с файлом 18.09',
    'new_only_old_order'=>'Нет в файле 15.09; соответствует обратному порядку файла 18.09',
    'both'=>'Совпадает с обоими файлами',
    'different'=>'Отличается от обоих файлов — нужна проверка',
    'invalid'=>'Неполная или некорректная пара координат',
    'duplicate_id'=>'Несколько строк с одним ID — неоднозначность',
    'missing'=>'В старой базе не найдена',
]; }
function reference(): array {
    $raw=gzdecode(base64_decode(REFERENCE_GZIP,true));
    if ($raw===false) throw new RuntimeException('Reference unavailable');
    $data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    return $data;
}
function pickColumn(array $columns,array $names): string {
    foreach ($names as $name) foreach ($columns as $column) if (trim($column)===$name) return $column;
    throw new RuntimeException('Required column absent');
}
function identifier(string $name): string { return '`'.str_replace('`','``',$name).'`'; }
function readAudit(PDO $pdo,array $ref): array {
    // Fail closed if the server cannot enforce a read-only transaction.
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    try {
        $q=$pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='feo' ORDER BY ORDINAL_POSITION");
        $columns=$q->fetchAll(PDO::FETCH_COLUMN);
        $id=pickColumn($columns,['zayavka_id','Заявка, ID','Заявка ID','request_id']);
        $sh=pickColumn($columns,['mno_sh','МНО_Координаты (Ш)']);
        $d=pickColumn($columns,['mno_d','МНО_Координаты (Д)']);
        $found=[];
        foreach (array_chunk(array_keys($ref['requests']),300) as $ids) {
            $sql='SELECT '.identifier($id).' AS request_id,'.identifier($sh).' AS sh,'.identifier($d).' AS d FROM `feo` WHERE '.identifier($id).' IN ('.implode(',',array_fill(0,count($ids),'?')).')';
            $q=$pdo->prepare($sql); $q->execute(array_map('strval',$ids));
            while ($row=$q->fetch(PDO::FETCH_ASSOC)) $found[trim((string)$row['request_id'])][]=['sh'=>$row['sh'],'d'=>$row['d']];
        }
        $pdo->rollBack();
    } catch (Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    $counts=array_fill_keys(array_keys(labels()),0); $rows=[];
    foreach($ref['requests'] as $id=>$r) {
        $matches=$found[(string)$id] ?? [];
        $status=classify($matches,$r); $counts[$status]++;
        $rows[]=['request_id'=>(string)$id,'status'=>$status,'legacy_rows'=>$matches,'file_2026_09_15'=>$r['old'],'file_2026_09_18'=>$r['new']];
    }
    return ['report_version'=>1,'checked_at_utc'=>gmdate('c'),'mode'=>'READ_ONLY','table'=>'feo','columns'=>['id'=>$id,'sh'=>$sh,'d'=>$d],
        'sources'=>$ref['sources'],'scope'=>'Only request IDs present in the local source files dated 2026-09-15 or 2026-09-18; all legacy feo rows for these IDs, without block/status filtering.',
        'pair_order'=>['raw_column_sh','raw_column_d'],'reference_request_count'=>count($rows),'counts'=>$counts,'status_labels'=>labels(),'rows'=>$rows];
}
function selfTest(): void {
    $config=parseConfig('<?php $dbConfig = ["host"=>"localhost", "dbname"=>"test", "username"=>"reader", "password"=>"fixture-only"]; throw new Exception("Must never execute");');
    if($config['password']!=='fixture-only') throw new RuntimeException('Config parser failure');
    $rejected=false;
    try { parseConfig('<?php $dbConfig=["host"=>getenv("DB_HOST")];'); } catch(Throwable $e) { $rejected=true; }
    if(!$rejected) throw new RuntimeException('Dynamic config was not rejected');
    $ref=['old'=>['86.842704','53.745021'],'new'=>['53.745021','86.842704']];
    $cases=[
        [[], $ref, 'missing'],
        [[['sh'=>'86,842704','d'=>'53.745021']],$ref,'old_file'],
        [[['sh'=>'53.745021','d'=>'86.842704']],$ref,'new_file'],
        [[['sh'=>'53','d'=>'86']],$ref,'different'],
        [[['sh'=>null,'d'=>'86']],$ref,'invalid'],
        [[['sh'=>'181','d'=>'53']],$ref,'invalid'],
        [[['sh'=>'53','d'=>'86'],['sh'=>'86','d'=>'53']],$ref,'duplicate_id'],
        [[['sh'=>'86.842704','d'=>'53.745021']],['old'=>null,'new'=>$ref['new']],'new_only_old_order'],
        [[['sh'=>'53.745021','d'=>'86.842704']],['old'=>null,'new'=>$ref['new']],'new_only_new_order'],
    ];
    foreach($cases as $case) if(classify($case[0],$case[1])!==$case[2]) throw new RuntimeException('Self-test failure: '.$case[2]);
    $data=reference();
    if($data['requests']['310340']['old']!==null || !samePair($data['requests']['310340']['new'],['53.745021','86.842704'])) throw new RuntimeException('Reference failure');
    echo 'SELF_TEST_PASS cases='.count($cases).' reference_requests='.count($data['requests']).PHP_EOL;
}
if(PHP_SAPI==='cli') {
    if(($argv[1]??'')==='--self-test') { selfTest(); exit; }
    fwrite(STDERR,"Open this file via HTTPS in your browser.\n"); exit(1);
}
ini_set('display_errors','0');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
$secure=isset($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS'])!=='off' && (string)$_SERVER['HTTPS']!=='';
if(!$secure) { http_response_code(400); echo 'Откройте скрипт по HTTPS. Реквизиты БД нельзя вводить через HTTP.'; exit; }
$report=null; $error='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    if(!hash_equals(ACCESS_HASH,hash('sha256',(string)($_POST['access_key']??'')))) {
        http_response_code(403); $error='Неверный ключ доступа.';
    } else {
        try {
            if(empty($_POST['manual_connection'])) {
                $configPath=__DIR__.'/config.php';
                if(!is_file($configPath) || !is_readable($configPath) || filesize($configPath)>1048576) throw new RuntimeException('Config missing');
                $config=parseConfig((string)file_get_contents($configPath));
            } else {
                $config=['host'=>(string)($_POST['db_host']??''),'dbname'=>(string)($_POST['db_name']??''),'username'=>(string)($_POST['db_user']??''),'password'=>(string)($_POST['db_password']??'')];
            }
            $host=trim($config['host']); $name=trim($config['dbname']); $user=trim($config['username']);
            if(!preg_match('/^[a-zA-Z0-9._-]{1,253}$/D',$host) || !preg_match('/^[a-zA-Z0-9_-]{1,128}$/D',$name) || $user==='') throw new RuntimeException('Invalid connection fields');
            if(!extension_loaded('pdo_mysql') || !function_exists('gzdecode')) throw new RuntimeException('Missing PDO MySQL or zlib');
            $pdo=new PDO('mysql:host='.$host.';port=3306;dbname='.$name.';charset=utf8mb4',$user,$config['password'],[
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>8,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false,
            ]);
            $report=readAudit($pdo,reference()); $pdo=null;
        } catch(Throwable $e) {
            http_response_code(422);
            $code=$e instanceof PDOException ? (string)$e->getCode() : '';
            $error='Проверка не выполнена. Автоподключение требует соседний config.php с обычным массивом $dbConfig (host, dbname, username, password). Если настройки вычисляются или лежат в другом файле, раскройте ручное подключение. Также проверьте таблицу feo, поля ID/координат и поддержку PHP PDO MySQL, zlib и транзакций READ ONLY.';
            if(preg_match('/^[A-Z0-9]{5}$/D',$code)) $error.=' SQLSTATE: '.$code;
        }
    }
}
?><!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Проверка координат старой БД ФЭО</title>
<style>body{font:16px system-ui,sans-serif;margin:32px auto;padding:0 20px;max-width:1100px;color:#202936}h1{font-size:26px}label{display:block;margin:14px 0}input{display:block;box-sizing:border-box;width:100%;max-width:460px;padding:9px;border:1px solid #aab4c1;border-radius:4px}button,.download{display:inline-block;padding:10px 16px;background:#175d92;color:white;border:0;border-radius:4px;text-decoration:none}table{border-collapse:collapse;width:100%;font-size:14px;margin:18px 0}td,th{padding:9px;text-align:left;border-bottom:1px solid #ddd;vertical-align:top}th{background:#eef2f6}.error{padding:12px;background:#fee;color:#800}.muted{color:#55616d}summary{cursor:pointer;margin:16px 0}code{white-space:nowrap}</style>
<h1>Проверка координат старой БД ФЭО</h1>
<p>Только чтение. Сравнение по ID заявки с выгрузками 15 и 18 сентября 2026 года. Скрипт не меняет заявки, координаты или структуру базы.</p>
<?php if($error): ?><p class="error"><?=h($error)?></p><?php endif ?>
<?php if(!$report): ?>
<form method="post" autocomplete="off">
<label>Ключ доступа к проверке<input type="password" name="access_key" required autocomplete="off"></label>
<p>По умолчанию реквизиты читаются из <code>config.php</code> рядом со скриптом, как в <code>index22.php</code>. Код этих файлов не запускается.</p>
<details><summary>Ручное подключение, если автоматическое не сработало</summary>
<label><input type="checkbox" name="manual_connection" value="1">Использовать введённые ниже реквизиты вместо config.php</label>
<label>Сервер старой БД<input name="db_host" value="localhost"></label>
<label>Имя старой базы<input name="db_name"></label>
<label>Пользователь БД<input name="db_user" autocomplete="off"></label>
<label>Пароль БД<input type="password" name="db_password" autocomplete="new-password"></label>
<p class="muted">Это реквизиты MySQL из настроек хостинга, а не логин и пароль ERP. Они используются только для этого запроса, не записываются скриптом в файлы или сессию и не включаются в отчёт. Для существующего пользователя только на чтение используйте его реквизиты.</p>
</details><button>Проверить без изменения данных</button></form>
<?php else: $json=json_encode($report,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR); ?>
<p><b>Проверка завершена.</b> ID в сравнении: <?=count($report['rows'])?>. Время UTC: <?=h($report['checked_at_utc'])?>.</p>
<a class="download" download="legacy-feo-coordinate-audit.json" href="data:application/json;base64,<?=base64_encode($json)?>">Скачать отчёт JSON</a>
<table><thead><tr><th>Результат</th><th>Заявок</th></tr></thead><tbody>
<?php foreach($report['counts'] as $status=>$count): ?><tr><td><?=h(labels()[$status])?></td><td><?=$count?></td></tr><?php endforeach ?></tbody></table>
<p class="muted">Совпадение с файлом — факт сравнения, а не подтверждение правильности места погрузки. Отличия могут быть ручными корректировками. Заявки, отсутствующие в обеих выгрузках, не проверяются.</p>
<?php function renderRows(array $rows): void { ?>
<table><thead><tr><th>Заявка</th><th>Старая БД: Ш / Д</th><th>15.09: Ш / Д</th><th>18.09: Ш / Д</th><th>Результат</th></tr></thead><tbody>
<?php foreach($rows as $row): ?><tr><td><?=h($row['request_id'])?></td><td><?php foreach($row['legacy_rows'] as $pair): ?><?=h((string)$pair['sh'].' / '.(string)$pair['d'])?><br><?php endforeach ?></td><td><?=h($row['file_2026_09_15']===null?'Нет заявки':implode(' / ',$row['file_2026_09_15']))?></td><td><?=h($row['file_2026_09_18']===null?'Нет заявки':implode(' / ',$row['file_2026_09_18']))?></td><td><?=h(labels()[$row['status']])?></td></tr><?php endforeach ?></tbody></table>
<?php } ?>
<h2>Четыре заявки последнего блока</h2>
<?php renderRows(array_values(array_filter($report['rows'],static function(array $row): bool { return in_array($row['request_id'],['291382','312015','310340','309391'],true); }))); ?>
<details><summary>Все результаты</summary><?php renderRows($report['rows']); ?></details>
<p>Скачайте отчёт и передайте его для разбора. После проверки удалите PHP-файл с хостинга.</p>
<?php endif ?></html>
