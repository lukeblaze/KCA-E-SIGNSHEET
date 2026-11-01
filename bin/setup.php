<?php
declare(strict_types=1);
// Usage: php bin/setup.php [--demo]
require dirname(__DIR__) . '/src/bootstrap.php';

use Kca\{Config, Db, Util};

$env = ROOT . '/.env';
if (!is_file($env)) {
    $tpl = (string)file_get_contents(ROOT . '/.env.example');
    $tpl = preg_replace('/^APP_SECRET=.*$/m', 'APP_SECRET=' . bin2hex(random_bytes(32)), $tpl);
    file_put_contents($env, $tpl);
    chmod($env, 0600);
    echo "Created .env with a fresh APP_SECRET\n";
    Config::load();
}
foreach (['docs', 'outbox', 'keys'] as $d) Config::storage($d);
$db = Db::connect();
echo "Database ready\n";
new Kca\Documents($db);
echo "Signing key ready (storage/keys/signing.pem). In production keep this key in an HSM or vault.\n";

if (in_array('--demo', $argv, true)) {
    $now = time();
    $code = 'S' . gmdate('ymd') . '-DEMO';
    $db->prepare('INSERT OR IGNORE INTO exam_sessions(code,period,venue,opens_at,closes_at,supervisor_email,supervisor_name,status,created_at,units,late_after,offline,source) VALUES(?,?,?,?,?,?,?,?,?,?,?,0,?)')
        ->execute([$code, 'Sem 2 2026 · Main exams', 'Main Hall A', gmdate('Y-m-d\TH:i:s\Z', $now - 600), gmdate('Y-m-d\TH:i:s\Z', $now + 4 * 3600), 'supervisor@kcau.ac.ke', 'Dr. Sample Supervisor', 'active', Util::now(),
            json_encode(['BSD 4101', 'BSD 4102', 'BSD 4103', 'BIT 3201']), 30, 'manual']);
    Kca\Features::ensureDirector($db, 'Exam Director');
    echo "Demo exam session $code created (open for 4 hours)\n";
}
echo "Run:  php -S 0.0.0.0:8080 -t public public/router.php\n";
