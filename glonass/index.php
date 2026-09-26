<?php
/**
 * 🚀 ГЛОНАССсофт — ОДИН СКРИПТ (v3, авто-поиск эндпоинта)
 * Запуск: php trackers.php
 * Рядом: только config.php
 */

$cfgFile = __DIR__ . '/config.php';
if (!file_exists($cfgFile)) die("❌ config.php не найден в " . __DIR__ . "\n");
$cfg = include($cfgFile);

$BASE_URL   = rtrim($cfg['api_base_url'], '/');
$TOKEN      = $cfg['api_token'] ?? '';
$TRACKERS   = $cfg['tracker_ids'] ?? [];
$RESULTS    = [];
$OK = 0; $ERR = 0;

echo "🚀 ГЛОНАССсофт — ОДИН СКРИПТ\n⏰ " . date('d.m.Y H:i:s') . "\n" . str_repeat("=", 60) . "\n";

// 🔍 1️⃣ АВТО-ПОИСК РАБОЧЕГО API-ПУТИ (решает проблему HTTP 404)
echo "🔎 Поиск рабочего API-эндпоинта... ";
$pathsToTry = ['/api', '/api/v3', '/api/rpc', '/wialon/ajax.html', '/svc/core/login', '/'];
$API_URL = $BASE_URL . '/api'; // дефолт

foreach ($pathsToTry as $path) {
    $testUrl = $BASE_URL . $path;
    $ch = curl_init($testUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['method' => 'test', 'params' => []]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_NOBODY         => false,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 200, 405 (метод запрещён, но путь существует) или 400 (неверный токен/параметры) — путь живой
    if ($code != 404 && $code != 0) {
        $API_URL = $testUrl;
        echo "✅ Найден: $path (HTTP $code)\n";
        break;
    }
}
if ($code == 404) echo "⚠️ Используем дефолт /api\n";

// 🔑 2️⃣ ПРОВЕРКА/ОБНОВЛЕНИЕ ТОКЕНА
echo "🔐 Проверка токена... ";
$tokenValid = false;
if ($TOKEN) {
    $resp = jsonRpcCall($API_URL, 'getObjects', [], $TOKEN);
    if (empty($resp['error']) || $resp['code'] < 400) {
        $tokenValid = true;
        echo "✅ Валиден\n";
    } else {
        echo "⚠️ Истёк, пробуем авторизацию...\n";
    }
}

if (!$tokenValid) {
    $auth = jsonRpcCall($API_URL, 'login', ['user' => $cfg['api_login'], 'pass' => $cfg['api_password']], '');
    $newTok = $auth['data']['token'] ?? $auth['token'] ?? $auth['sess_id'] ?? null;
    if ($newTok) {
        $TOKEN = $newTok;
        echo "✅ Авторизованы. Новый токен получен.\n";
        // Обновляем config.php
        $content = file_get_contents($cfgFile);
        $content = preg_replace("/'api_token'\s*=>\s*'[^']*'/", "'api_token' => '$TOKEN'", $content);
        file_put_contents($cfgFile, $content);
    } else {
        die("❌ Ошибка авторизации: " . json_encode($auth['data'] ?? $auth, JSON_UNESCAPED_UNICODE) . "\n");
    }
}

// 📡 3️⃣ ЗАГРУЗКА ВСЕХ ОБЪЕКТОВ
echo "📡 Загрузка списка объектов... ";
$objRes = jsonRpcCall($API_URL, 'getObjects', [], $TOKEN);
if (!empty($objRes['error']) || (empty($objRes['data']) && empty($objRes['objects']))) {
    // Пробуем альтернативный метод получения списка
    $objRes = jsonRpcCall($API_URL, 'searchItems', ['spec' => ['itemsType' => 'avl_unit', 'propName' => 'sys_name', 'propValueMask' => '*', 'sortType' => 'sys_name']], $TOKEN);
    $list = $objRes['data']['items'] ?? $objRes['data']['units'] ?? $objRes['data'] ?? [];
} else {
    $list = $objRes['data']['data'] ?? $objRes['data']['objects'] ?? $objRes['data'] ?? $objRes['objects'] ?? [];
}

$OBJ_MAP = [];
foreach ($list as $o) {
    $imei = strtoupper($o['imei'] ?? $o['props']['imei'] ?? $o['custom_fields']['imei'] ?? '');
    if ($imei) $OBJ_MAP[$imei] = $o;
}
echo "✅ Загружено: " . count($OBJ_MAP) . " объектов\n";

// 🛰️ 4️⃣ СБОР ПОЗИЦИЙ ПО ВСЕМ ТРЕКЕРАМ
foreach ($TRACKERS as $imei) {
    $imeiU = strtoupper($imei);
    echo "\n🔍 IMEI: $imeiU ... ";
    
    if (empty($OBJ_MAP[$imeiU])) { echo "❌ Нет в списке\n"; $ERR++; continue; }
    
    $obj  = $OBJ_MAP[$imeiU];
    $id   = $obj['id'] ?? $obj['unit_id'] ?? null;
    $name = $obj['name'] ?? $obj['alias'] ?? $obj['nm'] ?? "IMEI:$imeiU";
    echo "✅ $name (ID: $id) ... ";

    // Запрос последней точки
    $posRes = jsonRpcCall($API_URL, 'getObjectPositions', [
        'obj_id'     => $id,
        'time_start' => time() - 86400,
        'time_end'   => time(),
        'limit'      => 1
    ], $TOKEN);

    // Если метод не подходит, пробуем getLastPosition или renderMessages
    $pos = null;
    if (empty($posRes['data']) && empty($posRes['result'])) {
        $posRes = jsonRpcCall($API_URL, 'getLastPosition', ['obj_id' => $id], $TOKEN);
    }
    if (empty($posRes['data']) && empty($posRes['result'])) {
        $posRes = jsonRpcCall($API_URL, 'renderMessages', ['type' => 'last', 'itemId' => $id], $TOKEN);
    }

    $rawData = $posRes['data'] ?? $posRes['result'] ?? $posRes['positions'] ?? $posRes;
    if (is_array($rawData) && isset($rawData[0])) $pos = $rawData[0];
    elseif (is_array($rawData) && isset($rawData['lat'])) $pos = $rawData;

    if ($pos) {
        echo "🟢 OK\n";
        $RESULTS[$imeiU] = array_merge([
            'name' => $name, 'object_id' => $id, 'imei' => $imeiU, 'retrieved_at' => date('c')
        ], $pos);
        $OK++;
    } else {
        echo "⚠️ Нет данных (HTTP {$posRes['code']})\n";
        $ERR++;
    }
    usleep(200000);
}

// 📊 5️⃣ ВЫВОД ВСЕХ ПОЛЕЙ НА ЭКРАН
echo "\n" . str_repeat("=", 60) . "\n";
echo "📊 РЕЗУЛЬТАТЫ (ВСЕ поля из API):\n";
echo str_repeat("=", 60) . "\n";

foreach ($RESULTS as $imei => $data) {
    echo "\n🚗 {$data['name']} | IMEI: $imei | ID: {$data['object_id']}\n";
    echo str_repeat("-", 50) . "\n";
    foreach ($data as $key => $val) {
        if (is_null($val)) $v = 'null';
        elseif (is_array($val) || is_object($val)) $v = json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        elseif (is_bool($val)) $v = $val ? 'true' : 'false';
        else $v = $val;
        echo "   " . str_pad($key . ":", 26) . " $v\n";
    }
}

echo "\n📈 ИТОГИ: ✅ Успешно: $OK | ❌ Ошибки: $ERR из " . count($TRACKERS) . "\n";

// 💾 6️⃣ СОХРАНЕНИЕ В JSON
if (!empty($RESULTS)) {
    file_put_contents(__DIR__ . '/trackers_data.json', 
        json_encode($RESULTS, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo "💾 Сохранено в trackers_data.json\n";
}

echo "✅ Готово!\n";

// ==========================================
// 🔧 ЯДРО: JSON-RPC ЗАПРОС К API
// ==========================================
function jsonRpcCall($url, $method, $params, $token) {
    $payload = json_encode(['method' => $method, 'params' => $params], JSON_UNESCAPED_UNICODE);
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token) $headers[] = "X-Auth: $token";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) return ['error' => "cURL: $curlErr", 'code' => $code, 'raw' => ''];
    if (!$res || $res === 'false') return ['error' => "Пустой ответ (HTTP $code)", 'code' => $code, 'raw' => ''];

    $data = json_decode($res, true);
    return ['code' => $code, 'data' => $data, 'raw' => $res];
}
?>