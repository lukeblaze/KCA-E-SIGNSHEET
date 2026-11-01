<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Kca\\')) return;
    $path = ROOT . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) require $path;
});

Kca\Config::load();
date_default_timezone_set(Kca\Config::get('TZ', 'Africa/Nairobi'));
