<?php
function createPdoConnection(array $dbConfig)
{
    $host = $dbConfig['host'] ?? null;
    $port = (int)($dbConfig['port'] ?? 3306);
    $database = $dbConfig['database'] ?? ($dbConfig['dbname'] ?? null);
    $username = $dbConfig['username'] ?? null;
    $password = $dbConfig['password'] ?? null;
    $charset = $dbConfig['charset'] ?? 'utf8mb4';

    if (empty($host) || empty($database) || empty($username)) {
        if (function_exists('mapError')) {
            mapError('Database configuration is missing or invalid', ['host' => $host, 'database' => $database, 'username' => $username]);
        }
        die('Database configuration is missing or invalid');
    }

    try {
        return new PDO(
            "mysql:host={$host};port={$port};dbname={$database};charset={$charset}",
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (PDOException $e) {
        if (function_exists('mapError')) {
            mapError('PDO connection failed', ['error' => $e->getMessage()]);
        }
        die('Ошибка подключения к базе данных: ' . $e->getMessage());
    }
}
