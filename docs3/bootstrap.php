<?php
/**
 * Автозагрузчик классов
 */
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/src/';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});

/**
 * Загрузка конфигурации
 */
function loadConfig() {
    $configFile = __DIR__ . '/config/config.local.php';
    if (!file_exists($configFile)) {
        $configFile = __DIR__ . '/config/config.example.php';
    }
    return require $configFile;
}
