<?php
namespace App\Services;

use PDO;
use PDOException;

/**
 * Сервис для работы с базой данных (Singleton)
 */
class DatabaseService
{
    private static ?DatabaseService $instance = null;
    private ?PDO $pdo = null;
    private array $config;
    
    private function __construct(array $config)
    {
        $this->config = $config;
    }
    
    public static function getInstance(array $config): self
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }
    
    public function getConnection(): PDO
    {
        if ($this->pdo === null) {
            try {
                $this->pdo = new PDO(
                    "mysql:host={$this->config['host']};dbname={$this->config['dbname']};charset={$this->config['charset']}",
                    $this->config['username'],
                    $this->config['password'],
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_PERSISTENT => false,
                    ]
                );
            } catch (PDOException $e) {
                error_log("DB Connection Error: " . $e->getMessage());
                throw $e;
            }
        }
        return $this->pdo;
    }
    
    /**
     * Получить название отхода по коду ФККО с кэшированием
     */
    public function getWasteNameByCode(string $code): ?string
    {
        static $cache = [];
        
        if (!isset($cache[$code])) {
            $stmt = $this->getConnection()->prepare("SELECT name FROM fkko WHERE code = ?");
            $stmt->execute([$code]);
            $row = $stmt->fetch();
            $cache[$code] = $row ? $row['name'] : null;
        }
        
        return $cache[$code];
    }
}
