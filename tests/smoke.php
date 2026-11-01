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
$sup = new Client($port); $inv = new Client($port); $dir = new Client($port); $inv2 = new Client($port); $grace = new Client($port); $coord = new Client($port);
[$c] = $sup->login('supervisor@kcau.ac.ke', 'wrong'); ok($c === 401, 'wrong password rejected');
[$c, $j] = $sup->login('someone@gmail.com', 'Demo#Super1'); ok($c === 422 && str_contains($j['error'], 'KCA school email'), 'only @kcau.ac.ke staff emails can sign in');
[$c, $j] = $sup->login('supervisor@kcau.ac.ke', 'Demo#Super1'); ok($c === 200 && $j['user']['school'] === 'School of Technology', 'supervisor signs in (school returned)');
[$c] = $inv->login('invigilator1@kcau.ac.ke', 'Demo#Invig1'); ok($c === 200, 'invigilator signs in');
[$c] = $dir->login('director@kcau.ac.ke', 'Demo#Direct1'); ok($c === 200, 'director signs in');
[$c] = $inv2->login('invigilator2@kcau.ac.ke', 'Demo#Invig2'); ok($c === 200, 'second invigilator signs in');
[$c] = $grace->login('grace.wanjiru@kcau.ac.ke', 'Demo#Staff1'); ok($c === 200, 'other chief supervisor signs in');
[$c, $j] = $coord->login('coordinator@kcau.ac.ke', 'Demo#Coord1'); ok($c === 200 && $j['user']['role'] === 'coordinator', 'school coordinator signs in');
$raw = new Client($port); [$c] = $raw->req('GET', '/api/sessions'); ok($c === 401, 'API needs login');
$old = $sup->csrf; $sup->csrf = 'bad'; [$c] = $sup->req('POST', '/api/sessions', ['period' => 'x']); $sup->csrf = $old; ok($c === 403, 'missing/invalid CSRF token blocked');
$brute = new Client($port); $brute->login('x@kcau.ac.ke', 'a'); for ($i = 0; $i < 5; $i++) [$c] = $brute->login('x@kcau.ac.ke', 'b'); ok($c === 429, 'login rate limit kicks in');

echo "Sessions: only the exam director adds one-offs\n";
$o = date('c', time() - 600); $cl = date('c', time() + 3600);
$mk = ['period' => 'Sem 2 2026', 'venue' => 'Hall A', 'opens_at' => $o, 'closes_at' => $cl, 'supervisor_email' => 'supervisor@kcau.ac.ke', 'units' => ['BSD 4101', 'BSD 4102', 'BSD 4103', 'BIT 3201'], 'late_after' => 30];
[$c] = $inv->req('POST', '/api/sessions', $mk); ok($c === 403, 'invigilator cannot create a session');
[$c, $j] = $sup->req('POST', '/api/sessions', $mk); ok($c === 403 && str_contains($j['error'], 'Only the exam director'), 'chief supervisor cannot create a session (sessions come from the timetable)');
[$c, $j] = $dir->req('POST', '/api/sessions', array_merge($mk, ['supervisor_email' => 'invigilator1@kcau.ac.ke'])); ok($c === 422 && str_contains($j['error'], 'chief supervisor'), 'session needs a chief-supervisor account');
[$c, $j] = $dir->req('POST', '/api/sessions', array_merge($mk, ['units' => []])); ok($c === 422 && str_contains($j['error'], 'at least one paper'), 'session needs at least one paper');
[$c, $j] = $dir->req('POST', '/api/sessions', array_merge($mk, ['units' => ['NOPE 000']])); ok($c === 422, 'unknown papers are ignored, so a session of only unknown papers is refused');
[$c, $j] = $dir->req('POST', '/api/sessions', array_merge($mk, ['closes_at' => date('c', time() + 90000)])); ok($c === 422, '24-hour limit enforced');
[$c, $j] = $dir->req('POST', '/api/sessions', $mk); ok($c === 201, 'director loads a one-off session');
$sid = $j['session']['id'] ?? 0; $S1 = $j['session'] ?? [];
ok($S1['source'] === 'manual' && $S1['units'] === ['BSD 4101', 'BSD 4102', 'BSD 4103', 'BIT 3201'] && $S1['late_after'] === 30 && $S1['offline'] === false, 'session carries units, late_after, source and offline flag');
ok($S1['expected'] === 10 && $S1['level'] === 'director', 'expected = cleared registered students across the session papers (10)', json_encode($S1));
[$c, $j] = $dir->req('GET', '/api/portal/units'); ok($c === 200 && count($j['units']) >= 9 && isset($j['units'][0]['name']), 'director can list portal units');
[$c] = $sup->req('GET', '/api/portal/units'); ok($c === 403, 'portal unit list is director-only');
[$c, $j] = $sup->req('GET', '/api/sessions'); ok(count($j['sessions']) === 1 && $j['sessions'][0]['level'] === 'supervisor', 'chief supervisor sees the session they run');
[$c, $j] = $grace->req('GET', '/api/sessions'); ok(count($j['sessions']) === 0, 'another chief supervisor sees nothing');
[$c, $j] = $inv->req('GET', '/api/sessions'); ok(count($j['sessions']) === 0, 'invigilator without a grant sees nothing');
[$c] = $inv->req('GET', "/api/sessions/$sid/students?q=22/01001"); ok($c === 403, 'invigilator without grant is blocked');
[$c] = $sup->req('POST', "/api/sessions/$sid/access", ['email' => 'nobody@kcau.ac.ke']); ok($c === 404, 'cannot grant access to a non-portal account');
[$c] = $sup->req('POST', "/api/sessions/$sid/access", ['email' => 'invigilator1@kcau.ac.ke']); ok($c === 200, 'supervisor grants invigilator access');
[$c] = $sup->req('POST', "/api/sessions/$sid/access", ['email' => 'invigilator2@kcau.ac.ke']); ok($c === 200, 'second invigilator granted');
[$c, $j] = $inv->req('GET', '/api/sessions'); ok(count($j['sessions']) === 1 && $j['sessions'][0]['level'] === 'invigilator', 'invigilator now sees the session');
[$c, $j] = $dir->req('GET', '/api/sessions'); ok(count($j['sessions']) === 1, 'director sees every session');

echo "Student lookup: admission number only, with lockout\n";
$q = fn($v) => "/api/sessions/$sid/students?q=" . rawurlencode($v);
[$c, $j] = $inv->req('GET', $q('amina')); ok($c === 200 && $j['students'] === [], 'short text finds nothing');
[$c, $j] = $inv->req('GET', $q('Amina Wanjiru Otieno')); ok($j['students'] === [], 'name search is gone');
[$c, $j] = $inv->req('GET', $q('22/0100')); ok($j['students'] === [], 'partial admission number finds nothing');
[$c, $j] = $inv->req('GET', $q('22/01001')); ok(count($j['students']) === 1 && $j['students'][0]['full_name'] === 'Amina Wanjiru Otieno' && str_starts_with((string)$j['students'][0]['photo'], 'data:image'), 'exact admission number finds the student with portal photo');
[$c, $j] = $inv->req('GET', $q(' 2201001 ')); ok(count($j['students']) === 1, 'matching ignores spaces, slashes and case');
for ($i = 0; $i < 5; $i++) [$c, $j] = $sup->req('GET', $q('99/99999'));
ok($c === 200 && $j['students'] === [], 'five wrong numbers are answered normally');
[$c, $j] = $sup->req('GET', $q('99/99999')); ok($c === 429 && ($j['locked'] ?? false) === true, 'sixth attempt is locked');
[$c, $j] = $sup->req('GET', $q('22/01001')); ok($c === 429, 'even a correct number is refused while locked');
[$c, $j] = $inv->req('GET', $q('22/01001')); ok($c === 200 && count($j['students']) === 1, 'the lock is per staff member, not per session');
[$c] = $sup->req('POST', "/api/sessions/$sid/lookups/reset"); ok($c === 200, 'lookups can be reset');
[$c, $j] = $sup->req('GET', $q('22/01001')); ok($c === 200 && count($j['students']) === 1, 'search works again after reset');

echo "Clearance and units\n";
$u = fn($a, $cl = null) => "/api/sessions/$sid/students/" . rawurlencode($a) . '/units';
[$c, $j] = $inv->req('GET', $u('22/01004')); ok($c === 200 && $j['cleared'] === false && $j['reason'] !== '', 'uncleared student shows reason');
[$c, $j] = $inv->req('GET', $u('22/01001')); ok(count($j['units']) === 3 && $j['units'][0]['in_session'] === true, 'cleared student sees 3 units, flagged in_session');
[$c, $j] = $inv->req('GET', $u('22/01005'));
$by = array_column($j['units'], null, 'code');
ok(count($j['units']) === 3 && $by['BIT 3201']['in_session'] === true && $by['BIT 3202']['in_session'] === false && $by['BIT 3202']['can_edit'] === false, 'units outside the session are listed but flagged not in_session');
ok($j['session_units'] === ['BSD 4101', 'BSD 4102', 'BSD 4103', 'BIT 3201'], 'session_units returned');

echo "Signing\n";
$good = ['adm_no' => '22/01001', 'unit_code' => 'BSD 4101', 'booklet_no' => 'BK-004521', 'signature' => sigPng(), 'ident' => 'card_id'];
$post = fn($cl, $b) => $cl->req('POST', "/api/sessions/$sid/signatures", $b);
[$c] = $post($inv, array_merge($good, ['signature' => blankPng()])); ok($c === 422, 'blank signature rejected');
[$c] = $post($inv, array_merge($good, ['booklet_no' => '!'])); ok($c === 422, 'bad booklet number rejected');
[$c] = $post($inv, array_merge($good, ['unit_code' => 'BIT 3201'])); ok($c === 422, 'unit the student is not cleared for is rejected');
[$c, $j] = $post($inv, array_merge($good, ['adm_no' => '22/01005', 'unit_code' => 'BIT 3202', 'booklet_no' => 'BK-9'])); ok($c === 422 && str_contains($j['error'], 'not part of this exam session'), 'a cleared paper from another session is rejected by the server');
[$c] = $post($inv, array_merge($good, ['adm_no' => '22/01004'])); ok($c === 422, 'uncleared student cannot sign');
[$c, $j] = $post($inv, array_diff_key($good, ['ident' => 1])); ok($c === 422 && str_contains($j['error'], 'how the student was identified'), 'identity check must be recorded');
[$c] = $post($inv, array_merge($good, ['ident' => 'trust_me'])); ok($c === 422, 'unknown identity check value rejected');
[$c, $j] = $inv->req('GET', $u('22/01006')); ok($j['prefill']['phone'] === null && $j['prefill']['email'] === '2201006@students.kcau.ac.ke', 'portal prefill returned (email present, phone missing for 22/01006)');
[$c, $j] = $inv->req('GET', $u('22/01001')); ok($j['prefill']['phone'] === '+254712345678' && $j['prefill']['phone_from_portal'] === true, 'portal phone prefilled for 22/01001');
[$c, $j] = $post($inv, $good); ok($c === 200 && ($j['email']['status'] ?? '') === 'outbox', 'valid signature saved and email copy queued', json_encode($j));
$code1 = $j['email']['code'] ?? '';
ok(str_starts_with((string)($j['email']['pdf'] ?? ''), '/api/documents/KCA-'), 'save response carries the receipt PDF route');
[$c, $j2] = $inv->req('GET', "/api/sessions/$sid/signatures/" . rawurlencode('22/01001') . '/' . rawurlencode('BSD 4101')); ok($j2['signature']['email'] === '2201001@students.kcau.ac.ke' && $j2['signature']['full_name'] === 'Amina Wanjiru Otieno' && $j2['signature']['phone'] === '+254712345678', 'name, email and phone stored from the portal record');
$eml = glob("$tmp/outbox/*.eml"); ok(count($eml) === 1, 'email file written'); $mime = $eml ? file_get_contents($eml[0]) : '';
ok(str_contains($mime, 'To: <2201001@students.kcau.ac.ke>') && str_contains($mime, 'application/pdf'), 'email is addressed to the school email with a PDF attached');
ok(preg_match('/^KCA-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code1) === 1, 'receipt has a verification code');
[$c, $j] = $inv->req('GET', $u('22/01001')); ok($j['units'][0]['signed'] === true && $j['units'][1]['signed'] === false, 'unit now shows as signed');
[$c, $j] = $post($inv, ['adm_no' => '22/01002', 'unit_code' => 'BSD 4101', 'booklet_no' => 'bk-004521', 'signature' => sigPng(3), 'ident' => 'card_id']); ok($c === 409 && str_contains($j['error'], 'booklet serial number is already recorded'), 'booklet number is unique within a session (case-insensitive)');

[$c] = $post($inv, ['adm_no' => '22/01002', 'unit_code' => 'BSD 4101', 'booklet_no' => 'BK-77', 'email' => 'attacker@gmail.com', 'full_name' => 'Someone Else', 'phone' => '0799999999', 'signature' => sigPng(3), 'ident' => 'photo_only', 'ident_note' => 'ID left at hostel']);
$e2 = array_values(array_filter(glob("$tmp/outbox/*.eml"), fn($f) => str_contains((string)file_get_contents($f), '2201002@students')));
ok($c === 200 && count($e2) === 1 && !str_contains((string)file_get_contents($e2[0]), 'attacker@gmail.com'), 'email typed by the client is ignored; copy goes to the portal email');
[$c, $j2] = $inv->req('GET', "/api/sessions/$sid/signatures/" . rawurlencode('22/01002') . '/' . rawurlencode('BSD 4101')); ok($j2['signature']['full_name'] === 'Brian Kiprono Mwangi' && $j2['signature']['phone'] === '+254722000111', 'client-sent name and phone are ignored too');
echo "Correction within the exam period\n";
[$c, $j] = $post($inv, array_merge($good, ['booklet_no' => 'BK-004522'])); ok($c === 200 && $j['edited'] === true, 'student corrects their booklet number (no new identity check needed)');
[$c, $j] = $inv->req('GET', "/api/sessions/$sid/signatures/" . rawurlencode('22/01001') . '/' . rawurlencode('BSD 4101')); ok($j['signature']['version'] === 2 && $j['signature']['booklet_no'] === 'BK-004522', 'record is now version 2');
[$c, $j] = $inv->req('POST', '/api/verify', ['code' => $code1]); ok($j['valid'] === true && $j['status'] === 'superseded', 'old receipt is authentic but marked superseded');

echo "Identity review, flags and incidents\n";
[$c, $j] = $sup->req('GET', "/api/sessions/$sid/incidents"); $rev = $j['reviews'] ?? [];
ok($c === 200 && count($rev) === 1 && $rev[0]['review'] === 'pending' && $rev[0]['note'] === 'ID left at hostel', 'photo-only check is waiting for the supervisor');
$brianId = $rev[0]['id'] ?? 0;
[$c] = $inv->req('POST', "/api/signatures/$brianId/review"); ok($c === 403, 'invigilator cannot review an identity check');
[$c, $j] = $inv->req('GET', "/api/sessions/$sid/incidents"); ok($j['level'] === 'invigilator' && $j['incidents'] === [] && $j['reviews'] === [], 'invigilator sees no incidents or reviews');
[$c, $j] = $inv->req('POST', "/api/sessions/$sid/incidents", ['adm_no' => '22/01002', 'unit_code' => 'BSD 4101', 'category' => 'Disruption', 'text' => 'Talking during the paper']); ok($c === 403 && str_contains($j['error'], 'Flag for supervisor'), 'invigilators cannot record incidents');
[$c, $j] = $inv->req('POST', "/api/sessions/$sid/flags", ['adm_no' => '22/01002', 'unit_code' => 'BSD 4101', 'text' => 'short']); ok($c === 422, 'flag needs a description');
[$c, $j] = $inv->req('POST', "/api/sessions/$sid/flags", ['adm_no' => '22/01002', 'unit_code' => 'BSD 4101', 'text' => 'Seen looking at a neighbour\'s paper']); ok($c === 201 && $j['flag']['status'] === 'open', 'invigilator flags a student');
$flagId = $j['flag']['id'] ?? 0;
[$c, $j] = $inv2->req('GET', "/api/sessions/$sid/incidents"); ok($j['flags'] === [], 'an invigilator sees only their own flags');
[$c, $j] = $sup->req('GET', "/api/sessions/$sid/incidents"); ok(count($j['flags']) === 1 && $j['flags'][0]['by'] === 'invigilator1@kcau.ac.ke' && isset($j['statuses']['resit_required']) && count($j['categories']) === 5, 'supervisor sees the flag, statuses and categories');
$inc = ['adm_no' => '22/01002', 'unit_code' => 'BSD 4101', 'category' => 'Suspected malpractice', 'text' => 'Notes found under the desk.', 'flag_id' => $flagId];
[$c] = $dir->req('POST', "/api/sessions/$sid/incidents", $inc); ok($c === 403, 'the director cannot write incident notes either');
[$c, $j] = $sup->req('POST', "/api/sessions/$sid/incidents", array_merge($inc, ['unit_code' => 'BIT 3201'])); ok($c === 422 && str_contains($j['error'], 'not registered'), 'student must be registered for the unit');
[$c, $j] = $sup->req('POST', "/api/sessions/$sid/incidents", array_merge($inc, ['category' => 'Gossip'])); ok($c === 422, 'category must be from the list');
[$c, $j] = $sup->req('POST', "/api/sessions/$sid/incidents", $inc); ok($c === 201 && preg_match('/^INC-\d{4}-0001$/', $j['incident']['case_no']) === 1 && count($j['incident']['entries']) === 1, 'supervisor records an incident');
$incId = $j['incident']['id'] ?? 0; $caseNo = $j['incident']['case_no'] ?? '';
[$c, $j] = $sup->req('GET', "/api/sessions/$sid/incidents"); ok($j['flags'][0]['status'] === 'recorded', 'the flag is marked recorded');
[$c] = $inv->req('POST', "/api/incidents/$incId/update", ['status' => 'let_go', 'text' => 'Nothing more to do']); ok($c === 403, 'invigilator cannot update a case');
[$c] = $sup->req('POST', "/api/incidents/$incId/update", ['status' => 'bogus', 'text' => 'Nothing more to do']); ok($c === 422, 'unknown outcome rejected');
[$c] = $sup->req('POST', "/api/incidents/$incId/update", ['status' => 'resit_required', 'text' => 'Student must re-sit']); ok($c === 200, 'supervisor updates the case');
[$c, $j] = $sup->req('GET', "/api/sessions/$sid/incidents"); $ci = $j['incidents'][0] ?? [];
ok($ci['status'] === 'resit_required' && count($ci['entries']) === 2 && $ci['entries'][0]['status'] === 'open' && $ci['entries'][1]['status'] === 'resit_required', 'status history is kept as notes');
[$c] = $sup->req('POST', "/api/signatures/$brianId/review"); ok($c === 200, 'supervisor marks the identity check reviewed');
[$c, $j] = $sup->req('GET', "/api/sessions/$sid/incidents"); ok($j['reviews'][0]['review'] === 'reviewed' && $j['reviews'][0]['reviewed_by'] === 'Dr. Sample Supervisor', 'review recorded with the reviewer');

echo "Roll call and exits\n";
[$c, $j] = $inv->req('GET', "/api/sessions/$sid/rollcall"); $rc = array_column($j['units'] ?? [], null, 'code');
ok($c === 200 && count($j['units']) === 4 && $rc['BSD 4101']['registered'] === 3 && $rc['BSD 4101']['signed'] === 2, 'roll call lists every session paper with counts');
$pend = $rc['BSD 4101']['pending'] ?? [];
ok(count($pend) === 1 && $pend[0]['adm_no'] === '22/01004' && $pend[0]['state'] === 'not_cleared' && $pend[0]['reason'] === 'Fee balance not cleared', 'registered-but-uncleared student shows as not_cleared with the reason');
ok(count($rc['BSD 4103']['pending']) === 4 && $rc['BSD 4103']['pending'][0]['state'] === 'absent' && $rc['BSD 4103']['pending'][0]['reason'] === null, 'students yet to sign show as absent');
$amina = $rc['BSD 4101']['present'][0] ?? [];
ok($amina['adm_no'] === '22/01001' && $amina['exit_at'] === null, 'present list carries signature ids and exit state');
[$c, $j] = $inv->req('POST', "/api/signatures/{$amina['id']}/exit", ['note' => 'submitted early']); ok($c === 200 && !empty($j['exit_at']), 'exit recorded');
[$c, $j] = $inv->req('POST', "/api/signatures/{$amina['id']}/exit", []); ok($c === 409, 'a second exit is refused');
[$c, $j] = $inv->req('GET', "/api/sessions/$sid/rollcall"); ok($j['units'][0]['present'][0]['exit_note'] === 'submitted early', 'exit shows on the roll call');
[$c, $j] = $grace->req('GET', "/api/sessions/$sid/rollcall"); ok($c === 403, 'roll call needs access to the session');
[$c, $j] = $inv->req('GET', "/api/sessions/$sid/units"); $uu = array_column($j['units'], null, 'code');
ok($uu['BSD 4101']['registered'] === 3 && $uu['BSD 4101']['signed'] === 2 && $uu['BSD 4101']['absent'] === 1 && $uu['BSD 4101']['incidents'] === 1 && $uu['BSD 4103']['signed'] === 0 && $uu['BSD 4103']['absent'] === 4, 'unit report: registered / signed / absent / incidents per paper');

echo "Offline mode\n";
$qa = date('c', time() - 120);
[$c, $j] = $inv->req('GET', "/api/sessions/$sid/offline-pack"); ok($c === 409 && str_contains($j['error'], 'not enabled'), 'offline pack refused while offline mode is off');
[$c] = $inv->req('POST', "/api/sessions/$sid/offline", ['on' => true]); ok($c === 403, 'only the chief supervisor can switch offline mode on');
[$c] = $dir->req('POST', "/api/sessions/$sid/offline", ['on' => true]); ok($c === 403, 'the director cannot either');
$cyn = ['adm_no' => '22/01003', 'unit_code' => 'BIT 3201', 'booklet_no' => 'OFF-0001', 'signature' => sigPng(5), 'ident' => 'card_only', 'queued_at' => $qa];
[$c, $j] = $post($inv, $cyn); ok($c === 409 && str_contains($j['error'], 'Offline mode was not enabled'), 'a queued signature is refused when offline mode is off');
[$c, $j] = $sup->req('POST', "/api/sessions/$sid/offline", ['on' => true]); ok($c === 200 && $j['offline'] === true, 'chief supervisor switches offline mode on');
[$c, $j] = $inv->req('GET', "/api/sessions/$sid/offline-pack"); $pk = array_column($j['students'] ?? [], null, 'adm_no');
ok($c === 200 && $j['sid'] === $sid && isset($pk['22/01003']) && $pk['22/01004']['cleared'] === false && $pk['22/01005']['units'][1]['in_session'] === false && $pk['22/01001']['prefill']['phone_from_portal'] === true, 'offline pack carries the roster: photos, clearance, units, prefill');
[$c, $j] = $post($inv, $cyn); ok($c === 200, 'queued signature accepted once offline mode is on');
[$c, $j] = $post($inv, $cyn); ok($c === 200 && $j['edited'] === true, 'replaying the same queued item is just a correction (idempotent sync)');
[$c, $j] = $post($inv, ['adm_no' => '22/01005', 'unit_code' => 'BIT 3201', 'booklet_no' => 'BK-223', 'signature' => sigPng(4), 'ident' => 'card_only']); ok($c === 200, 'signature saved for 22/01005');
[$c, $j] = $post($inv, ['adm_no' => '22/01006', 'unit_code' => 'BSD 4102', 'booklet_no' => 'BK-323', 'signature' => sigPng(4), 'ident' => 'card_id']); ok($c === 422 && ($j['needs_phone'] ?? false), 'missing portal phone asks for one');
[$c, $j] = $post($inv, ['adm_no' => '22/01006', 'unit_code' => 'BSD 4102', 'booklet_no' => 'BK-323', 'phone' => '0700000333', 'signature' => sigPng(4), 'ident' => 'card_id']); ok($c === 200, 'signature saved for 22/01006 with typed phone');
$pdo = new PDO('sqlite:' . $tmp . '/esign.sqlite'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$row = $pdo->query("SELECT offline, queued_at, signed_at, ident FROM signatures WHERE adm_no='22/01003'")->fetch(PDO::FETCH_ASSOC);
ok($row && (int)$row['offline'] === 1 && $row['queued_at'] === gmdate('Y-m-d\TH:i:s\Z', strtotime($qa)) && $row['signed_at'] === $row['queued_at'], 'offline signature carries the offline flag, queued_at, and keeps its queued time');
$row = $pdo->query("SELECT offline, ident, review FROM signatures WHERE adm_no='22/01005'")->fetch(PDO::FETCH_ASSOC);
ok((int)$row['offline'] === 0 && $row['ident'] === 'card_only' && $row['review'] === null, 'online signature is not flagged offline, no review needed for card checks');
[$c, $j] = $sup->req('GET', '/api/sessions'); ok($j['sessions'][0]['signatures'] === 5 && $j['sessions'][0]['students'] === 5 && $j['sessions'][0]['offline'] === true, 'session progress counts update');

echo "Sheet PDF and verification\n";
[$c, $pdf, $rawpdf] = $inv->req('GET', "/api/sessions/$sid/sheet/" . rawurlencode('BSD 4101') . '.pdf', null, [], true); ok($c === 200 && str_starts_with($rawpdf, '%PDF-'), 'sealed sheet PDF downloads');
file_put_contents('/tmp/kca-sheet-sample.pdf', $rawpdf);
[$c, $j] = $inv->req('POST', '/api/verify', $rawpdf, ['Content-Type: application/pdf']); ok($j['valid'] === true && $j['entries'] === 2 && $j['kind'] === 'sheet', 'untouched sheet verifies by file', json_encode($j));
[$c, , $again] = $inv->req('GET', "/api/sessions/$sid/sheet/" . rawurlencode('BSD 4101') . '.pdf', null, [], true); ok($again === $rawpdf, 'an unchanged sheet is not re-sealed (same file, same code)');
[$c, $j] = $inv->req('POST', '/api/verify', $rawpdf . "\n% edited", ['Content-Type: application/pdf']); ok($j['valid'] === false, 'a PDF with even a trailing edit fails verification');
[$c, $j] = $inv->req('POST', '/api/verify', ['code' => 'KCA-AAAA-BBBB-CCCC']); ok($j['found'] === false, 'unknown code is not valid');
[$c, , $abs] = $inv->req('GET', "/api/sessions/$sid/sheet/" . rawurlencode('BSD 4103') . '.pdf', null, [], true); ok($c === 200 && str_starts_with($abs, '%PDF-'), 'a unit nobody has signed yet still gets a sheet listing the absent students');
[$c] = $inv->req('GET', "/api/sessions/$sid/sheet/" . rawurlencode('BIT 3202') . '.pdf', null, [], true); ok($c === 404, 'a paper that is not part of the session has no sheet');
$sh = $pdo->query("SELECT payload FROM documents WHERE kind='sheet' AND unit_code='BSD 4101' ORDER BY id DESC LIMIT 1")->fetchColumn();
$shp = json_decode((string)$sh, true) ?: [];
ok(count($shp['absent'] ?? []) === 1 && $shp['absent'][0]['state'] === 'not_cleared' && count($shp['incidents'] ?? []) === 1 && $shp['entries'][1]['ident'] === 'photo_only' && $shp['entries'][1]['review'] === 'reviewed' && !empty($shp['entries'][0]['exit_at']) && $shp['entries'][0]['version'] === 2 && !empty($shp['authority']['key_fingerprint']), 'sealed sheet records absent list, incident, ident review, exit, corrections and the authority block');
ok(strlen($rawpdf) > 5000, 'sheet PDF has content');

echo "Session pack\n";
[$c] = $inv->req('POST', "/api/sessions/$sid/pack"); ok($c === 403, 'invigilator cannot make the session pack');
[$c] = $inv->req('GET', "/api/sessions/$sid/pack.pdf", null, [], true); ok($c === 403, 'invigilator cannot download the session pack');
[$c, $j] = $sup->req('POST', "/api/sessions/$sid/pack"); ok($c === 200 && preg_match('/^KCA-/', $j['code']) === 1, 'supervisor seals the session pack');
$packCode = $j['code'] ?? '';
[$c, , $rawpack] = $sup->req('GET', "/api/sessions/$sid/pack.pdf", null, [], true); ok($c === 200 && str_starts_with($rawpack, '%PDF-'), 'session pack downloads as one PDF (cover + every unit sheet)');
file_put_contents('/tmp/kca-pack-sample.pdf', $rawpack);
[$c, $j] = $sup->req('POST', '/api/verify', $rawpack, ['Content-Type: application/pdf']); ok($j['valid'] === true && $j['kind'] === 'pack' && $j['entries'] === 5 && $j['code'] === $packCode, 'pack verifies by file', json_encode($j));
[$c, $j] = $inv->req('POST', '/api/verify', ['code' => $packCode]); ok($j['valid'] === true && $j['kind'] === 'pack' && $j['status'] === 'current', 'pack verifies by code');
$pp = json_decode((string)$pdo->query("SELECT payload FROM documents WHERE code='$packCode'")->fetchColumn(), true) ?: [];
$T = $pp['totals'] ?? [];
ok(($T['signed'] ?? 0) === 5 && $T['late'] === 0 && $T['exits'] === 1 && $T['offline'] === 1 && $T['photo_only'] === 1 && $T['review_pending'] === 0 && $T['incidents'] === 1 && $T['corrections'] === 2 && $pp['chain']['ok'] === true && count($pp['rows']) === 4, 'pack totals: signed, late, exits, offline, photo-only, review, incidents, corrections, audit chain', json_encode($T));

echo "A later session: late arrival and re-sit linking\n";
$mk2 = array_merge($mk, ['period' => 'Sem 2 2026 re-sits', 'venue' => 'Hall B', 'opens_at' => date('c', time() - 7200), 'closes_at' => date('c', time() + 3600), 'units' => ['BSD 4101']]);
[$c, $j] = $dir->req('POST', '/api/sessions', $mk2); $sid2 = $j['session']['id'] ?? 0; ok($c === 201 && $sid2 > $sid, 'director loads a second session');
$post2 = fn($cl, $b) => $cl->req('POST', "/api/sessions/$sid2/signatures", $b);
[$c, $j] = $post2($sup, ['adm_no' => '22/01001', 'unit_code' => 'BSD 4102', 'booklet_no' => 'BK-1', 'signature' => sigPng(), 'ident' => 'card_id']); ok($c === 422 && str_contains($j['error'], 'BSD 4101'), 'the paper must belong to the session being signed');
[$c, $j] = $post2($sup, ['adm_no' => '22/01002', 'unit_code' => 'BSD 4101', 'booklet_no' => 'BK-77', 'signature' => sigPng(3), 'ident' => 'card_id']); ok($c === 200, 're-sit signature saved (same booklet number is fine in a different session)');
$rs = $pdo->query("SELECT resit_of, signed_at FROM signatures WHERE session_id=$sid2")->fetch(PDO::FETCH_ASSOC);
ok($rs['resit_of'] === $caseNo, 'the re-sit sheet is linked to the earlier case', json_encode($rs));
[$c, $j] = $sup->req('GET', "/api/sessions/$sid/incidents"); $ci = $j['incidents'][0] ?? [];
ok($ci['resit_sid'] === $sid2 && count($ci['entries']) === 3 && $ci['entries'][2]['by'] === 'system', 'the earlier case records that a re-sit was issued');
[$c, $j] = $sup->req('POST', "/api/sessions/$sid2/close"); ok($c === 200 && $j['sealed'] === 1, 'closing the session seals its unit sheet');
$s2 = json_decode((string)$pdo->query("SELECT payload FROM documents WHERE kind='sheet' AND session_id=$sid2")->fetchColumn(), true) ?: [];
ok(($s2['entries'][0]['late'] ?? null) === true && $s2['entries'][0]['resit_of'] === $caseNo && $s2['late_after'] === 30, 'arrival 2 hours after opening is marked late; re-sit prior case is on the sealed sheet');

echo "Closing, reopening and director control\n";
[$c] = $inv->req('POST', "/api/sessions/$sid/close"); ok($c === 403, 'invigilator cannot close the session');
[$c, $j] = $sup->req('POST', "/api/sessions/$sid/close"); ok($c === 200 && $j['sealed'] === 4, 'supervisor closes the session; every unit sheet is sealed', json_encode($j));
$nsheets = (int)$pdo->query("SELECT COUNT(*) FROM documents WHERE kind='sheet' AND session_id=$sid")->fetchColumn(); ok($nsheets >= 4, 'sealed sheets are stored as records');
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
[$c, $j] = $sup->req('POST', "/api/sessions/$sid/signatures", array_merge($good, ['adm_no' => '22/01003', 'unit_code' => 'BIT 3201', 'booklet_no' => 'BK-NEW'])); ok($c === 409 || $c === 403, 'no new signatures after the session closed');

echo "Records, sealed originals and the director's seal\n";
[$c] = $inv->req('GET', '/api/records'); ok($c === 403, 'invigilators have no records page');
[$c] = $grace->req('GET', '/api/records'); ok($c === 403, 'a chief supervisor with no sessions has no records');
[$c, $j] = $dir->req('GET', '/api/records'); $recs = $j['records'] ?? [];
$kinds = array_count_values(array_column($recs, 'kind'));
ok($c === 200 && ($kinds['sheet'] ?? 0) >= 5 && ($kinds['receipt'] ?? 0) >= 6 && ($kinds['pack'] ?? 0) === 1, 'director sees receipts, sheets and the pack', json_encode($kinds));
$byCode = array_column($recs, null, 'code');
ok(($byCode[$code1]['status'] ?? '') === 'superseded' && $byCode[$code1]['student'] === '22/01001' && $byCode[$packCode]['status'] === 'current' && $byCode[$packCode]['kind'] === 'pack', 'record statuses: old receipt superseded, pack current');
[$c, $j] = $sup->req('GET', '/api/records'); ok($c === 200 && count($j['records']) === count($recs), 'chief supervisor sees the records of their sessions');
[$c, $pdfHdr, $rawdoc] = $dir->req('GET', "/api/documents/$packCode/pdf", null, [], true); ok($c === 200 && $rawdoc === $rawpack, 'stored sealed original opens by code (director)');
[$c, , $rawdoc2] = $sup->req('GET', "/api/documents/$code1.pdf", null, [], true); ok($c === 200 && str_starts_with($rawdoc2, '%PDF-'), 'a receipt opens by /documents/{code}.pdf (supervisor of the session)');
file_put_contents('/tmp/kca-receipt-sample.pdf', $rawdoc2);
[$c, $j] = $inv->req('POST', '/api/verify', $rawdoc2, ['Content-Type: application/pdf']); ok($j['valid'] === true && $j['kind'] === 'receipt' && $j['status'] === 'superseded', 'receipt PDF verifies by file');
[$c] = $grace->req('GET', "/api/documents/$packCode/pdf", null, [], true); ok($c === 403, 'other staff cannot open the stored original');
[$c] = $inv->req('GET', "/api/documents/$packCode/pdf", null, [], true); ok($c === 403, 'invigilators cannot open the pack');
[$c] = $dir->req('GET', '/api/documents/KCA-ZZZZ-ZZZZ-ZZZZ/pdf', null, [], true); ok($c === 404, 'unknown document code is 404');
[$c, $j] = $sup->req('GET', '/api/director/security'); ok($c === 403, 'seal-key custody log is director-only');
[$c, $j] = $dir->req('GET', '/api/director/security');
ok($c === 200 && preg_match('/^[0-9a-f]{64}$/', $j['fingerprint']) === 1 && $j['signature'] === null && $j['uses_total'] >= 12 && count($j['uses']) === 8 && $j['uses'][0]['purpose'] !== '' && count($j['custodians']) === 2 && $j['name'] === 'Exam Director', 'key fingerprint, custody log (every seal logged), no signature yet');
$usesBefore = $j['uses_total']; $fp = $j['fingerprint'];
[$c] = $sup->req('POST', '/api/director/signature', ['signature' => sigPng()]); ok($c === 403, 'only the director can upload the signature');
[$c] = $dir->req('POST', '/api/director/signature', ['signature' => 'data:text/html;base64,AAAA']); ok($c === 422, 'non-image signature upload refused');
[$c] = $dir->req('POST', '/api/director/signature', ['signature' => blankPng()]); ok($c === 422, 'blank signature image refused');
[$c] = $dir->req('POST', '/api/director/signature', ['signature' => sigPng(5)]); ok($c === 200, 'director uploads a signature');
[$c, $j] = $dir->req('GET', '/api/director/security'); ok(str_starts_with((string)$j['signature'], 'data:image/png;base64,') && $j['fingerprint'] === $fp, 'signature now returned; key fingerprint unchanged');
[$c, , $pack2] = $sup->req('GET', "/api/sessions/$sid/pack.pdf", null, [], true);
[$c, $j] = $sup->req('POST', '/api/verify', $pack2, ['Content-Type: application/pdf']); ok($j['valid'] === true && $j['code'] !== $packCode, 'a new director signature produces a new pack (the old one stays valid)');
[$c, $j] = $inv->req('POST', '/api/verify', ['code' => $packCode]); ok($j['valid'] === true && $j['status'] === 'superseded', 'the earlier pack is marked superseded');
file_put_contents('/tmp/kca-pack-sample2.pdf', $pack2);
$pp2 = json_decode((string)$pdo->query("SELECT payload FROM documents WHERE kind='pack' ORDER BY id DESC LIMIT 1")->fetchColumn(), true) ?: [];
ok(!empty($pp2['authority']['signature_sha256']) && $pp2['authority']['key_fingerprint'] === $fp, 'the seal records the director signature and key fingerprint');
[$c, $j] = $dir->req('GET', '/api/director/security'); ok($j['uses_total'] > $usesBefore, 'sealing the new pack was logged in the key custody log');
try { $pdo->exec('DELETE FROM key_uses'); ok(false, 'key custody log cannot be erased'); } catch (Throwable) { ok(true, 'key custody log cannot be erased (DB trigger)'); }
try { $pdo->exec('UPDATE incident_entries SET text=\'x\''); ok(false, 'incident notes cannot be edited'); } catch (Throwable) { ok(true, 'incident notes cannot be edited (DB trigger)'); }

echo "Audit trail\n";
[$c] = $inv->req('GET', '/api/audit/events'); ok($c === 403, 'audit timeline is director-only');
[$c, $j] = $dir->req('GET', '/api/audit/events'); ok($c === 200 && count($j['events']) === 14 && isset($j['events'][0]['label'], $j['events'][0]['kind'], $j['events'][0]['actor']) && $j['chain']['ok'] === true, 'audit timeline: 14 latest events with labels and chain status');
[$c, $j] = $dir->req('GET', '/api/audit/verify'); ok($j['ok'] === true && $j['entries'] > 10, 'audit chain intact (' . ($j['entries'] ?? 0) . ' entries)');
$acts = $pdo->query("SELECT DISTINCT action FROM audit_log")->fetchAll(PDO::FETCH_COLUMN);
$need = ['session_created', 'signature_saved', 'signature_saved_offline', 'signature_corrected', 'incident_flagged', 'incident_recorded', 'incident_updated', 'identity_reviewed', 'resit_linked', 'exit_recorded', 'search_locked', 'search_unlocked', 'offline_enabled', 'sheet_sealed', 'pack_sealed', 'pack_downloaded', 'record_viewed', 'session_closed', 'director_signature_updated', 'receipt_sent'];
ok(!array_diff($need, $acts), 'every new action is in the audit log', implode(',', array_diff($need, $acts)));
try { $pdo->exec("UPDATE audit_log SET actor='mallory' WHERE id=1"); ok(false, 'audit rows cannot be edited'); } catch (Throwable) { ok(true, 'audit rows cannot be edited (DB trigger)'); }
try { $pdo->exec('DELETE FROM documents'); ok(false, 'documents cannot be deleted'); } catch (Throwable) { ok(true, 'issued documents cannot be deleted (DB trigger)'); }
$pdo->exec('DROP TRIGGER audit_no_update'); $pdo->exec("UPDATE audit_log SET payload='{\"forged\":true}' WHERE id=3");
[$c, $j] = $dir->req('GET', '/api/audit/verify'); ok($j['ok'] === false && $j['broken_at'] === 3, 'even with DB access, a forged audit row is detected');
[$c, $j] = $dir->req('GET', '/api/audit/events'); ok($j['chain']['ok'] === false && $j['chain']['broken_at'] === 3, 'the timeline shows the broken chain');

echo "Timetable routes\n";
if (str_contains((string)file_get_contents($root . '/src/Timetable.php'), 'function index')) {
    [$c, $j] = $dir->req('GET', '/api/timetable'); ok($c === 200 && isset($j['imports']), 'GET /api/timetable delegates to Timetable');
    [$c] = $inv->req('GET', '/api/timetable'); ok($c === 403, 'invigilators cannot open the timetable');
} else {
    echo "  SKIP  timetable routes (src/Timetable.php is still a stub)\n";
}

$log = (string)@file_get_contents("$tmp/server.log");
ok(!preg_match('/Warning|Notice|Deprecated|Fatal error/', $log), 'no PHP warnings or notices in the server log', substr($log, 0, 300));
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
