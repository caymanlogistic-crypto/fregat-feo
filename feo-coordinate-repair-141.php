<?php
declare(strict_types=1);
// Task-specific repair of exactly 141 rows; PHP 7.4+, PDO MySQL, zlib.
const REPAIR_ACCESS_HASH = 'ad1a8cc5640c616705149f705ed0d86a236f916ff1a59b21229bad21a2e335e1';
const REPAIR_MANIFEST_GZIP = 'H4sIAGxDrWoC/4VYS2ulxxH9L1pbl67uenodyCYEAiFZBDPcZ2Iy8TgjOx4w899z+uuqFiRY2sxI4tx616nT99enl08/f77eP3y+//jp808fXv5x7qJP3z5dzCRcXYRY74NvdnuoXvymdpGwh93ofhmN7m7t0i/33sbtaverne3e++3x9E1afnn69tenTx9v87/H9x/vP5z/dYf9m/HVafBFmIfF4/T4+/cvz59+/M8/n7k999b1ucUzyXOjD3/99OXP/fd/OH35+PJl2q0Yacil8b1FnOH+Pkwej0eMy42D7HyjMc7n87XFCDrr7XY7twvf9DqMjc4Xuzx9/ebph/sv/xOZxqN5u96R26M9bvIbkTn+/fCXj/13f/zlT/8XmcWlj94ucB5Xk/t5XOzOdLuGm17oKk0f8Tjf2W+BoM8Xkmtjvsa9DZHr+ekrQvt8//fP95efjgJ2jdba07d/e5J+0saN4W7EieGAxtN3+KU1IjsQ40TNmGlC/ET4yWRBxHpMCMdJfJB0QLifDI3sVBCA34a4p6M4eXdhm47aqQfM94SEUEbLKJmNFW5EMKWVENheVjqZBiI7MB5NYShBQSsaPmnnNjKnRphBPyDUwpYdPgk1GT4heuqskWkTRdfDip6Qn7MvK4jXBy9IJ/EDMpC2IXNd0RgF0u0JUl89gB1mfGS5osbebEFG53FA/CSicZiRdgSsGc1Q5QMi6CQQs8JCp1ClXhDLgOU02EloNcExU40S4qFpRUwzbfRMRlNdEO59ZNpD3XX2iVE8zINbFIZ7egoe7SgNGzDCsSGiG6JY14QMZrOCaBXYJTBECyJEPHYwOVeAKIelFekoWUE8a8enIdERZxsqMkPCTGMAhyvy4Fni/IBTRoZWhmXPFHOwTTrn/IiwH4UcA3Uf/mrD3rMR/W2E0KoQogwffuzM3Dx3tCYhYwUCSKegOeuIKcJYsobonGT2s55HDQcK5NiZ2BBLCLZRNBHYcOGN8OIAbl0kIyHDEhaEW0LMmnguA/UxKaMglH4Yixy2HDWJ2Clrt2ynoVUWOVtjSJOCLA54E6JeEG9CIyHdeFTOauVoYKG9LwiRzs8uiIm858jk3XBRjXLESr4h6kYbsvpsc1tbn+GCC3rnWaOC+LtWourSsR9dK2klqzZ644Rg/qPlVoKwmveC0A4X94oSwehitch73+uPz0mt/+SCgiSJNBQ35GAIEFpg9aL8xKAktKaOjFbOA9ntoZs0B4i2E8WkK0AUrG6NbFthL1qkPol5/hUbYq2WGRNf24/Bpc65IqjhhtAa3TccKRqa0z1Qt3GkBK+mJDxkgzyv0xiggtkkkPhpgBgHFWZRGs5TDFuLhCp13KkcO2WqgKW1ReNyclTdNiASgHkesQCop0pFy5vxGhL1I1jsP7hyxwoSTZ43Cz7Kgl1UsLxUqLLpZ5j7kY5PKjKhCkVsVw5O4zhLyEzHa1HE+H1IJeRI3mg5IvB05EThiFmuCGiRj+HWeego6kSqrrnEFIHPbN30fkIL6dVKDh2sQKwJLys4+o23FS491HvQsQCzh92Mq0F1rnkeD2+5aOIcr5BoWV3oFD5imRcVc757lLyAcAOTcLQRNWLIBC6EeyIURKB5/XBtrNXkWox3jPiaJnwQx7RFXhnX0Uel7Kl0YLsLbgsg3bHcKOi2EluaoeaHnynNIBAkCRVKq6oy1f1RFe9zWtCjguR+YOaaH3cGwcok5WyQtTYSMb1wii5cC/SiIMJboMRWOQT+Gxsh5WY6HTnaA6o2i29EVXw4N6elBWCPirdtZrkgHXfLDt4Yk8OqbtbXrQLCJ/ooLTaVsbaxIV6lBU9oalEBb1eDbHRJK3ga0aoJ+q11Na2o5w2A5IYFVkwOJzzVb5cNSZkA5Snz76t/YGZ+teJSLR6CZ0YyE2pWwg+QIuR5So+qHVOAMxAbYu85Qhu2JEaQS9YAP0VobccBzx5isPuaBINubnDmG7RFpvUZP1rUpoLyUj/QQXsWeK5fihtgInTbeZ3LgQ8v6gCoA+I7Ih3bkrNlQADbq53ksWnHiaTMqBS92DyGSzhCOg1bqUOO+h5NsUoKpxsP0DVVqGsU68JhiT63eQTnqmG/oaOkptf2ow3Uua4VqoR89tyhqDmaDY88n34UK9Bjb5rlfL+RsfVdOcWb6uhA4NAwFy2Didu7Vviom07RKXqEMi86lqfkGuRPbT10SzgvzgWh4HglxPfjxqX5oSgwDQ1zWKV1q4R0klvOAkaNuOrmPrI/fb6XdL2QIG60jEQrygX7QYstXsBwbfZHSPtw+vxaYD3WwKJzhhIiJaJwiNTo0J80RTeopPZt0tuSUbgFQ3O+EQwaXRDfDYiDAtfI4XBVA7wNemVdrU2aGrn0mk/WywcoHk7H0YsBClYpR04pqf3kqNbSy3OIadRiO62n2FR96n096AhWoFeyR96b7oyQJ2VGAwxbjnrfz9j5Rshw8WwcsiGjVhHJzRE8ICPwpuqvmCLn+Z1Ahtv6Fh0AvDd0GKg9uuD3Q93Pbx4wxiXWvLvtJ43mYcRG4TDXS9D71uW/3WqAorYeA6s1d+vblwUpasVHtVN9VXM0qVcLOHdgHuGgdZLmKW/7rgHi70JG6TEQ5jjGTqCY1GL7STLsWEf1emtg1K1eLBA6pUGhQeZFXM/bpvthDp1QGdl8dx6O9DRVTbGuzwFMR/OLhyQYnQetHCHy7BK13i3fplDmulugttU7JIalaJiqlnxD6kUfDFK1/C4HwmcnnXpsqgBpSxriZ5R/vmW++/r1v5Wc0872FAAA';
const REPAIR_MANIFEST_HASH = 'd66d9ceb59a5d4deb17712f8760134034575e3efb28ff432577a12a0d9d50a84';

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


function manifest(): array {
    $json=gzdecode(base64_decode(REPAIR_MANIFEST_GZIP,true));
    if(!is_string($json) || !hash_equals(REPAIR_MANIFEST_HASH,hash('sha256',$json))) throw new RuntimeException('Повреждён список исправления.');
    $data=json_decode($json,true,512,JSON_THROW_ON_ERROR);
    if(count($data['requests'])!==141) throw new RuntimeException('Список должен содержать ровно 141 заявку.');
    foreach($data['requests'] as $id=>$pair) {
        if(!preg_match('/^[0-9]{6}$/D',(string)$id) || count($pair)!==2 || !is_string($pair[0]) || !is_string($pair[1]) || $pair[0]===$pair[1]) throw new RuntimeException('Некорректная пара в списке.');
        if(in_array((string)$id,['291382','312015','310340','309391','298696','308914'],true)) throw new RuntimeException('Запрещённая заявка в списке.');
    }
    return $data;
}
function qi(string $name): string { return '`'.str_replace('`','``',$name).'`'; }
function repairConnection(): PDO {
    $path=__DIR__.'/config.php';
    if(!is_file($path) || !is_readable($path) || filesize($path)>1048576) throw new RuntimeException('Не найден читаемый config.php рядом со скриптом.');
    try { $cfg=parseConfig((string)file_get_contents($path)); }
    catch(Throwable $e) { throw new RuntimeException('config.php имеет неподдерживаемую структуру. Код конфигурации не запускался.'); }
    $port=$cfg['port']??'3306';
    if(!preg_match('/^[a-zA-Z0-9._-]{1,253}$/D',$cfg['host']) || !preg_match('/^[a-zA-Z0-9_-]{1,128}$/D',$cfg['dbname']) || !ctype_digit((string)$port) || (int)$port<1 || (int)$port>65535) throw new RuntimeException('Некорректные параметры подключения.');
    return new PDO('mysql:host='.$cfg['host'].';port='.$port.';dbname='.$cfg['dbname'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STRINGIFY_FETCHES=>true,
        PDO::ATTR_TIMEOUT=>8,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false,
    ]);
}
function canInspectTriggers(array $grants,string $database): bool {
    foreach($grants as $grant) {
        if(!preg_match('/^GRANT (.+?) ON (.+?) TO /i',$grant,$m)) continue;
        $scope=str_replace(['`','\\'],['',''],$m[2]);
        if(!in_array($scope,['*.*',$database.'.*',$database.'.feo'],true)) continue;
        $privileges=array_map('trim',explode(',',strtoupper($m[1])));
        if(in_array('ALL PRIVILEGES',$privileges,true) || in_array('TRIGGER',$privileges,true)) return true;
    }
    return false;
}
function safety(PDO $pdo): array {
    $identity=$pdo->query('SELECT DATABASE() AS db,@@hostname AS server,@@port AS port')->fetch(PDO::FETCH_ASSOC);
    if(!$identity || !$identity['db']) throw new RuntimeException('Не выбрана база данных.');
    $engine=$pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='feo'")->fetchColumn();
    if(strtoupper((string)$engine)!=='INNODB') throw new RuntimeException('Операция остановлена: таблица feo должна быть InnoDB.');
    $columns=$pdo->query("SELECT COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='feo' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);
    $names=array_column($columns,'COLUMN_NAME');
    foreach(['id','zayavka_id','mno_sh','mno_d','updated_at'] as $required) if(!in_array($required,$names,true)) throw new RuntimeException('Операция остановлена: отсутствует поле '.$required.'.');
    $auto=[];
    foreach($columns as $column) {
        if((string)$column['GENERATION_EXPRESSION']!=='') throw new RuntimeException('Операция остановлена: обнаружены вычисляемые поля.');
        if(in_array($column['COLUMN_NAME'],['mno_sh','mno_d'],true) && !in_array(strtolower($column['DATA_TYPE']),['varchar','text','mediumtext','longtext'],true)) throw new RuntimeException('Операция остановлена: неожиданный тип координат.');
        if(stripos((string)$column['EXTRA'],'on update')!==false) $auto[]=$column['COLUMN_NAME'];
    }
    if(!in_array('updated_at',$auto,true)) $auto[]='updated_at';
    $indexes=$pdo->query("SELECT INDEX_NAME,NON_UNIQUE,COLUMN_NAME,SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='feo' ORDER BY INDEX_NAME,SEQ_IN_INDEX")->fetchAll(PDO::FETCH_ASSOC);
    $unique=[];
    foreach($indexes as $index) if((int)$index['NON_UNIQUE']===0) $unique[$index['INDEX_NAME']][]=$index;
    foreach(['id','zayavka_id'] as $required) {
        $ok=false;
        foreach($unique as $parts) if(count($parts)===1 && $parts[0]['COLUMN_NAME']===$required && $parts[0]['SUB_PART']===null) $ok=true;
        if(!$ok) throw new RuntimeException('Операция остановлена: требуется уникальный индекс '.$required.'.');
    }
    $grants=$pdo->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(PDO::FETCH_COLUMN);
    if(!canInspectTriggers($grants,$identity['db'])) throw new RuntimeException('Операция остановлена: прав недостаточно для достоверной проверки триггеров. Ничего не изменено.');
    $triggerCount=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='feo'")->fetchColumn();
    if($triggerCount!==0) throw new RuntimeException('Операция остановлена: на feo есть триггеры; нельзя гарантировать отсутствие побочных изменений.');
    $fkCount=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE (TABLE_SCHEMA=DATABASE() AND TABLE_NAME='feo' AND COLUMN_NAME IN ('mno_sh','mno_d') AND REFERENCED_TABLE_NAME IS NOT NULL) OR (REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='feo' AND REFERENCED_COLUMN_NAME IN ('mno_sh','mno_d'))")->fetchColumn();
    if($fkCount!==0) throw new RuntimeException('Операция остановлена: координаты участвуют во внешних ключах.');
    return ['identity'=>$identity,'columns'=>$columns,'auto'=>$auto,'indexes'=>$indexes];
}
function loadRows(PDO $pdo,array $requests,bool $lock): array {
    $ids=array_map('strval',array_keys($requests));
    $sql='SELECT * FROM `feo` WHERE `zayavka_id` IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY `zayavka_id`'.($lock?' FOR UPDATE':'');
    $q=$pdo->prepare($sql); $q->execute($ids); $rows=[];
    while($row=$q->fetch(PDO::FETCH_ASSOC)) {
        $id=(string)$row['zayavka_id'];
        if(!array_key_exists($id,$requests) || isset($rows[$id])) throw new RuntimeException('Неоднозначный ID заявки: '.$id.'.');
        $rows[$id]=$row;
    }
    ksort($rows,SORT_STRING);
    return $rows;
}
function buildPlan(array $rows,array $requests): array {
    $pending=[]; $done=[]; $conflicts=[];
    foreach($requests as $id=>$expected) {
        $id=(string)$id;
        if(!isset($rows[$id])) { $conflicts[]=$id.' — не найдена'; continue; }
        $row=$rows[$id]; $actual=[$row['mno_sh'],$row['mno_d']];
        if($actual===$expected) $pending[]=$id;
        elseif($actual===[$expected[1],$expected[0]]) $done[]=$id;
        else $conflicts[]=$id.' — текущая пара отличается от отчёта и ожидаемого результата';
    }
    return ['pending'=>$pending,'done'=>$done,'conflicts'=>$conflicts];
}
function fingerprint(array $meta,array $rows): string { return hash('sha256',json_encode([$meta,$rows],JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR)); }
function previewRepair(PDO $pdo,array $requests): array {
    $pdo->exec('SET TRANSACTION READ ONLY'); $pdo->beginTransaction();
    try {
        $meta=safety($pdo); $rows=loadRows($pdo,$requests,false); $plan=buildPlan($rows,$requests);
        $pdo->rollBack();
        return ['meta'=>$meta,'plan'=>$plan,'fingerprint'=>fingerprint($meta,$rows)];
    } catch(Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function artifactWrite(string $directory,string $name,array $data): string {
    if(!preg_match('/^[a-z0-9_-]+\.php$/D',$name)) throw new RuntimeException('Недопустимое имя файла копии.');
    if(file_exists($directory) && !is_dir($directory)) throw new RuntimeException('Не удалось создать каталог резервной копии.');
    if(!is_dir($directory) && !@mkdir($directory,0700) && !is_dir($directory)) throw new RuntimeException('Не удалось создать каталог резервной копии.');
    if(is_link($directory)) throw new RuntimeException('Каталог резервной копии не должен быть ссылкой.');
    $path=$directory.DIRECTORY_SEPARATOR.$name;
    $json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
    $body="<?php http_response_code(404); exit; ?>\n".$json;
    $file=@fopen($path,'xb');
    if(!$file) throw new RuntimeException('Не удалось сохранить резервную копию. Запись в БД не начиналась.');
    try {
        @chmod($path,0600); $offset=0;
        while($offset<strlen($body)) { $n=fwrite($file,substr($body,$offset)); if(!$n) throw new RuntimeException('Неполная запись резервной копии.'); $offset+=$n; }
        if(!fflush($file)) throw new RuntimeException('Не удалось сохранить резервную копию.');
        if(function_exists('fsync') && !fsync($file)) throw new RuntimeException('Не удалось синхронизировать резервную копию.');
    } finally { fclose($file); }
    if(!hash_equals(hash('sha256',$body),hash_file('sha256',$path))) throw new RuntimeException('Не прошла проверка резервной копии.');
    return $path;
}
function applyRepair(PDO $pdo,array $requests,string $expectedFingerprint,string $backupDirectory): array {
    $lockName='feo_coords_'.substr(REPAIR_MANIFEST_HASH,0,32);
    $q=$pdo->prepare('SELECT GET_LOCK(?,5)'); $q->execute([$lockName]);
    if((int)$q->fetchColumn()!==1) throw new RuntimeException('Другая проверка заняла блокировку. Повторите позднее.');
    $committed=false;
    try {
        $pdo->exec('SET TRANSACTION READ WRITE'); $pdo->beginTransaction();
        // The locking read pins the table metadata until the transaction ends.
        $rows=loadRows($pdo,$requests,true); $meta=safety($pdo); $plan=buildPlan($rows,$requests);
        if($plan['conflicts']) throw new RuntimeException('Конфликт: '.implode('; ',$plan['conflicts']).'. Весь пакет отменён.');
        if(!hash_equals($expectedFingerprint,fingerprint($meta,$rows))) throw new RuntimeException('Данные или структура изменились после просмотра. Выполните проверку заново.');
        if(!$plan['pending']) { $pdo->rollBack(); return ['changed'=>0,'already_done'=>count($plan['done']),'backup'=>null,'receipt'=>null,'message'=>'Все 141 заявки уже имеют ожидаемый старый порядок. Записей не было.']; }
        $operation=bin2hex(random_bytes(12)); $items=[];
        foreach($plan['pending'] as $id) {
            $row=$rows[$id]; $pair=$requests[$id];
            $items[]=['request_id'=>$id,'row_id'=>$row['id'],'before'=>['mno_sh'=>$pair[0],'mno_d'=>$pair[1]],'after'=>['mno_sh'=>$pair[1],'mno_d'=>$pair[0]],'updated_at'=>$row['updated_at'],'before_full_row_sha256'=>hash('sha256',json_encode($row,JSON_THROW_ON_ERROR))];
        }
        $backup=['version'=>1,'kind'=>'before_change_backup','operation_id'=>$operation,'created_at'=>gmdate('c'),'database'=>$meta['identity'],'manifest_sha256'=>REPAIR_MANIFEST_HASH,'preview_sha256'=>$expectedFingerprint,'status'=>'Prepared before writes; see receipt or rerun preview for final outcome','items'=>$items];
        $backupPath=artifactWrite($backupDirectory,'backup_'.$operation.'.php',$backup);
        $set=['`mno_sh`=?','`mno_d`=?'];
        foreach($meta['auto'] as $column) $set[]=qi($column).'='.qi($column);
        $update=$pdo->prepare('UPDATE `feo` SET '.implode(',',$set).' WHERE `id`=? AND `zayavka_id`=? AND BINARY `mno_sh`=BINARY ? AND BINARY `mno_d`=BINARY ?');
        foreach($plan['pending'] as $id) {
            $pair=$requests[$id];
            $update->execute([$pair[1],$pair[0],$rows[$id]['id'],$id,$pair[0],$pair[1]]);
            if($update->rowCount()!==1) throw new RuntimeException('Неожиданное количество изменённых строк для '.$id.'.');
        }
        $after=loadRows($pdo,$requests,true);
        foreach($rows as $id=>$before) {
            $expected=$before;
            if(in_array((string)$id,$plan['pending'],true)) { $expected['mno_sh']=$requests[$id][1]; $expected['mno_d']=$requests[$id][0]; }
            if(!isset($after[$id]) || $after[$id]!==$expected) throw new RuntimeException('Проверка всех полей не пройдена для '.$id.'. Пакет отменён.');
        }
        if(!$pdo->commit()) throw new RuntimeException('Не удалось подтвердить COMMIT.');
        $committed=true;
        $receipt=['version'=>1,'kind'=>'commit_receipt','operation_id'=>$operation,'committed_at'=>gmdate('c'),'database'=>$meta['identity'],'manifest_sha256'=>REPAIR_MANIFEST_HASH,'changed'=>count($plan['pending']),'already_done'=>count($plan['done']),'changed_ids'=>$plan['pending'],'all_other_fields_unchanged'=>true,'updated_at_unchanged'=>true,'backup_file'=>basename($backupPath)];
        $receiptPath=null; $warning='';
        try { $receiptPath=artifactWrite($backupDirectory,'receipt_'.$operation.'.php',$receipt); }
        catch(Throwable $e) { $warning='Изменения успешно зафиксированы, но файл квитанции сохранить не удалось. Скачайте результат сейчас. Резервная копия сохранена.'; }
        return ['changed'=>count($plan['pending']),'already_done'=>count($plan['done']),'backup'=>$backup,'receipt'=>$receipt,'message'=>$warning?:'Исправление выполнено и проверено. Остальные поля сохранены.'];
    } catch(Throwable $e) {
        if(!$committed && $pdo->inTransaction()) { try { $pdo->rollBack(); } catch(Throwable $ignored) {} }
        throw $e;
    } finally {
        try { $q=$pdo->prepare('SELECT RELEASE_LOCK(?)'); $q->execute([$lockName]); } catch(Throwable $ignored) {}
    }
}
function startRepairSession(): void {
    ini_set('session.use_strict_mode','1'); ini_set('session.use_only_cookies','1');
    session_name('FEOREPAIR'.substr(REPAIR_MANIFEST_HASH,0,12));
    session_set_cookie_params(['lifetime'=>0,'path'=>rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME']??'/')),'/').'/','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off','httponly'=>true,'samesite'=>'Strict']);
    session_start();
}
function downloadLink(string $label,string $action): string { return '<a href="?download='.h($action).'">'.h($label).'</a>'; }
function renderRepair(array $data,?array $view,?array $result,string $error,bool $authenticated): void {
?><!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Точечное исправление координат ФЭО</title>
<style>body{font:16px system-ui,sans-serif;max-width:1100px;margin:32px auto;padding:0 20px;color:#202936}h1{font-size:26px}h2{font-size:20px}table{border-collapse:collapse;width:100%;font-size:14px;margin:16px 0}td,th{padding:9px;text-align:left;border-bottom:1px solid #ddd}th{background:#edf2f6}input[type=password]{padding:10px;width:320px;max-width:90%}button,a{display:inline-block;margin:8px 8px 8px 0;padding:10px 14px;border-radius:4px}button{border:0;background:#175d92;color:white;cursor:pointer}.danger{background:#a82c2c}.error{background:#fff0f0;color:#8a1717;padding:14px}.ok{background:#edf8f1;padding:14px}.muted{color:#55616d}label{display:block;margin:18px 0}details{margin:16px 0}code{white-space:nowrap}</style>
<h1>Точечное исправление координат ФЭО</h1><p>Фиксированный список: <b>141 заявка</b> из отчёта 18.09.2026. Меняются только <code>mno_sh</code> и <code>mno_d</code>. Остальные поля, включая <code>updated_at</code>, сохраняются.</p>
<?php if($error): ?><p class="error"><?=h($error)?></p><?php endif ?>
<?php if(!$authenticated): ?><form method="post"><input type="hidden" name="action" value="login"><label>Ключ доступа<br><input type="password" name="access_key" required autocomplete="off"></label><button>Открыть проверку</button></form><p class="muted">Положите этот файл рядом с index22.php и config.php. Реквизиты БД будут прочитаны из config.php без выполнения его кода.</p>
<?php else: ?>
<?php if($result): ?><div class="ok"><b><?=h($result['message'])?></b><p>Исправлено сейчас: <?=(int)$result['changed']?>. Уже исправлены ранее: <?=(int)$result['already_done']?>.</p></div><?php if($result['backup']): ?><?=downloadLink('Скачать исходные пары (JSON)','backup')?><?=downloadLink('Скачать результат (JSON)','receipt')?><?php endif ?><?php endif ?>
<form method="post"><input type="hidden" name="action" value="preview"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><button>Проверить текущие координаты — без записи</button></form>
<?php if($view): $plan=$view['plan']; ?>
<h2>Предварительная проверка</h2><p>База: <b><?=h($view['meta']['identity']['db'])?></b>. Нужно исправить: <b><?=count($plan['pending'])?></b>. Уже в старом порядке: <b><?=count($plan['done'])?></b>. Конфликтов: <b><?=count($plan['conflicts'])?></b>.</p>
<?php if($plan['conflicts']): ?><div class="error">Запись всего пакета заблокирована.<ul><?php foreach($plan['conflicts'] as $conflict): ?><li><?=h($conflict)?></li><?php endforeach ?></ul></div>
<?php elseif($plan['pending']): ?>
<details><summary>Пары координат до и после исправления</summary><table><tr><th>Заявка</th><th>Сейчас Ш / Д</th><th>Будет Ш / Д</th></tr><?php foreach($plan['pending'] as $id): $pair=$data['requests'][$id]; ?><tr><td><?=h($id)?></td><td><?=h($pair[0].' / '.$pair[1])?></td><td><?=h($pair[1].' / '.$pair[0])?></td></tr><?php endforeach ?></table></details>
<p>Перед записью скрипт сохранит резервную копию исходных пар на сервере. Затем заблокирует проверяемые строки, повторит проверки и выполнит пакет одной транзакцией.</p>
<form method="post"><input type="hidden" name="action" value="apply"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="preview_token" value="<?=h($_SESSION['preview']['token']??'')?>"><label><input type="checkbox" name="confirm" value="yes" required>Подтверждаю перестановку только указанных <?=count($plan['pending'])?> пар координат.</label><button class="danger">Исправить только проверенные координаты</button></form>
<?php else: ?><p class="ok">Все 141 заявки уже имеют ожидаемый старый порядок. Ничего менять не требуется.</p><?php endif ?>
<?php endif ?><p class="muted">После выполнения скачайте исходные пары и результат. Удалите этот скрипт с хостинга; резервную копию сохраните. Повторный запуск не переставляет исправленные пары обратно.</p>
<?php endif ?></html><?php
}
function webRepair(): void {
    ini_set('display_errors','0');
    header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store, private'); header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    startRepairSession();
    $data=manifest(); $view=null; $result=null; $error='';
    $authenticated=isset($_SESSION['auth_at']) && $_SESSION['auth_at']>time()-1800;
    if(!$authenticated) $_SESSION=[];
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
        try {
            $action=(string)($_POST['action']??'');
            if($action==='login') {
                if(!hash_equals(REPAIR_ACCESS_HASH,hash('sha256',(string)($_POST['access_key']??'')))) { http_response_code(403); throw new RuntimeException('Неверный ключ доступа.'); }
                session_regenerate_id(true); $_SESSION=['auth_at'=>time(),'csrf'=>bin2hex(random_bytes(24))]; $authenticated=true;
            } else {
                if(!$authenticated || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))) { http_response_code(403); throw new RuntimeException('Сессия или подтверждение устарели. Войдите заново.'); }
                if($action==='preview') {
                    unset($_SESSION['preview']);
                    $view=previewRepair(repairConnection(),$data['requests']);
                    if(!$view['plan']['conflicts']) $_SESSION['preview']=['fingerprint'=>$view['fingerprint'],'token'=>bin2hex(random_bytes(24)),'created_at'=>time()];
                } elseif($action==='apply') {
                    $preview=$_SESSION['preview']??null; unset($_SESSION['preview']);
                    if(!is_array($preview) || $preview['created_at']<time()-600 || !hash_equals($preview['token'],(string)($_POST['preview_token']??'')) || ($_POST['confirm']??'')!=='yes') throw new RuntimeException('Нужны свежая предварительная проверка и отдельное подтверждение.');
                    $result=applyRepair(repairConnection(),$data['requests'],$preview['fingerprint'],__DIR__.'/.feo-coordinate-backups-'.substr(REPAIR_MANIFEST_HASH,0,12));
                    $_SESSION['result']=$result;
                } else throw new RuntimeException('Неизвестное действие.');
            }
        } catch(PDOException $e) { http_response_code(422); $error='Ошибка БД. Не удалось подтвердить выполнение. Запустите предварительную проверку повторно; она покажет фактическое состояние. SQLSTATE: '.(preg_match('/^[A-Z0-9]{5}$/D',(string)$e->getCode())?(string)$e->getCode():'не указан'); }
        catch(Throwable $e) { if(http_response_code()<400) http_response_code(422); $error=$e->getMessage(); }
    }
    if(isset($_GET['download'])) {
        $kind=(string)$_GET['download'];
        if(!$authenticated || !in_array($kind,['backup','receipt'],true) || empty($_SESSION['result'][$kind])) { http_response_code(403); echo 'Отчёт недоступен. Войдите и выполните проверку.'; return; }
        header('Content-Type: application/json; charset=utf-8'); header('Content-Disposition: attachment; filename="feo-coordinate-'.$kind.'.json"');
        echo json_encode($_SESSION['result'][$kind],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR); return;
    }
    renderRepair($data,$view,$result,$error,$authenticated);
}
if(defined('FEO_REPAIR_LIBRARY_ONLY') && FEO_REPAIR_LIBRARY_ONLY===true) return;
if(PHP_SAPI==='cli') { echo 'Open this file in your browser; library mode is reserved for local tests.'.PHP_EOL; exit(0); }
webRepair();
