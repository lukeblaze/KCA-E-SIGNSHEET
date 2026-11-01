<?php
declare(strict_types=1);
namespace Kca;

/** Builds, seals, stores and verifies the PDFs (student receipts and per-unit sign sheets). */
final class Documents
{
    private const NAVY = '0B2A5B';
    private const GOLD = 'F2A900';
    private const INK = '1B2433';
    private const GREY = '5A6577';
    private const LINE = 'D5DBE6';
    private const PALE = 'F3F5F9';

    private \OpenSSLAsymmetricKey $priv;
    private string $pubPem;

    public function __construct(private \PDO $db)
    {
        $dir = Config::storage('keys');
        $priv = $dir . '/signing.pem';
        $pub = $dir . '/signing.pub.pem';
        if (!is_file($priv)) {
            $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            if (!$k) throw new \RuntimeException('Cannot create signing key: ' . openssl_error_string());
            openssl_pkey_export($k, $pem);
            file_put_contents($priv, $pem);
            @chmod($priv, 0600);
            file_put_contents($pub, openssl_pkey_get_details($k)['key']);
        }
        $this->priv = openssl_pkey_get_private((string)file_get_contents($priv));
        $this->pubPem = openssl_pkey_get_details($this->priv)['key'];
    }

    // ---------------------------------------------------------------- receipt

    /** @return array{code:string,path:string,bytes:string} */
    public function receipt(array $sig, array $session): array
    {
        $payload = [
            'kind' => 'receipt', 'session' => $session['code'], 'period' => $session['period'], 'venue' => $session['venue'],
            'unit_code' => $sig['unit_code'], 'unit_name' => $sig['unit_name'], 'adm_no' => $sig['adm_no'], 'full_name' => $sig['full_name'],
            'phone' => $sig['phone'], 'booklet_no' => $sig['booklet_no'], 'email' => $sig['email'], 'signed_at' => $sig['signed_at'],
            'updated_at' => $sig['updated_at'], 'version' => (int)$sig['version'], 'signature_sha256' => hash('sha256', $sig['signature_png']),
            'captured_by' => $sig['captured_by'],
        ];
        $seal = $this->seal($payload);

        $p = new Pdf();
        $p->addPage();
        $this->header($p, 'Examination Attendance Receipt');
        $p->text(48, 118, 'Examination attendance receipt', self::NAVY, true, 20);
        $p->text(48, 136, 'Electronic sign sheet record. Student copy, emailed to the student\'s KCA school email.', self::GREY, false, 9.5);

        $rows = [
            ['Student', $sig['full_name']], ['Admission number', $sig['adm_no']],
            ['Unit', $sig['unit_code'] . '  ' . $sig['unit_name']], ['Exam period', $session['period']],
            ['Venue', $session['venue']], ['Signed at', Util::eat($sig['signed_at'], 'd M Y, H:i:s') . ' EAT'],
            ['Booklet serial number', $sig['booklet_no']], ['Phone number', $sig['phone']],
            ['School email', $sig['email']], ['Record version', 'v' . $sig['version'] . ($sig['version'] > 1 ? '  (corrected copy)' : '')],
        ];
        $y = 168;
        foreach (array_chunk($rows, 2) as $pair) {
            foreach ($pair as $i => [$l, $v]) {
                $x = 48 + $i * 252;
                $p->text($x, $y, strtoupper($l), self::GREY, true, 7.5);
                $p->text($x, $y + 15, $p->fit($v, 236, false, 11), self::INK, false, 11);
            }
            $y += 44;
        }
        $p->line(48, $y - 12, 547, $y - 12, self::LINE, 0.7);

        $p->text(48, $y + 8, 'STUDENT SIGNATURE', self::GREY, true, 7.5);
        $p->rect(48, $y + 16, 499, 110, '#FFFFFF', self::LINE);
        $p->imageFit($sig['signature_png'], 60, $y + 22, 475, 98);
        $this->sealBlock($p, $y + 150, $seal, 'Keep this copy. If a school record is ever lost or disputed, this document and its verification code are proof of your attendance.');
        $this->footer($p);

        return $this->store($p, 'receipt', $payload, $seal, (int)$sig['session_id'], (int)$sig['id'], $sig['unit_code'], 'Receipt ' . $sig['adm_no'] . ' ' . $sig['unit_code']);
    }

    // ------------------------------------------------------------------ sheet

    /** @return array{code:string,path:string,bytes:string,entries:int,reused:bool} */
    public function sheet(array $session, string $unitCode): array
    {
        $st = $this->db->prepare('SELECT * FROM signatures WHERE session_id=? AND unit_code=? ORDER BY full_name COLLATE NOCASE, adm_no');
        $st->execute([$session['id'], $unitCode]);
        $rows = $st->fetchAll();
        if (!$rows) throw new HttpError(404, 'No signatures recorded for that unit yet.');

        $entries = array_map(static fn($r) => [
            'adm_no' => $r['adm_no'], 'full_name' => $r['full_name'], 'booklet_no' => $r['booklet_no'], 'phone' => $r['phone'],
            'signed_at' => $r['signed_at'], 'updated_at' => $r['updated_at'], 'version' => (int)$r['version'], 'signature_sha256' => hash('sha256', $r['signature_png']),
        ], $rows);
        $content = [
            'kind' => 'sheet', 'session' => $session['code'], 'period' => $session['period'], 'venue' => $session['venue'],
            'unit_code' => $unitCode, 'unit_name' => $rows[0]['unit_name'], 'supervisor' => $session['supervisor_name'], 'entries' => $entries,
        ];
        $contentHash = hash('sha256', Util::canonical($content));
        $ex = $this->db->prepare("SELECT * FROM documents WHERE kind='sheet' AND session_id=? AND unit_code=? AND content_hash=? ORDER BY id DESC LIMIT 1");
        $ex->execute([$session['id'], $unitCode, $contentHash]);
        if ($d = $ex->fetch()) {
            $f = Config::storage('docs') . '/' . $d['code'] . '.pdf';
            if (is_file($f)) return ['code' => $d['code'], 'path' => $f, 'bytes' => (string)file_get_contents($f), 'entries' => count($entries), 'reused' => true];
        }

        $seal = $this->seal($content);
        $p = new Pdf();
        $perFirst = 11; $perNext = 15;
        $pageRows = [array_slice($rows, 0, $perFirst)];
        for ($i = $perFirst; $i < count($rows); $i += $perNext) $pageRows[] = array_slice($rows, $i, $perNext);

        $cols = [['No.', 26, 'L'], ['Adm. no.', 62, 'L'], ['Name', 132, 'L'], ['Booklet no.', 66, 'L'], ['Phone', 78, 'L'], ['Time', 38, 'L'], ['Signature', 97, 'L']];
        $rowH = 44;
        $n = 0; $y = 0;
        foreach ($pageRows as $pi => $chunk) {
            $p->addPage();
            $this->header($p, 'Examination Attendance Sheet');
            if ($pi === 0) {
                $p->text(48, 112, $rows[0]['unit_code'] . '  ' . $rows[0]['unit_name'], self::NAVY, true, 17);
                $meta = [['Exam period', $session['period']], ['Venue', $session['venue']], ['Chief supervisor', $session['supervisor_name']], ['Students signed', (string)count($rows)]];
                foreach ($meta as $i => [$l, $v]) {
                    $x = 48 + ($i % 2) * 252; $yy = 140 + intdiv($i, 2) * 34;
                    $p->text($x, $yy, strtoupper($l), self::GREY, true, 7.5);
                    $p->text($x, $yy + 13, $p->fit($v, 236, false, 10.5), self::INK, false, 10.5);
                }
                $y = 216;
            } else {
                $y = 104;
            }
            $x = 48;
            $p->rect(48, $y, 499, 22, self::NAVY);
            foreach ($cols as [$h, $w]) { $p->text($x + 5, $y + 14.5, $h, 'FFFFFF', true, 8); $x += $w; }
            $y += 22;
            foreach ($chunk as $r) {
                $n++;
                $x = 48;
                $cells = [(string)$n, $r['adm_no'], $r['full_name'], $r['booklet_no'], $r['phone'], Util::eat($r['signed_at'], 'H:i')];
                foreach ($cells as $ci => $val) {
                    $p->text($x + 5, $y + $rowH / 2 + 3, $p->fit($val, $cols[$ci][1] - 8, false, 8.5), self::INK, false, 8.5);
                    $x += $cols[$ci][1];
                }
                $p->imageFit($r['signature_png'], $x + 3, $y + 3, $cols[6][1] - 6, $rowH - 6);
                $p->line(48, $y + $rowH, 547, $y + $rowH, self::LINE, 0.6);
                $y += $rowH;
            }
        }
        // seal block: needs ~150pt; start a new page if the last page is too full
        if ($y + 22 + 185 > 805) { $p->addPage(); $this->header($p, 'Examination Attendance Sheet'); $y = 104; }
        $this->sealBlock($p, $y + 22, $seal, 'This sheet was produced from the electronic sign sheet system. Any change after sealing, including a single character, invalidates the fingerprint and signature. Entries edited by students after signing are recorded as new versions in the audit log.', true);
        $this->footer($p);

        return $this->store($p, 'sheet', $content, $seal, (int)$session['id'], null, $unitCode, 'Sign sheet ' . $unitCode . ' ' . $session['period'], $contentHash, count($rows));
    }

    // ----------------------------------------------------------------- verify

    /** Public verification by code. Returns only masked details. */
    public function verifyCode(string $code): array
    {
        $st = $this->db->prepare('SELECT * FROM documents WHERE code=?');
        $st->execute([strtoupper(trim($code))]);
        $d = $st->fetch();
        if (!$d) return ['found' => false, 'valid' => false, 'message' => 'No document with that code was issued by this system.'];
        return $this->describe($d, null);
    }

    public function verifyFile(string $bytes): array
    {
        if (!str_starts_with($bytes, '%PDF-')) return ['found' => false, 'valid' => false, 'message' => 'That file is not a PDF.'];
        $h = hash('sha256', $bytes);
        $st = $this->db->prepare('SELECT * FROM documents WHERE file_sha256=?');
        $st->execute([$h]);
        $d = $st->fetch();
        if (!$d) return ['found' => false, 'valid' => false, 'message' => 'This file does not match any document issued by this system. It may have been altered, or it was not issued here.'];
        return $this->describe($d, true);
    }

    private function describe(array $d, ?bool $fileMatches): array
    {
        $intact = hash_equals($d['payload_hash'], hash('sha256', $d['payload']))
            && openssl_verify($d['payload'], (string)base64_decode($d['rsa_signature']), $this->pubPem, OPENSSL_ALGO_SHA256) === 1;
        $stored = Config::storage('docs') . '/' . $d['code'] . '.pdf';
        $fileOk = $fileMatches ?? (is_file($stored) && hash_equals($d['file_sha256'], hash_file('sha256', $stored)));
        $pl = json_decode($d['payload'], true) ?: [];

        $status = 'current';
        if ($d['kind'] === 'receipt' && $d['signature_id']) {
            $v = $this->db->prepare('SELECT version FROM signatures WHERE id=?');
            $v->execute([$d['signature_id']]);
            $cur = $v->fetchColumn();
            if ($cur !== false && (int)$cur > (int)($pl['version'] ?? 1)) $status = 'superseded';
        } elseif ($d['kind'] === 'sheet') {
            $nx = $this->db->prepare("SELECT 1 FROM documents WHERE kind='sheet' AND session_id=? AND unit_code=? AND id>?");
            $nx->execute([$d['session_id'], $d['unit_code'], $d['id']]);
            if ($nx->fetchColumn()) $status = 'superseded';
        }
        $out = [
            'found' => true, 'valid' => $intact && $fileOk, 'record_intact' => $intact, 'file_matches' => $fileMatches ?? $fileOk,
            'status' => $status, 'code' => $d['code'], 'kind' => $d['kind'], 'issued_at' => $d['created_at'],
            'period' => $pl['period'] ?? null, 'venue' => $pl['venue'] ?? null, 'unit' => trim(($pl['unit_code'] ?? '') . ' ' . ($pl['unit_name'] ?? '')),
        ];
        if ($d['kind'] === 'receipt') { $out['student'] = Util::maskAdm((string)($pl['adm_no'] ?? '')); $out['version'] = $pl['version'] ?? 1; }
        else $out['entries'] = count($pl['entries'] ?? []);
        $out['message'] = $out['valid']
            ? ($status === 'superseded' ? 'Authentic and unaltered, but a newer version of this record has been issued since.' : 'Authentic and unaltered.')
            : 'Integrity check FAILED. Do not rely on this document.';
        return $out;
    }

    // ---------------------------------------------------------------- helpers

    /** @return array{code:string,json:string,hash:string,sig:string} */
    private function seal(array $payload): array
    {
        $code = Util::code();
        $payload['doc_code'] = $code;
        $json = Util::canonical($payload);
        if (!openssl_sign($json, $sig, $this->priv, OPENSSL_ALGO_SHA256)) throw new \RuntimeException('Signing failed');
        return ['code' => $code, 'json' => $json, 'hash' => hash('sha256', $json), 'sig' => base64_encode($sig)];
    }

    private function store(Pdf $p, string $kind, array $payload, array $seal, int $sessionId, ?int $sigId, string $unit, string $title, ?string $contentHash = null, int $entries = 1): array
    {
        $bytes = $p->output(['title' => $title, 'subject' => 'Verification code ' . $seal['code'], 'keywords' => 'KCA, exam, attendance, ' . $seal['code']]);
        $path = Config::storage('docs') . '/' . $seal['code'] . '.pdf';
        file_put_contents($path, $bytes);
        @chmod($path, 0600);
        $this->db->prepare('INSERT INTO documents(code,kind,session_id,signature_id,unit_code,content_hash,payload_hash,rsa_signature,file_sha256,created_at,payload) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$seal['code'], $kind, $sessionId, $sigId, $unit, $contentHash ?? hash('sha256', Util::canonical($payload)), $seal['hash'], $seal['sig'], hash('sha256', $bytes), Util::now(), $seal['json']]);
        return ['code' => $seal['code'], 'path' => $path, 'bytes' => $bytes, 'entries' => $entries, 'reused' => false];
    }

    private function header(Pdf $p, string $sub): void
    {
        $p->rect(0, 0, Pdf::W, 70, self::NAVY);
        $p->rect(0, 70, Pdf::W, 4, self::GOLD);
        $p->rect(48, 20, 34, 34, self::GOLD);
        $p->text(65, 42, 'KCA', self::NAVY, true, 10, 'C');
        $p->text(94, 36, 'KCA UNIVERSITY', 'FFFFFF', true, 15);
        $p->text(94, 52, $sub, 'D9E2F2', false, 9.5);
        $p->text(547, 42, 'e-Sign Sheet', 'FFFFFF', true, 10, 'R');
    }

    private function footer(Pdf $p): void
    {
        $n = $p->pageCount();
        for ($i = 0; $i < $n; $i++) {
            $p->gotoPage($i);
            $p->line(48, 812, 547, 812, self::LINE, 0.6);
            $p->text(48, 825, 'KCA University electronic exam sign sheet. Verify authenticity at ' . Config::get('VERIFY_BASE_URL', 'http://localhost:8080') . '/#/verify', self::GREY, false, 7.5);
            $p->text(547, 825, 'Page ' . ($i + 1) . ' of ' . $n, self::GREY, false, 7.5, 'R');
        }
    }

    private function sealBlock(Pdf $p, float $y, array $seal, string $note, bool $sign = false): void
    {
        $url = Config::get('VERIFY_BASE_URL', 'http://localhost:8080') . '/#/verify';
        $p->rect(48, $y, 499, 128, self::PALE, self::NAVY, 1);
        $p->rect(48, $y, 5, 128, self::GOLD);
        $p->text(64, $y + 18, 'DOCUMENT SEAL', self::NAVY, true, 9);
        $p->text(64, $y + 40, 'Verification code', self::GREY, true, 7.5);
        $p->text(64, $y + 56, $seal['code'], self::INK, true, 15);
        $p->text(250, $y + 40, 'Verify at', self::GREY, true, 7.5);
        $p->text(250, $y + 54, $url, self::INK, false, 9);
        $p->text(64, $y + 76, 'SHA-256 fingerprint of the sealed record', self::GREY, true, 7.5);
        $p->text(64, $y + 88, substr($seal['hash'], 0, 32), self::INK, false, 8.5);
        $p->text(64, $y + 99, substr($seal['hash'], 32), self::INK, false, 8.5);
        $p->text(330, $y + 76, 'Digital signature (RSA-2048 / SHA-256)', self::GREY, true, 7.5);
        $p->text(330, $y + 88, substr($seal['sig'], 0, 34), self::INK, false, 7.5);
        $p->text(330, $y + 98, substr($seal['sig'], 34, 34) . '...', self::INK, false, 7.5);
        $lines = $p->wrap($note, 470, false, 7.5);
        foreach (array_slice($lines, 0, 2) as $i => $l) $p->text(64, $y + 113 + $i * 9.5, $l, self::GREY, false, 7.5);
        if ($sign) {
            $p->line(380, $y + 160, 547, $y + 160, self::INK, 0.7);
            $p->text(463, $y + 172, 'Chief supervisor signature', self::GREY, false, 8, 'C');
        }
    }
}
