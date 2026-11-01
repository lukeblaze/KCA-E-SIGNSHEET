<?php
declare(strict_types=1);
namespace Kca;

use Kca\Portal\PortalAdapter;

/**
 * Schema and shared helpers for the features added after the first release: units per session, identity checks,
 * incidents and flags, roll call and exits, offline queueing, seal-key custody, the director's signature and
 * the student-search lockout.
 */
final class Features
{
    /** How the invigilator identified the student. */
    public const IDENT = [
        'card_id'    => 'Exam card and student ID checked',
        'card_only'  => 'Exam card checked, no student ID',
        'photo_only' => 'Photo match only (no exam card or ID)',
    ];
    public const STATUSES = [
        'open' => 'Open', 'resolved_fixed' => 'Resolved: issue fixed', 'let_go' => 'Noted: no action taken',
        'resit_required' => 'Re-sit required', 'referred' => 'Referred to disciplinary committee',
    ];
    public const CATS = ['Suspected malpractice', 'Unauthorised material', 'Disruption', 'Illness or emergency', 'Other'];

    public const LOCK_MISSES = 5;
    public const LOCK_WINDOW = 120; // seconds

    private static array $regCache = [];

    public static function migrate(\PDO $db): void
    {
        $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS incidents(
  id INTEGER PRIMARY KEY, case_no TEXT UNIQUE NOT NULL, session_id INTEGER NOT NULL REFERENCES exam_sessions(id),
  adm_no TEXT NOT NULL, full_name TEXT NOT NULL, unit_code TEXT NOT NULL, category TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'open', opened_by TEXT NOT NULL, opened_by_email TEXT NOT NULL, opened_at TEXT NOT NULL,
  resit_session_id INTEGER);
CREATE INDEX IF NOT EXISTS idx_inc_sess ON incidents(session_id, unit_code);
CREATE TABLE IF NOT EXISTS incident_entries(
  id INTEGER PRIMARY KEY, incident_id INTEGER NOT NULL REFERENCES incidents(id), ts TEXT NOT NULL,
  by_name TEXT NOT NULL, by_email TEXT NOT NULL, text TEXT NOT NULL, status TEXT NOT NULL);
CREATE INDEX IF NOT EXISTS idx_incent ON incident_entries(incident_id);
CREATE TABLE IF NOT EXISTS flags(
  id INTEGER PRIMARY KEY, session_id INTEGER NOT NULL REFERENCES exam_sessions(id), adm_no TEXT NOT NULL, full_name TEXT NOT NULL,
  unit_code TEXT NOT NULL, text TEXT NOT NULL, by_email TEXT NOT NULL, at TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'open');
CREATE TABLE IF NOT EXISTS key_uses(
  id INTEGER PRIMARY KEY, ts TEXT NOT NULL, doc_code TEXT NOT NULL, purpose TEXT NOT NULL, by_email TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS director_profile(
  id INTEGER PRIMARY KEY CHECK(id = 1), name TEXT NOT NULL, signature_png BLOB, signature_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS lookup_misses(id INTEGER PRIMARY KEY, k TEXT NOT NULL, ts INTEGER NOT NULL);
CREATE INDEX IF NOT EXISTS idx_miss_k ON lookup_misses(k, ts);

CREATE TRIGGER IF NOT EXISTS ient_no_update BEFORE UPDATE ON incident_entries BEGIN SELECT RAISE(ABORT,'incident notes are append-only'); END;
CREATE TRIGGER IF NOT EXISTS ient_no_delete BEFORE DELETE ON incident_entries BEGIN SELECT RAISE(ABORT,'incident notes are append-only'); END;
CREATE TRIGGER IF NOT EXISTS keyuse_no_update BEFORE UPDATE ON key_uses BEGIN SELECT RAISE(ABORT,'key use log is append-only'); END;
CREATE TRIGGER IF NOT EXISTS keyuse_no_delete BEFORE DELETE ON key_uses BEGIN SELECT RAISE(ABORT,'key use log is append-only'); END;
SQL);
        foreach ([
            'ident' => 'TEXT', 'ident_note' => "TEXT NOT NULL DEFAULT ''", 'review' => 'TEXT', 'reviewed_by' => 'TEXT',
            'offline' => 'INTEGER NOT NULL DEFAULT 0', 'queued_at' => 'TEXT', 'exit_at' => 'TEXT', 'exit_note' => "TEXT NOT NULL DEFAULT ''", 'resit_of' => 'TEXT',
        ] as $c => $ddl) Db::addColumn($db, 'signatures', $c, $ddl);
    }

    // ----------------------------------------------------------- sessions

    /** Papers sat in this session. A legacy session saved without units accepts every portal unit. */
    public static function unitsOf(array $s, PortalAdapter $portal): array
    {
        $u = json_decode((string)($s['units'] ?? '[]'), true);
        if (is_array($u) && $u) return array_values(array_map('strval', $u));
        return array_column($portal->listUnits(), 'code');
    }

    public static function lateCutoff(array $s): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$s['opens_at']) + max(1, (int)($s['late_after'] ?? 30)) * 60);
    }

    /** Students registered for a unit this period (cleared or not). Cached for the length of one request. */
    public static function registered(PortalAdapter $portal, string $code): array
    {
        return self::$regCache[$code] ??= $portal->registeredStudents($code);
    }

    public static function expected(PortalAdapter $portal, array $s): int
    {
        $n = 0;
        foreach (self::unitsOf($s, $portal) as $c) foreach (self::registered($portal, $c) as $st) if ($st['cleared']) $n++;
        return $n;
    }

    /**
     * Signed rows (full DB rows, alphabetical), registered students who have not signed (absent / not cleared),
     * and how many were registered.
     * @return array{rows:list<array>,absent:list<array>,registered:int}
     */
    public static function unitReport(\PDO $db, PortalAdapter $portal, array $s, string $code): array
    {
        $st = $db->prepare('SELECT * FROM signatures WHERE session_id=? AND unit_code=? ORDER BY full_name COLLATE NOCASE, adm_no');
        $st->execute([$s['id'], $code]);
        $rows = $st->fetchAll();
        $signed = [];
        foreach ($rows as $r) $signed[Util::normAdm($r['adm_no'])] = true;
        $reg = self::registered($portal, $code);
        $absent = [];
        foreach ($reg as $x) {
            if (isset($signed[Util::normAdm($x['adm_no'])])) continue;
            $absent[] = ['adm_no' => $x['adm_no'], 'full_name' => $x['full_name'], 'state' => $x['cleared'] ? 'absent' : 'not_cleared',
                'reason' => $x['cleared'] ? null : ($x['reason'] ?: 'Not cleared')];
        }
        return ['rows' => $rows, 'absent' => $absent, 'registered' => count($reg)];
    }

    // ---------------------------------------------------------- incidents

    /** Cases with their full note history, in the shape the UI and the sealed sheet use. */
    public static function incidents(\PDO $db, int $sessionId, ?string $unit = null): array
    {
        $sql = 'SELECT * FROM incidents WHERE session_id=?' . ($unit !== null ? ' AND unit_code=?' : '') . ' ORDER BY id';
        $st = $db->prepare($sql);
        $st->execute($unit !== null ? [$sessionId, $unit] : [$sessionId]);
        $out = [];
        foreach ($st->fetchAll() as $i) {
            $e = $db->prepare('SELECT ts, by_name AS "by", text, status FROM incident_entries WHERE incident_id=? ORDER BY id');
            $e->execute([$i['id']]);
            $out[] = ['id' => (int)$i['id'], 'case_no' => $i['case_no'], 'sid' => (int)$i['session_id'], 'adm_no' => $i['adm_no'], 'full_name' => $i['full_name'],
                'unit' => $i['unit_code'], 'category' => $i['category'], 'status' => $i['status'], 'opened_by' => $i['opened_by'], 'opened_at' => $i['opened_at'],
                'entries' => $e->fetchAll(), 'resit_sid' => $i['resit_session_id'] === null ? null : (int)$i['resit_session_id']];
        }
        return $out;
    }

    // -------------------------------------------------- director / seal key

    public static function ensureDirector(\PDO $db, string $name): void
    {
        $db->prepare('INSERT INTO director_profile(id,name,signature_png,signature_at) VALUES(1,?,NULL,?) ON CONFLICT(id) DO UPDATE SET name=excluded.name')
            ->execute([$name, Util::now()]);
    }

    /** @return array{name:string,png:?string,at:?string} */
    public static function director(\PDO $db): array
    {
        $r = $db->query('SELECT name, signature_png, signature_at FROM director_profile WHERE id=1')->fetch();
        if (!$r) return ['name' => 'Exam Director', 'png' => null, 'at' => null];
        return ['name' => $r['name'], 'png' => $r['signature_png'] !== null ? (string)$r['signature_png'] : null, 'at' => $r['signature_png'] !== null ? $r['signature_at'] : null];
    }

    public static function keyUse(\PDO $db, string $doc, string $purpose, string $by): void
    {
        $db->prepare('INSERT INTO key_uses(ts,doc_code,purpose,by_email) VALUES(?,?,?,?)')->execute([Util::now(), $doc, $purpose, $by]);
    }

    // ----------------------------------------------------- search lockout

    public static function lockKey(string $email, int|string $sid): string { return strtolower($email) . ':' . $sid; }

    public static function missCount(\PDO $db, string $key): int
    {
        $db->prepare('DELETE FROM lookup_misses WHERE ts < ?')->execute([time() - self::LOCK_WINDOW]);
        $n = $db->prepare('SELECT COUNT(*) FROM lookup_misses WHERE k=? AND ts >= ?');
        $n->execute([$key, time() - self::LOCK_WINDOW]);
        return (int)$n->fetchColumn();
    }
}
