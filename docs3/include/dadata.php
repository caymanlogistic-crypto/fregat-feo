<?php
// API конфигурация
define('DADATA_API_KEY', getenv('DADATA_API_TOKEN') ?: '');
define('DADATA_API_URL', 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/findById/party');

/**
 * Получение юридического адреса компании по ИНН
 * @param string $inn ИНН компании (10 или 12 цифр)
 * @return string|null Юридический адрес или null, если компания не найдена
 */
function getLegalAddressByINN($inn) {
    $options = [
        'http' => [
            'header' => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Token ' . DADATA_API_KEY
            ],
            'method' => 'POST',
            'content' => json_encode(['query' => preg_replace('/[^0-9]/', '', $inn)])
        ]
    ];
    
    $result = @file_get_contents(DADATA_API_URL, false, stream_context_create($options));
    if (!$result) return null;
    
    $response = json_decode($result, true);
    if (empty($response['suggestions'][0])) return null;
    
    $companyData = $response['suggestions'][0]['data'];
    
    // Получаем полный юридический адрес
    $address = $companyData['address']['value'] ?? null;
    
// Если есть почтовый индекс, добавляем его для полного формата (без дублирования)
if ($address && isset($companyData['address']['data']['postal_code'])) {
    $postalCode = $companyData['address']['data']['postal_code'];
    
    // Проверяем, не начинается ли уже адрес с этого индекса
    if (!str_starts_with($address, $postalCode)) {
        $address = $postalCode . ', ' . $address;
    }
}
    
    return $address;
}
?>