<?php
declare(strict_types=1);
namespace Kca;

use Kca\Portal\PortalAdapter;

final class Api
{
    private const IDLE_SECONDS = 1800;
    private ?array $user = null;
    private ?Timetable $tt = null;
    private ?array $unitNameCache = null;

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
                // timetable (src/Timetable.php)
                ['GET',    '#^/api/timetable$#',                               'ttIndex',         true],
                ['POST',   '#^/api/timetable/preview$#',                       'ttPreview',       true],
                ['GET',    '#^/api/timetable/(\d+)$#',                         'ttShow',          true],
                ['POST',   '#^/api/timetable/(\d+)/assign$#',                  'ttAssign',        true],
                ['POST',   '#^/api/timetable/(\d+)/submit$#',                  'ttSubmit',        true],
                ['POST',   '#^/api/timetable/(\d+)/approve$#',                 'ttApprove',       true],
                ['POST',   '#^/api/timetable/(\d+)/reject$#',                  'ttReject',        true],
                ['GET',    '#^/api/portal/units$#',                            'portalUnits',     true],
                // sessions
                ['GET',    '#^/api/sessions$#',                                'listSessions',    true],
                ['POST',   '#^/api/sessions$#',                                'createSession',   true],
                ['POST',   '#^/api/sessions/(\d+)/close$#',                    'closeSession',    true],
                ['GET',    '#^/api/sessions/(\d+)/access$#',                   'listAccess',      true],
                ['POST',   '#^/api/sessions/(\d+)/access$#',                   'grantAccess',     true],
                ['DELETE', '#^/api/sessions/(\d+)/access/([^/]+)$#',           'revokeAccess',    true],
                ['GET',    '#^/api/sessions/(\d+)/students$#',                 'searchStudents',  true],
                ['POST',   '#^/api/sessions/(\d+)/lookups/reset$#',            'resetLookups',    true],
                ['GET',    '#^/api/sessions/(\d+)/students/([^/]+)/units$#',   'studentUnits',    true],
                ['GET',    '#^/api/sessions/(\d+)/signatures/([^/]+)/([^/]+)$#', 'getSignature',  true],
                ['POST',   '#^/api/sessions/(\d+)/validate-email$#',           'validateEmail',   true],
                ['POST',   '#^/api/sessions/(\d+)/signatures$#',               'saveSignature',   true],
                ['GET',    '#^/api/sessions/(\d+)/units$#',                    'sessionUnits',    true],
                ['GET',    '#^/api/sessions/(\d+)/rollcall$#',                 'rollcall',        true],
                ['POST',   '#^/api/sessions/(\d+)/offline$#',                  'setOffline',      true],
                ['GET',    '#^/api/sessions/(\d+)/offline-pack$#',             'offlinePack',     true],
                ['GET',    '#^/api/sessions/(\d+)/incidents$#',                'listIncidents',   true],
                ['POST',   '#^/api/sessions/(\d+)/incidents$#',                'createIncident',  true],
                ['POST',   '#^/api/sessions/(\d+)/flags$#',                    'createFlag',      true],
                ['GET',    '#^/api/sessions/(\d+)/sheet/([^/]+)\.pdf$#',       'sheetPdf',        true],
                ['POST',   '#^/api/sessions/(\d+)/pack$#',                     'makePack',        true],
                ['GET',    '#^/api/sessions/(\d+)/pack\.pdf$#',                'packPdf',         true],
                // signatures, incidents, reopen requests
                ['POST',   '#^/api/signatures/(\d+)/resend$#',                 'resend',          true],
                ['POST',   '#^/api/signatures/(\d+)/exit$#',                   'recordExit',      true],
                ['POST',   '#^/api/signatures/(\d+)/review$#',                 'reviewIdent',     true],
                ['POST',   '#^/api/signatures/(\d+)/reopen-request$#',         'reopenRequest',   true],
                ['POST',   '#^/api/incidents/(\d+)/update$#',                  'updateIncident',  true],
                ['GET',    '#^/api/reopen-requests$#',                         'listReopen',      true],
                ['POST',   '#^/api/reopen-requests/(\d+)/(approve|reject)$#',  'resolveReopen',   true],
                // records and director
                ['GET',    '#^/api/records$#',                                 'records',         true],
                ['GET',    '#^/api/documents/([A-Za-z0-9-]+)(?:/pdf|\.pdf)$#', 'documentPdf',     true],
                ['GET',    '#^/api/director/security$#',                       'directorSecurity', true],
                ['POST',   '#^/api/director/signature$#',                      'directorSignature', true],
                ['GET',    '#^/api/audit/events$#',                            'auditEvents',     true],
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


    private function unitNames(): array
    {
        if ($this->unitNameCache === null) {
            $this->unitNameCache = [];
            foreach ($this->portal->listUnits() as $u) $this->unitNameCache[$u['code']] = $u['name'];
        }
        return $this->unitNameCache;
    }

    private function unitName(string $code): string { return $this->unitNames()[$code] ?? $code; }

    private function unitsOf(array $s): array { return Features::unitsOf($s, $this->portal); }

    private function sessionOut(array $s, string $level, bool $open): array
    {
        $c = $this->db->prepare('SELECT COUNT(*) FROM signatures WHERE session_id=?');
        $c->execute([$s['id']]);
        $stu = $this->db->prepare('SELECT COUNT(DISTINCT adm_no) FROM signatures WHERE session_id=?');
        $stu->execute([$s['id']]);
        return [
            'source' => $s['source'] ?? 'timetable', 'id' => (int)$s['id'], 'code' => $s['code'], 'period' => $s['period'], 'venue' => $s['venue'],
            'opens_at' => $s['opens_at'], 'closes_at' => $s['closes_at'], 'status' => $s['status'], 'supervisor' => $s['supervisor_name'],
            'level' => $level, 'open' => $open, 'offline' => (bool)$s['offline'], 'units' => $this->unitsOf($s), 'late_after' => (int)$s['late_after'],
            'expected' => Features::expected($this->portal, $s), 'signatures' => (int)$c->fetchColumn(), 'students' => (int)$stu->fetchColumn(),
        ];
    }

    private function pdfOut(string $bytes, string $code, string $name): void
    {
        $attach = !empty($_GET['download']);
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($attach ? 'attachment' : 'inline') . '; filename="' . preg_replace('/[^A-Za-z0-9_-]/', '_', $name) . '.pdf"');
        header('Cache-Control: no-store');
        header('X-Document-Code: ' . $code);
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
    }

    // ------------------------------------------------------------ timetable

    private function tt(): Timetable
    {
        return $this->tt ??= new Timetable($this->db, $this->portal, $this->audit);
    }

    private function ttIndex(): void { $this->json(200, $this->tt()->index($this->user)); }
    private function ttPreview(): void { $this->json(200, $this->tt()->preview($this->user, $this->body())); }
    private function ttShow(string $id): void { $this->json(200, $this->tt()->show($this->user, (int)$id)); }
    private function ttAssign(string $id): void { $this->json(200, $this->tt()->assign($this->user, (int)$id, $this->body())); }
    private function ttSubmit(string $id): void { $this->json(200, $this->tt()->submit($this->user, (int)$id)); }
    private function ttApprove(string $id): void { $this->json(200, $this->tt()->approve($this->user, (int)$id)); }
    private function ttReject(string $id): void { $this->json(200, $this->tt()->reject($this->user, (int)$id, $this->body())); }

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
        $dom = preg_quote(strtolower((string)Config::get('STAFF_EMAIL_DOMAIN', 'kcau.ac.ke')), '/');
        if (!preg_match('/^[^@\s]+@' . $dom . '$/', $email)) throw new HttpError(422, 'Sign in with your KCA school email (name@kcau.ac.ke). Outside email addresses cannot be used.');
        $key = $email . '|' . ($_SERVER['REMOTE_ADDR'] ?? '');
        $this->db->prepare('DELETE FROM login_attempts WHERE ts < ?')->execute([time() - 900]);
        $n = $this->db->prepare('SELECT COUNT(*) FROM login_attempts WHERE k=?');
        $n->execute([$key]);
        if ((int)$n->fetchColumn() >= 5) throw new HttpError(429, 'Too many failed attempts. Wait 15 minutes and try again.');
        $u = $pw !== '' ? $this->portal->authenticateStaff($email, $pw) : null;
        if (!$u) {
            $this->db->prepare('INSERT INTO login_attempts(k,ts) VALUES(?,?)')->execute([$key, time()]);
            $this->audit->log($email ?: 'unknown', 'login_failed', null, []);
            throw new HttpError(401, 'Incorrect email or password.');
        }
        $u = ['email' => strtolower($u['email']), 'name' => $u['name'], 'role' => $u['role'], 'school' => $u['school'] ?? ''];
        session_regenerate_id(true);
        $_SESSION['user'] = $u;
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
        $_SESSION['last'] = time();
        $this->user = $u;
        if ($u['role'] === 'exam_director') Features::ensureDirector($this->db, $u['name']);
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

    private function portalUnits(): void
    {
        if ($this->user['role'] !== 'exam_director') throw new HttpError(403, 'Only the exam director can add a one-off session.');
        $this->json(200, ['units' => array_map(static fn($u) => ['code' => $u['code'], 'name' => $u['name']], $this->portal->listUnits())]);
    }

    // -------------------------------------------------------------- sessions

    private function listSessions(): void
    {
        $role = $this->user['role'];
        if ($role === 'exam_director') {
            $rows = $this->db->query('SELECT * FROM exam_sessions ORDER BY id DESC LIMIT 200')->fetchAll();
        } else {
            $st = $this->db->prepare('SELECT s.* FROM exam_sessions s WHERE s.supervisor_email=? OR s.id IN (SELECT session_id FROM session_access WHERE staff_email=? AND revoked_at IS NULL) ORDER BY s.id DESC LIMIT 200');
            $st->execute([$this->actor(), $this->actor()]);
            $rows = $st->fetchAll();
        }
        $out = [];
        foreach ($rows as $s) {
            try { [$row, $lvl, $open] = $this->access($s['id']); $out[] = $this->sessionOut($row, $lvl, $open); } catch (HttpError) {}
        }
        $this->json(200, ['sessions' => $out]);
    }

    /** A one-off session added by hand. Normal sessions are created when the exam director approves a timetable. */
    private function createSession(): void
    {
        if ($this->user['role'] !== 'exam_director') throw new HttpError(403, 'Sessions come from the exam timetable. Only the exam director can add a one-off session.');
        $b = $this->body();
        $chief = $this->portal->findStaff(strtolower($this->str($b, 'supervisor_email', 120)));
        if (!$chief || $chief['role'] !== 'supervisor') throw new HttpError(422, 'Choose a chief supervisor for this session.');
        $period = $this->str($b, 'period', 80); $venue = $this->str($b, 'venue', 80);
        $o = Util::toUtc($this->str($b, 'opens_at', 40)); $c = Util::toUtc($this->str($b, 'closes_at', 40));
        if ($period === '' || $venue === '' || !$o || !$c) throw new HttpError(422, 'Exam period, venue, opening and closing time are required.');
        if ($c <= $o) throw new HttpError(422, 'Closing time must be after opening time.');
        if ((strtotime($c) - strtotime($o)) > 86400) throw new HttpError(422, 'An exam session cannot last longer than 24 hours.');
        $known = $this->unitNames();
        $units = [];
        foreach (is_array($b['units'] ?? null) ? $b['units'] : [] as $u) if (is_string($u) && isset($known[$u]) && !in_array($u, $units, true)) $units[] = $u;
        if (!$units) throw new HttpError(422, 'Choose at least one paper for this session.');
        $late = max(5, min(180, (int)($b['late_after'] ?? 30) ?: 30));
        $code = 'S' . gmdate('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
        $this->db->prepare('INSERT INTO exam_sessions(code,period,venue,opens_at,closes_at,supervisor_email,supervisor_name,status,created_at,units,late_after,offline,source) VALUES(?,?,?,?,?,?,?,?,?,?,?,0,?)')
            ->execute([$code, $period, $venue, $o, $c, strtolower($chief['email']), $chief['name'], 'active', Util::now(), json_encode($units), $late, 'manual']);
        $id = (int)$this->db->lastInsertId();
        $this->audit->log($this->actor(), 'session_created', $id, ['period' => $period, 'venue' => $venue, 'from' => 'manual one-off']);
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
        $st = $this->db->prepare('SELECT * FROM exam_sessions WHERE id=?');
        $st->execute([$s['id']]);
        $s = $st->fetch();
        $sealed = 0;
        foreach ($this->unitsOf($s) as $code) {
            try { if ($this->sealUnit($s, $code)) $sealed++; }
            catch (\Throwable $e) { error_log('[esign] sealing ' . $code . ' failed: ' . $e->getMessage()); }
        }
        $this->json(200, ['ok' => true, 'sealed' => $sealed]);
    }

    /** Seal (or reuse) one unit's sheet and log the key use. @return ?array sheet result + report, null if the unit has nothing on it */
    private function sealUnit(array $s, string $code): ?array
    {
        $report = Features::unitReport($this->db, $this->portal, $s, $code);
        $incs = Features::incidents($this->db, (int)$s['id'], $code);
        $r = $this->docs->sheet($s, $code, $this->unitName($code), $report, $incs, $this->actor());
        if (!$r) return null;
        if (!$r['reused']) $this->audit->log($this->actor(), 'sheet_sealed', (int)$s['id'], ['unit' => $code, 'doc' => $r['code']]);
        return ['sheet' => $r, 'report' => $report, 'incidents' => $incs, 'code' => $code, 'name' => $this->unitName($code)];
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
            ->execute([$s['id'], strtolower($staff['email']), $staff['name'], $this->actor(), Util::now()]);
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

    // ------------------------------------------------------------ offline mode

    private function setOffline(string $sid): void
    {
        [$s, $lvl] = $this->access($sid);
        $this->needLevel($lvl, ['supervisor']);
        $on = !empty($this->body()['on']);
        $this->db->prepare('UPDATE exam_sessions SET offline=? WHERE id=?')->execute([$on ? 1 : 0, $s['id']]);
        $this->audit->log($this->actor(), $on ? 'offline_enabled' : 'offline_disabled', (int)$s['id'], []);
        $this->json(200, ['ok' => true, 'offline' => $on]);
    }

    /** Roster the device keeps while offline: every student registered for a paper in this session, cleared or not. */
    private function offlinePack(string $sid): void
    {
        [$s] = $this->access($sid);
        if (!$s['offline']) throw new HttpError(409, 'Offline mode is not enabled for this session.');
        $units = $this->unitsOf($s);
        $seen = [];
        foreach ($units as $code) foreach (Features::registered($this->portal, $code) as $r) $seen[Util::normAdm($r['adm_no'])] = $r['adm_no'];
        $out = [];
        foreach ($seen as $adm) {
            $stu = $this->portal->getStudent($adm);
            if (!$stu) continue;
            $pp = EmailPolicy::phone((string)($stu['phone'] ?? ''));
            $u = [];
            if ($stu['cleared']) foreach ($this->portal->clearedUnits($stu['adm_no'], $s['period']) as $x) $u[] = ['code' => $x['code'], 'name' => $x['name'], 'in_session' => in_array($x['code'], $units, true)];
            $out[] = ['adm_no' => $stu['adm_no'], 'full_name' => $stu['full_name'], 'programme' => $stu['programme'], 'photo' => $this->portal->studentPhoto($stu['adm_no']),
                'cleared' => (bool)$stu['cleared'], 'reason' => $stu['reason'] ?: null, 'units' => $u,
                'prefill' => ['email' => strtolower((string)$stu['school_email']), 'phone' => $pp, 'phone_from_portal' => $pp !== null]];
        }
        $this->audit->log($this->actor(), 'offline_pack_downloaded', (int)$s['id'], ['students' => count($out)]);
        $this->json(200, ['sid' => (int)$s['id'], 'students' => $out]);
    }

    // -------------------------------------------------------------- students

    /** Student lookup by full admission number only. Five wrong numbers in two minutes lock the search for that staff member and session. */
    private function searchStudents(string $sid): void
    {
        [$s] = $this->access($sid);
        $raw = trim((string)($_GET['q'] ?? ''));
        $qa = Util::normAdm($raw);
        if (strlen($qa) < 6) { $this->json(200, ['students' => []]); return; }
        $key = Features::lockKey($this->actor(), $s['id']);
        if (Features::missCount($this->db, $key) >= Features::LOCK_MISSES) {
            throw new HttpError(429, 'Search is locked after several wrong admission numbers. Call the invigilator to unlock it.', ['locked' => true]);
        }
        $stu = $this->portal->getStudent(mb_substr($raw, 0, 40));
        if ($stu && Util::normAdm($stu['adm_no']) !== $qa) $stu = null; // exact match only: never a partial or fuzzy hit
        if ($stu) {
            $this->db->prepare('DELETE FROM lookup_misses WHERE k=?')->execute([$key]);
        } else {
            $this->db->prepare('INSERT INTO lookup_misses(k,ts) VALUES(?,?)')->execute([$key, time()]);
            if (Features::missCount($this->db, $key) >= Features::LOCK_MISSES) $this->audit->log($this->actor(), 'search_locked', (int)$s['id'], []);
        }
        $this->json(200, ['students' => $stu ? [$this->pubStudent($stu)] : []]);
    }

    private function resetLookups(string $sid): void
    {
        [$s] = $this->access($sid);
        $this->db->prepare('DELETE FROM lookup_misses WHERE k=?')->execute([Features::lockKey($this->actor(), $s['id'])]);
        $this->audit->log($this->actor(), 'search_unlocked', (int)$s['id'], []);
        $this->json(200, ['ok' => true]);
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
        $inSession = $this->unitsOf($s);
        $now = Util::now();
        $out = [];
        foreach ($this->portal->clearedUnits($stu['adm_no'], $s['period']) as $u) {
            $in = in_array($u['code'], $inSession, true);
            $q = $this->db->prepare('SELECT id, session_id, signed_at, updated_at, version, email_status, receipt_code, reopened_until FROM signatures WHERE adm_no=? AND unit_code=?'
                . ($in ? ' AND session_id=?' : '') . ' ORDER BY id LIMIT 1');
            $q->execute($in ? [$stu['adm_no'], $u['code'], $s['id']] : [$stu['adm_no'], $u['code']]);
            $r = $q->fetch() ?: null;
            if (!$in) {
                $out[] = ['code' => $u['code'], 'name' => $u['name'], 'in_session' => false, 'signed' => (bool)$r, 'signed_at' => $r['signed_at'] ?? null,
                    'signature_id' => null, 'version' => null, 'can_edit' => false];
                continue;
            }
            $out[] = ['in_session' => true, 'code' => $u['code'], 'name' => $u['name'], 'signed' => (bool)$r, 'signature_id' => $r ? (int)$r['id'] : null,
                'signed_at' => $r['signed_at'] ?? null, 'version' => $r ? (int)$r['version'] : null, 'email_status' => $r['email_status'] ?? null,
                'can_edit' => $r ? $this->canEdit($s, $r, $now) : false];
        }
        $pp = EmailPolicy::phone((string)($stu['phone'] ?? ''));
        $this->json(200, ['student' => $this->pubStudent($stu), 'cleared' => true, 'units' => $out, 'session_units' => $inSession,
            'prefill' => ['email' => strtolower((string)$stu['school_email']), 'phone' => $pp, 'phone_from_portal' => $pp !== null]]);
    }

    private function pubStudent(array $s): array
    {
        return ['adm_no' => $s['adm_no'], 'full_name' => $s['full_name'], 'programme' => $s['programme'], 'photo' => $this->portal->studentPhoto($s['adm_no'])];
    }

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
        $r['id'] = (int)$r['id']; $r['version'] = (int)$r['version'];
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
        $sessionUnits = $this->unitsOf($s);
        if (!in_array($unitCode, $sessionUnits, true)) throw new HttpError(422, 'That paper is not part of this exam session (' . implode(', ', $sessionUnits) . '). Check the student is in the right hall.');

        // Name, admission number, email and phone come from the portal record only. Anything the client sends for them is ignored.
        $phone = EmailPolicy::phone((string)($stu['phone'] ?? ''));
        if (!$phone) {
            $phone = EmailPolicy::phone($this->str($b, 'phone', 30));
            if (!$phone) throw new HttpError(422, 'The portal has no phone number for this student. Enter a valid Kenyan phone number, e.g. 0712 345 678.', ['needs_phone' => true]);
        }
        $booklet = strtoupper($this->str($b, 'booklet_no', 30));
        if (!preg_match('/^[A-Z0-9][A-Z0-9\-\/ ]{2,19}$/', $booklet)) throw new HttpError(422, 'Enter the booklet serial number exactly as printed (3-20 letters/digits).');
        $this->assertBookletFree((int)$s['id'], $booklet, $stu['adm_no'], $unitCode);
        $em = EmailPolicy::check($stu['school_email'], $stu['school_email']);
        if (!$em['ok']) throw new HttpError(422, 'The school email on this student\'s portal record is not an allowed KCA address. Ask the exam director to correct the portal record.', ['checks' => $em['checks']]);

        $ex = $this->db->prepare('SELECT 1 FROM signatures WHERE session_id=? AND adm_no=? AND unit_code=?');
        $ex->execute([$s['id'], $stu['adm_no'], $unitCode]);
        $isEdit = (bool)$ex->fetchColumn();
        $ident = isset($b['ident']) && is_string($b['ident']) ? $b['ident'] : null;
        if (!$isEdit && ($ident === null || !isset(Features::IDENT[$ident]))) throw new HttpError(422, 'The invigilator must record how the student was identified (exam card and student ID, or photo only).');
        $queuedRaw = $b['queued_at'] ?? null;
        $queued = is_string($queuedRaw) && $queuedRaw !== '' ? Util::toUtc($queuedRaw) : null;
        if ($queuedRaw && !$s['offline']) throw new HttpError(409, 'Offline mode was not enabled for this session by the chief supervisor.');
        $png = $this->decodeSignature((string)($b['signature'] ?? ''));

        $now = Util::now();
        if ($queued && $queued <= $now && $queued >= $s['opens_at']) $now = $queued;
        $identNote = $ident === 'photo_only' ? $this->str($b, 'ident_note', 120) : '';

        $linked = null;
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $ex = $this->db->prepare('SELECT * FROM signatures WHERE session_id=? AND adm_no=? AND unit_code=?');
            $ex->execute([$s['id'], $stu['adm_no'], $unitCode]);
            $old = $ex->fetch() ?: null;
            $this->assertBookletFree((int)$s['id'], $booklet, $stu['adm_no'], $unitCode); // again inside the lock: two devices cannot both take it
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
                $this->db->prepare('INSERT INTO signatures(session_id,adm_no,unit_code,unit_name,full_name,phone,booklet_no,email,signature_png,signed_at,updated_at,version,captured_by,ident,ident_note,review,offline,queued_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,1,?,?,?,?,?,?)')
                    ->execute([$s['id'], $stu['adm_no'], $unitCode, $unit['name'], $stu['full_name'], $phone, $booklet, $em['email'], $png, $now, $now, $this->actor(),
                        $ident, $identNote, $ident === 'photo_only' ? 'pending' : null, $queued ? 1 : 0, $queued]);
                $sigId = (int)$this->db->lastInsertId();
                // Re-sit: link to an earlier case on this student and unit that required one.
                $pr = $this->db->prepare("SELECT * FROM incidents WHERE adm_no=? AND unit_code=? AND status='resit_required' AND resit_session_id IS NULL AND session_id<>? ORDER BY id LIMIT 1");
                $pr->execute([$stu['adm_no'], $unitCode, $s['id']]);
                if ($prior = $pr->fetch()) {
                    $this->db->prepare('UPDATE signatures SET resit_of=? WHERE id=?')->execute([$prior['case_no'], $sigId]);
                    $this->db->prepare('UPDATE incidents SET resit_session_id=? WHERE id=?')->execute([$s['id'], $prior['id']]);
                    $this->db->prepare('INSERT INTO incident_entries(incident_id,ts,by_name,by_email,text,status) VALUES(?,?,?,?,?,?)')
                        ->execute([$prior['id'], $now, 'system', 'system', 'Re-sit sign sheet issued: ' . $s['period'] . ', ' . $s['venue'] . '.', $prior['status']]);
                    $linked = $prior['case_no'];
                }
            }
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
        $version = $old ? (int)$old['version'] + 1 : 1;
        if ($linked) $this->audit->log('system', 'resit_linked', (int)$s['id'], ['case' => $linked, 'adm_no' => $stu['adm_no']]);
        $this->audit->log($this->actor(), $old ? 'signature_corrected' : ($queued ? 'signature_saved_offline' : 'signature_saved'), (int)$s['id'], [
            'adm_no' => $stu['adm_no'], 'unit' => $unitCode, 'signature_sha256' => hash('sha256', $png), 'booklet' => $booklet, 'version' => $version,
        ]);

        $mail = $this->deliver($sigId);
        $this->json(200, ['ok' => true, 'edited' => (bool)$old, 'email' => $mail]);
    }

    /** A booklet serial number belongs to one student in one session. */
    private function assertBookletFree(int $sid, string $booklet, string $adm, string $unitCode): void
    {
        $d = $this->db->prepare('SELECT 1 FROM signatures WHERE session_id=? AND booklet_no=? AND NOT (adm_no=? AND unit_code=?) LIMIT 1');
        $d->execute([$sid, $booklet, $adm, $unitCode]);
        if ($d->fetchColumn()) throw new HttpError(409, 'That booklet serial number is already recorded for another student in this session. Check the number on the booklet, or call the invigilator.');
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
        if ($ink < 25) throw new HttpError(422, 'The signature looks empty. Please sign in the signature box.');
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
            $doc = $this->docs->receipt($sig, $session, $this->user['email'] ?? 'system');
            $url = Config::get('VERIFY_BASE_URL', 'http://localhost:8080') . '/#/verify';
            $text = "Dear {$sig['full_name']},\n\nThis is your copy of the electronic exam sign sheet entry for {$sig['unit_code']} {$sig['unit_name']} "
                . "({$session['period']}, {$session['venue']}), signed on " . Util::eat($sig['signed_at'], 'd M Y, H:i') . " EAT.\n\n"
                . ($sig['version'] > 1 ? "This is a CORRECTED copy (version {$sig['version']}). It replaces earlier copies.\n\n" : '')
                . "Please keep this email. If the school's record is ever lost or disputed, the attached PDF is your proof of attendance. The school also keeps the sealed original.\n\n"
                . "Verification code: {$doc['code']}\nVerify it at: $url\n\n"
                . "If you did not sit this exam or the details are wrong, tell the exam director immediately.\n\nKCA University. This is an automated message; do not reply.\n";
            $res = $this->mailer->send($sig['email'], 'KCA exam attendance copy: ' . $sig['unit_code'] . ' (' . $sig['adm_no'] . ')', $text,
                ['name' => 'KCA-attendance-' . preg_replace('/\W+/', '', $sig['unit_code']) . '-' . $doc['code'] . '.pdf', 'data' => $doc['bytes']]);
            $this->db->prepare('UPDATE signatures SET email_status=?, email_sent_at=?, receipt_code=? WHERE id=?')
                ->execute([$res['status'], in_array($res['status'], ['sent', 'outbox'], true) ? Util::now() : null, $doc['code'], $sigId]);
            $this->audit->log('system', $res['status'] === 'failed' ? 'receipt_email_failed' : 'receipt_sent', (int)$sig['session_id'],
                ['signature_id' => $sigId, 'doc' => $doc['code'], 'status' => $res['status'], 'to_domain' => substr(strrchr($sig['email'], '@'), 1)]);
            return ['status' => $res['status'], 'code' => $doc['code'], 'to' => $sig['email'], 'pdf' => '/api/documents/' . $doc['code'] . '/pdf'];
        } catch (\Throwable $e) {
            error_log('[esign] deliver failed: ' . $e->getMessage());
            $this->db->prepare("UPDATE signatures SET email_status='failed' WHERE id=?")->execute([$sigId]);
            return ['status' => 'failed', 'code' => null, 'to' => $sig['email'], 'pdf' => null];
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

    /** Load a signature row and the caller's access to its session. @return array{0:array,1:array,2:string} */
    private function sigAccess(string $id): array
    {
        $st = $this->db->prepare('SELECT * FROM signatures WHERE id=?');
        $st->execute([(int)$id]);
        $g = $st->fetch();
        if (!$g) throw new HttpError(404, 'Not found.');
        [$s, $lvl] = $this->access($g['session_id']);
        return [$g, $s, $lvl];
    }

    private function recordExit(string $id): void
    {
        [$g, $s, $lvl] = $this->sigAccess($id);
        $this->needLevel($lvl, ['supervisor', 'invigilator', 'director']);
        if ($g['exit_at']) throw new HttpError(409, 'An exit is already recorded for this student.');
        $note = $this->str($this->body(), 'note', 80);
        $now = Util::now();
        $this->db->prepare('UPDATE signatures SET exit_at=?, exit_note=? WHERE id=?')->execute([$now, $note, $g['id']]);
        $this->audit->log($this->actor(), 'exit_recorded', (int)$s['id'], ['signature_id' => (int)$g['id'], 'adm_no' => $g['adm_no'], 'note' => $note]);
        $this->json(200, ['ok' => true, 'exit_at' => $now]);
    }

    /** The chief supervisor confirms a photo-only identity check. */
    private function reviewIdent(string $id): void
    {
        [$g, $s, $lvl] = $this->sigAccess($id);
        $this->needLevel($lvl, ['supervisor']);
        $this->db->prepare("UPDATE signatures SET review='reviewed', reviewed_by=? WHERE id=?")->execute([$this->user['name'], $g['id']]);
        $this->audit->log($this->actor(), 'identity_reviewed', (int)$s['id'], ['signature_id' => (int)$g['id']]);
        $this->json(200, ['ok' => true]);
    }

    // ----------------------------------------------------- roll call, reports

    private function sessionUnits(string $sid): void
    {
        [$s] = $this->access($sid);
        $out = [];
        foreach ($this->unitsOf($s) as $code) {
            $r = Features::unitReport($this->db, $this->portal, $s, $code);
            if (!$r['rows'] && !$r['absent']) continue;
            $inc = $this->db->prepare('SELECT COUNT(*) FROM incidents WHERE session_id=? AND unit_code=?');
            $inc->execute([$s['id'], $code]);
            $out[] = ['code' => $code, 'name' => $this->unitName($code), 'registered' => $r['registered'], 'signed' => count($r['rows']), 'absent' => count($r['absent']),
                'email_problems' => count(array_filter($r['rows'], static fn($g) => !in_array($g['email_status'], ['sent', 'outbox'], true))), 'incidents' => (int)$inc->fetchColumn()];
        }
        $this->json(200, ['units' => $out]);
    }

    private function rollcall(string $sid): void
    {
        [$s, $lvl] = $this->access($sid);
        $this->needLevel($lvl, ['supervisor', 'invigilator', 'director']);
        $units = [];
        foreach ($this->unitsOf($s) as $code) {
            $r = Features::unitReport($this->db, $this->portal, $s, $code);
            $units[] = ['code' => $code, 'name' => $this->unitName($code), 'registered' => $r['registered'], 'signed' => count($r['rows']),
                'pending' => array_map(fn($a) => ['adm_no' => $a['adm_no'], 'full_name' => $a['full_name'], 'photo' => $this->portal->studentPhoto($a['adm_no']), 'state' => $a['state'], 'reason' => $a['reason']], $r['absent']),
                'present' => array_map(static fn($g) => ['id' => (int)$g['id'], 'adm_no' => $g['adm_no'], 'full_name' => $g['full_name'], 'signed_at' => $g['signed_at'],
                    'exit_at' => $g['exit_at'], 'exit_note' => $g['exit_note'] ?? ''], $r['rows'])];
        }
        $this->json(200, ['updated_at' => Util::now(), 'units' => $units]);
    }

    private function sheetPdf(string $sid, string $unit): void
    {
        [$s, $lvl] = $this->access($sid);
        $this->needLevel($lvl, ['supervisor', 'invigilator', 'director']);
        if (!in_array($unit, $this->unitsOf($s), true)) throw new HttpError(404, 'That paper is not part of this exam session.');
        $u = $this->sealUnit($s, $unit);
        if (!$u) throw new HttpError(404, 'No signatures recorded for that unit yet.');
        $r = $u['sheet'];
        $this->audit->log($this->actor(), 'sheet_downloaded', (int)$s['id'], ['unit' => $unit, 'doc' => $r['code'], 'reused' => $r['reused']]);
        $this->pdfOut($r['bytes'], $r['code'], "signsheet-$unit-{$r['code']}");
    }

    // ------------------------------------------------------------ session pack

    /** Seal every unit sheet, then the pack cover. @return array{0:array,1:array} pack result, session */
    private function buildPack(array $s): array
    {
        $units = [];
        foreach ($this->unitsOf($s) as $code) if ($u = $this->sealUnit($s, $code)) $units[] = $u;
        $q = $this->db->prepare('SELECT * FROM signatures WHERE session_id=?');
        $q->execute([$s['id']]);
        $sigs = $q->fetchAll();
        $cut = Features::lateCutoff($s);
        $h = $this->db->prepare('SELECT COUNT(*) FROM signature_history WHERE signature_id IN (SELECT id FROM signatures WHERE session_id=?)');
        $h->execute([$s['id']]);
        $inc = $this->db->prepare('SELECT COUNT(*) FROM incidents WHERE session_id=?');
        $inc->execute([$s['id']]);
        $totals = [
            'signed' => count($sigs), 'late' => count(array_filter($sigs, static fn($g) => $g['signed_at'] > $cut)),
            'exits' => count(array_filter($sigs, static fn($g) => $g['exit_at'])), 'offline' => count(array_filter($sigs, static fn($g) => $g['offline'])),
            'photo_only' => count(array_filter($sigs, static fn($g) => $g['ident'] === 'photo_only')),
            'review_pending' => count(array_filter($sigs, static fn($g) => $g['review'] === 'pending')),
            'incidents' => (int)$inc->fetchColumn(), 'corrections' => (int)$h->fetchColumn(),
        ];
        $r = $this->docs->pack($s, $units, $totals, $this->audit->verify(), $this->actor());
        if (!$r['reused']) $this->audit->log($this->actor(), 'pack_sealed', (int)$s['id'], ['doc' => $r['code']]);
        return [$r, $s];
    }

    private function makePack(string $sid): void
    {
        [$s, $lvl] = $this->access($sid);
        $this->needLevel($lvl, ['supervisor', 'director']);
        [$r] = $this->buildPack($s);
        $this->json(200, ['code' => $r['code'], 'pdf' => '/api/documents/' . $r['code'] . '/pdf']);
    }

    private function packPdf(string $sid): void
    {
        [$s, $lvl] = $this->access($sid);
        $this->needLevel($lvl, ['supervisor', 'director']);
        [$r] = $this->buildPack($s);
        $this->audit->log($this->actor(), 'pack_downloaded', (int)$s['id'], ['doc' => $r['code']]);
        $this->pdfOut($r['bytes'], $r['code'], 'session-pack-' . $s['code'] . '-' . $r['code']);
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
            $mins = max(5, min(240, (int)($this->body()['minutes'] ?? 30) ?: 30));
            $until = gmdate('Y-m-d\TH:i:s\Z', time() + $mins * 60);
            $this->db->prepare('UPDATE signatures SET reopened_until=? WHERE id=?')->execute([$until, $r['signature_id']]);
        }
        $this->audit->log($this->actor(), 'reopen_' . $action . 'd', (int)$r['session_id'], ['request' => (int)$r['id'], 'signature_id' => (int)$r['signature_id'], 'minutes' => $mins]);
        $this->json(200, ['ok' => true, 'minutes' => $mins]);
    }

    // ------------------------------------------------- flags and incidents

    private function flagOut(array $f): array
    {
        return ['id' => (int)$f['id'], 'sid' => (int)$f['session_id'], 'adm_no' => $f['adm_no'], 'full_name' => $f['full_name'], 'unit' => $f['unit_code'],
            'text' => $f['text'], 'by' => $f['by_email'], 'at' => $f['at'], 'status' => $f['status']];
    }

    private function listIncidents(string $sid): void
    {
        [$s, $lvl] = $this->access($sid);
        $q = $this->db->prepare('SELECT * FROM flags WHERE session_id=?' . ($lvl === 'invigilator' ? ' AND by_email=?' : '') . ' ORDER BY id');
        $q->execute($lvl === 'invigilator' ? [$s['id'], $this->actor()] : [$s['id']]);
        $flags = array_map(fn($f) => $this->flagOut($f), $q->fetchAll());
        if ($lvl === 'invigilator') { $this->json(200, ['level' => $lvl, 'flags' => $flags, 'incidents' => [], 'reviews' => []]); return; }
        $rv = $this->db->prepare('SELECT id, full_name, adm_no, unit_code, ident_note, review, reviewed_by FROM signatures WHERE session_id=? AND review IS NOT NULL ORDER BY id');
        $rv->execute([$s['id']]);
        $reviews = array_map(static fn($g) => ['id' => (int)$g['id'], 'full_name' => $g['full_name'], 'adm_no' => $g['adm_no'], 'unit_code' => $g['unit_code'],
            'note' => $g['ident_note'], 'review' => $g['review'], 'reviewed_by' => $g['reviewed_by']], $rv->fetchAll());
        $this->json(200, ['level' => $lvl, 'flags' => $flags, 'incidents' => Features::incidents($this->db, (int)$s['id']), 'statuses' => Features::STATUSES,
            'categories' => Features::CATS, 'reviews' => $reviews]);
    }

    /** Invigilators only flag; the chief supervisor decides what is recorded on the sheet. */
    private function createFlag(string $sid): void
    {
        [$s, $lvl] = $this->access($sid);
        $this->needLevel($lvl, ['supervisor', 'invigilator']);
        $b = $this->body();
        $stu = $this->portal->getStudent($this->str($b, 'adm_no', 30));
        if (!$stu) throw new HttpError(404, 'Student not found on the portal.');
        $text = $this->str($b, 'text', 300);
        if (mb_strlen($text) < 10) throw new HttpError(422, 'Describe what you saw (at least 10 characters).');
        $unit = $this->str($b, 'unit_code', 30);
        $at = Util::now();
        $this->db->prepare('INSERT INTO flags(session_id,adm_no,full_name,unit_code,text,by_email,at) VALUES(?,?,?,?,?,?,?)')
            ->execute([$s['id'], $stu['adm_no'], $stu['full_name'], $unit, $text, $this->actor(), $at]);
        $id = (int)$this->db->lastInsertId();
        $this->audit->log($this->actor(), 'incident_flagged', (int)$s['id'], ['adm_no' => $stu['adm_no'], 'unit' => $unit]);
        $this->json(201, ['ok' => true, 'flag' => ['id' => $id, 'sid' => (int)$s['id'], 'adm_no' => $stu['adm_no'], 'full_name' => $stu['full_name'], 'unit' => $unit,
            'text' => $text, 'by' => $this->actor(), 'at' => $at, 'status' => 'open']]);
    }

    private function createIncident(string $sid): void
    {
        [$s, $lvl] = $this->access($sid);
        if ($lvl === 'invigilator') throw new HttpError(403, 'Only the chief supervisor can record an incident. Use "Flag for supervisor" instead.');
        $this->needLevel($lvl, ['supervisor']);
        $b = $this->body();
        $stu = $this->portal->getStudent($this->str($b, 'adm_no', 30));
        if (!$stu) throw new HttpError(404, 'Student not found on the portal.');
        $unit = $this->str($b, 'unit_code', 30);
        $registered = false;
        if ($unit !== '') foreach (Features::registered($this->portal, $unit) as $r) if (Util::normAdm($r['adm_no']) === Util::normAdm($stu['adm_no'])) $registered = true;
        if (!$registered) throw new HttpError(422, 'That student is not registered for ' . ($unit ?: 'that unit') . '.');
        $cat = $this->str($b, 'category', 60);
        if (!in_array($cat, Features::CATS, true)) throw new HttpError(422, 'Choose an incident category.');
        $text = $this->str($b, 'text', 500);
        if (mb_strlen($text) < 10) throw new HttpError(422, 'Write what was seen (at least 10 characters).');
        $now = Util::now();
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $id = (int)$this->db->query('SELECT COALESCE(MAX(id),0)+1 FROM incidents')->fetchColumn();
            $case = 'INC-' . date('Y') . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
            $this->db->prepare('INSERT INTO incidents(id,case_no,session_id,adm_no,full_name,unit_code,category,status,opened_by,opened_by_email,opened_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$id, $case, $s['id'], $stu['adm_no'], $stu['full_name'], $unit, $cat, 'open', $this->user['name'], $this->actor(), $now]);
            $this->db->prepare('INSERT INTO incident_entries(incident_id,ts,by_name,by_email,text,status) VALUES(?,?,?,?,?,?)')
                ->execute([$id, $now, $this->user['name'], $this->actor(), $text, 'open']);
            if (!empty($b['flag_id'])) $this->db->prepare("UPDATE flags SET status='recorded' WHERE id=? AND session_id=?")->execute([(int)$b['flag_id'], $s['id']]);
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
        $this->audit->log($this->actor(), 'incident_recorded', (int)$s['id'], ['case' => $case, 'adm_no' => $stu['adm_no'], 'unit' => $unit]);
        $inc = array_values(array_filter(Features::incidents($this->db, (int)$s['id'], $unit), static fn($i) => $i['id'] === $id))[0] ?? null;
        $this->json(201, ['ok' => true, 'incident' => $inc]);
    }

    private function updateIncident(string $id): void
    {
        $st = $this->db->prepare('SELECT * FROM incidents WHERE id=?');
        $st->execute([(int)$id]);
        $inc = $st->fetch();
        if (!$inc) throw new HttpError(404, 'Case not found.');
        [, $lvl] = $this->access($inc['session_id']);
        if ($lvl === 'invigilator') throw new HttpError(403, 'Only the chief supervisor can update an incident.');
        $this->needLevel($lvl, ['supervisor']);
        $b = $this->body();
        $status = $this->str($b, 'status', 30); $text = $this->str($b, 'text', 500);
        if (!isset(Features::STATUSES[$status])) throw new HttpError(422, 'Choose an outcome.');
        if (mb_strlen($text) < 5) throw new HttpError(422, 'Add a short note (at least 5 characters).');
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $this->db->prepare('UPDATE incidents SET status=? WHERE id=?')->execute([$status, $inc['id']]);
            $this->db->prepare('INSERT INTO incident_entries(incident_id,ts,by_name,by_email,text,status) VALUES(?,?,?,?,?,?)')
                ->execute([$inc['id'], Util::now(), $this->user['name'], $this->actor(), $text, $status]);
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
        $this->audit->log($this->actor(), 'incident_updated', (int)$inc['session_id'], ['case' => $inc['case_no'], 'status' => $status]);
        $this->json(200, ['ok' => true]);
    }

    // ------------------------------------------------- records and documents

    /** Sealed originals kept by the school: the director sees everything, a chief supervisor the documents of their own sessions. */
    private function records(): void
    {
        $dir = $this->user['role'] === 'exam_director';
        if (!$dir) {
            $c = $this->db->prepare('SELECT 1 FROM exam_sessions WHERE supervisor_email=? LIMIT 1');
            $c->execute([$this->actor()]);
            if (!$c->fetchColumn()) throw new HttpError(403, 'Your role cannot do that.');
        }
        $q = $this->db->prepare('SELECT d.*, e.supervisor_email FROM documents d LEFT JOIN exam_sessions e ON e.id=d.session_id ' . ($dir ? '' : 'WHERE e.supervisor_email=? ') . 'ORDER BY d.id DESC LIMIT 500');
        $q->execute($dir ? [] : [$this->actor()]);
        $out = [];
        foreach ($q->fetchAll() as $d) {
            $pl = json_decode($d['payload'], true) ?: [];
            $out[] = ['code' => $d['code'], 'kind' => $d['kind'], 'unit' => $pl['unit_code'] ?? $d['unit_code'], 'unit_name' => $pl['unit_name'] ?? '',
                'period' => $pl['period'] ?? null, 'venue' => $pl['venue'] ?? null, 'issued_at' => $d['created_at'], 'status' => $this->docs->status($d),
                'student' => $d['kind'] === 'receipt' ? ($pl['adm_no'] ?? null) : null,
                'entries' => $d['kind'] === 'sheet' ? count($pl['entries'] ?? []) : ($d['kind'] === 'pack' ? (int)($pl['totals']['signed'] ?? 0) : null),
                'pdf' => '/api/documents/' . $d['code'] . '/pdf'];
        }
        $this->json(200, ['records' => $out]);
    }

    /** Open a stored sealed original. Logged every time. */
    private function documentPdf(string $code): void
    {
        $d = $this->docs->find($code);
        if (!$d) throw new HttpError(404, 'No document with that code.');
        [$s, $lvl] = $this->access((int)$d['session_id']);
        $this->needLevel($lvl, match ($d['kind']) { 'pack' => ['supervisor', 'director'], 'sheet' => ['supervisor', 'invigilator', 'director'], default => ['supervisor', 'invigilator', 'director'] });
        $bytes = $this->docs->storedBytes($d);
        $this->audit->log($this->actor(), 'record_viewed', (int)$s['id'], ['doc' => $d['code']]);
        $this->pdfOut($bytes, $d['code'], $d['kind'] . '-' . $d['code']);
    }

    // ------------------------------------------------------ director controls

    private function needDirector(): void
    {
        if ($this->user['role'] !== 'exam_director') throw new HttpError(403, 'Only the exam director can see this.');
    }

    private function directorSecurity(): void
    {
        $this->needDirector();
        $d = Features::director($this->db);
        $uses = $this->db->query('SELECT ts, doc_code AS doc, purpose, by_email AS "by" FROM key_uses ORDER BY id DESC LIMIT 8')->fetchAll();
        $total = (int)$this->db->query('SELECT COUNT(*) FROM key_uses')->fetchColumn();
        $this->json(200, ['name' => $d['name'], 'signature' => $d['png'] !== null ? 'data:image/png;base64,' . base64_encode($d['png']) : null, 'signature_at' => $d['at'],
            'fingerprint' => $this->docs->fingerprint(), 'uses_total' => $total, 'uses' => $uses,
            'custodians' => ['Exam director', 'ICT security officer (KCA)'],
            'retention' => 'Set by KCA’s data protection policy and applied by KCA ICT. Records are owned by the school.']);
    }

    /** Upload the director's signature image. Re-encoded to a small PNG so nothing but pixels is stored. */
    private function directorSignature(): void
    {
        if ($this->user['role'] !== 'exam_director') throw new HttpError(403, 'Only the exam director can do that.');
        $bad = 'Upload a PNG or JPG image of the signature (under 300 KB).';
        $sig = (string)($this->body()['signature'] ?? '');
        if (!preg_match('#^data:image/(png|jpeg);base64,([A-Za-z0-9+/=]+)$#', $sig, $m) || strlen($sig) > 400_000) throw new HttpError(422, $bad);
        $bin = base64_decode($m[2], true);
        $im = $bin === false ? false : @imagecreatefromstring($bin);
        if (!$im) throw new HttpError(422, $bad);
        $w = imagesx($im); $h = imagesy($im);
        if ($w < 40 || $h < 15 || $w > 6000 || $h > 6000) throw new HttpError(422, $bad);
        $k = min(1.0, 500 / $w, 170 / $h);
        $nw = max(1, (int)round($w * $k)); $nh = max(1, (int)round($h * $k));
        $out = imagecreatetruecolor($nw, $nh);
        imagealphablending($out, false); imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 255, 255, 255, 127));
        imagealphablending($out, true);
        imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $ink = 0;
        if ($m[1] === 'jpeg') { // a photographed signature sits on white paper: make the paper transparent
            imagealphablending($out, false);
            $clear = imagecolorallocatealpha($out, 255, 255, 255, 127);
            for ($y = 0; $y < $nh; $y++) for ($x = 0; $x < $nw; $x++) {
                $c = imagecolorat($out, $x, $y);
                if ((($c >> 16) & 255) > 215 && (($c >> 8) & 255) > 215 && ($c & 255) > 215) imagesetpixel($out, $x, $y, $clear); else $ink++;
            }
        } else {
            for ($y = 0; $y < $nh; $y++) for ($x = 0; $x < $nw; $x++) if (((imagecolorat($out, $x, $y) >> 24) & 0x7F) < 100) $ink++;
        }
        if ($ink < 25) throw new HttpError(422, 'The signature image looks empty. ' . $bad);
        imagealphablending($out, false); imagesavealpha($out, true);
        ob_start(); imagepng($out, null, 9); $png = (string)ob_get_clean();
        $now = Util::now();
        $this->db->prepare('INSERT INTO director_profile(id,name,signature_png,signature_at) VALUES(1,?,?,?) ON CONFLICT(id) DO UPDATE SET name=excluded.name, signature_png=excluded.signature_png, signature_at=excluded.signature_at')
            ->execute([$this->user['name'], $png, $now]);
        $this->audit->log($this->actor(), 'director_signature_updated', null, ['signature_sha256' => hash('sha256', $png)]);
        $this->json(200, ['ok' => true]);
    }

    // ------------------------------------------------------------------ audit

    private function auditEvents(): void
    {
        if ($this->user['role'] !== 'exam_director') throw new HttpError(403, 'Only the exam director can see this.');
        $L = [
            'login' => ['Staff sign-in', 'info'], 'login_failed' => ['Failed sign-in', 'warn'], 'logout' => ['Staff sign-out', 'info'], 'signature_saved' => ['Signature recorded', 'ok'],
            'signature_saved_offline' => ['Signature synced from offline', 'ok'], 'signature_corrected' => ['Signature corrected', 'warn'], 'receipt_sent' => ['Sealed copy emailed', 'ok'],
            'receipt_email_failed' => ['Email copy failed', 'bad'], 'sheet_sealed' => ['Unit sheet sealed', 'ok'], 'sheet_downloaded' => ['Sheet opened', 'info'],
            'session_created' => ['Session loaded', 'info'], 'session_closed' => ['Session closed', 'warn'], 'access_granted' => ['Invigilator access granted', 'info'],
            'access_revoked' => ['Invigilator access revoked', 'warn'], 'incident_recorded' => ['Incident recorded', 'bad'], 'incident_updated' => ['Incident updated', 'warn'],
            'incident_flagged' => ['Invigilator flagged a student', 'warn'], 'identity_reviewed' => ['Identity check reviewed', 'info'], 'resit_linked' => ['Re-sit linked to earlier case', 'info'],
            'reopen_requested' => ['Reopen requested', 'warn'], 'reopen_approved' => ['Form reopened by director', 'warn'], 'reopen_rejected' => ['Reopen declined', 'info'],
            'offline_enabled' => ['Offline mode switched on', 'warn'], 'offline_disabled' => ['Offline mode switched off', 'info'], 'offline_pack_downloaded' => ['Offline roster downloaded', 'info'],
            'record_viewed' => ['Stored record opened', 'info'], 'pack_sealed' => ['Session pack sealed', 'ok'], 'pack_downloaded' => ['Session pack opened', 'info'],
            'exit_recorded' => ['Student exit recorded', 'info'], 'search_locked' => ['Student search locked (wrong numbers)', 'warn'], 'search_unlocked' => ['Student search unlocked by staff', 'info'],
            'director_signature_updated' => ['Director signature changed', 'warn'], 'timetable_submitted' => ['Timetable submitted', 'info'], 'timetable_approved' => ['Timetable approved', 'ok'],
            'timetable_rejected' => ['Timetable rejected', 'warn'], 'timetable_assignment_changed' => ['Timetable assignment changed', 'info'],
        ];
        $ev = [];
        foreach ($this->db->query('SELECT ts, actor, action, payload FROM audit_log ORDER BY id DESC LIMIT 14') as $r) {
            $l = $L[$r['action']] ?? [ucfirst(str_replace('_', ' ', $r['action'])), 'info'];
            $j = json_decode($r['payload'], true);
            $d = is_array($j) ? (string)($j['adm_no'] ?? $j['unit'] ?? $j['case'] ?? $j['to'] ?? $j['from'] ?? '') : '';
            $ev[] = ['ts' => $r['ts'], 'actor' => $r['actor'], 'label' => $l[0], 'kind' => $l[1], 'detail' => $d];
        }
        $this->json(200, ['events' => $ev, 'chain' => $this->audit->verify()]);
    }

    private function auditVerify(): void
    {
        if ($this->user['role'] !== 'exam_director') throw new HttpError(403, 'Only the exam director can run this check.');
        $this->json(200, $this->audit->verify());
    }
}
