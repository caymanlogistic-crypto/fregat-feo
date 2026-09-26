<?php
namespace App\Services;

/**
 * Сервис для выполнения Python-скриптов
 */
class PythonWorkerService
{
    private string $pythonBinary;
    private string $workerScript;
    
    public function __construct(string $pythonBinary, string $workerScript)
    {
        $this->pythonBinary = $pythonBinary;
        $this->workerScript = $workerScript;
    }
    
    /**
     * Выполнить парсинг PDF файла
     * @param string $pdfPath Путь к PDF файлу
     * @return array|null Результат парсинга или null при ошибке
     * @throws \RuntimeException Если Python не найден или скрипт вернул ошибку
     */
    public function parsePdf(string $pdfPath): ?array
    {
        if (!file_exists($this->pythonBinary)) {
            throw new \RuntimeException("Python не найден: " . $this->pythonBinary);
        }
        
        if (!file_exists($this->workerScript)) {
            throw new \RuntimeException("Worker скрипт не найден: " . $this->workerScript);
        }
        
        if (!file_exists($pdfPath)) {
            throw new \RuntimeException("PDF файл не найден: " . $pdfPath);
        }
        
        $cmd = sprintf(
            '%s %s %s 2>&1',
            escapeshellarg($this->pythonBinary),
            escapeshellarg($this->workerScript),
            escapeshellarg($pdfPath)
        );
        
        $descriptorspec = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"]
        ];
        
        $process = proc_open($cmd, $descriptorspec, $pipes);
        
        if (!is_resource($process)) {
            throw new \RuntimeException("Не удалось запустить процесс");
        }
        
        fclose($pipes[0]);
        
        $output = stream_get_contents($pipes[1]);
        $errorOutput = stream_get_contents($pipes[2]);
        
        fclose($pipes[1]);
        fclose($pipes[2]);
        
        $returnCode = proc_close($process);
        
        if ($returnCode !== 0) {
            throw new \RuntimeException("Ошибка Python:\n" . ($errorOutput ?: $output));
        }
        
        // Извлекаем JSON из вывода
        if (preg_match('/\{.*\}/s', $output, $matches)) {
            $data = json_decode($matches[0], true);
            if ($data && is_array($data)) {
                return $data;
            }
        }
        
        throw new \RuntimeException("JSON не найден в выводе Python");
    }
}
