<?php
class SlitexClient
{
    private $baseUrl;
    private $token;

    public function __construct($baseUrl, $token)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
    }

    public function fetchDevices()
    {
        return $this->fetch('/api/external/devices');
    }

    public function fetchAdminFields()
    {
        return $this->fetch('/api/external/devices/admin-fields');
    }

    private function acquireRenameLock(): array
    {
        // Keep the advisory lock on a dedicated connection. Callers may use the
        // application's main PDO for business transactions; a failure there must
        // not silently release the SLITEX mutex while rename/reconciliation runs.
        $dbConfig = $GLOBALS['dbConfig'] ?? null;
        if (!is_array($dbConfig)) {
            return ['success' => false, 'pdo' => null, 'name' => null, 'error' => 'Database configuration is required for safe SLITEX rename'];
        }

        $host = (string)($dbConfig['host'] ?? '');
        $port = (int)($dbConfig['port'] ?? 3306);
        $database = (string)($dbConfig['database'] ?? ($dbConfig['dbname'] ?? ''));
        $username = (string)($dbConfig['username'] ?? ($dbConfig['user'] ?? ''));
        $password = (string)($dbConfig['password'] ?? '');
        $charset = (string)($dbConfig['charset'] ?? 'utf8mb4');
        if ($host === '' || $database === '' || $username === '') {
            return ['success' => false, 'pdo' => null, 'name' => null, 'error' => 'Invalid DB configuration for SLITEX rename lock'];
        }

        $lockName = 'maptest_slitex_tracker_assignment_v2';
        $lockPdo = null;
        try {
            $lockPdo = new PDO(
                "mysql:host={$host};port={$port};dbname={$database};charset={$charset}",
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );
            $stmt = $lockPdo->prepare('SELECT GET_LOCK(:lock_name, :wait_seconds)');
            if (!$stmt || !$stmt->execute([':lock_name' => $lockName, ':wait_seconds' => 15])) {
                return ['success' => false, 'pdo' => $lockPdo, 'name' => null, 'error' => 'Failed to request SLITEX rename lock'];
            }
            if ((int)$stmt->fetchColumn() !== 1) {
                return ['success' => false, 'pdo' => $lockPdo, 'name' => null, 'error' => 'Another SLITEX rename is in progress'];
            }
            return ['success' => true, 'pdo' => $lockPdo, 'name' => $lockName, 'error' => ''];
        } catch (Throwable $e) {
            return ['success' => false, 'pdo' => $lockPdo, 'name' => null, 'error' => $e->getMessage()];
        }
    }

    private function releaseRenameLock(array $lock): void
    {
        $pdo = $lock['pdo'] ?? null;
        $lockName = $lock['name'] ?? null;
        if (!($pdo instanceof PDO) || !is_string($lockName) || $lockName === '') {
            return;
        }
        try {
            $stmt = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            if ($stmt) {
                $stmt->execute([':lock_name' => $lockName]);
            }
        } catch (Throwable $ignored) {
        }
    }

    private function assertRenameLockHeld(array $lock): void
    {
        $pdo = $lock['pdo'] ?? null;
        $lockName = $lock['name'] ?? null;
        if (!($pdo instanceof PDO) || !is_string($lockName) || $lockName === '') {
            throw new RuntimeException('SLITEX rename lock is missing');
        }
        $stmt = $pdo->prepare('SELECT IS_USED_LOCK(:lock_name) AS owner_id, CONNECTION_ID() AS connection_id');
        if (!$stmt || !$stmt->execute([':lock_name' => $lockName])) {
            throw new RuntimeException('Failed to verify SLITEX rename lock');
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (string)($row['owner_id'] ?? '') === '' || (string)($row['connection_id'] ?? '') === ''
            || !hash_equals((string)$row['connection_id'], (string)$row['owner_id'])) {
            throw new RuntimeException('SLITEX rename lock was lost');
        }
    }

    private function readDeviceName(string $uniqueid): array
    {
        $devicesRaw = $this->fetchDevices();
        $devices = isset($devicesRaw['data']) && is_array($devicesRaw['data']) ? $devicesRaw['data'] : $devicesRaw;
        if (!is_array($devices)) {
            return ['found' => false, 'name' => null];
        }
        foreach ($devices as $device) {
            if (!is_array($device)) continue;
            if ((string)($device['uniqueid'] ?? '') !== $uniqueid) continue;
            return ['found' => true, 'name' => (string)($device['name'] ?? '')];
        }
        return ['found' => false, 'name' => null];
    }

    public function renameDevice(string $uniqueid, string $name): array
    {
        $lock = $this->acquireRenameLock();
        if (!($lock['success'] ?? false)) {
            return ['success' => false, 'status' => 0, 'error' => (string)($lock['error'] ?? 'SLITEX rename lock failed'), 'body' => ''];
        }

        try {
            $this->assertRenameLockHeld($lock);

            $path = '/api/external/devices/' . rawurlencode($uniqueid) . '/name';
            $url = $this->baseUrl . $path;

            $ch = curl_init();
            if ($ch === false) {
                mapError('Slitex rename: curl_init failed', ['uniqueid' => $uniqueid]);
                return ['success' => false, 'status' => 0, 'error' => 'curl_init failed', 'body' => ''];
            }

            $payload = json_encode(['name' => $name], JSON_UNESCAPED_UNICODE);
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_CUSTOMREQUEST => 'PATCH',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Content-Type: application/json',
                    'X-API-Token: ' . $this->token,
                ],
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $response = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            $requestSucceeded = !($response === false || $curlErr) && $httpCode >= 200 && $httpCode < 300;
            if (!$requestSucceeded) {
                mapError('Slitex rename request ambiguous/failed; reconciling actual state', [
                    'url' => $url,
                    'http_code' => $httpCode,
                    'error' => $curlErr,
                ]);
            }

            // Whether PATCH returned 2xx or timed out, only actual current SLITEX
            // state decides success. This handles lost acknowledgements safely.
            $actual = $this->readDeviceName($uniqueid);
            if (($actual['found'] ?? false) && hash_equals($name, (string)($actual['name'] ?? ''))) {
                return [
                    'success' => true,
                    'status' => $httpCode,
                    'error' => '',
                    'body' => $requestSucceeded ? (string)$response : 'PATCH response was ambiguous; GET confirmed requested NAME',
                    'reconciled' => !$requestSucceeded,
                ];
            }

            $actualName = ($actual['found'] ?? false) ? (string)($actual['name'] ?? '') : '[device not found/unverifiable]';
            $error = $requestSucceeded
                ? 'SLITEX did not confirm exact renamed device name'
                : (($curlErr ?: ('HTTP ' . $httpCode)) . '; actual NAME: ' . $actualName);
            return ['success' => false, 'status' => $httpCode, 'error' => $error, 'body' => is_string($response) ? $response : ''];
        } finally {
            $this->releaseRenameLock($lock);
        }
    }

    public function fetch($path)
    {
        $url = $this->baseUrl . $path;

        $ch = curl_init();
        if ($ch === false) {
            mapError('curl_init failed', ['url' => $url]);
            return [];
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["X-API-Token: {$this->token}"],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlErr) {
            mapError('Slitex request failed', ['url' => $url, 'http_code' => $httpCode, 'error' => $curlErr]);
            return [];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            mapError('Slitex non-2xx response', ['url' => $url, 'http_code' => $httpCode]);
            return [];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            mapError('Slitex invalid JSON', ['url' => $url, 'json_error' => json_last_error_msg()]);
            return [];
        }

        return $decoded;
    }
}
