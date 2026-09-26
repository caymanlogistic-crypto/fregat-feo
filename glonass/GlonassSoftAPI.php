<?php
/**
 * Класс для работы с ГЛОНАССсофт API (версия 3.0)
 * Документация: https://glonasssoft.ru/wiki/metody-api-dlya-raboty-s-glonasssoft/
 * 
 * Исправления от 06.04.2026:
 * - Исправлена опечатка $0.5 → 0.5 в расчёте usleep()
 * - getTrackers(): добавлен обязательный параметр 'limit'
 * - getTrackerByImei(): парсинг IMEI из поля 'name' и возврат numeric vehicleId
 */

class GlonassSoftAPI
{
    private $baseUrl;
    private $authToken;
    private $login;
    private $password;
    private $lastRequestTime = 0;

    public function __construct($baseUrl, $login, $password)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->login = $login;
        $this->password = $password;
    }

    public function setToken($token): void { $this->authToken = $token; }
    public function getToken(): ?string { return $this->authToken; }

    /**
     * Авторизация и получение токена
     */
    public function login(): ?string
    {
        $url = $this->baseUrl . '/api/v3/auth/login';
        
        $response = $this->makeRequest('POST', $url, [
            'login' => $this->login,
            'password' => $this->password
        ]);
        
        if ($response && isset($response['AuthId']) && empty($response['Error'])) {
            $this->authToken = $response['AuthId'];
            return $this->authToken;
        }
        
        $errorMsg = $response['Error'] ?? 'Неизвестная ошибка';
        $errorCode = $response['ErrorStatus'] ?? null;
        error_log("❌ GlonassSoft Auth Error $errorCode: $errorMsg");
        
        return null;
    }

    /**
     * Получение списка трекеров с фильтрацией
     * ⚠️ Обязательно передавать 'limit', иначе API вернёт 400
     */
    public function getTrackers(array $filters = []): ?array
    {
        if (!$this->authToken && !$this->login()) return null;

        $url = $this->baseUrl . '/api/v3/vehicles/find';
        // ✅ Добавляем limit по умолчанию
        $filters = array_merge(['limit' => 1000], $filters);
        
        return $this->makeRequest('POST', $url, $filters, true);
    }

    /**
     * Поиск трекера по IMEI (15 hex-символов)
     * Возвращает: ['id' => numeric_vehicleId, 'name' => ..., 'imei' => ...]
     */
    public function getTrackerByImei(string $imei): ?array
    {
        if (!$this->authToken && !$this->login()) return null;

        // Получаем все трекеры (API не поддерживает прямой поиск по IMEI)
        $allTrackers = $this->getTrackers(['limit' => 1000]);
        if (!is_array($allTrackers)) return null;
        
        // Ищем по IMEI внутри поля name: "Имя (97C73511B79EA97)"
        foreach ($allTrackers as $tracker) {
            $name = $tracker['name'] ?? '';
            
            // Извлекаем 15 hex-символов из скобок в конце имени
            if (preg_match('/\(([A-F0-9]{15})\)\s*$/i', $name, $matches)) {
                $foundImei = strtoupper($matches[1]);
                
                if (strcasecmp($foundImei, strtoupper($imei)) === 0) {
                    return [
                        // ✅ vehicleId — числовой, нужен для /objects/{id}/lastdata
                        'id' => (int)($tracker['vehicleId'] ?? $tracker['id'] ?? 0),
                        'name' => $tracker['name'],
                        'imei' => $foundImei,
                        'guid' => $tracker['vehicleGuid'] ?? null,
                    ];
                }
            }
        }
        
        return null;
    }

    /**
     * Получение последних телеметрических данных
     * @param int $vehicleId Числовой ID из vehicleId (НЕ vehicleGuid!)
     */
    public function getLastData(int $vehicleId): ?array
    {
        if (!$this->authToken && !$this->login()) return null;
        
        $url = $this->baseUrl . '/api/v3/objects/' . $vehicleId . '/lastdata';
        return $this->makeRequest('GET', $url, null, true);
    }

    /**
     * Универсальный метод для выполнения HTTP-запросов
     */
    private function makeRequest(string $method, string $url, $data = null, bool $withAuth = false): ?array
    {
        // ⚠️ Лимит API: пауза 0.5 сек между запросами
        $elapsed = microtime(true) - $this->lastRequestTime;
        if ($elapsed < 0.5) {
            // ✅ ИСПРАВЛЕНО: убран лишний $ перед 0.5
            $sleepTime = (int)((0.5 - $elapsed) * 1000000);
            if ($sleepTime > 0) {
                usleep($sleepTime);
            }
        }
        $this->lastRequestTime = microtime(true);

        $ch = curl_init($url);
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        
        if ($withAuth && $this->authToken) {
            $headers[] = 'X-Auth: ' . $this->authToken;
        }
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING => '',
        ]);
        
        if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
            }
        } elseif ($method === 'GET' && $data !== null) {
            $url .= '?' . http_build_query($data);
            curl_setopt($ch, CURLOPT_URL, $url);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            error_log("CURL Error: $error");
            return null;
        }
        
        $result = json_decode($response, true);
        
        // ⚠️ ГЛОНАССсофт возвращает ошибки ВНУТРИ JSON при HTTP 200
        if (!empty($result['Error'])) {
            error_log("API Error {$result['ErrorStatus']}: {$result['Error']}");
             return $result; // ✅ Возвращаем массив с ошибкой, чтобы скрипт мог её показать
        }
        
        // Повторная авторизация при истечении токена
        if ($httpCode === 401 && $withAuth && $this->authToken) {
            $this->authToken = null;
            if ($this->login()) {
                return $this->makeRequest($method, $url, $data, $withAuth);
            }
            return null;
        }
        
        return $result;
    }
}