<?php
declare(strict_types=1);
namespace Kca;

use Kca\Portal\PortalAdapter;

final class Api
{
    private const IDLE_SECONDS = 1800;
    private ?array $user = null;

    public function __construct(
        private \PDO $db, private PortalAdapter $portal, private Audit $audit, private Documents $docs, private Mailer $mailer
    ) {}

    // ------------------------------------------------------------------ router

    public function handle(string $method, string $path): void
    {
        try {
            $this->startSession();
            $routes = [
                ['GET',    '#^/api/config$#',                                  'config',          false],
                ['POST',   '#^/api/login$#',                                   'login',           false],
                ['POST',   '#^/api/verify$#',                                  'verify',          false],
                ['POST',   '#^/api/logout$#',                                  'logout',          true],
                ['GET',    '#^/api/me$#',                                      'me',              true],
                ['GET',    '#^/api/sessions$#',                                'listSessions',    true],
                ['POST',   '#^/api/sessions$#',                                'createSession',   true],
                ['POST',   '#^/api/sessions/(\d+)/close$#',                    'closeSession',    true],
                ['GET',    '#^/api/sessions/(\d+)/access$#',                   'listAccess',      true],
                ['POST',   '#^/api/sessions/(\d+)/access$#',                   'grantAccess',     true],
                ['DELETE', '#^/api/sessions/(\d+)/access/([^/]+)$#',           'revokeAccess',    true],
                ['GET',    '#^/api/sessions/(\d+)/students$#',                 'searchStudents',  true],
                ['GET',    '#^/api/sessions/(\d+)/students/([^/]+)/units$#',   'studentUnits',    true],
                ['GET',    '#^/api/sessions/(\d+)/signatures/([^/]+)/([^/]+)$#', 'getSignature',  true],
                ['POST',   '#^/api/sessions/(\d+)/validate-email$#',           'validateEmail',   true],
                ['POST',   '#^/api/sessions/(\d+)/signatures$#',               'saveSignature',   true],
                ['GET',    '#^/api/sessions/(\d+)/units$#',                    'sessionUnits',    true],
                ['GET',    '#^/api/sessions/(\d+)/sheet/([^/]+)\.pdf$#',       'sheetPdf',        true],
                ['POST',   '#^/api/signatures/(\d+)/resend$#',                 'resend',          true],
                ['POST',   '#^/api/signatures/(\d+)/reopen-request$#',         'reopenRequest',   true],
                ['GET',    '#^/api/reopen-requests$#',                         'listReopen',      true],
                ['POST',   '#^/api/reopen-requests/(\d+)/(approve|reject)$#',  'resolveReopen',   true],
                ['GET',    '#^/api/audit/verify$#',                            'auditVerify',     true],
            ];
            foreach ($routes as [$m, $re, $fn, $auth]) {
                if ($m !== $method || !preg_match($re, $path, $mm)) continue;
                if ($auth) $this->requireUser($method !== 'GET');
                array_shift($mm);
                $this->$fn(...array_map('rawurldecode', $mm));
                return;
            }
            throw new HttpError(404, 'Not found');
        } catch (HttpError $e) {
            $this->json($e->status, ['error' => $e->getMessage()] + $e->extra);
        } catch (\Throwable $e) {
            error_log('[esign] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $this->json(500, ['error' => 'Something went wrong on the server. Nothing was lost; try again.']);
        }
    }

    // ---------------------------------------------------------------- plumbing

    private function startSession(): void
    {
        session_name('KCAESIGN');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')]);
        session_start();
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
        if (isset($_SESSION['user'], $_SESSION['last']) && time() - $_SESSION['last'] > self::IDLE_SECONDS) {
            $_SESSION = ['csrf' => bin2hex(random_bytes(24))];
        }
        $_SESSION['last'] = time();
        $this->user = $_SESSION['user'] ?? null;
    }

    private function requireUser(bool $write): void
    {
        if (!$this->user) throw new HttpError(401, 'Please sign in again.');
        if ($write && !hash_equals($_SESSION['csrf'], (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) throw new HttpError(403, 'Security check failed. Reload the page.');
    }

    private function json(int $status, array $data): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function body(): array
    {
        $raw = file_get_contents('php://input', false, null, 0, 1_500_000);
        if ($raw === '' || $raw === false) return [];
        $j = json_decode($raw, true);
        if (!is_array($j)) throw new HttpError(400, 'Invalid request body.');
        return $j;
    }

    private function str(array $b, string $k, int $max = 200): string
    {
        $v = $b[$k] ?? '';
        if (!is_string($v)) throw new HttpError(422, "Invalid $k.");
        $v = trim(preg_replace('/\p{C}+/u', ' ', $v) ?? '');
        if (mb_strlen($v) > $max) throw new HttpError(422, "$k is too long.");
        return $v;
    }

    /** @return array{0:array,1:string,2:bool} session row, access level (director|supervisor|invigilator), currently open for signing */
    private function access(int|string $sid): array
    {
        $st = $this->db->prepare('SELECT * FROM exam_sessions WHERE id=?');
        $st->execute([(int)$sid]);
        $s = $st->fetch();
        if (!$s) throw new HttpError(404, 'Exam session not found.');
        $now = Util::now();
        $active = $s['status'] === 'active';
        $open = $active && $s['opens_at'] <= $now && $now <= $s['closes_at'];
        $role = $this->user['role'];
        if ($role === 'exam_director') return [$s, 'director', $open];
        if ($s['supervisor_email'] === $this->user['email']) return [$s, 'supervisor', $open];
        $g = $this->db->prepare('SELECT 1 FROM session_access WHERE session_id=? AND staff_email=? AND revoked_at IS NULL');
        $g->execute([$s['id'], $this->user['email']]);
        if ($g->fetchColumn() && $active && $now <= $s['closes_at']) return [$s, 'invigilator', $open];
        throw new HttpError(403, 'You do not have access to this exam session.');
    }

    private function needLevel(string $level, array $allowed): void
    {
        if (!in_array($level, $allowed, true)) throw new HttpError(403, 'Your role cannot do that.');
    }

    private function actor(): string { return $this->user['email']; }

    private function sessionOut(array $s, string $level, bool $open): array
    {
        $c = $this->db->prepare('SELECT COUNT(*) FROM signatures WHERE session_id=?');
        $c->execute([$s['id']]);
        $stu = $this->db->prepare('SELECT COUNT(DISTINCT adm_no) FROM signatures WHERE session_id=?');
        $stu->execute([$s['id']]);
        return [
            'id' => (int)$s['id'], 'code' => $s['code'], 'period' => $s['period'], 'venue' => $s['venue'], 'opens_at' => $s['opens_at'],
            'closes_at' => $s['closes_at'], 'status' => $s['status'], 'supervisor' => $s['supervisor_name'], 'level' => $level, 'open' => $open,
            'signatures' => (int)$c->fetchColumn(), 'students' => (int)$stu->fetchColumn(),
        ];
    }

    // --------------------------------------------------------------- public

    private function config(): void
    {
        $this->json(200, ['institution' => 'KCA University', 'dev' => !Config::isProd(), 'portal' => Config::get('PORTAL_DRIVER', 'mock'),
            'mail_mode' => Config::get('MAIL_MODE', 'outbox'), 'csrf' => $_SESSION['csrf'], 'user' => $this->user]);
    }

    private function login(): void
    {
        $b = $this->body();
        $email = strtolower($this->str($b, 'email', 120));
        $pw = (string)($b['password'] ?? '');
        $key = $email . '|' . ($_SERVER['REMOTE_ADDR'] ?? '');
        $this->db->prepare('DELETE FROM login_attempts WHERE ts < ?')->execute([time() - 900]);
        $n = $this->db->prepare('SELECT COUNT(*) FROM login_attempts WHERE k=?');
        $n->execute([$key]);
        if ((int)$n->fetchColumn() >= 5) throw new HttpError(429, 'Too many failed attempts. Wait 15 minutes and try again.');
        $u = $email !== '' && $pw !== '' ? $this->portal->authenticateStaff($email, $pw) : null;
        if (!$u) {
            $this->db->prepare('INSERT INTO login_attempts(k,ts) VALUES(?,?)')->execute([$key, time()]);
            $this->audit->log($email ?: 'unknown', 'login_failed', null, []);
            throw new HttpError(401, 'Incorrect email or password.');
        }
        session_regenerate_id(true);
        $_SESSION['user'] = $u;
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
        $_SESSION['last'] = time();
        $this->user = $u;
        $this->audit->log($u['email'], 'login', null, ['role' => $u['role']]);
        $this->json(200, ['user' => $u, 'csrf' => $_SESSION['csrf']]);
    }

    private function logout(): void
    {
        $this->audit->log($this->actor(), 'logout', null, []);
        $_SESSION = [];
        session_destroy();
        $this->json(200, ['ok' => true]);
    }

    private function me(): void { $this->json(200, ['user' => $this->user, 'csrf' => $_SESSION['csrf']]); }

    private function verify(): void
    {
        $ct = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_starts_with($ct, 'application/pdf') || str_starts_with($ct, 'application/octet-stream')) {
            $bytes = file_get_contents('php://input', false, null, 0, 8_000_000) ?: '';
            $this->json(200, $this->docs->verifyFile($bytes));
            return;
        }
        $code = $this->str($this->body(), 'code', 40);
        if (!preg_match('/^KCA-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/i', $code)) throw new HttpError(422, 'Codes look like KCA-ABCD-EFGH-JKLM.');
        $this->json(200, $this->docs->verifyCode($code));
    }

    // -------------------------------------------------------------- sessions

    private function listSessions(): void
    {
        $role = $this->user['role'];
        if ($role === 'exam_director') {
            $rows = $this->db->query('SELECT * FROM exam_sessions ORDER BY id DESC LIMIT 100')->fetchAll();
        } else {
            $st = $this->db->prepare('SELECT s.* FROM exam_sessions s WHERE s.supervisor_email=? OR s.id IN (SELECT session_id FROM session_access WHERE staff_email=? AND revoked_at IS NULL) ORDER BY s.id DESC LIMIT 100');
            $st->execute([$this->actor(), $this->actor()]);
            $rows = $st->fetchAll();
        }
        $out = [];
        foreach ($rows as $s) {
            try { [$row, $lvl, $open] = $this->access($s['id']); $out[] = $this->sessionOut($row, $lvl, $open); } catch (HttpError) {}
        }
        $this->json(200, ['sessions' => $out]);
    }

    private function createSession(): void
    {
        if ($this->user['role'] !== 'supervisor') throw new HttpError(403, 'Only a chief supervisor can load an exam session.');
        $b = $this->body();
        $period = $this->str($b, 'period', 80); $venue = $this->str($b, 'venue', 80);
        $o = Util::toUtc($this->str($b, 'opens_at', 40)); $c = Util::toUtc($this->str($b, 'closes_at', 40));
        if ($period === '' || $venue === '' || !$o || !$c) throw new HttpError(422, 'Exam period, venue, opening and closing time are required.');
        if ($c <= $o) throw new HttpError(422, 'Closing time must be after opening time.');
        if ((strtotime($c) - strtotime($o)) > 86400) throw new HttpError(422, 'An exam session cannot last longer than 24 hours.');
        $code = 'S' . gmdate('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
        $this->db->prepare('INSERT INTO exam_sessions(code,period,venue,opens_at,closes_at,supervisor_email,supervisor_name,status,created_at) VALUES(?,?,?,?,?,?,?,?,?)')
            ->execute([$code, $period, $venue, $o, $c, $this->actor(), $this->user['name'], 'active', Util::now()]);
        $id = (int)$this->db->lastInsertId();
        $this->audit->log($this->actor(), 'session_created', $id, compact('code', 'period', 'venue', 'o', 'c'));
        [$s, $l, $op] = $this->access($id);
        $this->json(201, ['session' => $this->sessionOut($s, $l, $op)]);
    }

    private function closeSession(string $sid): void
    {
        [$s, $lvl] = $this->access($sid);
        $this->needLevel($lvl, ['supervisor', 'director']);
        $now = Util::now();
        $this->db->prepare("UPDATE exam_sessions SET status='closed', closes_at=MIN(closes_at, ?) WHERE id=?")->execute([$now, $s['id']]);
        $this->audit->log($this->actor(), 'session_closed', (int)$s['id'], []);
        $this->json(200, ['ok' => true]);
    }

    private function listAccess(string $sid): void
    {
        [$s, $lvl] = $this->access($sid);
        $this->needLevel($lvl, ['supervisor', 'director']);
        $st = $this->db->prepare('SELECT staff_email AS email, staff_name AS name, granted_by, granted_at, revoked_at FROM session_access WHERE session_id=? ORDER BY id');
        $st->execute([$s['id']]);
        $this->json(200, ['access' => $st->fetchAll()]);
    }

    private function grantAccess(string $sid): void
    {
        [$s, $lvl] = $this->access($sid);
        $this->needLevel($lvl, ['supervisor']);
        if ($s['status'] !== 'active') throw new HttpError(409, 'This session is closed.');
        $email = strtolower($this->str($this->body(), 'email', 120));
        $staff = $this->portal->findStaff($email);
        if (!$staff || !in_array($staff['role'], ['invigilator', 'supervisor'], true)) throw new HttpError(404, 'No invigilator account with that email on the portal.');
        if ($staff['email'] === $s['supervisor_email']) throw new HttpError(409, 'You already have full access.');
        $this->db->prepare('INSERT INTO session_access(session_id,staff_email,staff_name,granted_by,granted_at) VALUES(?,?,?,?,?)
            ON CONFLICT(session_id,staff_email) DO UPDATE SET revoked_at=NULL, granted_by=excluded.granted_by, granted_at=excluded.granted_at')
            ->execute([$s['id'], $staff['email'], $staff['name'], $this->actor(), Util::now()]);
        $this->audit->log($this->actor(), 'access_granted', (int)$s['id'], ['to' => $staff['email']]);
        $this->json(200, ['ok' => true, 'name' => $staff['name']]);
    }

    private function revokeAccess(string $sid, string $email): void
    {
        [$s, $lvl] = $this->access($sid);
        $this->needLevel($lvl, ['supervisor']);
        $this->db->prepare('UPDATE session_access SET revoked_at=? WHERE session_id=? AND staff_email=? AND revoked_at IS NULL')->execute([Util::now(), $s['id'], strtolower($email)]);
        $this->audit->log($this->actor(), 'access_revoked', (int)$s['id'], ['from' => strtolower($email)]);
        $this->json(200, ['ok' => true]);
    }

    // -------------------------------------------------------------- students

    private function searchStudents(string $sid): void
    {
        [$s] = $this->access($sid);
        $q = trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) { $this->json(200, ['students' => []]); return; }
        $res = $this->portal->searchStudents(mb_substr($q, 0, 60), 8);
        $this->json(200, ['students' => array_map(fn($r) => ['adm_no' => $r['adm_no'], 'full_name' => $r['full_name'], 'programme' => $r['programme']], $res)]);
    }

    private function studentUnits(string $sid, string $adm): void
    {
        [$s] = $this->access($sid);
        $stu = $this->portal->getStudent($adm);
        if (!$stu) throw new HttpError(404, 'Student not found on the portal.');
        if (!$stu['cleared']) {
            $this->json(200, ['student' => $this->pubStudent($stu), 'cleared' => false, 'reason' => $stu['reason'] ?: 'Not cleared to sit exams', 'units' => []]);
            return;
        }
        $units = $this->portal->clearedUnits($stu['adm_no'], $s['period']);
        $q = $this->db->prepare('SELECT id, unit_code, signed_at, updated_at, version, email_status, receipt_code, reopened_until FROM signatures WHERE session_id=? AND adm_no=?');
        $q->execute([$s['id'], $stu['adm_no']]);
        $signed = [];
        foreach ($q->fetchAll() as $r) $signed[$r['unit_code']] = $r;
        $now = Util::now();
        $out = [];
        foreach ($units as $u) {
            $r = $signed[$u['code']] ?? null;
            $out[] = ['code' => $u['code'], 'name' => $u['name'], 'signed' => (bool)$r, 'signature_id' => $r ? (int)$r['id'] : null,
                'signed_at' => $r['signed_at'] ?? null, 'version' => $r ? (int)$r['version'] : null, 'email_status' => $r['email_status'] ?? null,
                'can_edit' => $r ? $this->canEdit($s, $r, $now) : false];
        }
        $this->json(200, ['student' => $this->pubStudent($stu), 'cleared' => true, 'units' => $out]);
    }

    private function pubStudent(array $s): array { return ['adm_no' => $s['adm_no'], 'full_name' => $s['full_name'], 'programme' => $s['programme']]; }

    private function canEdit(array $s, array $sig, string $now): bool
    {
        if ($s['status'] === 'active' && $now <= $s['closes_at']) return true;
        return !empty($sig['reopened_until']) && $sig['reopened_until'] > $now;
    }

    private function getSignature(string $sid, string $adm, string $unit): void
    {
        [$s] = $this->access($sid);
        $st = $this->db->prepare('SELECT id, adm_no, unit_code, unit_name, full_name, phone, booklet_no, email, signature_png, version, email_status, reopened_until FROM signatures WHERE session_id=? AND adm_no=? AND unit_code=?');
        $st->execute([$s['id'], $adm, $unit]);
        $r = $st->fetch();
        if (!$r) throw new HttpError(404, 'Not signed yet.');
        $r['signature'] = 'data:image/png;base64,' . base64_encode($r['signature_png']);
        unset($r['signature_png']);
        $r['can_edit'] = $this->canEdit($s, $r, Util::now());
        $this->json(200, ['signature' => $r]);
    }

    private function validateEmail(string $sid): void
    {
        $this->access($sid);
        $b = $this->body();
        $stu = $this->portal->getStudent($this->str($b, 'adm_no', 30));
        $this->json(200, EmailPolicy::check($this->str($b, 'email', 254), $stu['school_email'] ?? null));
    }

    // ------------------------------------------------------------- signatures

    private function saveSignature(string $sid): void
    {
        [$s, $lvl, $open] = $this->access($sid);
        $this->needLevel($lvl, ['supervisor', 'invigilator', 'director']);
        $b = $this->body();
        $adm = $this->str($b, 'adm_no', 30);
        $unitCode = $this->str($b, 'unit_code', 30);

        $stu = $this->portal->getStudent($adm);
        if (!$stu) throw new HttpError(404, 'Student not found on the portal.');
        if (!$stu['cleared']) throw new HttpError(422, 'This student is not cleared to sit exams: ' . ($stu['reason'] ?: 'see exam director') . '.');
        $unit = null;
        foreach ($this->portal->clearedUnits($stu['adm_no'], $s['period']) as $u) if ($u['code'] === $unitCode) $unit = $u;
        if (!$unit) throw new HttpError(422, 'The student is not cleared to sit that unit this semester.');

        $phone = EmailPolicy::phone($this->str($b, 'phone', 30));
        if (!$phone) throw new HttpError(422, 'Enter a valid Kenyan phone number, e.g. 0712 345 678.');
        $booklet = strtoupper($this->str($b, 'booklet_no', 30));
        if (!preg_match('/^[A-Z0-9][A-Z0-9\-\/ ]{2,19}$/', $booklet)) throw new HttpError(422, 'Enter the booklet serial number exactly as printed (3-20 letters/digits).');
        $em = EmailPolicy::check($this->str($b, 'email', 254), $stu['school_email']);
        if (!$em['ok']) throw new HttpError(422, 'That email cannot receive the sign sheet copy. Use your own KCA school email.', ['checks' => $em['checks']]);
        $png = $this->decodeSignature((string)($b['signature'] ?? ''));

        $now = Util::now();
        $ex = $this->db->prepare('SELECT * FROM signatures WHERE session_id=? AND adm_no=? AND unit_code=?');
        $ex->execute([$s['id'], $stu['adm_no'], $unitCode]);
        $old = $ex->fetch();

        $this->db->exec('BEGIN IMMEDIATE');
        try {
            if ($old) {
                if (!$this->canEdit($s, $old, $now)) throw new HttpError(403, 'The exam period for this unit has closed. Ask the exam director to reopen your form.', ['needs_reopen' => true, 'signature_id' => (int)$old['id']]);
                $snap = $old; $snap['signature_png'] = base64_encode($old['signature_png']);
                $this->db->prepare('INSERT INTO signature_history(signature_id,version,snapshot,changed_by,changed_at) VALUES(?,?,?,?,?)')
                    ->execute([$old['id'], $old['version'], json_encode($snap), $this->actor(), $now]);
                $this->db->prepare('UPDATE signatures SET phone=?,booklet_no=?,email=?,signature_png=?,updated_at=?,version=version+1,captured_by=?,email_status=\'pending\',reopened_until=NULL WHERE id=?')
                    ->execute([$phone, $booklet, $em['email'], $png, $now, $this->actor(), $old['id']]);
                $sigId = (int)$old['id'];
            } else {
                if (!$open) throw new HttpError(409, 'This exam session is not open for signing right now.');
                $this->db->prepare('INSERT INTO signatures(session_id,adm_no,unit_code,unit_name,full_name,phone,booklet_no,email,signature_png,signed_at,updated_at,version,captured_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,1,?)')
                    ->execute([$s['id'], $stu['adm_no'], $unitCode, $unit['name'], $stu['full_name'], $phone, $booklet, $em['email'], $png, $now, $now, $this->actor()]);
                $sigId = (int)$this->db->lastInsertId();
            }
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
        $this->audit->log($this->actor(), $old ? 'signature_corrected' : 'signature_saved', (int)$s['id'], [
            'adm_no' => $stu['adm_no'], 'unit' => $unitCode, 'signature_sha256' => hash('sha256', $png), 'booklet' => $booklet,
            'version' => $old ? (int)$old['version'] + 1 : 1,
        ]);

        $mail = $this->deliver($sigId);
        $this->json(200, ['ok' => true, 'edited' => (bool)$old, 'email' => $mail]);
    }

    private function decodeSignature(string $dataUrl): string
    {
        if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) throw new HttpError(422, 'Please sign in the signature box.');
        $png = base64_decode($m[1], true);
        if ($png === false || strlen($png) > 400_000) throw new HttpError(422, 'Signature image is invalid or too large.');
        $info = @getimagesizefromstring($png);
        if (!$info || $info[2] !== IMAGETYPE_PNG || $info[0] > 1600 || $info[1] > 800 || $info[0] < 100 || $info[1] < 40) throw new HttpError(422, 'Signature image is invalid.');
        $im = imagecreatefromstring($png);
        if (!$im) throw new HttpError(422, 'Signature image is invalid.');
        $small = imagescale($im, 120, 40) ?: $im;
        $ink = 0;
        for ($y = 0; $y < imagesy($small); $y++) for ($x = 0; $x < imagesx($small); $x++) if (((imagecolorat($small, $x, $y) >> 24) & 0x7F) < 100) $ink++;
        if ($ink < 25) throw new HttpError(422, 'The signature looks empty. Please sign in the box.');
        // Re-encode: strips any non-image payload hidden in PNG chunks
        imagesavealpha($im, true);
        ob_start(); imagepng($im, null, 9); $clean = (string)ob_get_clean();
        return $clean;
    }

    /** Build the receipt, email it, record the result. Never throws: the signature is already safely stored. */
    private function deliver(int $sigId): array
    {
        $st = $this->db->prepare('SELECT * FROM signatures WHERE id=?');
        $st->execute([$sigId]);
        $sig = $st->fetch();
        $ss = $this->db->prepare('SELECT * FROM exam_sessions WHERE id=?');
        $ss->execute([$sig['session_id']]);
        $session = $ss->fetch();
        try {
            $doc = $this->docs->receipt($sig, $session);
            $url = Config::get('VERIFY_BASE_URL', 'http://localhost:8080') . '/#/verify';
            $text = "Dear {$sig['full_name']},\n\nThis is your copy of the electronic exam sign sheet entry for {$sig['unit_code']} {$sig['unit_name']} "
                . "({$session['period']}, {$session['venue']}), signed on " . Util::eat($sig['signed_at'], 'd M Y \a\t H:i') . " EAT.\n\n"
                . ($sig['version'] > 1 ? "This is a CORRECTED copy (version {$sig['version']}). It replaces earlier copies.\n\n" : '')
                . "Please keep this email. If the school's record is ever lost or disputed, the attached PDF is your proof of attendance.\n\n"
                . "Verification code: {$doc['code']}\nVerify it at: $url\n\n"
                . "If you did not sit this exam or the details are wrong, tell the exam director immediately.\n\nKCA University\nThis is an automated message; do not reply.\n";
            $res = $this->mailer->send($sig['email'], 'KCA exam attendance copy: ' . $sig['unit_code'] . ' (' . $sig['adm_no'] . ')', $text,
                ['name' => 'KCA-attendance-' . preg_replace('/\W+/', '', $sig['unit_code']) . '-' . $doc['code'] . '.pdf', 'data' => $doc['bytes']]);
            $this->db->prepare('UPDATE signatures SET email_status=?, email_sent_at=?, receipt_code=? WHERE id=?')
                ->execute([$res['status'], in_array($res['status'], ['sent', 'outbox'], true) ? Util::now() : null, $doc['code'], $sigId]);
            $this->audit->log('system', 'receipt_' . $res['status'], (int)$sig['session_id'], ['signature_id' => $sigId, 'doc' => $doc['code'], 'to_domain' => substr(strrchr($sig['email'], '@'), 1)]);
            return ['status' => $res['status'], 'code' => $doc['code'], 'to' => $sig['email']];
        } catch (\Throwable $e) {
            error_log('[esign] deliver failed: ' . $e->getMessage());
            $this->db->prepare("UPDATE signatures SET email_status='failed' WHERE id=?")->execute([$sigId]);
            return ['status' => 'failed', 'code' => null, 'to' => $sig['email']];
        }
    }

    private function resend(string $id): void
    {
        $st = $this->db->prepare('SELECT session_id FROM signatures WHERE id=?');
        $st->execute([(int)$id]);
        $sid = $st->fetchColumn();
        if ($sid === false) throw new HttpError(404, 'Not found.');
        [, $lvl] = $this->access($sid);
        $this->needLevel($lvl, ['supervisor', 'invigilator', 'director']);
        $this->json(200, ['email' => $this->deliver((int)$id)]);
    }

    // ---------------------------------------------------------------- reports

    private function sessionUnits(string $sid): void
    {
        [$s] = $this->access($sid);
        $st = $this->db->prepare("SELECT unit_code AS code, unit_name AS name, COUNT(*) AS signed, SUM(email_status NOT IN ('sent','outbox')) AS email_problems FROM signatures WHERE session_id=? GROUP BY unit_code, unit_name ORDER BY unit_code");
        $st->execute([$s['id']]);
        $this->json(200, ['units' => $st->fetchAll()]);
    }

    private function sheetPdf(string $sid, string $unit): void
    {
        [$s, $lvl] = $this->access($sid);
        $this->needLevel($lvl, ['supervisor', 'invigilator', 'director']);
        $r = $this->docs->sheet($s, $unit);
        $this->audit->log($this->actor(), 'sheet_downloaded', (int)$s['id'], ['unit' => $unit, 'doc' => $r['code'], 'reused' => $r['reused']]);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_-]/', '_', "signsheet-$unit-{$r['code']}") . '.pdf"');
        header('Cache-Control: no-store');
        header('X-Document-Code: ' . $r['code']);
        echo $r['bytes'];
    }

    // ----------------------------------------------------------- reopen flow

    private function reopenRequest(string $id): void
    {
        $st = $this->db->prepare('SELECT * FROM signatures WHERE id=?');
        $st->execute([(int)$id]);
        $sig = $st->fetch();
        if (!$sig) throw new HttpError(404, 'Not found.');
        [, $lvl] = $this->access($sig['session_id']);
        $this->needLevel($lvl, ['supervisor', 'invigilator']);
        $reason = $this->str($this->body(), 'reason', 300);
        if (mb_strlen($reason) < 10) throw new HttpError(422, 'Explain what needs correcting (at least 10 characters).');
        $p = $this->db->prepare("SELECT 1 FROM reopen_requests WHERE signature_id=? AND status='pending'");
        $p->execute([$sig['id']]);
        if ($p->fetchColumn()) throw new HttpError(409, 'A request for this form is already waiting for the exam director.');
        $this->db->prepare('INSERT INTO reopen_requests(signature_id,requested_by,reason,requested_at) VALUES(?,?,?,?)')->execute([$sig['id'], $this->actor(), $reason, Util::now()]);
        $this->audit->log($this->actor(), 'reopen_requested', (int)$sig['session_id'], ['signature_id' => (int)$sig['id'], 'reason' => $reason]);
        $this->json(201, ['ok' => true]);
    }

    private function listReopen(): void
    {
        if ($this->user['role'] !== 'exam_director') throw new HttpError(403, 'Only the exam director can see these.');
        $rows = $this->db->query("SELECT r.id, r.reason, r.status, r.requested_by, r.requested_at, s.full_name, s.adm_no, s.unit_code, s.unit_name, s.version, e.venue, e.period
            FROM reopen_requests r JOIN signatures s ON s.id=r.signature_id JOIN exam_sessions e ON e.id=s.session_id
            ORDER BY (r.status='pending') DESC, r.id DESC LIMIT 100")->fetchAll();
        $this->json(200, ['requests' => $rows]);
    }

    private function resolveReopen(string $id, string $action): void
    {
        if ($this->user['role'] !== 'exam_director') throw new HttpError(403, 'Only the exam director can do that.');
        $st = $this->db->prepare("SELECT r.*, s.session_id FROM reopen_requests r JOIN signatures s ON s.id=r.signature_id WHERE r.id=? AND r.status='pending'");
        $st->execute([(int)$id]);
        $r = $st->fetch();
        if (!$r) throw new HttpError(404, 'No pending request with that number.');
        $now = Util::now();
        $this->db->prepare('UPDATE reopen_requests SET status=?, resolved_by=?, resolved_at=? WHERE id=?')->execute([$action === 'approve' ? 'approved' : 'rejected', $this->actor(), $now, $r['id']]);
        $mins = 0;
        if ($action === 'approve') {
            $mins = max(5, min(240, (int)($this->body()['minutes'] ?? 30)));
            $until = gmdate('Y-m-d\TH:i:s\Z', time() + $mins * 60);
            $this->db->prepare('UPDATE signatures SET reopened_until=? WHERE id=?')->execute([$until, $r['signature_id']]);
        }
        $this->audit->log($this->actor(), 'reopen_' . $action . 'd', (int)$r['session_id'], ['request' => (int)$r['id'], 'signature_id' => (int)$r['signature_id'], 'minutes' => $mins]);
        $this->json(200, ['ok' => true, 'minutes' => $mins]);
    }

    private function auditVerify(): void
    {
        if ($this->user['role'] !== 'exam_director') throw new HttpError(403, 'Only the exam director can run this check.');
        $this->json(200, $this->audit->verify());
    }
}
