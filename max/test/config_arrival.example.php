<?php

$config = require dirname(__DIR__) . '/config_arrival.example.php';
$config['unload']['radius_m'] = 20000000;

return $config;

