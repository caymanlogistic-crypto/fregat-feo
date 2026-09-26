<?php
/**
 * Исправленное получение списка трекеров
 * Запуск: php fix_vehicles.php
 */

$baseUrl = 'https://fm2.delta.ru';
$token = trim((string) (getenv('GLONASS_API_TOKEN') ?: '')); // Ваш токен из диагностики

echo "🔧 Тестируем разные варианты запроса к /vehicles/find ...\n\n";

// Варианты запросов для теста
$variants = [
    'POST с пустым фильтром' => [
        'method' => 'POST',
        'url' => $baseUrl . '/api/v3/vehicles/find',
        'headers' => ['Content-Type: application/json', 'X-Auth: ' . $token],
        'body' => json_encode(['limit' => 100]), // ← Добавляем limit!
    ],
    'POST с фильтром по статусу' => [
        'method' => 'POST',
        'url' => $baseUrl . '/api/v3/vehicles/find',
        'headers' => ['Content-Type: application/json', 'X-Auth: ' . $token],
        'body' => json_encode(['status' => 'active', 'limit' => 100]),
    ],
    'GET без тела' => [
        'method' => 'GET',
        'url' => $baseUrl . '/api/v3/vehicles/find?limit=100',
        'headers' => ['X-Auth: ' . $token],
        'body' => null,
    ],
    'POST с parentId=0' => [
        'method' => 'POST',
        'url' => $baseUrl . '/api/v3/vehicles/find',
        'headers' => ['Content-Type: application/json', 'X-Auth: ' . $token],
        'body' => json_encode(['parentId' => 0, 'limit' => 100]),
    ],
];

$success = false;

foreach ($variants as $name => $req) {
    echo "🔄 Тест: $name\n";
    
    $ch = curl_init($req['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $req['headers'],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    
    if ($req['method'] === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $req['body']);
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    echo "   HTTP: $httpCode";
    if ($error) echo " | CURL: $error";
    echo "\n";
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if (is_array($data)) {
            echo "   ✅ УСПЕХ! Трекеров: " . count($data) . "\n";
            if (count($data) > 0) {
                $sample = $data[0];
                echo "   Пример IMEI: " . ($sample['imei'] ?? 'не указан') . "\n";
                echo "   Пример ID: " . ($sample['id'] ?? 'не указан') . "\n";
            }
            $success = true;
            
            // Сохраняем рабочий вариант в файл
            file_put_contents('vehicles_response.json', $response);
            echo "   💾 Ответ сохранён в vehicles_response.json\n";
            break;
        }
    } else {
        // Показываем ошибку для отладки
        $err = json_decode($response, true);
        if (!empty($err['Error'])) {
            echo "   ❌ {$err['Error']} (код {$err['ErrorStatus']})\n";
        }
    }
    echo "\n";
}

if (!$success) {
    echo "⚠️ Ни один вариант не сработал.\n";
    echo "\n🔍 Попробуйте вручную в браузере (с токеном):\n";
    echo "https://fm2.delta.ru/api/v3/vehicles/find?limit=100\n";
    echo "\nИли обратитесь в поддержку с вопросом:\n";
    echo "Какой формат запроса ожидает эндпоинт /api/v3/vehicles/find ?\n";
}