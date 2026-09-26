<?php

// ===== НОВЫЕ СТРОКИ (запрет кеширования) =====
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

// Сброс OPcache для разработки
if (function_exists('opcache_reset')) {
    opcache_reset();
}
// ===== КОНЕЦ НОВЫХ СТРОК =====

ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
include ("include/excel_filler.php");

// Обработка скачивания
if (isset($_GET['action']) && $_GET['action'] === 'fill_ttn') {
    if (isset($_SESSION['parsed_data'])) {
        $dataForExport = $_SESSION['parsed_data'];
        // Уничтожаем сессию ДО отправки файла, чтобы при следующем заходе страница была чистой
        session_unset();
        session_destroy();
        handleTtnDownload($dataForExport);
        exit;
    }
}

require_once __DIR__ . '/../config.php';
include ("include/dadata.php");
include ("include/price.php");
include ("include/packing_validator.php");

function getContractNumber($zayavkaId) {
    global $price_with_details;
    if (!isset($price_with_details[$zayavkaId])) {
        return 'Заявка не МГ';
    }
    $pricePerKg = $price_with_details[$zayavkaId]['price_per_kg'] ?? null;
    if ($pricePerKg === null) {
        return 'Заявка не МГ';
    }
    if ($pricePerKg == 40 || $pricePerKg == 36) {
        return '№ 25-02/26 от 06.03.2026';
    } else {
        return '№ 26-02/26 от 06.03.2026';
    }
}

function customJsonEncode($data, $indent = 0) {
    $sp = str_repeat('  ', $indent);
    $sp2 = str_repeat('  ', $indent + 1);
    if (is_null($data)) return 'null';
    if (is_bool($data)) return $data ? 'true' : 'false';
    if (is_int($data)) return (string)$data;
    if (is_float($data)) {
        $s = sprintf('%.10f', $data);
        $s = rtrim($s, '0');
        $s = rtrim($s, '.');
        return $s;
    }
    if (is_string($data)) {
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }
    if (is_array($data)) {
        $isList = array_keys($data) === range(0, count($data) - 1);
        if (empty($data)) return $isList ? '[]' : '{}';
        $items = [];
        foreach ($data as $k => $v) {
            $val = customJsonEncode($v, $indent + 1);
            if ($isList) {
                $items[] = $sp2 . $val;
            } else {
                $key = json_encode((string)$k, JSON_UNESCAPED_UNICODE);
                $items[] = $sp2 . $key . ': ' . $val;
            }
        }
        if ($isList) {
            return "[\n" . implode(",\n", $items) . "\n" . $sp . "]";
        } else {
            return "{\n" . implode(",\n", $items) . "\n" . $sp . "}";
        }
    }
    return 'null';
}

$result = null;
$error = null;
$summary = null;
$parsedData = null;

if (isset($_SESSION['parsed_data']) && !empty($_SESSION['parsed_data'])) {
    $parsedData = $_SESSION['parsed_data'];
    
    // ПРИНУДИТЕЛЬНО ПРИМЕНЯЕМ ИСПРАВЛЕНИЯ ИЗ БД ПРИ КАЖДОМ ВЫВОДЕ
    try {
        $pdo = new PDO(
            "mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset=utf8mb4",
            $dbConfig['username'],
            $dbConfig['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        // Получаем все исправления из БД
        $stmt = $pdo->query("SELECT wrong_value, correct_value FROM packing_dict");
        $allCorrections = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        if (!empty($allCorrections)) {
            $parsedData = applyPackingCorrections($parsedData, $allCorrections);
        }
    } catch (PDOException $e) {
        error_log("Failed to apply packing corrections: " . $e->getMessage());
    }
    
    // ФОРМИРУЕМ ОПИСАНИЯ ПОСЛЕ ПРИМЕНЕНИЯ ИСПРАВЛЕНИЙ
    if (function_exists('addSummaryToDocument')) {
        $parsedData = addSummaryToDocument($parsedData);
    }
    
    // СОХРАНЯЕМ ОБРАТНО В СЕССИЮ
    $_SESSION['parsed_data'] = $parsedData;
    
    $result = customJsonEncode($parsedData);
    $summary = calculateSummary($parsedData['items'] ?? []);
}

function fixWasteNamesInResult($data) {
    global $dbConfig;
    static $codeCache = [];
    if (!isset($data['items']) || !is_array($data['items'])) {
        return $data;
    }
    try {
        $pdo = new PDO(
            "mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset=utf8mb4",
            $dbConfig['username'],
            $dbConfig['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
        foreach ($data['items'] as &$item) {
            $code = isset($item['код ФККО']) ? trim((string)$item['код ФККО']) : '';
            $currentName = isset($item['наименование отхода']) ? $item['наименование отхода'] : '';
            if (empty($code)) {
                $item['наименование отхода'] = $currentName . ' [ERROR: код ФККО отсутствует]';
                $item['fkko_found'] = false;
                continue;
            }
            if (!isset($codeCache[$code])) {
                $stmt = $pdo->prepare("SELECT name FROM fkko WHERE code = ?");
                $stmt->execute([$code]);
                $row = $stmt->fetch();
                $codeCache[$code] = $row ? $row['name'] : null;
            }
            $correctName = $codeCache[$code];
            if ($correctName) {
                $item['наименование отхода'] = $correctName;
                $item['fkko_found'] = true;
            } else {
                $item['наименование отхода'] = $currentName . ' [ERROR: код ' . $code . ' не найден в справочнике ФККО]';
                $item['fkko_found'] = false;
            }
        }
    } catch (PDOException $e) {
        error_log("DB Error: " . $e->getMessage());
        foreach ($data['items'] as &$item) {
            $currentName = isset($item['наименование отхода']) ? $item['наименование отхода'] : '';
            $item['наименование отхода'] = $currentName . ' [ERROR: БД недоступна]';
            $item['fkko_found'] = false;
        }
    }
    return $data;
}

function calculateSummary($items) {
    $summary = [
        'total_mass_netto' => 0,
        'total_mass_brutto' => 0,
        'total_volume' => 0,
        'items_count' => is_array($items) ? count($items) : 0,
        'by_waste_code' => []
    ];
    if (!is_array($items)) {
        return $summary;
    }
    foreach ($items as $item) {
        if (isset($item['масса нетто']) && is_numeric($item['масса нетто'])) {
            $summary['total_mass_netto'] += (float)$item['масса нетто'];
        }
        if (isset($item['Масса брутто']) && is_numeric($item['Масса брутто'])) {
            $summary['total_mass_brutto'] += (float)$item['Масса брутто'];
        }
        if (isset($item['объем']) && is_numeric($item['объем'])) {
            $summary['total_volume'] += (float)$item['объем'];
        }
        $code = isset($item['код ФККО']) ? $item['код ФККО'] : 'без_кода';
        if (!isset($summary['by_waste_code'][$code])) {
            $summary['by_waste_code'][$code] = [
                'code' => $code,
                'name' => $item['наименование отхода'] ?? '—',
                'total_mass_netto' => 0,
                'count' => 0
            ];
        }
        $summary['by_waste_code'][$code]['total_mass_netto'] += (float)($item['масса нетто'] ?? 0);
        $summary['by_waste_code'][$code]['count']++;
    }
    $summary['total_mass_netto'] = round($summary['total_mass_netto'], 3);
    $summary['total_mass_brutto'] = round($summary['total_mass_brutto'], 3);
    $summary['total_volume'] = round($summary['total_volume'], 3);
    return $summary;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fixedData = handlePackingFixRequest();
    if ($fixedData !== null) {
        if ($fixedData !== false) {
            $parsedData = $fixedData;
            // После исправления применяем описания
            if (function_exists('addSummaryToDocument')) {
                $parsedData = addSummaryToDocument($parsedData);
            }
            $result = customJsonEncode($parsedData);
            $summary = calculateSummary($parsedData['items'] ?? []);
            $_SESSION['parsed_data'] = $parsedData;
        }
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
        $error = "Файл не выбран или ошибка загрузки";
        unset($_SESSION['parsed_data']);
        unset($_SESSION['pending_packing_fix']);
    } else {
        unset($_SESSION['parsed_data']);
        unset($_SESSION['pending_packing_fix']);
        $file = $_FILES['pdf']['tmp_name'];
        $python = __DIR__ . '/venv/bin/python';
        $worker = __DIR__ . '/include/worker.py';
        if (!file_exists($python)) {
            $error = "Python не найден: " . $python;
        } elseif (!file_exists($worker)) {
            $error = "Worker не найден: " . $worker;
        } else {
            $cmd = sprintf('%s %s %s 2>&1', escapeshellarg($python), escapeshellarg($worker), escapeshellarg($file));
            $output = [];
            $returnVar = 0;
            exec($cmd, $output, $returnVar);
            if ($returnVar !== 0) {
                $error = "Ошибка Python:\n" . implode("\n", $output);
            } else {
                $rawOutput = implode("\n", $output);
                if (preg_match('/\{.*\}/s', $rawOutput, $matches)) {
                    $jsonString = $matches[0];
                    $data = json_decode($jsonString, true);
                    if ($data && is_array($data)) {
                        if (!isset($data['document'])) {
                            $data['document'] = [];
                        }
                        $data['document']['Юридический адрес'] = getLegalAddressByINN($data['document']['ИНН']);
                        $orderNumber = getContractNumber($data['document']['Номер поручения']) ?? getContractNumber($data['document']['order_number']) ?? '—';
                        $data['document']['Договор МГ'] = $orderNumber;
                        $data = fixWasteNamesInResult($data);
                        
                        // ВАЛИДАЦИЯ УПАКОВКИ (ЗДЕСЬ ПРИМЕНЯЮТСЯ ИСПРАВЛЕНИЯ)
                        try {
                            $pdo = new PDO(
                                "mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset=utf8mb4",
                                $dbConfig['username'],
                                $dbConfig['password'],
                                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                            );
                            $packingValidation = validatePackings($data, $pdo);
                            if ($packingValidation['status'] === 'need_fix') {
                                $data = $packingValidation['data'];
                                $parsedData = $data;
                                $_SESSION['parsed_data'] = $parsedData;
                                header('Location: ' . $_SERVER['PHP_SELF']);
                                exit;
                            }
                            if ($packingValidation['status'] === 'ok') {
                                $data = $packingValidation['data']; // ЗДЕСЬ УЖЕ ПРИМЕНЕНЫ ИСПРАВЛЕНИЯ
                            }
                        } catch (PDOException $e) {
                            error_log("DB Error in packing validation: " . $e->getMessage());
                        }
                        
                        // ========== ФОРМИРУЕМ ОПИСАНИЯ (ПОСЛЕ ПРИМЕНЕНИЯ ИСПРАВЛЕНИЙ УПАКОВКИ) ==========
                        if (function_exists('addSummaryToDocument')) {
                            $data = addSummaryToDocument($data);
                        }
                        
                        $summary = calculateSummary($data['items'] ?? []);
                        $result = customJsonEncode($data);
                        $parsedData = $data;
                        $_SESSION['parsed_data'] = $parsedData;
                        header('Location: ' . $_SERVER['PHP_SELF']);
                        exit;
                    } else {
                        $error = "Ошибка декодирования JSON";
                        $result = $jsonString;
                        $parsedData = null;
                    }
                } else {
                    $error = "JSON не найден в выводе Python";
                    $result = $rawOutput;
                    $parsedData = null;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Генерация ТН</title>
 <link rel="stylesheet" href="../style.css">
<style>
* { box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; padding: 20px; background: #f5f5f5; }
.container { max-width: 1400px; margin: 0 auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
h2, h3, h4 { color: #333; margin-top: 0; }
pre { background: #f8f8f8; padding: 15px; overflow-x: auto; border-radius: 8px; font-size: 12px; border: 1px solid #e0e0e0; white-space: pre-wrap; word-wrap: break-word; }
.error { color: #d32f2f; background: #ffebee; padding: 15px; border-radius: 8px; border-left: 4px solid #d32f2f; }
table { border-collapse: collapse; width: 100%; margin-top: 20px; font-size: 14px; }
th, td { border: 1px solid #ddd; padding: 10px 12px; text-align: left; vertical-align: top; }
th { background: #f0f0f0; font-weight: 600; }
.error-row { background-color: #ffebee; }
.table-wrapper { overflow-x: auto; max-width: 100%; border-radius: 8px; border: 1px solid #e0e0e0; }
.status-badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
.status-success { background: #e8f5e9; color: #2e7d32; }
.status-error { background: #ffebee; color: #c62828; }
.upload-form { background: #fafafa; padding: 20px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #e0e0e0; }
input[type="file"] { padding: 10px; border: 1px solid #ccc; border-radius: 4px; background: white; }
button { background: #1976d2; color: white; border: none; padding: 10px 24px; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 600; }
button:hover { background: #1565c0; }
.btn-green { background: #4caf50; }
.btn-green:hover { background: #388e3c; }
hr { margin: 20px 0; border: none; border-top: 1px solid #e0e0e0; }
details summary { cursor: pointer; color: #666; font-size: 13px; }
.button-group { display: flex; gap: 15px; margin-top: 20px; }
.fix-form {
    background: #fff3e0;
    border: 2px solid #ff9800;
    border-radius: 12px;
    padding: 25px;
    margin: 20px 0;
}
.fix-form table { width: auto; border: none; }
.fix-form th, .fix-form td { border: none; padding: 10px; }
.fix-form th { background: none; text-align: right; }
.fix-form input[type="text"] { padding: 8px; width: 300px; border: 1px solid #ccc; border-radius: 4px; }
.button-group-form { display: flex; gap: 15px; margin-top: 20px; }
.btn-save { background: #4caf50; color: white; padding: 10px 24px; border: none; border-radius: 4px; cursor: pointer; }
.btn-skip { background: #ff9800; color: white; padding: 10px 24px; border: none; border-radius: 4px; cursor: pointer; }
.btn-cancel { background: #9e9e9e; color: white; padding: 10px 24px; border: none; border-radius: 4px; cursor: pointer; }
</style>
</head>
<body>
<?php include '../menu2.php'; ?>
<div class="container">
    <h2>📄 Генерация ТН</h2>
    <div class="upload-form">
        <form method="POST" enctype="multipart/form-data">
            <input type="file" name="pdf" accept=".pdf" required>
            <button type="submit">Старт</button>
        </form>
    </div>

    <?php if ($error): ?>
        <div class="error">
            <strong>❌ Ошибка:</strong><br>
            <pre><?= htmlspecialchars($error) ?></pre>
        </div>
    <?php endif; ?>

    <?php if (needPackingFixForm()):
        $pending = $_SESSION['pending_packing_fix'];
        $wrongPackings = $pending['wrong_packings'];
    ?>
        <?php foreach ($wrongPackings as $wrong => $info): ?>
            <div class="fix-form">
                <h3>⚠️ Требуется исправление способа упаковки</h3>
                <form method="POST">
                    <input type="hidden" name="wrong_value" value="<?= htmlspecialchars($wrong) ?>">
                    <table>
                        <tr>
                            <th>Некорректное значение:<br><br></th>
                            <td>
                                <textarea name="correct_value" 
                                          rows="3" 
                                          cols="400"
                                          style="width: 600px;"
                                          required><?= htmlspecialchars($wrong) ?></textarea>
                            </td>
                        </tr>
                    </table>
                    <div class="button-group-form">
                        <button type="submit" name="fix_packing_action" value="save_one" class="btn-save">
                            ✅ Сохранить и продолжить
                        </button>
                        <button type="submit" name="fix_packing_action" value="skip_one" class="btn-skip">
                            ⏭️ Пропустить
                        </button>
                        <button type="submit" name="fix_packing_action" value="cancel_all" class="btn-cancel">
                            ❌ Отменить всё
                        </button>
                    </div>
                </form>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!needPackingFixForm() && isset($parsedData) && is_array($parsedData) && !empty($parsedData)): ?>
        <?php if (isset($parsedData['document']) && is_array($parsedData['document'])): ?>
            <h3>📄 Информация о документе</h3>
            <div class="table-wrapper">
                <table>
                    <tbody>
                        <?php foreach ($parsedData['document'] as $key => $value): ?>
                            <tr>
                                <th style="background:#f9f9f9; width:250px;"><?= htmlspecialchars((string)$key) ?></th>
                                <td><?= htmlspecialchars((string)($value ?? '—')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if (isset($parsedData['items']) && is_array($parsedData['items']) && count($parsedData['items']) > 0): ?>
            <h3><br>📦 Детализация по позициям</h3>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <?php foreach (array_keys($parsedData['items'][0]) as $column): ?>
                                <th><?= htmlspecialchars((string)$column) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($parsedData['items'] as $item): ?>
                            <tr class="<?= isset($item['fkko_found']) && !$item['fkko_found'] ? 'error-row' : '' ?>">
                                <?php foreach ($item as $key => $value): ?>
                                    <td>
                                        <?php if ($key === 'fkko_found'): ?>
                                            <span class="status-badge <?= $value ? 'status-success' : 'status-error' ?>">
                                                <?= $value ? '✓ Найден в ФККО' : '✗ Не найден в ФККО' ?>
                                            </span>
                                        <?php elseif (is_float($value) || is_int($value)): ?>
                                            <span class="float-number"><?= htmlspecialchars((string)$value) ?></span>
                                        <?php else: ?>
                                            <?= htmlspecialchars((string)($value ?? '—')) ?>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="button-group">
            <button type="button" class="btn-green" id="downloadBtn" onclick="triggerDownloadAndReset()">📝 Заполнить ТТН и скачать</button>
        </div>

        <script>
        async function triggerDownloadAndReset() {
            const btn = document.getElementById('downloadBtn');
            btn.disabled = true;
            btn.textContent = '⏳ Подготовка файла...';
            
            try {
                const response = await fetch('index.php?action=fill_ttn', { credentials: 'include' });
                if (!response.ok) throw new Error('Ошибка сервера');

                const disposition = response.headers.get('Content-Disposition');
                let filename = 'ttn.xlsx';
                if (disposition && disposition.includes('filename=')) {
                    const match = disposition.match(/filename\*=UTF-8''(.+)|filename="?([^"]+)"?/);
                    if (match) filename = decodeURIComponent(match[1] || match[2]);
                }

                const blob = await response.blob();
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                
                setTimeout(() => {
                    window.URL.revokeObjectURL(url);
                    a.remove();
                    window.location.href = 'index.php';
                }, 200);
            } catch (err) {
                console.error(err);
                alert('Не удалось скачать файл. Попробуйте снова.');
                window.location.href = 'index.php';
            }
        }
        </script>

        <hr>
        <details>
            <summary>🔍 Показать нормализованный JSON</summary>
            <pre><?= htmlspecialchars($result ?? '') ?></pre>
        </details>
    <?php elseif ($result && !$error && !needPackingFixForm()): ?>
        <pre><?= htmlspecialchars($result) ?></pre>
    <?php endif; ?>

    <?php if (!$error && !isset($parsedData) && $_SERVER['REQUEST_METHOD'] !== 'POST' && !needPackingFixForm()): ?>
      
    <?php endif; ?>
</div>
</body>
</html>