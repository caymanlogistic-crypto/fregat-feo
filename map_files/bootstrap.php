<?php
$legacyConfigPath = dirname(__DIR__) . '/config.php';
if (is_file($legacyConfigPath)) {
    require_once $legacyConfigPath;
}

// Staging is deployed by rsync while PHP OPcache can keep old class bytecode.
// Invalidate data-layer files before require_once so map layer changes are applied immediately.
if (function_exists('opcache_invalidate')) {
    @opcache_invalidate(__DIR__ . '/Repositories/StatusBlockRepository.php', true);
    @opcache_invalidate(__DIR__ . '/Repositories/FeoRepository.php', true);
    @opcache_invalidate(__DIR__ . '/Repositories/FlightRepository.php', true);
    @opcache_invalidate(__DIR__ . '/Services/MapDataService.php', true);
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Repositories/FlightRepository.php';
require_once __DIR__ . '/Repositories/DriverRepository.php';
require_once __DIR__ . '/Repositories/StatusBlockRepository.php';
require_once __DIR__ . '/Repositories/FeoRepository.php';
require_once __DIR__ . '/Services/SlitexClient.php';
require_once __DIR__ . '/Services/TrackerService.php';
require_once __DIR__ . '/Services/TransportMatcherService.php';
require_once __DIR__ . '/Services/TransportVisibilityService.php';
require_once __DIR__ . '/Services/MapDataService.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    $dbHost = is_array($dbConfig ?? null) ? ($dbConfig['host'] ?? null) : null;
    $dbName = is_array($dbConfig ?? null) ? ($dbConfig['database'] ?? ($dbConfig['dbname'] ?? null)) : null;
    $dbUser = is_array($dbConfig ?? null) ? ($dbConfig['username'] ?? ($dbConfig['user'] ?? null)) : null;

    if (!isset($dbConfig) || !is_array($dbConfig) || empty($dbHost) || empty($dbName) || empty($dbUser)) {
        mapError('Database configuration is missing or invalid', [
            'dbConfig_type' => gettype($dbConfig ?? null),
            'has_host' => !empty($dbHost),
            'has_database' => !empty($dbName),
            'has_username' => !empty($dbUser),
        ]);
        die('Database configuration is missing or invalid');
    }
    $pdo = createPdoConnection($dbConfig);
}
