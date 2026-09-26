<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Конвертер нового формата ФЭО -> XLSX, который штатно загружается через index22.php.
 *
 * Входной формат (первая строка — заголовки):
 * Номер | Доп. склад | Срок доставки | Регион | Адрес отправления | ЖДВ |
 * Масса брутто, т | Заказчик | Организация
 *
 * Координаты определяются через Яндекс Геокодер по полю "Адрес отправления".
 * ВАЖНО: сохраняем историческую договорённость текущей БД/index22:
 *   - longitude -> "МНО_Координаты (Ш)" -> mno_sh
 *   - latitude  -> "МНО_Координаты (Д)" -> mno_d
 * То есть это намеренно НЕ соответствует буквальному смыслу Ш/Д, зато совместимо
 * с текущими данными и прежним coordinate_updater_swapped.php.
 */

const YANDEX_GEOCODER_URL = 'https://geocode-maps.yandex.ru/1.x/';
const MAX_FILE_SIZE = 20 * 1024 * 1024;

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function normalizeHeader(mixed $value): string
{
    $value = trim((string)$value);
    return preg_replace('/\s+/u', ' ', $value) ?? $value;
}

function expectedInputHeaders(): array
{
    return [
        'Номер',
        'Доп. склад',
        'Срок доставки',
        'Регион',
        'Адрес отправления',
        'ЖДВ',
        'Масса брутто, т',
        'Заказчик',
        'Организация',
    ];
}

/**
 * Заголовки полностью повторяют getFieldMapping() из текущего index22.php.
 * Это позволяет загружать результат обычной штатной кнопкой импорта.
 */
function index22Headers(): array
{
    return [
        'Заявка, ID',
        'Дата заявки',
        'Полное наименование заказчика (п.1а)',
        'Юридический адрес заказчика (п.1а)',
        'ИНН заказчика (п.1а)',
        '№ договора с ФГУП ФЭО (п. 1а)',
        'Дата договора с ФГУП ФЭО (п.1а)',
        'Заказ с ОП_Наименование организации (Грузополучатель) (п. 2)',
        'ИНН ОП Грузополучателя (п. 2)',
        'Адрес объекта обращения ОП Адрес места доставки отходов (п.2 транспртной накладной)',
        'Юридический адрес ОП Грузополучателя (п. 2)',
        'ИНН ОО (Грузоотправитель) (п.1)',
        'ОО_Наименование организации (Грузоотправитель) (п.1)',
        'Юридический адрес ОО (Грузоотправитель) (п.1)',
        'МНО, Регион',
        'МНО, МО',
        'МНО, Адрес (адрес места погрузки отходов)',
        'Наименование отхода (код отхода по ФККО) (п. 3)',
        'Количество грузовых мест, шт (п. 3)',
        'Вид тары (п. 3)',
        'Суммарная масса (нетто), т (п. 3)',
        'Суммарная масса (брутто), т (п. 3)',
        'Длина места (только если грузовое место одно) (п. 3)',
        'Ширина места (только если грузовое место одно) (п. 3)',
        'Высота места (только если грузовое место одно) (п. 3)',
        'Суммарный объем, куб.м (п. 3)',
        'Номер опасного груза по классификации ООН (п. 3)',
        'Адрес объекта обращения ОП Адрес места выгрузки отходов (п.10 транспортной накладной)',
        'Наименование ОТ (Грузоперевозчик) (п. 6)',
        'ИНН ОТ (п. 6)',
        'Юридический адрес ОТ организации Перевозчика (п. 6)',
        'Дата создания поручения',
        'Заказ с ОТ, Статус',
        'Наличие транспортной накладной',
        'Заказ с ОП, Статус',
        'ID заказа с ОП',
        'Заказ с ОТ, дата выгрузки',
        'Заявка, ЖДВ (по)',
        'Контактные данные в МНО (Тел.)',
        'Телефон (дополнительный)',
        'Контактные данные в МНО (Эл.почта)',
        'МНО_Координаты (Ш)',
        'МНО_Координаты (Д)',
        'Заказ с ОП, Стоимость услуг',
    ];
}

function parseDateValue(mixed $value): ?string
{
    if ($value === null || trim((string)$value) === '') {
        return null;
    }

    if (is_numeric($value)) {
        try {
            return Date::excelToDateTimeObject((float)$value)->format('Y-m-d');
        } catch (Throwable) {
            // continue to string parsing
        }
    }

    $raw = trim((string)$value);
    foreach (['d.m.Y', 'd.m.y', 'Y-m-d'] as $format) {
        $dt = DateTimeImmutable::createFromFormat('!' . $format, $raw);
        if ($dt instanceof DateTimeImmutable) {
            return $dt->format('Y-m-d');
        }
    }

    $ts = strtotime($raw);
    return $ts === false ? null : date('Y-m-d', $ts);
}

function parseDecimal(mixed $value): ?float
{
    if ($value === null || trim((string)$value) === '') {
        return null;
    }

    $normalized = str_replace([' ', ','], ['', '.'], trim((string)$value));
    return is_numeric($normalized) ? (float)$normalized : null;
}

function curlJson(string $url, array $params): array
{
    $fullUrl = $url . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $ch = curl_init($fullUrl);
    if ($ch === false) {
        throw new RuntimeException('Не удалось инициализировать cURL.');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'FEO-index22-converter/2.0',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $error !== '') {
        throw new RuntimeException('Ошибка обращения к Яндекс Геокодеру: ' . $error);
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Яндекс Геокодер вернул HTTP ' . $status . '.');
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        throw new RuntimeException('Яндекс Геокодер вернул некорректный JSON.');
    }

    return $json;
}

function geocodeYandex(string $address, string $apiKey): array
{
    $json = curlJson(YANDEX_GEOCODER_URL, [
        'apikey' => $apiKey,
        'geocode' => $address,
        'format' => 'json',
        'results' => 1,
        'lang' => 'ru_RU',
    ]);

    $members = $json['response']['GeoObjectCollection']['featureMember'] ?? [];
    if (!$members || empty($members[0]['GeoObject']['Point']['pos'])) {
        return [
            'ok' => false,
            'longitude' => null,
            'latitude' => null,
            'found_address' => null,
            'precision' => null,
            'message' => 'Адрес не найден',
        ];
    }

    $geoObject = $members[0]['GeoObject'];
    $pos = preg_split('/\s+/', trim((string)$geoObject['Point']['pos'])) ?: [];
    if (count($pos) !== 2 || !is_numeric($pos[0]) || !is_numeric($pos[1])) {
        return [
            'ok' => false,
            'longitude' => null,
            'latitude' => null,
            'found_address' => null,
            'precision' => null,
            'message' => 'Геокодер вернул координаты неожиданного формата',
        ];
    }

    $longitude = (float)$pos[0];
    $latitude = (float)$pos[1];
    if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
        return [
            'ok' => false,
            'longitude' => null,
            'latitude' => null,
            'found_address' => null,
            'precision' => null,
            'message' => 'Координаты вне допустимого диапазона',
        ];
    }

    return [
        'ok' => true,
        'longitude' => $longitude,
        'latitude' => $latitude,
        'found_address' => $geoObject['metaDataProperty']['GeocoderMetaData']['text'] ?? null,
        'precision' => $geoObject['metaDataProperty']['GeocoderMetaData']['precision'] ?? null,
        'message' => null,
    ];
}

function loadNewFormat(string $path): array
{
    $spreadsheet = IOFactory::load($path);
    $worksheet = $spreadsheet->getActiveSheet();
    $rows = $worksheet->toArray(null, true, true, false);
    if (!$rows) {
        throw new RuntimeException('Excel-файл пуст.');
    }

    $headers = array_map('normalizeHeader', array_shift($rows));
    $headerToIndex = [];
    foreach ($headers as $index => $header) {
        if ($header !== '') {
            $headerToIndex[$header] = $index;
        }
    }

    $missing = [];
    foreach (expectedInputHeaders() as $required) {
        if (!array_key_exists($required, $headerToIndex)) {
            $missing[] = $required;
        }
    }
    if ($missing) {
        throw new RuntimeException('Новый формат не распознан. Нет колонок: ' . implode(', ', $missing));
    }

    $result = [];
    $seenIds = [];
    foreach ($rows as $i => $row) {
        $sourceRow = $i + 2;
        $id = trim((string)($row[$headerToIndex['Номер']] ?? ''));
        if ($id === '') {
            continue;
        }
        if (!preg_match('/^\d{1,20}$/', $id)) {
            throw new RuntimeException("Строка {$sourceRow}: некорректный номер заявки '{$id}'.");
        }
        if (isset($seenIds[$id])) {
            throw new RuntimeException("Строка {$sourceRow}: заявка {$id} повторяется в файле.");
        }
        $seenIds[$id] = true;

        $address = trim((string)($row[$headerToIndex['Адрес отправления']] ?? ''));
        if ($address === '') {
            throw new RuntimeException("Строка {$sourceRow}, заявка {$id}: пустой адрес отправления.");
        }

        $result[] = [
            '_row' => $sourceRow,
            'Номер' => $id,
            'Доп. склад' => trim((string)($row[$headerToIndex['Доп. склад']] ?? '')),
            'Срок доставки' => trim((string)($row[$headerToIndex['Срок доставки']] ?? '')),
            'Регион' => trim((string)($row[$headerToIndex['Регион']] ?? '')),
            'Адрес отправления' => $address,
            'ЖДВ' => parseDateValue($row[$headerToIndex['ЖДВ']] ?? null),
            'Масса брутто, т' => parseDecimal($row[$headerToIndex['Масса брутто, т']] ?? null),
            'Заказчик' => trim((string)($row[$headerToIndex['Заказчик']] ?? '')),
            'Организация' => trim((string)($row[$headerToIndex['Организация']] ?? '')),
        ];
    }

    if (!$result) {
        throw new RuntimeException('В файле не найдено ни одной заявки.');
    }

    return $result;
}

function buildIndex22Row(array $source, array $geo): array
{
    $row = array_fill_keys(index22Headers(), null);

    $row['Заявка, ID'] = $source['Номер'];
    $row['Полное наименование заказчика (п.1а)'] = $source['Заказчик'] !== '' ? $source['Заказчик'] : null;
    $row['ОО_Наименование организации (Грузоотправитель) (п.1)'] = $source['Организация'] !== '' ? $source['Организация'] : null;
    $row['МНО, Регион'] = $source['Регион'] !== '' ? $source['Регион'] : null;
    $row['МНО, Адрес (адрес места погрузки отходов)'] = $source['Адрес отправления'];
    $row['Суммарная масса (брутто), т (п. 3)'] = $source['Масса брутто, т'];
    $row['Заявка, ЖДВ (по)'] = $source['ЖДВ'];

    // Исторически используем "переставленную" схему текущей БД:
    // mno_sh = longitude, mno_d = latitude.
    if ($geo['ok']) {
        $row['МНО_Координаты (Ш)'] = $geo['longitude'];
        $row['МНО_Координаты (Д)'] = $geo['latitude'];
    }

    return $row;
}

function saveWorkbook(array $convertedRows, array $diagnostics, string $outputPath): void
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Лист1');

    $headers = index22Headers();
    foreach ($headers as $col => $header) {
        $sheet->setCellValue([$col + 1, 1], $header);
    }

    foreach ($convertedRows as $rowIndex => $row) {
        $excelRow = $rowIndex + 2;
        foreach ($headers as $col => $header) {
            $value = $row[$header] ?? null;
            $sheet->setCellValue([$col + 1, $excelRow], $value);
        }
    }

    $sheet->getStyle('1:1')->getFont()->setBold(true);
    $sheet->getStyle('1:1')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
    $sheet->freezePane('A2');
    $sheet->setAutoFilter($sheet->calculateWorksheetDimension());

    // Диагностический лист index22 не читает: он загружает active sheet, поэтому
    // активным оставляем строго Лист1. Лист нужен человеку для проверки геокодинга.
    $diag = $spreadsheet->createSheet();
    $diag->setTitle('Проверка координат');
    $diagHeaders = ['Строка источника', 'Заявка', 'Исходный адрес', 'Найденный адрес', 'Точность', 'Longitude -> Ш', 'Latitude -> Д', 'Статус'];
    foreach ($diagHeaders as $col => $header) {
        $diag->setCellValue([$col + 1, 1], $header);
    }
    foreach ($diagnostics as $i => $item) {
        $r = $i + 2;
        $diag->fromArray([
            $item['source_row'],
            $item['id'],
            $item['source_address'],
            $item['found_address'],
            $item['precision'],
            $item['longitude'],
            $item['latitude'],
            $item['status'],
        ], null, 'A' . $r);
    }
    $diag->getStyle('1:1')->getFont()->setBold(true);
    $diag->getStyle('A:H')->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
    $diag->getStyle('C:D')->getAlignment()->setWrapText(true);
    $diag->freezePane('A2');
    $diag->setAutoFilter($diag->calculateWorksheetDimension());

    $spreadsheet->setActiveSheetIndex(0);
    (new Xlsx($spreadsheet))->save($outputPath);
}

if (empty($_SESSION['converter_csrf'])) {
    $_SESSION['converter_csrf'] = bin2hex(random_bytes(32));
}

$error = null;
$result = null;
$downloadName = null;
$downloadPath = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $token = (string)($_POST['csrf_token'] ?? '');
        if (!hash_equals($_SESSION['converter_csrf'], $token)) {
            throw new RuntimeException('Сессия устарела. Обновите страницу и повторите.');
        }

        $apiKey = trim((string)($_POST['yandex_api_key'] ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('Укажите API-ключ Яндекс Геокодера.');
        }

        if (!isset($_FILES['excel_file']) || !is_array($_FILES['excel_file'])) {
            throw new RuntimeException('Выберите Excel-файл.');
        }
        $file = $_FILES['excel_file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Ошибка загрузки Excel-файла. Код: ' . (int)($file['error'] ?? -1));
        }
        if ((int)($file['size'] ?? 0) > MAX_FILE_SIZE) {
            throw new RuntimeException('Файл слишком большой. Максимум 20 МБ.');
        }
        $extension = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ($extension !== 'xlsx') {
            throw new RuntimeException('Поддерживаются только файлы .xlsx.');
        }

        $sourceRows = loadNewFormat((string)$file['tmp_name']);
        $converted = [];
        $diagnostics = [];
        $cache = [];
        $successCount = 0;
        $failedCount = 0;

        foreach ($sourceRows as $source) {
            $address = $source['Адрес отправления'];
            $cacheKey = mb_strtolower(trim($address), 'UTF-8');

            if (!isset($cache[$cacheKey])) {
                try {
                    $cache[$cacheKey] = geocodeYandex($address, $apiKey);
                } catch (Throwable $geoError) {
                    $cache[$cacheKey] = [
                        'ok' => false,
                        'longitude' => null,
                        'latitude' => null,
                        'found_address' => null,
                        'precision' => null,
                        'message' => $geoError->getMessage(),
                    ];
                }
                // Не долбим API одинаковыми/слишком частыми запросами.
                usleep(120000);
            }

            $geo = $cache[$cacheKey];
            $converted[] = buildIndex22Row($source, $geo);
            if ($geo['ok']) {
                $successCount++;
            } else {
                $failedCount++;
            }

            $diagnostics[] = [
                'source_row' => $source['_row'],
                'id' => $source['Номер'],
                'source_address' => $address,
                'found_address' => $geo['found_address'] ?? '',
                'precision' => $geo['precision'] ?? '',
                'longitude' => $geo['longitude'] ?? '',
                'latitude' => $geo['latitude'] ?? '',
                'status' => $geo['ok'] ? 'OK' : ('ОШИБКА: ' . ($geo['message'] ?? 'неизвестная ошибка')),
            ];
        }

        $base = preg_replace('/[^\p{L}\p{N}_-]+/u', '_', pathinfo((string)$file['name'], PATHINFO_FILENAME)) ?: 'feo';
        $downloadName = $base . '_index22_ready.xlsx';
        $downloadPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('feo_index22_', true) . '.xlsx';
        saveWorkbook($converted, $diagnostics, $downloadPath);

        // Файл отдаём отдельным POST по временной ссылке в текущей сессии.
        $_SESSION['converter_download'] = [
            'path' => $downloadPath,
            'name' => $downloadName,
            'created' => time(),
        ];
        $_SESSION['converter_csrf'] = bin2hex(random_bytes(32));

        $result = [
            'total' => count($sourceRows),
            'geocoded' => $successCount,
            'failed' => $failedCount,
            'unique_addresses' => count($cache),
        ];
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}

if (isset($_GET['download']) && $_GET['download'] === '1') {
    $download = $_SESSION['converter_download'] ?? null;
    if (!is_array($download) || empty($download['path']) || !is_file($download['path'])) {
        http_response_code(404);
        exit('Файл для скачивания не найден или уже удалён.');
    }
    if (time() - (int)($download['created'] ?? 0) > 3600) {
        @unlink($download['path']);
        unset($_SESSION['converter_download']);
        http_response_code(410);
        exit('Ссылка устарела. Сформируйте файл заново.');
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode((string)$download['name']));
    header('Content-Length: ' . filesize($download['path']));
    readfile($download['path']);
    exit;
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Новый формат ФЭО → index22.php</title>
    <style>
        body { margin:0; background:#f3f5f7; color:#1e293b; font-family:Arial,sans-serif; }
        .wrap { max-width:920px; margin:32px auto; padding:0 18px 48px; }
        .card { background:#fff; border:1px solid #dbe2ea; border-radius:10px; box-shadow:0 4px 18px rgba(15,23,42,.07); overflow:hidden; }
        .head { padding:22px 24px; border-bottom:1px solid #e5e7eb; }
        h1 { margin:0 0 8px; font-size:24px; }
        .muted { color:#64748b; line-height:1.5; }
        form { padding:22px 24px 26px; }
        label { display:block; margin:0 0 8px; font-weight:700; }
        input[type=file], input[type=password] { width:100%; box-sizing:border-box; padding:10px 12px; border:1px solid #cbd5e1; border-radius:7px; margin-bottom:18px; }
        button, .download { display:inline-block; border:0; border-radius:7px; padding:12px 18px; background:#0f172a; color:#fff; font-weight:700; text-decoration:none; cursor:pointer; }
        .msg { margin:18px 24px 0; padding:13px 15px; border-radius:7px; }
        .error { background:#fee2e2; border:1px solid #fecaca; color:#991b1b; }
        .success { background:#dcfce7; border:1px solid #86efac; color:#166534; }
        .stats { display:flex; flex-wrap:wrap; gap:10px; margin-top:12px; }
        .badge { background:#fff; border:1px solid #bbf7d0; border-radius:6px; padding:6px 9px; }
        .note { margin-top:14px; font-size:13px; color:#64748b; line-height:1.5; }
        code { background:#f1f5f9; padding:2px 5px; border-radius:4px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="head">
            <h1>Конвертер нового Excel в формат index22.php</h1>
            <div class="muted">
                Загружает новый 9-колоночный XLSX, массово определяет координаты по «Адрес отправления» через Яндекс Геокодер
                и формирует XLSX с точными заголовками, которые понимает штатный импорт <code>index22.php</code>.
            </div>
        </div>

        <?php if ($error): ?>
            <div class="msg error"><strong>Ошибка:</strong> <?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($result): ?>
            <div class="msg success">
                <strong>Файл сформирован.</strong>
                <div class="stats">
                    <span class="badge">Заявок: <?= e($result['total']) ?></span>
                    <span class="badge">Координаты OK: <?= e($result['geocoded']) ?></span>
                    <span class="badge">Ошибок: <?= e($result['failed']) ?></span>
                    <span class="badge">Уникальных адресов: <?= e($result['unique_addresses']) ?></span>
                </div>
                <div style="margin-top:14px;"><a class="download" href="?download=1">Скачать XLSX для index22.php</a></div>
            </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['converter_csrf']) ?>">

            <label for="excel_file">Новый Excel-файл (.xlsx)</label>
            <input id="excel_file" type="file" name="excel_file" accept=".xlsx" required>

            <label for="yandex_api_key">API-ключ Яндекс Геокодера</label>
            <input id="yandex_api_key" type="password" name="yandex_api_key" autocomplete="off" required>

            <button type="submit">Определить координаты и подготовить XLSX</button>

            <div class="note">
                «Доп. склад» и «Срок доставки» в <code>index22.php</code> соответствующих полей не имеют и в БД не импортируются.
                Неизвестные старому импортеру данные не выдумываются: отсутствующие поля остаются пустыми.
                В готовом файле второй лист «Проверка координат» показывает найденный Яндексом адрес и точность геокодирования;
                импортируется только активный первый лист «Лист1».
            </div>
        </form>
    </div>
</div>
</body>
</html>
