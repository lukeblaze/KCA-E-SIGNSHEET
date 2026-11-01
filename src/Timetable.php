<?php
declare(strict_types=1);
namespace Kca;

use Kca\Portal\PortalAdapter;

/**
 * Timetable-driven session planner (rules-based, no AI). Port of the "timetable engine" in demo/demo-backend.js:
 * CSV in -> normalised sittings -> checked for clashes -> staff assigned -> director approves -> exam sessions + invigilator access.
 * Methods take the signed-in user ['email','name','role','school'] and return the same JSON-shaped arrays as the demo routes.
 */
final class Timetable
{
    private const SCHOOLS = ['School of Technology', 'School of Business', 'School of Education'];
    private const MON = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];
    private const TITLES = ['dr', 'prof', 'mr', 'mrs', 'ms', 'miss', 'eng', 'rev', 'sir'];
    private const HDR = [
        'unit' => ['unit', 'unitcode', 'code', 'course', 'coursecode', 'paper'],
        'date' => ['date', 'examdate', 'day'],
        'start' => ['start', 'starttime', 'from', 'time'],
        'end' => ['end', 'endtime', 'to', 'finish'],
        'venue' => ['venue', 'room', 'hall', 'location'],
        'lecturer' => ['lecturer', 'lecturers', 'instructor', 'teacher', 'lecturername', 'lectureremail'],
        'candidates' => ['candidates', 'students', 'hallsize', 'size', 'headcount', 'numberofstudents'],
    ];
    private const BUF = 1800; // seconds a person needs between two sittings

    private ?array $staffCache = null;
    private ?array $unitCache = null;
    private array $regCache = [];
    private ?array $existing = null;

    public function __construct(private \PDO $db, private PortalAdapter $portal, private Audit $audit) {}

    public static function migrate(\PDO $db): void
    {
        $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS timetable_imports(
  id INTEGER PRIMARY KEY, by_email TEXT NOT NULL, by_name TEXT NOT NULL, school TEXT NOT NULL, period TEXT NOT NULL,
  ratio NUMERIC NOT NULL DEFAULT 40, late_after NUMERIC NOT NULL DEFAULT 30, csv TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'draft', created_at TEXT NOT NULL, plan TEXT NOT NULL,
  decided_by TEXT, note TEXT NOT NULL DEFAULT '', session_ids TEXT NOT NULL DEFAULT '[]');
CREATE INDEX IF NOT EXISTS idx_tt_imports_by ON timetable_imports(by_email, status);
SQL);
    }

    // ================================================================ public API (one method per demo route)

    public function index(array $user): array
    {
        $this->reset();
        $this->needTT($user);
        $rows = $this->db->query('SELECT * FROM timetable_imports ORDER BY id DESC')->fetchAll();
        $imports = [];
        foreach ($rows as $r) {
            $i = $this->hydrate($r);
            if ($user['role'] === 'exam_director' || $i['by'] === ($user['email'] ?? null)) $imports[] = $this->impOut($i);
        }
        $chiefs = [];
        foreach ($this->staff() as $u) if ($u['role'] === 'supervisor') $chiefs[] = ['email' => $u['email'], 'name' => $u['name']];
        return [
            'school' => ($user['school'] ?? '') ?: 'All schools',
            'schools' => self::SCHOOLS, 'role' => $user['role'], 'ratio' => 40, 'imports' => $imports, 'chiefs' => $chiefs,
        ];
    }

    public function preview(array $user, array $body): array
    {
        $this->reset();
        $this->needTT($user);
        $school = $user['role'] === 'coordinator' ? ($user['school'] ?? '') : ($this->str($body, 'school', 60) ?: 'All schools');
        $period = $this->str($body, 'period', 80);
        $ratio = $this->tidyNum(max(10, min(200, $this->numOr($body['ratio'] ?? null, 40))));
        $late = $this->tidyNum(max(5, min(180, $this->numOr($body['late_after'] ?? null, 30))));
        if ($period === '') throw new HttpError(422, 'Enter the exam period, for example Sem 2 2026, Main exams.');
        $csv = $body['csv'] ?? '';
        $csv = is_string($csv) ? $csv : (is_scalar($csv) && $csv !== false ? (string)$csv : '');
        if (strlen($csv) > 400000) throw new HttpError(413, 'That file is too large.');
        if ($this->trim($csv) === '') throw new HttpError(422, 'Paste or choose a timetable file first.');
        // Excel on Windows saves CSV as Windows-1252; the rest of the system speaks UTF-8.
        if (!mb_check_encoding($csv, 'UTF-8')) $csv = mb_convert_encoding($csv, 'UTF-8', 'Windows-1252');

        $plan = $this->buildPlan($csv, ['school' => $school, 'ratio' => $ratio]);
        $this->db->prepare("DELETE FROM timetable_imports WHERE status='draft' AND by_email=?")->execute([$user['email']]);
        $this->db->prepare('INSERT INTO timetable_imports(by_email,by_name,school,period,ratio,late_after,csv,status,created_at,plan) VALUES(?,?,?,?,?,?,?,?,?,?)')
            ->execute([$user['email'], $user['name'], $school, $period, $ratio, $late, $csv, 'draft', Util::now(), $this->enc($plan)]);
        $imp = $this->getImp($user, (int)$this->db->lastInsertId());
        return ['import' => $this->impOut($imp, true)];
    }

    public function show(array $user, int $id): array
    {
        $this->reset();
        $this->needTT($user);
        return ['import' => $this->impOut($this->getImp($user, $id), true)];
    }

    public function assign(array $user, int $id, array $body): array
    {
        $this->reset();
        $this->needTT($user);
        $imp = $this->getImp($user, $id);
        if (!in_array($imp['status'], ['draft', 'pending'], true)) throw new HttpError(409, 'This upload is already decided.');
        if ($imp['status'] === 'pending' && $user['role'] !== 'exam_director') throw new HttpError(403, 'Only the exam director can change a submitted timetable.');
        $si = null;
        foreach ($imp['plan']['sessions'] as $k => $x) if ($x['key'] === ($body['key'] ?? null)) { $si = $k; break; }
        if ($si === null) throw new HttpError(404, 'Sitting not found.');

        $emailIn = $body['email'] ?? null;
        $has = is_string($emailIn) ? $emailIn !== '' : (bool)$emailIn;
        $u = null;
        if ($has && is_string($emailIn)) foreach ($this->staff() as $x) if ($x['email'] === $emailIn) { $u = $x; break; }
        if ($has && (!$u || !in_array($u['role'], ['supervisor', 'invigilator'], true))) throw new HttpError(422, 'Choose a staff account from the list.');

        $S = &$imp['plan']['sessions'];
        $slot = $body['slot'] ?? null;
        if ($slot === 'chief') {
            $S[$si]['chief'] = $u ? $u['email'] : null;
        } elseif ($slot === 'inv') {
            $n = $this->num($body['index'] ?? null);
            $invs = array_values($S[$si]['invs']);
            if ($u) {
                // JS parity: arr[n] = x fills/overwrites index n; indexes past the end leave holes that are then filtered out (= append); non-index keys are ignored.
                if (is_finite($n) && floor($n) == $n && $n >= 0) { if ($n < count($invs)) $invs[(int)$n] = $u['email']; else $invs[] = $u['email']; }
            } else {
                $at = is_nan($n) ? 0 : (int)($n < 0 ? ceil($n) : floor($n));
                if ($at < 0) $at = max(count($invs) + $at, 0);
                if ($at < count($invs)) array_splice($invs, $at, 1);
            }
            $S[$si]['invs'] = array_values(array_filter($invs, static fn($e) => $e !== null && $e !== ''));
        } else {
            throw new HttpError(422, 'Unknown slot.');
        }
        $S[$si]['notes'] = array_values(array_filter($S[$si]['notes'] ?? [], static fn($n) => !str_starts_with($n, 'Chief:')));
        $S[$si]['notes'][] = 'Changed by ' . $user['name'] . '.';
        foreach (array_keys($S) as $k) $this->validateSession($S, $k);
        unset($S);
        $this->savePlan($imp);
        $this->audit->log($user['email'], 'timetable_assignment_changed', null, ['import' => $imp['id'], 'key' => $imp['plan']['sessions'][$si]['key'], 'slot' => $slot, 'to' => $has ? $emailIn : null]);
        return ['import' => $this->impOut($imp, true)];
    }

    public function submit(array $user, int $id): array
    {
        $this->reset();
        $this->needTT($user);
        $imp = $this->getImp($user, $id);
        if ($imp['status'] !== 'draft') throw new HttpError(409, 'Already submitted.');
        if ($imp['plan']['errors']) throw new HttpError(409, 'Fix the highlighted errors before submitting.');
        foreach ($imp['plan']['sessions'] as $s) if ($s['problems']) throw new HttpError(409, 'Resolve the assignment problems before submitting.');
        $this->db->prepare("UPDATE timetable_imports SET status='pending' WHERE id=?")->execute([$imp['id']]);
        $imp['status'] = 'pending';
        $this->audit->log($user['email'], 'timetable_submitted', null, ['import' => $imp['id'], 'sittings' => count($imp['plan']['sessions'])]);
        return ['import' => $this->impOut($imp)];
    }

    public function approve(array $user, int $id): array
    {
        $this->reset();
        if (($user['role'] ?? '') !== 'exam_director') throw new HttpError(403, 'Only the exam director can approve a timetable.');
        $created = [];
        $this->db->exec('BEGIN IMMEDIATE');
        $done = false;
        try {
            $imp = $this->getImp($user, $id);
            if (!in_array($imp['status'], ['draft', 'pending'], true)) throw new HttpError(409, 'Already decided.');
            // Re-check against everything loaded since the upload was previewed.
            $imp['plan']['errors'] = $this->buildPlan($imp['csv'], ['school' => $imp['school'], 'ratio' => $imp['ratio']])['errors'];
            foreach (array_keys($imp['plan']['sessions']) as $k) $this->validateSession($imp['plan']['sessions'], $k);
            $msg = $imp['plan']['errors'] ? 'Fix the highlighted errors first.' : null;
            if (!$msg) foreach ($imp['plan']['sessions'] as $s) if ($s['problems']) { $msg = 'Resolve the assignment problems first.'; break; }
            if ($msg) {
                $this->savePlan($imp); // keep the refreshed errors visible, like the demo
                $this->db->exec('COMMIT'); $done = true;
                throw new HttpError(409, $msg);
            }
            $staff = [];
            foreach ($this->staff() as $u) $staff[$u['email']] = $u;
            $ins = $this->db->prepare('INSERT INTO exam_sessions(code,period,venue,opens_at,closes_at,supervisor_email,supervisor_name,status,created_at,units,late_after,offline,source,import_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $acc = $this->db->prepare('INSERT INTO session_access(session_id,staff_email,staff_name,granted_by,granted_at) VALUES(?,?,?,?,?)');
            foreach ($imp['plan']['sessions'] as $s) {
                $ch = $staff[$s['chief']];
                $sid = 0;
                for ($try = 0; $try < 20; $try++) {
                    $code = 'S' . str_replace('-', '', substr($s['date'], 2)) . '-' . strtoupper(bin2hex(random_bytes(2)));
                    $exists = $this->db->prepare('SELECT 1 FROM exam_sessions WHERE code=?');
                    $exists->execute([$code]);
                    if (!$exists->fetchColumn()) break;
                }
                $ins->execute([$code, $imp['period'], $s['venue'], Util::toUtc($s['opens_at']), Util::toUtc($s['closes_at']), $ch['email'], $ch['name'], 'active', Util::now(),
                    json_encode(array_values($s['units']), JSON_UNESCAPED_UNICODE), (int)$imp['late_after'], 0, 'timetable', $imp['id']]);
                $sid = (int)$this->db->lastInsertId();
                foreach ($s['invs'] as $e) $acc->execute([$sid, $staff[$e]['email'], $staff[$e]['name'], 'timetable', Util::now()]);
                $created[] = ['id' => $sid, 'period' => $imp['period'], 'venue' => $s['venue']];
            }
            $imp['status'] = 'approved';
            $imp['decided_by'] = $user['name'];
            $imp['session_ids'] = array_column($created, 'id');
            $this->db->prepare("UPDATE timetable_imports SET status='approved', decided_by=?, session_ids=?, plan=? WHERE id=?")
                ->execute([$user['name'], json_encode($imp['session_ids']), $this->enc($imp['plan']), $imp['id']]);
            $this->db->exec('COMMIT'); $done = true;
        } catch (\Throwable $e) {
            if (!$done) $this->db->exec('ROLLBACK');
            throw $e;
        }
        foreach ($created as $c) $this->audit->log($user['email'], 'session_created', $c['id'], ['period' => $c['period'], 'venue' => $c['venue'], 'from' => 'timetable', 'import' => $imp['id']]);
        $this->audit->log($user['email'], 'timetable_approved', null, ['import' => $imp['id'], 'sessions' => count($created)]);
        return ['import' => $this->impOut($imp), 'created' => count($created)];
    }

    public function reject(array $user, int $id, array $body): array
    {
        $this->reset();
        if (($user['role'] ?? '') !== 'exam_director') throw new HttpError(403, 'Only the exam director can reject a timetable.');
        $imp = $this->getImp($user, $id);
        if (!in_array($imp['status'], ['draft', 'pending'], true)) throw new HttpError(409, 'Already decided.');
        $note = $this->str($body, 'note', 200);
        $this->db->prepare("UPDATE timetable_imports SET status='rejected', decided_by=?, note=? WHERE id=?")->execute([$user['name'], $note, $imp['id']]);
        $imp['status'] = 'rejected'; $imp['decided_by'] = $user['name']; $imp['note'] = $note;
        $this->audit->log($user['email'], 'timetable_rejected', null, ['import' => $imp['id'], 'note' => $note]);
        return ['import' => $this->impOut($imp)];
    }

    // ================================================================ access, storage, output

    private function reset(): void { $this->staffCache = $this->unitCache = $this->existing = null; $this->regCache = []; }

    private function needTT(array $user): void
    {
        if (!in_array($user['role'] ?? '', ['exam_director', 'coordinator'], true)) throw new HttpError(403, 'Only the exam director or a school exam coordinator can manage the timetable.');
    }

    private function getImp(array $user, int $id): array
    {
        $st = $this->db->prepare('SELECT * FROM timetable_imports WHERE id=?');
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) throw new HttpError(404, 'Timetable upload not found.');
        $i = $this->hydrate($r);
        if (($user['role'] ?? '') === 'coordinator' && $i['by'] !== ($user['email'] ?? null)) throw new HttpError(403, 'That upload belongs to another coordinator.');
        return $i;
    }

    private function hydrate(array $r): array
    {
        return [
            'id' => (int)$r['id'], 'by' => $r['by_email'], 'by_name' => $r['by_name'], 'school' => $r['school'], 'period' => $r['period'],
            'ratio' => $this->tidyNum((float)$r['ratio']), 'late_after' => $this->tidyNum((float)$r['late_after']), 'csv' => $r['csv'],
            'status' => $r['status'], 'at' => $r['created_at'], 'plan' => json_decode($r['plan'], true, 512, JSON_THROW_ON_ERROR),
            'decided_by' => $r['decided_by'], 'note' => $r['note'] ?? '', 'session_ids' => json_decode($r['session_ids'] ?: '[]', true) ?: [],
        ];
    }

    private function enc(array $plan): string
    {
        return json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    private function savePlan(array $imp): void
    {
        $this->db->prepare('UPDATE timetable_imports SET plan=? WHERE id=?')->execute([$this->enc($imp['plan']), $imp['id']]);
    }

    private function impOut(array $i, bool $full = false): array
    {
        $base = ['id' => $i['id'], 'by' => $i['by_name'], 'school' => $i['school'], 'period' => $i['period'], 'status' => $i['status'], 'at' => $i['at'],
            'sessions' => count($i['plan']['sessions']), 'errors' => count($i['plan']['errors']), 'decided_by' => $i['decided_by'] ?: null,
            'note' => $i['note'] ?: '', 'session_ids' => $i['session_ids'] ?: []];
        if (!$full) return $base;
        $byEmail = [];
        foreach ($this->staff() as $u) $byEmail[$u['email']] = $u;
        $name = static fn(string $e): string => $byEmail[$e]['name'] ?? $e;
        $workload = [];
        $sessions = [];
        foreach ($i['plan']['sessions'] as $s) {
            foreach (array_filter(array_merge([$s['chief']], $s['invs'])) as $e) $workload[$e] = ($workload[$e] ?? 0) + 1;
            $s['chief_name'] = $s['chief'] ? $name($s['chief']) : null;
            $s['inv_names'] = array_map($name, $s['invs']);
            $sessions[] = $s;
        }
        $wl = [];
        foreach ($workload as $e => $n) $wl[] = ['email' => (string)$e, 'name' => $name((string)$e), 'sittings' => $n];
        usort($wl, static fn($a, $b) => $b['sittings'] <=> $a['sittings']);
        $plan = $i['plan'];
        $plan['sessions'] = $sessions;
        $plan['workload'] = $wl;
        $pool = [];
        foreach ($this->pool() as $u) $pool[] = ['email' => $u['email'], 'name' => $u['name'], 'role' => $u['role'], 'school' => $u['school']];
        return $base + ['ratio' => $i['ratio'], 'late_after' => $i['late_after'], 'csv' => $i['csv'], 'plan' => $plan, 'pool' => $pool];
    }

    // ================================================================ portal data

    /** @return list<array{email:string,name:string,role:string,school:string,teaches:list<string>}> */
    private function staff(): array
    {
        if ($this->staffCache === null) {
            $this->staffCache = [];
            foreach ($this->portal->listStaff() as $u) {
                $this->staffCache[] = ['email' => strtolower($u['email']), 'name' => $u['name'], 'role' => $u['role'], 'school' => $u['school'] ?? '', 'teaches' => $u['teaches'] ?? []];
            }
        }
        return $this->staffCache;
    }

    private function pool(): array
    {
        return array_values(array_filter($this->staff(), static fn($u) => in_array($u['role'], ['supervisor', 'invigilator'], true)));
    }

    /** @return array<string,array{code:string,name:string,school:string}> keyed by code */
    private function units(): array
    {
        if ($this->unitCache === null) {
            $this->unitCache = [];
            foreach ($this->portal->listUnits() as $u) $this->unitCache[$u['code']] = $u;
        }
        return $this->unitCache;
    }

    private function schoolOf(string $code): string { return $this->units()[$code]['school'] ?? 'Other'; }

    private function regCount(string $code): int
    {
        return $this->regCache[$code] ??= count($this->portal->registeredStudents($code));
    }

    // ================================================================ helpers

    private function trim(string $s): string
    {
        return preg_replace('/^[\s\x{00A0}\x{FEFF}]+|[\s\x{00A0}\x{FEFF}]+$/u', '', $s) ?? trim($s);
    }

    private function collapse(string $s): string
    {
        return preg_replace('/\s+/u', ' ', $s) ?? preg_replace('/\s+/', ' ', $s);
    }

    private function norm(string $s): string { return preg_replace('/[^A-Z0-9]/', '', strtoupper($s)) ?? ''; }

    /** JS localeCompare stand-in: case-insensitive, lower case before upper on ties. */
    private function lc(string $a, string $b): int
    {
        return strcasecmp($a, $b) ?: -strcmp($a, $b);
    }

    private function str(array $b, string $k, int $max): string
    {
        $v = $b[$k] ?? null;
        if ($v === null) $v = '';
        if (!is_string($v)) throw new HttpError(422, 'Invalid ' . $k . '.');
        $t = $this->collapse($this->trim($v));
        if (mb_strlen($t) > $max) throw new HttpError(422, $k . ' is too long.');
        return $t;
    }

    /** JS Number(x). */
    private function num(mixed $v): float
    {
        if ($v === null) return 0.0;
        if (is_bool($v)) return $v ? 1.0 : 0.0;
        if (is_int($v) || is_float($v)) return (float)$v;
        if (is_string($v)) { $t = $this->trim($v); return $t === '' ? 0.0 : (is_numeric($t) ? (float)$t : NAN); }
        return NAN;
    }

    /** Number(x) || fallback */
    private function numOr(mixed $v, float $fallback): float
    {
        $n = $this->num($v);
        return (is_nan($n) || $n == 0.0) ? $fallback : $n;
    }

    private function tidyNum(float $n): int|float { return floor($n) == $n && abs($n) < 1e15 ? (int)$n : $n; }

    private function iso(int $ts): string { return gmdate('Y-m-d\TH:i:s\Z', $ts); }

    /** Timetable wall-clock time (Africa/Nairobi, +03:00) to a Unix timestamp. */
    private function iv(string $date, string $hm): int
    {
        [$y, $mo, $d] = array_map('intval', explode('-', $date));
        [$h, $mi] = array_map('intval', explode(':', $hm));
        return gmmktime($h, $mi, 0, $mo, $d, $y) - 3 * 3600;
    }

    // ================================================================ parsing

    /** @return list<array{cells:list<string>,line:int}> */
    private function parseCsv(string $text): array
    {
        $first = '';
        foreach (preg_split('/\r?\n/', $text) as $l) if ($this->trim($l) !== '') { $first = $l; break; }
        $delim = ','; $best = -1;
        foreach ([',', ';', "\t"] as $d) { $n = substr_count($first, $d) + 1; if ($n > $best) { $best = $n; $delim = $d; } }
        $rows = []; $row = []; $cell = ''; $q = false;
        $src = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        $len = strlen($src);
        $any = fn(array $r): bool => (bool)array_filter($r, fn($x) => $this->trim($x) !== '');
        for ($i = 0; $i < $len; $i++) {
            $c = $src[$i];
            if ($q) {
                if ($c === '"') { if ($i + 1 < $len && $src[$i + 1] === '"') { $cell .= '"'; $i++; } else $q = false; }
                else $cell .= $c;
            } elseif ($c === '"') $q = true;
            elseif ($c === $delim) { $row[] = $cell; $cell = ''; }
            elseif ($c === "\n" || $c === "\r") {
                if ($c === "\r" && $i + 1 < $len && $src[$i + 1] === "\n") $i++;
                $row[] = $cell;
                if ($any($row)) $rows[] = ['cells' => $row, 'line' => count($rows) + 1];
                $row = []; $cell = '';
            } else $cell .= $c;
        }
        $row[] = $cell;
        if ($any($row)) $rows[] = ['cells' => $row, 'line' => count($rows) + 1];
        return $rows;
    }

    private function nairobiYear(): int
    {
        return (int)(new \DateTimeImmutable('now', new \DateTimeZone('Africa/Nairobi')))->format('Y');
    }

    private function parseDate(string $v): ?string
    {
        $v = $this->collapse(mb_strtolower($this->trim($v)));
        $y = $mo = $d = 0;
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $v, $m)) { $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3]; }
        elseif (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})$/', $v, $m)) { $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3]; if ($y < 100) $y += 2000; }
        else {
            $w = preg_replace('/^(mon|tue|wed|thu|fri|sat|sun)[a-z]*,?\s*/', '', $v) ?? $v;
            if (!preg_match('/^(\d{1,2})(?:st|nd|rd|th)?\s*(?:of\s+)?([a-z]{3,9})\.?,?\s*(\d{4})?$/', $w, $m)) return null;
            $d = (int)$m[1];
            $mo = array_search(substr($m[2], 0, 3), self::MON, true);
            $mo = $mo === false ? 0 : $mo + 1;
            $y = ($m[3] ?? '') !== '' ? (int)$m[3] : $this->nairobiYear();
        }
        if (!$y || $mo < 1 || $mo > 12 || $d < 1 || $d > 31) return null;
        if (!checkdate($mo, $d, $y)) return null;
        return sprintf('%d-%02d-%02d', $y, $mo, $d);
    }

    private function parseTime(string $v): ?string
    {
        $v = preg_replace('/\s+/u', '', mb_strtolower($this->trim($v))) ?? '';
        if (!preg_match('/^(\d{1,2})(?:[:.h](\d{2}))?(am|pm|a\.m\.|p\.m\.)?$/', $v, $m)) return null;
        $h = (int)$m[1];
        $mi = ($m[2] ?? '') !== '' ? (int)$m[2] : 0;
        $ap = ($m[3] ?? '') !== '' ? $m[3][0] : '';
        if ($ap === 'p' && $h < 12) $h += 12;
        if ($ap === 'a' && $h === 12) $h = 0;
        if ($h > 23 || $mi > 59 || ($ap !== '' && (int)$m[1] > 12)) return null;
        return sprintf('%02d:%02d', $h, $mi);
    }

    /** @return list<string> */
    private function nameToks(string $s): array
    {
        $s = mb_strtolower($s);
        $s = preg_replace('/[^a-z\s.]/u', ' ', $s) ?? $s;
        $s = str_replace('.', ' ', $s);
        return array_values(array_filter(preg_split('/\s+/u', $s) ?: [], static fn($t) => $t !== '' && !in_array($t, self::TITLES, true)));
    }

    private function matchStaff(string $v): ?array
    {
        $v = $this->trim($v);
        if ($v === '') return null;
        if (str_contains($v, '@')) {
            foreach ($this->staff() as $u) if (strcasecmp($u['email'], $v) === 0) return $u;
            $f = $this->portal->findStaff($v); // e.g. a coordinator or the director named as lecturer
            return $f ? ['email' => strtolower($f['email']), 'name' => $f['name'], 'role' => $f['role'], 'school' => $f['school'] ?? '', 'teaches' => []] : null;
        }
        $q = $this->nameToks($v);
        if (!$q) return null;
        $hits = [];
        foreach ($this->staff() as $u) {
            $t = $this->nameToks($u['name']);
            $all = true;
            foreach ($q as $x) {
                if (mb_strlen($x) === 1) { $any = false; foreach ($t as $y) if (mb_substr($y, 0, 1) === $x) { $any = true; break; } if (!$any) { $all = false; break; } }
                elseif (!in_array($x, $t, true)) { $all = false; break; }
            }
            if ($all) $hits[] = $u;
        }
        return count($hits) === 1 ? $hits[0] : null;
    }

    private function unitLecturers(string $code, array $extra): array
    {
        $out = [];
        foreach ($this->staff() as $u) if (in_array($code, $u['teaches'], true)) $out[$u['email']] = true;
        foreach ($extra[$code] ?? [] as $e) $out[$e] = true;
        return array_map('strval', array_keys($out));
    }

    // ================================================================ plan building

    private function buildPlan(string $csv, array $opt): array
    {
        $rows = $this->parseCsv($csv);
        $errors = []; $tidy = []; $warns = []; $lecturers = [];
        if (count($rows) < 2) {
            $errors[] = ['line' => 0, 'msg' => 'The file has no timetable rows. The first line must be the column headings.'];
            return ['rows' => 0, 'errors' => $errors, 'tidy' => $tidy, 'warns' => $warns, 'sessions' => []];
        }
        $head = array_map(fn($c) => preg_replace('/[^a-z]/', '', strtolower($c)) ?? '', $rows[0]['cells']);
        $col = [];
        foreach (self::HDR as $k => $aliases) {
            $col[$k] = -1;
            foreach ($head as $idx => $h) if (in_array($h, $aliases, true)) { $col[$k] = $idx; break; }
        }
        $miss = array_values(array_filter(['unit', 'date', 'start', 'end', 'venue'], fn($k) => $col[$k] < 0));
        if ($miss) {
            $errors[] = ['line' => 1, 'msg' => 'Missing column(s): ' . implode(', ', $miss) . '. Needed: Unit, Date, Start, End, Venue (Lecturer and Candidates are optional).'];
            return ['rows' => 0, 'errors' => $errors, 'tidy' => $tidy, 'warns' => $warns, 'sessions' => []];
        }
        $codes = array_keys($this->units());
        $items = [];
        foreach (array_slice($rows, 1) as $r) {
            $g = fn(string $k): string => $col[$k] < 0 ? '' : $this->trim((string)($r['cells'][$col[$k]] ?? ''));
            $line = $r['line'];
            $ok = true;
            $bad = function (string $field, string $found, string $msg, ?string $suggest = null) use (&$ok, &$errors, $line): void {
                $ok = false;
                $errors[] = ['line' => $line, 'field' => $field, 'found' => $found, 'msg' => $msg, 'suggest' => $suggest];
            };
            $rawU = $g('unit'); $key = $this->norm($rawU); $unit = null;
            foreach ($codes as $c) if ($this->norm($c) === $key) { $unit = $c; break; }
            if ($unit !== null) {
                if ($rawU !== $unit) $tidy[] = "Line $line: unit \"$rawU\" written as $unit";
            } else {
                $best = array_map(fn($c) => [$c, levenshtein($this->norm($c), $key)], $codes);
                usort($best, static fn($a, $b) => $a[1] <=> $b[1]);
                $sg = ($best && $best[0][1] <= 2 && (!isset($best[1]) || $best[1][1] > $best[0][1])) ? $best[0][0] : null;
                $bad('unit', $rawU, $rawU !== '' ? "\"$rawU\" is not a unit on the portal." . ($sg ? " Did you mean $sg?" : '') : 'Unit is empty.', $sg);
            }
            if ($unit !== null && ($opt['school'] ?? '') && $opt['school'] !== 'All schools' && $this->schoolOf($unit) !== $opt['school']) {
                $bad('unit', $rawU, "$unit belongs to " . $this->schoolOf($unit) . ", not {$opt['school']}. Only that school's coordinator can upload it.");
            }
            $rawD = $g('date'); $date = $this->parseDate($rawD);
            if (!$date) $bad('date', $rawD, "Date \"$rawD\" is not understood. Use for example 03/11/2026 or 3 Nov 2026.");
            elseif ($rawD !== $date) $tidy[] = "Line $line: date \"$rawD\" read as $date";
            $rawS = $g('start'); $rawE = $g('end'); $st = $this->parseTime($rawS); $en = $this->parseTime($rawE);
            if (!$st) $bad('start', $rawS, "Start time \"$rawS\" is not understood. Use for example 09:00 or 2pm.");
            if (!$en) $bad('end', $rawE, "End time \"$rawE\" is not understood. Use for example 12:00 or 5pm.");
            if ($st && $en && $date && $this->iv($date, $en) <= $this->iv($date, $st)) $bad('end', $rawE, 'End time must be after the start time.');
            $venue = $this->collapse($g('venue'));
            if ($venue === '') $bad('venue', '', 'Venue is empty.');
            $cand = $g('candidates');
            $cand = $cand === '' ? null : (int)preg_replace('/[^0-9]/', '', $cand);
            if ($ok) {
                $pv = preg_replace_callback('/\b\w/', static fn($m) => strtoupper($m[0]), $venue) ?? $venue;
                $pv = preg_replace_callback('/\b(\d+)([a-z])\b/', static fn($m) => $m[1] . strtoupper($m[2]), $pv) ?? $pv;
                $it = ['line' => $line, 'unit' => $unit, 'date' => $date, 'start' => $st, 'end' => $en, 'venue' => $pv, 'vkey' => $this->norm($venue), 'cand' => $cand];
                if ($pv !== $venue) $tidy[] = "Line $line: venue \"$venue\" written as $pv";
                $lv = $g('lecturer');
                if ($lv !== '') foreach (preg_split('/[;\/]/', $lv) as $part) {
                    $u = $this->matchStaff($part);
                    $p = $this->trim($part);
                    if ($u) {
                        $lecturers[$unit][] = $u['email'];
                        if ($p !== $u['name']) $tidy[] = "Line $line: lecturer \"$p\" matched to {$u['name']}";
                    } else $warns[] = "Line $line: lecturer \"$p\" is not on the portal staff list, so the own-paper rule cannot be applied for them.";
                }
                $items[] = $it;
            }
        }
        $byKey = [];
        foreach ($items as $it) {
            $k = implode('|', [$it['date'], $it['start'], $it['end'], $it['vkey']]);
            $byKey[$k] ??= ['key' => $k, 'date' => $it['date'], 'start' => $it['start'], 'end' => $it['end'], 'venue' => $it['venue'], 'vkey' => $it['vkey'], 'units' => [], 'candidates' => 0, 'lines' => []];
            $s = &$byKey[$k];
            if (in_array($it['unit'], $s['units'], true)) { $warns[] = "Line {$it['line']}: {$it['unit']} appears twice for the same sitting; counted once."; unset($s); continue; }
            $s['units'][] = $it['unit']; $s['lines'][] = $it['line'];
            $s['candidates'] += $it['cand'] ?? $this->regCount($it['unit']);
            unset($s);
        }
        $sessions = array_values($byKey);
        usort($sessions, fn($a, $b) => strcmp($a['date'], $b['date']) ?: strcmp($a['start'], $b['start']) ?: $this->lc($a['venue'], $b['venue']));
        foreach ($sessions as &$s) {
            $s['opens_at'] = $this->iso($this->iv($s['date'], $s['start']));
            $s['closes_at'] = $this->iso($this->iv($s['date'], $s['end']));
            $cnt = [];
            foreach ($s['units'] as $u) { $sc = $this->schoolOf($u); $cnt[$sc] = ($cnt[$sc] ?? 0) + 1; }
            $top = null;
            foreach ($cnt as $sc => $n) if ($top === null || $n > $cnt[$top]) $top = (string)$sc;
            $s['school'] = $top;
            $s['need'] = max(1, (int)ceil($s['candidates'] / $opt['ratio']));
            $s['lect'] = [];
            foreach ($s['units'] as $u) $s['lect'][$u] = $this->unitLecturers($u, $lecturers);
        }
        unset($s);
        $n = count($sessions);
        for ($a = 0; $a < $n; $a++) for ($b = $a + 1; $b < $n; $b++) {
            $x = $sessions[$a]; $y = $sessions[$b];
            if ($x['vkey'] === $y['vkey'] && $x['opens_at'] < $y['closes_at'] && $y['opens_at'] < $x['closes_at']) {
                $errors[] = ['line' => $x['lines'][0], 'msg' => "{$x['venue']} is double-booked: " . implode(', ', $x['units']) . " ({$x['start']}-{$x['end']}) overlaps " . implode(', ', $y['units']) . " ({$y['start']}-{$y['end']}) on {$x['date']}."];
            }
        }
        foreach ($sessions as $s) foreach ($this->existingSessions() as $o) {
            if ($this->norm($o['venue']) === $s['vkey'] && $o['open'] < strtotime($s['closes_at']) && strtotime($s['opens_at']) < $o['close']) {
                $errors[] = ['line' => $s['lines'][0], 'msg' => "{$s['venue']} on {$s['date']} {$s['start']}-{$s['end']} clashes with an existing session already loaded (" . implode(', ', $o['units']) . ').'];
            }
        }
        $this->assignAll($sessions, $opt);
        return ['rows' => count($items), 'errors' => $errors, 'tidy' => $tidy, 'warns' => $warns, 'sessions' => $sessions];
    }

    // ================================================================ assignment

    /** Active sessions already in the database, with everyone who works them. */
    private function existingSessions(): array
    {
        if ($this->existing === null) {
            $this->existing = [];
            $acc = [];
            foreach ($this->db->query('SELECT session_id, staff_email FROM session_access WHERE revoked_at IS NULL') as $a) $acc[(int)$a['session_id']][] = strtolower($a['staff_email']);
            foreach ($this->db->query("SELECT id, venue, opens_at, closes_at, supervisor_email, units FROM exam_sessions WHERE status='active'") as $o) {
                $units = json_decode((string)$o['units'], true);
                $this->existing[] = ['venue' => $o['venue'], 'open' => (int)strtotime($o['opens_at']), 'close' => (int)strtotime($o['closes_at']), 'units' => is_array($units) ? $units : [],
                    'emails' => array_merge([strtolower($o['supervisor_email'])], $acc[(int)$o['id']] ?? [])];
            }
        }
        return $this->existing;
    }

    /** @return list<array{0:int,1:int,2:string}> [start minus buffer, end, venue] */
    private function busyOf(string $email, array $sessions, ?int $skip): array
    {
        $out = [];
        foreach ($this->existingSessions() as $o) if (in_array($email, $o['emails'], true)) $out[] = [$o['open'] - self::BUF, $o['close'], $o['venue']];
        foreach ($sessions as $k => $s) {
            if ($k !== $skip && (($s['chief'] ?? null) === $email || in_array($email, $s['invs'] ?? [], true))) $out[] = [strtotime($s['opens_at']) - self::BUF, strtotime($s['closes_at']), $s['venue']];
        }
        return $out;
    }

    private function isFree(string $email, int $k, array $sessions): bool
    {
        $s = $sessions[$k];
        $close = strtotime($s['closes_at']); $open = strtotime($s['opens_at']);
        foreach ($this->busyOf($email, $sessions, $k) as [$a, $b]) if ($a < $close && $open - self::BUF < $b) return false;
        return true;
    }

    private function teachesHere(string $email, array $s): bool
    {
        foreach ($s['units'] as $u) if (in_array($email, $s['lect'][$u] ?? [], true)) return true;
        return false;
    }

    private function loadOf(string $email, array $sessions): int { return count($this->busyOf($email, $sessions, null)); }

    private function assignAll(array &$sessions, array $opt): void
    {
        foreach ($sessions as &$s) { $s['chief'] = null; $s['invs'] = []; $s['notes'] = []; }
        unset($s);
        $order = array_keys($sessions);
        usort($order, fn($a, $b) => strcmp($sessions[$a]['opens_at'], $sessions[$b]['opens_at']) ?: $sessions[$b]['candidates'] <=> $sessions[$a]['candidates']);
        $pool = $this->pool();
        foreach ($order as $k) {
            // Closures read $sessions[$k] fresh each call: the chief and invigilators chosen so far change who is eligible.
            $score = function (array $u, float $extra = 0) use (&$sessions, $k): float {
                return $this->loadOf($u['email'], $sessions) + ($u['school'] === $sessions[$k]['school'] ? 0 : 1.5) + $extra;
            };
            $ok = function (array $u) use (&$sessions, $k): bool {
                $s = $sessions[$k];
                return $this->isFree($u['email'], $k, $sessions) && !$this->teachesHere($u['email'], $s) && $u['email'] !== $s['chief'] && !in_array($u['email'], $s['invs'], true);
            };
            $rank = function (array $cands, callable $sc): array {
                $scored = array_map(fn($u) => [$u, $sc($u)], array_values($cands));
                usort($scored, fn($a, $b) => ($a[1] <=> $b[1]) ?: $this->lc($a[0]['name'], $b[0]['name']));
                return array_map(fn($x) => $x[0], $scored);
            };
            $chiefs = $rank(array_filter($pool, fn($u) => $u['role'] === 'supervisor' && $ok($u)), $score);
            if ($chiefs) {
                $c = $chiefs[0];
                $ld = $this->loadOf($c['email'], $sessions);
                $sessions[$k]['chief'] = $c['email'];
                $sessions[$k]['notes'][] = "Chief: {$c['name']}, free at this time, $ld other sitting" . ($ld === 1 ? '' : 's') . ' so far' . ($c['school'] === $sessions[$k]['school'] ? ', same school' : '') . '.';
            }
            for ($i = 0; $i < $sessions[$k]['need']; $i++) {
                $cands = $rank(array_filter($pool, $ok), fn($u) => $score($u, $u['role'] === 'supervisor' ? .25 : 0));
                if (!$cands) break;
                $sessions[$k]['invs'][] = $cands[0]['email'];
            }
        }
        foreach (array_keys($sessions) as $k) $this->validateSession($sessions, $k);
    }

    /** Recomputes problems for sitting $k (stored on it) and returns them. */
    private function validateSession(array &$sessions, int|string $k): array
    {
        $s = $sessions[$k];
        $problems = []; $seen = [];
        $byEmail = [];
        foreach ($this->staff() as $u) $byEmail[$u['email']] = $u;
        $chk = function (?string $email, string $label) use (&$problems, &$seen, $byEmail, $s, $k, &$sessions): void {
            if (!$email) return;
            $u = $byEmail[$email] ?? null;
            if (!$u) { $problems[] = "$label: unknown staff account."; return; }
            if (isset($seen[$email])) $problems[] = "{$u['name']} is listed twice.";
            $seen[$email] = true;
            if (!$this->isFree($email, (int)$k, $sessions)) $problems[] = "{$u['name']} is already assigned to another sitting at this time.";
            if ($this->teachesHere($email, $s)) $problems[] = "{$u['name']} teaches a paper in this session and cannot invigilate it.";
        };
        if (!$s['chief']) $problems[] = 'No chief supervisor is free for this sitting. Choose one manually.';
        else {
            $u = $byEmail[$s['chief']] ?? null;
            if (!$u || $u['role'] !== 'supervisor') $problems[] = 'The chief must be a chief-supervisor account.';
            $chk($s['chief'], 'Chief');
        }
        foreach ($s['invs'] as $e) $chk($e, 'Invigilator');
        if (count($s['invs']) < $s['need']) $problems[] = "Needs {$s['need']} invigilator(s) for {$s['candidates']} candidates; " . count($s['invs']) . ' assigned.';
        $sessions[$k]['problems'] = $problems;
        return $problems;
    }
}
