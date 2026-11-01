<?php
declare(strict_types=1);
namespace Kca;

final class Db
{
    public static function connect(): \PDO
    {
        $path = Config::get('DB_PATH', Config::storage() . '/esign.sqlite');
        $new = !is_file($path);
        $pdo = new \PDO('sqlite:' . $path, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        if ($new) @chmod($path, 0600);
        $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;');
        self::migrate($pdo);
        return $pdo;
    }

    /** Add a column to a table created by an earlier version (SQLite has no IF NOT EXISTS for columns). */
    public static function addColumn(\PDO $db, string $table, string $col, string $ddl): void
    {
        foreach ($db->query("PRAGMA table_info($table)") as $c) if ($c['name'] === $col) return;
        $db->exec("ALTER TABLE $table ADD COLUMN $col $ddl");
    }

    public static function migrate(\PDO $db): void
    {
        $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS exam_sessions(
  id INTEGER PRIMARY KEY, code TEXT UNIQUE NOT NULL, period TEXT NOT NULL, venue TEXT NOT NULL,
  opens_at TEXT NOT NULL, closes_at TEXT NOT NULL, supervisor_email TEXT NOT NULL, supervisor_name TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'active', created_at TEXT NOT NULL,
  units TEXT NOT NULL DEFAULT '[]', late_after INTEGER NOT NULL DEFAULT 30, offline INTEGER NOT NULL DEFAULT 0,
  source TEXT NOT NULL DEFAULT 'timetable', import_id INTEGER);
CREATE TABLE IF NOT EXISTS session_access(
  id INTEGER PRIMARY KEY, session_id INTEGER NOT NULL REFERENCES exam_sessions(id),
  staff_email TEXT NOT NULL, staff_name TEXT NOT NULL, granted_by TEXT NOT NULL, granted_at TEXT NOT NULL,
  revoked_at TEXT, UNIQUE(session_id, staff_email));
CREATE TABLE IF NOT EXISTS signatures(
  id INTEGER PRIMARY KEY, session_id INTEGER NOT NULL REFERENCES exam_sessions(id),
  adm_no TEXT NOT NULL, unit_code TEXT NOT NULL, unit_name TEXT NOT NULL, full_name TEXT NOT NULL,
  phone TEXT NOT NULL, booklet_no TEXT NOT NULL, email TEXT NOT NULL, signature_png BLOB NOT NULL,
  signed_at TEXT NOT NULL, updated_at TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1, captured_by TEXT NOT NULL,
  email_status TEXT NOT NULL DEFAULT 'pending', email_sent_at TEXT, receipt_code TEXT, reopened_until TEXT,
  UNIQUE(session_id, adm_no, unit_code));
CREATE TABLE IF NOT EXISTS signature_history(
  id INTEGER PRIMARY KEY, signature_id INTEGER NOT NULL, version INTEGER NOT NULL,
  snapshot TEXT NOT NULL, changed_by TEXT NOT NULL, changed_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS reopen_requests(
  id INTEGER PRIMARY KEY, signature_id INTEGER NOT NULL REFERENCES signatures(id), requested_by TEXT NOT NULL,
  reason TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending', requested_at TEXT NOT NULL,
  resolved_by TEXT, resolved_at TEXT);
CREATE TABLE IF NOT EXISTS documents(
  id INTEGER PRIMARY KEY, code TEXT UNIQUE NOT NULL, kind TEXT NOT NULL, session_id INTEGER, signature_id INTEGER,
  unit_code TEXT, content_hash TEXT NOT NULL, payload_hash TEXT NOT NULL, rsa_signature TEXT NOT NULL,
  file_sha256 TEXT NOT NULL, created_at TEXT NOT NULL, payload TEXT NOT NULL);
CREATE INDEX IF NOT EXISTS idx_docs_file ON documents(file_sha256);
CREATE TABLE IF NOT EXISTS audit_log(
  id INTEGER PRIMARY KEY, ts TEXT NOT NULL, actor TEXT NOT NULL, action TEXT NOT NULL, session_id INTEGER,
  payload TEXT NOT NULL, prev_hash TEXT NOT NULL, hash TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS login_attempts(id INTEGER PRIMARY KEY, k TEXT NOT NULL, ts INTEGER NOT NULL);
CREATE INDEX IF NOT EXISTS idx_login_k ON login_attempts(k, ts);

CREATE TRIGGER IF NOT EXISTS audit_no_update BEFORE UPDATE ON audit_log BEGIN SELECT RAISE(ABORT,'audit_log is append-only'); END;
CREATE TRIGGER IF NOT EXISTS audit_no_delete BEFORE DELETE ON audit_log BEGIN SELECT RAISE(ABORT,'audit_log is append-only'); END;
CREATE TRIGGER IF NOT EXISTS hist_no_update BEFORE UPDATE ON signature_history BEGIN SELECT RAISE(ABORT,'history is append-only'); END;
CREATE TRIGGER IF NOT EXISTS hist_no_delete BEFORE DELETE ON signature_history BEGIN SELECT RAISE(ABORT,'history is append-only'); END;
CREATE TRIGGER IF NOT EXISTS doc_no_update BEFORE UPDATE ON documents BEGIN SELECT RAISE(ABORT,'documents are immutable'); END;
CREATE TRIGGER IF NOT EXISTS doc_no_delete BEFORE DELETE ON documents BEGIN SELECT RAISE(ABORT,'documents are immutable'); END;
SQL);
        // Columns added after the first release: upgrade existing databases in place.
        foreach (['units' => "TEXT NOT NULL DEFAULT '[]'", 'late_after' => 'INTEGER NOT NULL DEFAULT 30', 'offline' => 'INTEGER NOT NULL DEFAULT 0',
                  'source' => "TEXT NOT NULL DEFAULT 'timetable'", 'import_id' => 'INTEGER'] as $c => $ddl) self::addColumn($db, 'exam_sessions', $c, $ddl);
        // Feature tables (incidents, flags, seal-key use, director profile, search lockouts, timetable uploads) are created by their owners.
        Features::migrate($db);
        Timetable::migrate($db);
    }
}
