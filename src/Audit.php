<?php
declare(strict_types=1);
namespace Kca;

/** Append-only, HMAC-chained log. Editing or removing any row breaks every later hash. */
final class Audit
{
    private const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(private \PDO $db) {}

    public function log(string $actor, string $action, ?int $sessionId, array $payload = []): void
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $prev = $this->db->query('SELECT hash FROM audit_log ORDER BY id DESC LIMIT 1')->fetchColumn() ?: self::GENESIS;
            $ts = Util::now();
            $json = Util::canonical($payload);
            $hash = $this->hash($prev, $ts, $actor, $action, $sessionId, $json);
            $st = $this->db->prepare('INSERT INTO audit_log(ts,actor,action,session_id,payload,prev_hash,hash) VALUES(?,?,?,?,?,?,?)');
            $st->execute([$ts, $actor, $action, $sessionId, $json, $prev, $hash]);
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }

    /** @return array{ok:bool,entries:int,broken_at:?int} */
    public function verify(): array
    {
        $prev = self::GENESIS;
        $n = 0;
        foreach ($this->db->query('SELECT * FROM audit_log ORDER BY id') as $r) {
            $n++;
            $expect = $this->hash($prev, $r['ts'], $r['actor'], $r['action'], $r['session_id'] === null ? null : (int)$r['session_id'], $r['payload']);
            if ($r['prev_hash'] !== $prev || !hash_equals($expect, $r['hash'])) return ['ok' => false, 'entries' => $n, 'broken_at' => (int)$r['id']];
            $prev = $r['hash'];
        }
        return ['ok' => true, 'entries' => $n, 'broken_at' => null];
    }

    private function hash(string $prev, string $ts, string $actor, string $action, ?int $sid, string $json): string
    {
        return hash_hmac('sha256', implode('|', [$prev, $ts, $actor, $action, (string)$sid, $json]), Config::secret());
    }
}
