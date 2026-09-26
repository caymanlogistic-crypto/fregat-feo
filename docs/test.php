<?php
declare(strict_types=1);
if (defined('PDF_WRAPPER_LOADED')) return;
define('PDF_WRAPPER_LOADED', true);

$config = [
    'python_cmd' => 'python3',
    'parser_script' => __DIR__ . '/parcer.py',
    'output_dir' => __DIR__ . '/output_json',
    'log_file' => __DIR__ . '/wrapper.log'
];

function logMsg(string $msg, string $level = 'INFO', ?string $log = null): void {
    $log = $log ?? (__DIR__ . '/wrapper.log');
    @file_put_contents($log, "[" . date('Y-m-d H:i:s') . "] [{$level}] {$msg}\n", FILE_APPEND | LOCK_EX);
}

function runParser(string $pdfPath, array $cfg): array {
    $res = ['success' => false, 'file' => basename($pdfPath), 'data' => null, 'errors' => [], 'time' => 0];
    $start = microtime(true);

    if (!file_exists($pdfPath)) { $res['errors'][] = "File not found"; return $res; }
    if (!file_exists($cfg['parser_script'])) { $res['errors'][] = "Parser missing"; return $res; }
    if (!is_dir($cfg['output_dir']) && !mkdir($cfg['output_dir'], 0777, true)) { $res['errors'][] = "Dir create failed"; return $res; }

    // Запускаем Python с -i и -o
    $cmd = sprintf('%s %s -i %s -o %s -f json',
        escapeshellarg($cfg['python_cmd']),
        escapeshellarg($cfg['parser_script']),
        escapeshellarg(dirname($pdfPath)),
        escapeshellarg($cfg['output_dir'])
    );

    $desc = [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]];
    $proc = @proc_open($cmd, $desc, $pipes, null, null, ['suppress_errors' => true]);

    if (is_resource($proc)) {
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($proc);
        $res['time'] = round(microtime(true) - $start, 2);

        if ($code !== 0) {
            $res['errors'][] = "Python error (code {$code})";
            if (trim($stderr)) $res['errors'][] = trim($stderr);
            logMsg("FAIL [{$code}]: {$stderr}", 'ERROR', $cfg['log_file']);
            return $res;
        }

        $jsonFile = $cfg['output_dir'] . '/' . pathinfo(basename($pdfPath), PATHINFO_FILENAME) . '.json';
        if (file_exists($jsonFile)) {
            $data = @json_decode(file_get_contents($jsonFile), true);
            if (json_last_error() === JSON_ERROR_NONE && isset($data['success'])) {
                $res['success'] = true;
                $res['data'] = $data;
                logMsg("OK: " . basename($pdfPath), 'INFO', $cfg['log_file']);
            } else {
                $res['errors'][] = "Invalid JSON: " . json_last_error_msg();
            }
        } else {
            $res['errors'][] = "Output JSON missing";
            if (trim($stderr)) $res['errors'][] = "Stderr: " . trim($stderr);
        }
    } else {
        $res['errors'][] = "proc_open failed";
    }
    return $res;
}

// ==================== UI ====================
$files = glob(__DIR__ . '/*.pdf');
$results = [];
foreach ($files as $f) $results[] = runParser($f, $config);

renderHtml($results);

function renderHtml(array $results): void {
    ?>
<!DOCTYPE html>
<html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PDF Parser</title>
<style>
    body{font-family:system-ui,sans-serif;background:#f5f7fa;padding:20px;color:#333}
    .container{max-width:1400px;margin:0 auto}
    h2{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:15px;border-radius:8px;margin:0 0 20px}
    .summary{display:flex;gap:15px;margin-bottom:20px}
    .stat{background:#fff;padding:10px 15px;border-radius:6px;border-left:4px solid #667eea}
    .stat.ok{border-color:#27ae60}.stat.err{border-color:#e74c3c}
    .card{background:#fff;padding:15px;margin:10px 0;border-radius:8px;box-shadow:0 2px 5px rgba(0,0,0,.05)}
    .meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;background:#f8f9fa;padding:10px;border-radius:6px;margin-bottom:10px}
    .meta strong{display:block;color:#666;font-size:12px}
    table{width:100%;border-collapse:collapse;font-size:13px;margin-top:5px}th,td{padding:6px;border:1px solid #ddd;text-align:left}th{background:#4a6cf7;color:#fff}
    .err{background:#ffe6e6;color:#c00;padding:8px;border-radius:4px;margin-top:5px}details{margin:5px 0}summary{cursor:pointer;font-weight:bold}
</style></head><body><div class="container">
<h2>📄 Результат парсинга</h2>
<div class="summary">
    <div class="stat">📁 Файлов: <?=count($results)?></div>
    <div class="stat ok">✅ Успешно: <?=count(array_filter($results, fn($r)=>$r['success']))?></div>
    <div class="stat err">❌ Ошибки: <?=count(array_filter($results, fn($r)=>!$r['success']))?></div>
</div>
<?php foreach ($results as $r): ?>
<div class="card">
    <strong>📎 <?=htmlspecialchars($r['file'])?></strong> <span style="color:#888">(<?=number_format($r['time'],2)?>s)</span>
    <?php if(!$r['success']): ?>
        <div class="err"><b>Ошибка:</b> <?=implode('<br>', array_map('htmlspecialchars', $r['errors']))?></div>
    <?php else: ?>
        <?php if($m=$r['data']['metadata']??null): ?>
        <div class="meta">
            <?php $labels=['document_number'=>'№','document_date'=>'Дата','shipper_name'=>'Грузоотправитель','shipper_inn'=>'ИНН','pickup_address'=>'Адрес'];
            foreach($labels as $k=>$l) if(!empty($m[$k])) echo "<div><strong>{$l}</strong>".htmlspecialchars($m[$k])."</div>"; ?>
        </div>
        <?php endif; ?>
        <?php foreach($r['data']['tables']??[] as $t): ?>
        <details>
            <summary>📊 Стр. <?=$t['page_num']?>, Таблица #<?=$t['table_idx']?> (<?=$t['row_count']?> строк)</summary>
            <div style="overflow-x:auto"><table>
            <?php $hdr=null; $rows=[];
            foreach($t['rows'] as $row){ $c=array_column($row['cells'],'text'); if($row['is_header'] && !$hdr) $hdr=$c; else $rows[]=$c; }
            if($hdr){echo "<tr>";foreach($hdr as $h) echo "<th>".htmlspecialchars($h?:"-")."</th>";echo "</tr>";}
            foreach($rows as $row){echo "<tr>";foreach($row as $c) echo "<td>".htmlspecialchars($c)."</td>";echo "</tr>";} ?>
            </table></div>
        </details>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php endforeach; ?>
</div></body></html>
<?php
}
?>