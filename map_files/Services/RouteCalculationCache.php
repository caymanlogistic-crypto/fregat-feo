<?php
declare(strict_types=1);

final class RouteCalculationCache
{
    private const TTL = 7 * 86400;
    private const LEASE = 300;
    // Bump the profile when the origin or routing objective changes.
    private const PROFILE = 'voskresensk-kirova-3v:auto:no-traffic:open:v1';

    public function __construct(private string $directory, private $clock = null)
    {
    }

    public function handle(string $action, array $points, array $payload = []): array
    {
        $keys = $this->pointKeys($points);
        // Old open tabs keep their own direction; new calculations start at the warehouse.
        $destination = array_key_exists('destination', $payload) ? $this->destination($payload['destination']) : null;
        $fromWarehouse = ($payload['direction'] ?? '') === 'from-warehouse';
        $roundTrip = ($payload['return_to_warehouse'] ?? false) === true;
        if ($fromWarehouse && $destination === null) throw new InvalidArgumentException('Выберите склад для расчёта');
        $mode = $fromWarehouse ? 'warehouse-start:auto:no-traffic:' . ($roundTrip ? 'v4:round:' : 'v3:open:') : 'warehouse-finish:auto:no-traffic:open:v2:';
        $profile = $destination === null ? self::PROFILE : $mode . json_encode($destination, JSON_THROW_ON_ERROR);
        $key = hash('sha256', $profile . ':' . implode(';', $keys));
        $this->prepareDirectory();
        $lock = @fopen($this->directory . '/' . $key . '.lock', 'c');
        if ($lock === false) throw new RuntimeException('Общее хранилище расчётов недоступно');
        if (!flock($lock, LOCK_EX)) { fclose($lock); throw new RuntimeException('Общее хранилище расчётов недоступно'); }
        try {
            $path = $this->directory . '/' . $key . '.json';
            $record = is_file($path) ? json_decode((string)@file_get_contents($path), true) : [];
            if (!is_array($record)) $record = [];
            $now = $this->clock ? (int)($this->clock)() : time();
            $hit = isset($record['result']) && ($record['expires_at'] ?? 0) > $now;
            if ($action === 'claim') {
                if ($hit) return ['status' => 'hit', 'result' => $record['result']];
                if (($record['lease_until'] ?? 0) > $now) return ['status' => 'pending'];
                $token = bin2hex(random_bytes(24));
                $this->write($path, ['lease_token' => $token, 'lease_until' => $now + self::LEASE]);
                return ['status' => 'compute', 'token' => $token];
            }
            if ($action === 'save' && $hit) {
                // Idempotent retry after a completed save; never overwrite a ready result.
                return ['status' => 'hit', 'result' => $record['result']];
            }
            $token = (string)($payload['token'] ?? '');
            if ($token === '' || !hash_equals((string)($record['lease_token'] ?? ''), $token)) {
                throw new InvalidArgumentException('Этот расчёт уже выполняется в другом окне. Повторите позже.');
            }
            if ($action === 'release') {
                $this->write($path, []);
                return ['status' => 'released'];
            }
            if ($action !== 'save') throw new InvalidArgumentException('Неизвестное действие');
            if (($record['lease_until'] ?? 0) <= $now) {
                throw new InvalidArgumentException('Время сохранения расчёта истекло. Повторите расчёт.');
            }
            $result = $this->validateResult($payload['result'] ?? null, $keys, $destination, $fromWarehouse);
            $this->write($path, ['result' => $result, 'expires_at' => $now + self::TTL]);
            return ['status' => 'saved', 'result' => $result];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function pointKeys(array $points): array
    {
        if (count($points) < 1 || count($points) > 49) throw new InvalidArgumentException('Некорректное количество адресов');
        $keys = [];
        foreach ($points as $point) {
            $coords = $this->coordinates($point);
            $keys[sprintf('%.7F,%.7F', $coords[0], $coords[1])] = true;
        }
        $result = array_keys($keys);
        sort($result, SORT_STRING);
        return $result;
    }

    private function coordinates(mixed $value): array
    {
        if (!is_array($value) || count($value) !== 2 || !is_numeric($value[0] ?? null) || !is_numeric($value[1] ?? null)) {
            throw new InvalidArgumentException('Некорректные координаты');
        }
        $lat = (float)$value[0]; $lon = (float)$value[1];
        if (!is_finite($lat) || !is_finite($lon) || abs($lat) > 90 || abs($lon) > 180) {
            throw new InvalidArgumentException('Некорректные координаты');
        }
        return [$lat, $lon];
    }

    private function destination(mixed $value): array
    {
        if (!is_array($value) || !is_numeric($value['id'] ?? null) || ((int)$value['id'] < 1 && (int)$value['id'] !== -1)) {
            throw new InvalidArgumentException('Выберите склад для расчёта');
        }
        $address = trim((string)($value['address'] ?? ''));
        $coords = ($value['coords'] ?? null) === null ? null : $this->coordinates($value['coords']);
        if (strlen($address) > 2000 || ($coords === null && $address === '')) throw new InvalidArgumentException('У склада не указан адрес');
        if ($coords !== null) $coords = array_map(fn($number) => sprintf('%.7F', $number), $coords);
        return ['id' => (int)$value['id'], 'address' => $address, 'coords' => $coords];
    }

    private function validateResult(mixed $value, array $keys, ?array $destination, bool $fromWarehouse): array
    {
        if (!is_array($value) || !is_array($value['order_keys'] ?? null)) throw new InvalidArgumentException('Некорректный результат расчёта');
        $order = $value['order_keys'];
        $sorted = $order; sort($sorted, SORT_STRING);
        if ($sorted !== $keys) throw new InvalidArgumentException('В сохранённом маршруте изменился набор адресов');
        $distance = $value['distance'] ?? null; $duration = $value['duration'] ?? null;
        if (!is_numeric($distance) || !is_numeric($duration) || !is_finite((float)$distance) || !is_finite((float)$duration)
            || $distance < 0 || $distance > 1e9 || $duration < 0 || $duration > 1e8) {
            throw new InvalidArgumentException('Некорректное расстояние или время');
        }
        $line = $value['coordinates'] ?? null;
        if (!is_array($line) || count($line) < 2 || count($line) > 300000) throw new InvalidArgumentException('Некорректная линия маршрута');
        $coordinates = array_map(fn($point) => $this->coordinates($point), $line);
        $endpoint = $destination === null || $fromWarehouse ? 'origin' : 'destination';
        $endpointCoords = $this->coordinates($value[$endpoint] ?? null);
        if ($destination !== null && $destination['coords'] !== null) {
            foreach ([0, 1] as $axis) {
                if (abs($endpointCoords[$axis] - (float)$destination['coords'][$axis]) > 0.0000001) {
                    throw new InvalidArgumentException('В сохранённом маршруте изменился склад');
                }
            }
        }
        return ['order_keys' => array_values($order), $endpoint => $endpointCoords,
            'distance' => (float)$distance, 'duration' => (float)$duration, 'coordinates' => $coordinates];
    }

    private function prepareDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0770, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Общее хранилище расчётов недоступно');
        }
        // This directory contains runtime data only; Apache must not serve its files.
        $guard = $this->directory . '/.htaccess';
        if (!is_file($guard) && @file_put_contents($guard, "Require all denied\n", LOCK_EX) === false) {
            throw new RuntimeException('Не удалось защитить хранилище расчётов');
        }
    }

    private function write(string $path, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        if (@file_put_contents($temporary, $json, LOCK_EX) === false) throw new RuntimeException('Не удалось сохранить расчёт');
        @chmod($temporary, 0660);
        if (!@rename($temporary, $path)) {
            $written = @file_put_contents($path, $json, LOCK_EX);
            @unlink($temporary);
            if ($written === false) throw new RuntimeException('Не удалось сохранить расчёт');
        }
    }
}
