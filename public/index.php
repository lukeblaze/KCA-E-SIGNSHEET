<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use Kca\{Api, Audit, Db, Documents, Mailer};
use Kca\Portal\PortalFactory;

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; style-src 'self' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
if (!empty($_SERVER['HTTPS'])) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (str_starts_with($path, '/api/')) {
    $db = Db::connect();
    (new Api($db, PortalFactory::make(), new Audit($db), new Documents($db), new Mailer()))->handle($_SERVER['REQUEST_METHOD'], $path);
    return;
}
readfile(__DIR__ . '/app.html');
