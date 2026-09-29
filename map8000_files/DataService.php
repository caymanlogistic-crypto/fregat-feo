<?php
declare(strict_types=1);

require_once __DIR__ . '/XlsxReader.php';

final class Map8000DataService
{
    public function __construct(
        private readonly string $xlsxPath,
        private readonly string $cachePath
    ) {
    }

    public function load(): array
    {
        $fingerprint = $this->fingerprint();
        $cached = $this->readCache();
        if (is_array($cached) && ($cached['fingerprint'] ?? '') === $fingerprint && isset($cached['payload'])) {
            return $cached['payload'];
        }

        $payload = (new Map8000XlsxReader())->readRequests($this->xlsxPath);
        $this->writeCache([
            'fingerprint' => $fingerprint,
            'generated_at' => date(DATE_ATOM),
            'payload' => $payload,
        ]);
        return $payload;
    }

    private function fingerprint(): string
    {
        if (!is_file($this->xlsxPath)) {
            throw new RuntimeException('Excel-файл заявок не найден.');
        }
        return implode(':', [
            (string)filesize($this->xlsxPath),
            (string)filemtime($this->xlsxPath),
        ]);
    }

    private function readCache(): ?array
    {
        if (!is_file($this->cachePath)) {
            return null;
        }
        $handle = @fopen($this->cachePath, 'rb');
        if ($handle === false) {
            return null;
        }
        try {
            if (!flock($handle, LOCK_SH)) {
                return null;
            }
            $json = stream_get_contents($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
        $decoded = json_decode((string)$json, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function writeCache(array $cache): void
    {
        $directory = dirname($this->cachePath);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            return;
        }
        $json = json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        $temporary = $this->cachePath . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($temporary, $json, LOCK_EX) === false) {
            return;
        }
        @chmod($temporary, 0660);
        if (!@rename($temporary, $this->cachePath)) {
            $contents = @file_get_contents($temporary);
            if ($contents !== false) {
                @file_put_contents($this->cachePath, $contents, LOCK_EX);
            }
            @unlink($temporary);
        }
    }
}
