<?php
namespace App\Services;

/**
 * Сервис для работы с API DaData
 */
class DadataService
{
    private string $apiKey;
    private string $apiUrl;
    private int $timeout;
    
    public function __construct(string $apiKey, string $apiUrl, int $timeout = 10)
    {
        $this->apiKey = $apiKey;
        $this->apiUrl = $apiUrl;
        $this->timeout = $timeout;
    }
    
    /**
     * Получение юридического адреса компании по ИНН
     * @param string $inn ИНН компании (10 или 12 цифр)
     * @return string|null Юридический адрес или null, если компания не найдена
     */
    public function getLegalAddressByINN(string $inn): ?string
    {
        $cleanInn = preg_replace('/[^0-9]/', '', $inn);
        
        $options = [
            'http' => [
                'header' => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Authorization: Token ' . $this->apiKey,
                ],
                'method' => 'POST',
                'content' => json_encode(['query' => $cleanInn]),
                'timeout' => $this->timeout,
            ]
        ];
        
        $context = stream_context_create($options);
        $result = @file_get_contents($this->apiUrl, false, $context);
        
        if ($result === false) {
            error_log("DaData API request failed");
            return null;
        }
        
        $response = json_decode($result, true);
        if (empty($response['suggestions'][0])) {
            return null;
        }
        
        $companyData = $response['suggestions'][0]['data'];
        $address = $companyData['address']['value'] ?? null;
        
        if ($address && isset($companyData['address']['data']['postal_code'])) {
            $postalCode = $companyData['address']['data']['postal_code'];
            if (!str_starts_with($address, $postalCode)) {
                $address = $postalCode . ', ' . $address;
            }
        }
        
        return $address;
    }
}
