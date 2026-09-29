<?php
declare(strict_types=1);

final class Map8000RouteStore
{
    private string $lockPath;

    public function __construct(private readonly string $path)
    {
        $this->lockPath = $path . '.lock';
    }

    public function all(): array
    {
        $data = $this->withLock(LOCK_SH, fn(): array => $this->readUnlocked());
        $routes = is_array($data['routes'] ?? null) ? $data['routes'] : [];
        usort($routes, static fn(array $a, array $b): int => ((int)$b['id']) <=> ((int)$a['id']));
        return $routes;
    }

    public function save(array $route): array
    {
        return $this->withLock(LOCK_EX, function () use ($route): array {
            $data = $this->readUnlocked();
            $routes = is_array($data['routes'] ?? null) ? $data['routes'] : [];
            $requestedId = max(0, (int)($route['id'] ?? 0));
            $now = date(DATE_ATOM);

            if ($requestedId > 0) {
                $found = false;
                foreach ($routes as $index => $existing) {
                    if ((int)($existing['id'] ?? 0) !== $requestedId) {
                        continue;
                    }
                    $route['id'] = $requestedId;
                    $route['created_at'] = (string)($existing['created_at'] ?? $now);
                    $route['updated_at'] = $now;
                    $routes[$index] = $route;
                    $found = true;
                    break;
                }
                if (!$found) {
                    throw new RuntimeException('Редактируемый рейс не найден.');
                }
            } else {
                $nextId = max(1, (int)($data['next_id'] ?? 1));
                $route['id'] = $nextId;
                $route['created_at'] = $now;
                $route['updated_at'] = $now;
                $routes[] = $route;
                $data['next_id'] = $nextId + 1;
            }

            $data['routes'] = array_values($routes);
            $data['version'] = 1;
            $data['updated_at'] = $now;
            $this->writeUnlocked($data);
            return $route;
        });
    }

    public function delete(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        return $this->withLock(LOCK_EX, function () use ($id): bool {
            $data = $this->readUnlocked();
            $routes = is_array($data['routes'] ?? null) ? $data['routes'] : [];
            $remaining = array_values(array_filter(
                $routes,
                static fn(array $route): bool => (int)($route['id'] ?? 0) !== $id
            ));
            if (count($remaining) === count($routes)) {
                return false;
            }
            $data['routes'] = $remaining;
            $data['updated_at'] = date(DATE_ATOM);
            $this->writeUnlocked($data);
            return true;
        });
    }

    private function withLock(int $mode, callable $callback): mixed
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Не удалось создать каталог данных map8000.');
        }
        $handle = fopen($this->lockPath, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Не удалось открыть блокировку хранилища рейсов.');
        }
        try {
            if (!flock($handle, $mode)) {
                throw new RuntimeException('Не удалось заблокировать хранилище рейсов.');
            }
            $result = $callback();
            flock($handle, LOCK_UN);
            return $result;
        } finally {
            fclose($handle);
        }
    }

    private function readUnlocked(): array
    {
        if (!is_file($this->path)) {
            return ['version' => 1, 'next_id' => 1, 'routes' => []];
        }
        $json = file_get_contents($this->path);
        $data = json_decode((string)$json, true);
        if (!is_array($data)) {
            throw new RuntimeException('Файл рейсов повреждён и не является корректным JSON.');
        }
        return $data;
    }

    private function writeUnlocked(array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Не удалось сформировать JSON рейсов.');
        }
        $temporary = $this->path . '.tmp-' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Не удалось записать временный файл рейсов.');
        }
        @chmod($temporary, 0660);
        if (!@rename($temporary, $this->path)) {
            // Windows cannot atomically replace an existing file with rename().
            // The separate lock file still protects this fallback from concurrent writers.
            $contents = file_get_contents($temporary);
            $written = $contents !== false
                ? file_put_contents($this->path, $contents, LOCK_EX)
                : false;
            @unlink($temporary);
            if ($written === false) {
                throw new RuntimeException('Не удалось заменить файл рейсов.');
            }
        }
    }
}
