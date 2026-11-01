<?php
declare(strict_types=1);
// End-to-end test: starts a private server with throwaway storage, then drives the real API.
// Usage: php tests/smoke.php
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/kca-esign-test-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
$port = 18000 + random_int(0, 999);
$env = array_merge(getenv(), ['APP_ENV' => 'dev', 'APP_SECRET' => bin2hex(random_bytes(32)), 'STORAGE_DIR' => $tmp, 'MAIL_MODE' => 'outbox',
    'VERIFY_BASE_URL' => "http://127.0.0.1:$port", 'PORTAL_DRIVER' => 'mock', 'MX_CHECK' => '0']);
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', "$root/public", "$root/public/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', "$tmp/server.log", 'w']], $pipes, $root, $env);
register_shutdown_function(function () use ($proc, $tmp) { proc_terminate($proc); exec('rm -rf ' . escapeshellarg($tmp)); });
usleep(600000);

$pass = 0; $fail = 0;
function ok(bool $c, string $name, string $extra = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name $extra\n"; } }

class Client {
    public string $jar; public string $csrf = '';
    public function __construct(public int $port) { $this->jar = tempnam(sys_get_temp_dir(), 'jar'); }
    public function req(string $m, string $path, $body = null, array $hdr = [], bool $raw = false): array {
        $ch = curl_init("http://127.0.0.1:{$this->port}$path");
        $h = array_merge(['X-CSRF-Token: ' . $this->csrf], $hdr);
        if (is_array($body)) { $body = json_encode($body); $h[] = 'Content-Type: application/json'; }
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_HTTPHEADER => $h, CURLOPT_HEADER => false]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $res = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        return [$code, $raw ? $res : (json_decode($res, true) ?? []), $res];
    }
    public function login(string $e, string $p): array { [$c, $j] = $this->req('GET', '/api/config'); $this->csrf = $j['csrf']; [$c, $j] = $this->req('POST', '/api/login', ['email' => $e, 'password' => $p]); if ($c === 200) $this->csrf = $j['csrf']; return [$c, $j]; }
}
function sigPng(int $strokes = 6): string {
    $im = imagecreatetruecolor(600, 200); imagesavealpha($im, true); imagealphablending($im, false);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127)); imagealphablending($im, true); imagesetthickness($im, 4);
    $c = imagecolorallocate($im, 10, 30, 100);
    for ($i = 0; $i < $strokes; $i++) imageline($im, 30 + $i * 80, 150, 70 + $i * 80, 40, $c);
    ob_start(); imagepng($im); return 'data:image/png;base64,' . base64_encode(ob_get_clean());
}
function blankPng(): string { $im = imagecreatetruecolor(600, 200); imagesavealpha($im, true); imagealphablending($im, false); imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127)); ob_start(); imagepng($im); return 'data:image/png;base64,' . base64_encode(ob_get_clean()); }

echo "Auth\n";
$sup = new Client($port); $inv = new Client($port); $dir = new Client($port);
[$c] = $sup->login('supervisor@kcau.ac.ke', 'wrong'); ok($c === 401, 'wrong password rejected');
[$c] = $sup->login('supervisor@kcau.ac.ke', 'Demo#Super1'); ok($c === 200, 'supervisor signs in');
[$c] = $inv->login('invigilator1@kcau.ac.ke', 'Demo#Invig1'); ok($c === 200, 'invigilator signs in');
[$c] = $dir->login('director@kcau.ac.ke', 'Demo#Direct1'); ok($c === 200, 'director signs in');
$raw = new Client($port); [$c] = $raw->req('GET', '/api/sessions'); ok($c === 401, 'API needs login');
$old = $sup->csrf; $sup->csrf = 'bad'; [$c] = $sup->req('POST', '/api/sessions', ['period' => 'x']); $sup->csrf = $old; ok($c === 403, 'missing/invalid CSRF token blocked');
$brute = new Client($port); $brute->login('x@x.com', 'a'); for ($i = 0; $i < 5; $i++) [$c] = $brute->login('x@x.com', 'b'); ok($c === 429, 'login rate limit kicks in');

echo "Session and delegation\n";
$o = date('c', time() - 600); $cl = date('c', time() + 3600);
[$c] = $inv->req('POST', '/api/sessions', ['period' => 'P', 'venue' => 'V', 'opens_at' => $o, 'closes_at' => $cl]); ok($c === 403, 'invigilator cannot create a session');
[$c, $j] = $sup->req('POST', '/api/sessions', ['period' => 'Sem 2 2026', 'venue' => 'Hall A', 'opens_at' => $o, 'closes_at' => $cl]); ok($c === 201, 'supervisor loads a session');
$sid = $j['session']['id'] ?? 0;
[$c] = $inv->req('GET', "/api/sessions/$sid/students?q=amina"); ok($c === 403, 'invigilator without grant is blocked');
[$c] = $sup->req('POST', "/api/sessions/$sid/access", ['email' => 'nobody@kcau.ac.ke']); ok($c === 404, 'cannot grant access to a non-portal account');
[$c] = $sup->req('POST', "/api/sessions/$sid/access", ['email' => 'invigilator1@kcau.ac.ke']); ok($c === 200, 'supervisor grants invigilator access');
[$c, $j] = $inv->req('GET', "/api/sessions/$sid/students?q=amina"); ok($c === 200 && count($j['students']) === 2, 'search by name finds both Aminas');
[$c, $j] = $inv->req('GET', "/api/sessions/$sid/students?q=22/01001"); ok(count($j['students'] ?? []) === 1, 'search by admission number');

echo "Clearance and units\n";
[$c, $j] = $inv->req('GET', "/api/sessions/$sid/students/" . rawurlencode('22/01004') . '/units'); ok($c === 200 && $j['cleared'] === false && $j['reason'] !== '', 'uncleared student shows reason');
[$c, $j] = $inv->req('GET', "/api/sessions/$sid/students/" . rawurlencode('22/01001') . '/units'); ok(count($j['units']) === 3, 'cleared student sees 3 units');

echo "Signing\n";
$good = ['adm_no' => '22/01001', 'unit_code' => 'BSD 4101', 'phone' => '0712 345 678', 'booklet_no' => 'BK-004521', 'email' => '2201001@students.kcau.ac.ke', 'signature' => sigPng()];
[$c, $j] = $inv->req('POST', "/api/sessions/$sid/signatures", array_merge($good, ['email' => 'amina@gmail.com'])); ok($c === 422, 'gmail address rejected');
[$c, $j] = $inv->req('POST', "/api/sessions/$sid/signatures", array_merge($good, ['email' => '2201002@students.kcau.ac.ke'])); ok($c === 422, "another student's school email rejected");
[$c, $j] = $inv->req('POST', "/api/sessions/$sid/signatures", array_merge($good, ['email' => '2201001@students.kcau.ac.ke.evil.com'])); ok($c === 422, 'look-alike domain rejected');
[$c] = $inv->req('POST', "/api/sessions/$sid/signatures", array_merge($good, ['signature' => blankPng()])); ok($c === 422, 'blank signature rejected');
[$c] = $inv->req('POST', "/api/sessions/$sid/signatures", array_merge($good, ['phone' => '123'])); ok($c === 422, 'bad phone rejected');
[$c] = $inv->req('POST', "/api/sessions/$sid/signatures", array_merge($good, ['unit_code' => 'BIT 3201'])); ok($c === 422, 'unit the student is not cleared for is rejected');
[$c] = $inv->req('POST', "/api/sessions/$sid/signatures", array_merge($good, ['adm_no' => '22/01004'])); ok($c === 422, 'uncleared student cannot sign');
[$c, $j] = $inv->req('POST', "/api/sessions/$sid/signatures", $good); ok($c === 200 && ($j['email']['status'] ?? '') === 'outbox', 'valid signature saved and email copy queued', json_encode($j));
$code1 = $j['email']['code'] ?? '';
$eml = glob("$tmp/outbox/*.eml"); ok(count($eml) === 1, 'email file written'); $mime = $eml ? file_get_contents($eml[0]) : '';
ok(str_contains($mime, 'To: <2201001@students.kcau.ac.ke>') && str_contains($mime, 'application/pdf'), 'email is addressed to the school email with a PDF attached');
ok(preg_match('/^KCA-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code1) === 1, 'receipt has a verification code');
[$c, $j] = $inv->req('GET', "/api/sessions/$sid/students/" . rawurlencode('22/01001') . '/units'); ok($j['units'][0]['signed'] === true && $j['units'][1]['signed'] === false, 'unit now shows as signed');

echo "Correction within the exam period\n";
[$c, $j] = $inv->req('POST', "/api/sessions/$sid/signatures", array_merge($good, ['booklet_no' => 'BK-004522'])); ok($c === 200 && $j['edited'] === true, 'student corrects their booklet number');
[$c, $j] = $inv->req('GET', "/api/sessions/$sid/signatures/" . rawurlencode('22/01001') . '/' . rawurlencode('BSD 4101')); ok($j['signature']['version'] === 2 && $j['signature']['booklet_no'] === 'BK-004522', 'record is now version 2');
[$c, $j] = $inv->req('POST', '/api/verify', ['code' => $code1]); ok($j['valid'] === true && $j['status'] === 'superseded', 'old receipt is authentic but marked superseded');

echo "More signatures and the sheet PDF\n";
foreach ([['22/01002', '2201002', 'BSD 4101', '0722000111', 'BK-1'], ['22/01005', '2201005', 'BIT 3201', '0733000222', 'BK-2'], ['22/01006', '2201006', 'BSD 4102', '0700000333', 'BK-3']] as [$a, $e, $u, $ph, $bk]) {
    [$c] = $inv->req('POST', "/api/sessions/$sid/signatures", ['adm_no' => $a, 'unit_code' => $u, 'phone' => $ph, 'booklet_no' => $bk . '23', 'email' => "$e@students.kcau.ac.ke", 'signature' => sigPng(4)]);
    ok($c === 200, "signature saved for $a");
}
[$c, $pdf, $rawpdf] = $inv->req('GET', "/api/sessions/$sid/sheet/" . rawurlencode('BSD 4101') . '.pdf', null, [], true); ok($c === 200 && str_starts_with($rawpdf, '%PDF-'), 'sealed sheet PDF downloads');
file_put_contents('/tmp/kca-sheet-sample.pdf', $rawpdf);
[$c, $j] = $inv->req('POST', '/api/verify', $rawpdf, ['Content-Type: application/pdf']); ok($j['valid'] === true && $j['entries'] === 2, 'untouched sheet verifies by file', json_encode($j));
$tampered = str_replace('BK-004522', 'BK-999999', $rawpdf); $tampered2 = $rawpdf . "\n% edited";
[$c, $j] = $inv->req('POST', '/api/verify', $tampered2, ['Content-Type: application/pdf']); ok($j['valid'] === false, 'a PDF with even a trailing edit fails verification');
[$c, $j] = $inv->req('POST', '/api/verify', ['code' => 'KCA-AAAA-BBBB-CCCC']); ok($j['found'] === false, 'unknown code is not valid');

echo "Closing, reopening and director control\n";
[$c] = $inv->req('POST', "/api/sessions/$sid/close"); ok($c === 403, 'invigilator cannot close the session');
[$c] = $sup->req('POST', "/api/sessions/$sid/close"); ok($c === 200, 'supervisor closes the session');
[$c, $j] = $inv->req('POST', "/api/sessions/$sid/signatures", array_merge($good, ['booklet_no' => 'BK-000000'])); ok($c === 403, 'invigilator access ends when the session closes');
[$c, $j] = $sup->req('POST', "/api/sessions/$sid/signatures", array_merge($good, ['booklet_no' => 'BK-000000'])); ok($c === 403 && ($j['needs_reopen'] ?? false), 'edit after close needs the exam director', json_encode($j));
$sigId = $j['signature_id'] ?? 0;
[$c] = $sup->req('POST', "/api/signatures/$sigId/reopen-request", ['reason' => 'x']); ok($c === 422, 'reopen request needs a real reason');
[$c] = $sup->req('POST', "/api/signatures/$sigId/reopen-request", ['reason' => 'Booklet number mistyped']); ok($c === 201, 'supervisor files a reopen request');
[$c] = $sup->req('POST', '/api/reopen-requests/1/approve', ['minutes' => 30]); ok($c === 403, 'supervisor cannot approve their own request');
[$c, $j] = $dir->req('GET', '/api/reopen-requests'); ok(count($j['requests']) === 1 && $j['requests'][0]['status'] === 'pending', 'director sees the pending request');
[$c] = $dir->req('POST', '/api/reopen-requests/1/approve', ['minutes' => 30]); ok($c === 200, 'director reopens the form');
[$c, $j] = $sup->req('POST', "/api/sessions/$sid/signatures", array_merge($good, ['booklet_no' => 'BK-004599'])); ok($c === 200 && $j['edited'], 'correction accepted during the reopened window');
[$c, $j] = $sup->req('POST', "/api/sessions/$sid/signatures", array_merge($good, ['booklet_no' => 'BK-004600'])); ok($c === 403, 'window closes again after one correction');

echo "Audit trail\n";
[$c, $j] = $dir->req('GET', '/api/audit/verify'); ok($j['ok'] === true && $j['entries'] > 10, 'audit chain intact (' . ($j['entries'] ?? 0) . ' entries)');
$pdo = new PDO('sqlite:' . $tmp . '/esign.sqlite'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
try { $pdo->exec("UPDATE audit_log SET actor='mallory' WHERE id=1"); ok(false, 'audit rows cannot be edited'); } catch (Throwable) { ok(true, 'audit rows cannot be edited (DB trigger)'); }
try { $pdo->exec('DELETE FROM documents'); ok(false, 'documents cannot be deleted'); } catch (Throwable) { ok(true, 'issued documents cannot be deleted (DB trigger)'); }
$pdo->exec('DROP TRIGGER audit_no_update'); $pdo->exec("UPDATE audit_log SET payload='{\"forged\":true}' WHERE id=3");
[$c, $j] = $dir->req('GET', '/api/audit/verify'); ok($j['ok'] === false && $j['broken_at'] === 3, 'even with DB access, a forged audit row is detected');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
