<?php
// Router for PHP's built-in server:  php -S 0.0.0.0:8080 -t public public/router.php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file) && str_starts_with(realpath($file) ?: '', __DIR__ . '/assets')) return false;
require __DIR__ . '/index.php';
