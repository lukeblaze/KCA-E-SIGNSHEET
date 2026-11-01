<?php
declare(strict_types=1);
namespace Kca;

/** Builds, seals, stores and verifies the PDFs: student receipts, per-unit sign sheets and the session pack. */
final class Documents
{
    private const NAVY = '0B2A5B';
    private const GOLD = 'F2A900';
    private const INK = '1B2433';
    private const GREY = '5A6577';
    private const LINE = 'D5DBE6';
    private const PALE = 'F3F5F9';
    private const RED = 'B3261E';

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

    // -------------------------------------------------------------- authority

    /** SHA-256 of the public seal key (DER). Shown to the director and drawn as a security pattern on every document. */
    public function fingerprint(): string
    {
        $body = preg_replace('/-----[^-]+-----|\s+/', '', $this->pubPem) ?? '';
        return hash('sha256', (string)base64_decode($body));
    }

    /** The exam director's name and signature image, printed on every document and recorded inside every seal. */
    public function authority(): array
    {
        $d = Features::director($this->db);
        return ['name' => $d['name'], 'png' => $d['png'], 'signature_sha256' => $d['png'] !== null ? hash('sha256', $d['png']) : null, 'key_fingerprint' => $this->fingerprint()];
    }

    private static function authPayload(array $a): array
    {
        return ['name' => $a['name'], 'signature_sha256' => $a['signature_sha256'], 'key_fingerprint' => $a['key_fingerprint']];
    }

    // ---------------------------------------------------------------- receipt

    /** @return array{code:string,path:string,bytes:string} */
    public function receipt(array $sig, array $session, string $by = 'system'): array
    {
        $auth = $this->authority();
        $payload = [
            'kind' => 'receipt', 'session' => $session['code'], 'period' => $session['period'], 'venue' => $session['venue'],
            'unit_code' => $sig['unit_code'], 'unit_name' => $sig['unit_name'], 'adm_no' => $sig['adm_no'], 'full_name' => $sig['full_name'],
            'phone' => $sig['phone'], 'booklet_no' => $sig['booklet_no'], 'email' => $sig['email'], 'signed_at' => $sig['signed_at'],
            'updated_at' => $sig['updated_at'], 'version' => (int)$sig['version'], 'signature_sha256' => hash('sha256', $sig['signature_png']),
            'captured_by' => $sig['captured_by'], 'ident' => $sig['ident'] ?? null, 'authority' => self::authPayload($auth),
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
            ['School email', $sig['email']], ['Identity check', Features::IDENT[$sig['ident'] ?? ''] ?? 'Recorded'],
            ['Record version', 'v' . $sig['version'] . ($sig['version'] > 1 ? '  (corrected copy)' : '')],
        ];
        $y = 168;
        foreach (array_chunk($rows, 2) as $pair) {
            foreach ($pair as $i => [$l, $v]) {
                $x = 48 + $i * 252;
                $p->text($x, $y, strtoupper($l), self::GREY, true, 7.5);
                $p->text($x, $y + 15, $p->fit($v, 236, false, 11), self::INK, false, 11);
            }
            $y += 40;
        }
        $p->line(48, $y - 12, 547, $y - 12, self::LINE, 0.7);

        $p->text(48, $y + 6, 'STUDENT SIGNATURE', self::GREY, true, 7.5);
        $p->rect(48, $y + 12, 499, 100, '#FFFFFF', self::LINE);
        $p->imageFit($sig['signature_png'], 60, $y + 17, 475, 90);
        $end = $this->sealBlock($p, $y + 128, $seal, 'Keep this copy. If a school record is ever lost or disputed, this document and its verification code are proof of your attendance.');
        $this->authorityBlock($p, $end + 10, $auth);
        $this->footer($p);

        return $this->store($p, 'receipt', $payload, $seal, (int)$sig['session_id'], (int)$sig['id'], $sig['unit_code'], 'Receipt ' . $sig['adm_no'] . ' ' . $sig['unit_code'], null, 1, $by, 'Sealed student receipt');
    }

    // ------------------------------------------------------------------ sheet

    /**
     * Seal (or reuse the identical already-sealed) sign sheet for one unit.
     * @param array{rows:list<array>,absent:list<array>,registered:int} $report from Features::unitReport
     * @param list<array> $incidents from Features::incidents (this unit only)
     * @return ?array{code:string,path:string,bytes:string,entries:int,reused:bool,seal:array} null when there is nothing to put on the sheet
     */
    public function sheet(array $session, string $unitCode, string $unitName, array $report, array $incidents, string $by = 'system'): ?array
    {
        $rows = $report['rows'];
        if (!$rows && !$report['absent']) return null;
        $auth = $this->authority();
        $content = $this->sheetContent($session, $unitCode, $unitName, $report, $incidents, $auth);
        $contentHash = hash('sha256', Util::canonical($content));
        $ex = $this->db->prepare("SELECT * FROM documents WHERE kind='sheet' AND session_id=? AND unit_code=? AND content_hash=? ORDER BY id DESC LIMIT 1");
        $ex->execute([$session['id'], $unitCode, $contentHash]);
        if ($d = $ex->fetch()) {
            $f = Config::storage('docs') . '/' . $d['code'] . '.pdf';
            if (is_file($f)) return ['code' => $d['code'], 'path' => $f, 'bytes' => (string)file_get_contents($f), 'entries' => count($rows), 'reused' => true,
                'seal' => ['code' => $d['code'], 'hash' => $d['payload_hash'], 'sig' => $d['rsa_signature']]];
        }

        $seal = $this->seal($content);
        $p = new Pdf();
        $this->drawSheet($p, $content, $rows, $seal, $auth);
        $this->footer($p);
        $r = $this->store($p, 'sheet', $content, $seal, (int)$session['id'], null, $unitCode, 'Sign sheet ' . $unitCode . ' ' . $session['period'], $contentHash, count($rows), $by, 'Sealed unit sign sheet');
        $r['seal'] = ['code' => $seal['code'], 'hash' => $seal['hash'], 'sig' => $seal['sig']];
        return $r;
    }

    private function sheetContent(array $session, string $unitCode, string $unitName, array $report, array $incidents, array $auth): array
    {
        $cut = Features::lateCutoff($session);
        $entries = array_map(static fn($r) => [
            'adm_no' => $r['adm_no'], 'full_name' => $r['full_name'], 'booklet_no' => $r['booklet_no'], 'phone' => $r['phone'],
            'signed_at' => $r['signed_at'], 'updated_at' => $r['updated_at'], 'version' => (int)$r['version'], 'signature_sha256' => hash('sha256', $r['signature_png']),
            'ident' => $r['ident'], 'ident_note' => $r['ident_note'] ?? '', 'review' => $r['review'], 'reviewed_by' => $r['reviewed_by'],
            'offline' => (bool)$r['offline'], 'late' => $r['signed_at'] > $cut, 'exit_at' => $r['exit_at'], 'exit_note' => $r['exit_note'] ?? '', 'resit_of' => $r['resit_of'],
        ], $report['rows']);
        $incs = array_map(static fn($i) => [
            'case_no' => $i['case_no'], 'adm_no' => $i['adm_no'], 'full_name' => $i['full_name'], 'category' => $i['category'], 'status' => $i['status'],
            'entries' => array_map(static fn($e) => ['ts' => $e['ts'], 'by' => $e['by'], 'text' => $e['text'], 'status' => $e['status']], $i['entries']),
            'resit_linked' => $i['resit_sid'] !== null,
        ], $incidents);
        return [
            'kind' => 'sheet', 'session' => $session['code'], 'period' => $session['period'], 'venue' => $session['venue'],
            'unit_code' => $unitCode, 'unit_name' => $unitName, 'supervisor' => $session['supervisor_name'], 'opens_at' => $session['opens_at'],
            'late_after' => (int)$session['late_after'], 'registered' => $report['registered'], 'entries' => $entries,
            'absent' => array_map(static fn($a) => ['adm_no' => $a['adm_no'], 'full_name' => $a['full_name'], 'state' => $a['state'], 'reason' => $a['reason']], $report['absent']),
            'incidents' => $incs, 'authority' => self::authPayload($auth),
        ];
    }

    /** Lines of small print under a student's name on the sheet: [text, isRed]. */
    private function tags(array $e, array $c, ?array $inc): array
    {
        $t = [];
        $short = ['card_id' => 'exam card and student ID checked', 'card_only' => 'exam card only, no student ID', 'photo_only' => 'photo only'];
        if ($e['ident']) {
            $x = 'ID: ' . ($short[$e['ident']] ?? $e['ident']);
            if ($e['ident'] === 'photo_only') {
                $x .= $e['review'] === 'reviewed' ? ' (reviewed by ' . $e['reviewed_by'] . ')' : ' (review pending)';
                if ($e['ident_note'] !== '') $x .= '. Reason: ' . $e['ident_note'];
            }
            $t[] = [$x, $e['ident'] === 'photo_only' && $e['review'] !== 'reviewed'];
        }
        if ($e['version'] > 1) $t[] = ['CORRECTED (version ' . $e['version'] . ')', false];
        if ($e['resit_of']) $t[] = ['RE-SIT · prior case ' . $e['resit_of'], false];
        if ($inc) $t[] = ['INCIDENT ' . $inc['case_no'] . ' · ' . (Features::STATUSES[$inc['status']] ?? $inc['status']), true];
        if ($e['offline']) $t[] = ['Signed offline, synced later', false];
        if ($e['late']) $t[] = ['LATE ARRIVAL (more than ' . $c['late_after'] . ' min after start)', true];
        if ($e['exit_at']) $t[] = ['LEFT ' . Util::eat($e['exit_at'], 'H:i') . ($e['exit_note'] !== '' ? ' · ' . $e['exit_note'] : ''), false];
        return $t;
    }

    private function room(Pdf $p, float $y, float $need, string $sub): float
    {
        if ($y + $need <= 800) return $y;
        $p->addPage();
        $this->header($p, $sub);
        return 104;
    }

    private function tableHead(Pdf $p, float $y, array $cols): float
    {
        $x = 48;
        $p->rect(48, $y, 499, 22, self::NAVY);
        foreach ($cols as [$h, $w]) { $p->text($x + 5, $y + 14.5, $h, 'FFFFFF', true, 8); $x += $w; }
        return $y + 22;
    }

    /** Draws one unit's sheet (starting on a fresh page) into $p. */
    private function drawSheet(Pdf $p, array $c, array $rows, array $seal, array $auth): void
    {
        $sub = 'Examination Attendance Sheet';
        $p->addPage();
        $this->header($p, $sub);
        $p->text(48, 112, $p->fit($c['unit_code'] . '  ' . $c['unit_name'], 499, true, 17), self::NAVY, true, 17);
        $meta = [['Exam period', $c['period']], ['Venue', $c['venue']], ['Chief supervisor', $c['supervisor']],
            ['Registered', (string)$c['registered']], ['Signed', (string)count($c['entries'])], ['Did not sign', (string)count($c['absent'])]];
        foreach ($meta as $i => [$l, $v]) {
            $x = 48 + ($i % 2) * 252; $yy = 140 + intdiv($i, 2) * 34;
            $p->text($x, $yy, strtoupper($l), self::GREY, true, 7.5);
            $p->text($x, $yy + 13, $p->fit($v, 236, false, 10.5), self::INK, false, 10.5);
        }
        $p->text(48, 250, 'Arrivals more than ' . $c['late_after'] . ' minutes after the session opened at ' . Util::eat($c['opens_at'], 'H:i') . ' are marked LATE.', self::GREY, false, 7.5);

        $cols = [['No.', 24], ['Adm. no.', 60], ['Name and checks', 148], ['Booklet no.', 62], ['Phone', 72], ['Time', 34], ['Signature', 99]];
        $y = 262;
        if (!$rows) {
            $p->text(48, $y + 10, 'No signatures were collected for this unit.', self::GREY, false, 9.5);
            $y += 30;
        } else {
            $y = $this->tableHead($p, $y, $cols);
            $incBy = [];
            foreach ($c['incidents'] as $i) $incBy[$i['adm_no']] ??= $i;
            foreach ($rows as $n => $r) {
                $e = $c['entries'][$n];
                $lines = [];
                foreach ($this->tags($e, $c, $incBy[$e['adm_no']] ?? null) as [$tx, $red]) foreach ($p->wrap($tx, $cols[2][1] - 8, false, 6.8) as $ln) $lines[] = [$ln, $red];
                $h = max(44.0, 22.0 + 8.4 * count($lines));
                if ($y + $h > 798) { $p->addPage(); $this->header($p, $sub); $y = $this->tableHead($p, 104, $cols); }
                $x = 48;
                $cells = [(string)($n + 1), $e['adm_no'], $e['full_name'], $e['booklet_no'], $e['phone'], Util::eat($e['signed_at'], 'H:i')];
                foreach ($cells as $ci => $val) {
                    $p->text($x + 5, $y + 14, $p->fit($val, $cols[$ci][1] - 8, $ci === 2, 8.5), self::INK, $ci === 2, 8.5);
                    $x += $cols[$ci][1];
                }
                foreach ($lines as $k => [$ln, $red]) $p->text(48 + $cols[0][1] + $cols[1][1] + 5, $y + 24 + $k * 8.4, $ln, $red ? self::RED : self::GREY, false, 6.8);
                $p->imageFit($r['signature_png'], $x + 3, $y + 3, $cols[6][1] - 6, $h - 6);
                $p->line(48, $y + $h, 547, $y + $h, self::LINE, 0.6);
                $y += $h;
            }
            $y += 14;
        }

        if ($c['absent']) {
            $y = $this->room($p, $y, 40, $sub);
            $p->text(48, $y + 8, 'REGISTERED BUT DID NOT SIGN (' . count($c['absent']) . ')', self::NAVY, true, 9);
            $y += 22;
            foreach ($c['absent'] as $a) {
                $y = $this->room($p, $y, 14, $sub);
                $state = $a['state'] === 'absent' ? 'Absent / did not sign' : 'Not cleared: ' . $a['reason'];
                $p->text(48, $y + 6, $p->fit($a['adm_no'] . '  ' . $a['full_name'], 250, false, 8.5), self::INK, false, 8.5);
                $p->text(310, $y + 6, $p->fit($state, 237, false, 8.5), $a['state'] === 'absent' ? self::INK : self::RED, false, 8.5);
                $y += 13;
            }
            $y += 12;
        }
        if ($c['incidents']) {
            $y = $this->room($p, $y, 50, $sub);
            $p->text(48, $y + 8, 'INCIDENT NOTES (RECORDED BY THE CHIEF SUPERVISOR)', self::NAVY, true, 9);
            $y += 24;
            foreach ($c['incidents'] as $i) {
                $y = $this->room($p, $y, 40, $sub);
                $p->text(48, $y + 6, $p->fit($i['case_no'] . ' · ' . $i['adm_no'] . ' ' . $i['full_name'] . ' · ' . $i['category'] . ' · ' . (Features::STATUSES[$i['status']] ?? $i['status']), 499, true, 8.5), self::RED, true, 8.5);
                $y += 14;
                foreach ($i['entries'] as $e) {
                    foreach ($p->wrap(Util::eat($e['ts']) . ' · ' . $e['by'] . ': ' . $e['text'], 485, false, 8) as $ln) {
                        $y = $this->room($p, $y, 12, $sub);
                        $p->text(58, $y + 6, $ln, self::GREY, false, 8);
                        $y += 10.5;
                    }
                }
                if ($i['resit_linked']) { $y = $this->room($p, $y, 12, $sub); $p->text(58, $y + 6, 'Re-sit sheet linked to this case.', self::GREY, false, 8); $y += 10.5; }
                $y += 8;
            }
        }

        $y = $this->room($p, $y + 8, 268, $sub);
        $end = $this->sealBlock($p, $y, $seal, 'This sheet was produced from the electronic sign sheet system. Any change after sealing, including a single character, invalidates the fingerprint and signature. Entries edited by students after signing are recorded as new versions in the audit log.');
        $end = $this->authorityBlock($p, $end + 10, $auth);
        $p->line(380, $end + 40, 547, $end + 40, self::INK, 0.7);
        $p->text(463, $end + 52, 'Chief supervisor signature', self::GREY, false, 8, 'C');
    }

    // ------------------------------------------------------------------- pack

    /**
     * Seal (or reuse) the session pack: a cover page with totals and audit-chain status followed by every unit sheet.
     * @param list<array{code:string,name:string,report:array,incidents:array,sheet:array}> $units sheets already sealed with sheet()
     * @param array<string,int> $totals
     * @param array{ok:bool,entries:int} $chain
     * @return array{code:string,path:string,bytes:string,entries:int,reused:bool}
     */
    public function pack(array $session, array $units, array $totals, array $chain, string $by = 'system'): array
    {
        $auth = $this->authority();
        $key = hash('sha256', Util::canonical(['sheets' => array_map(static fn($u) => $u['sheet']['code'], $units), 'chain_ok' => $chain['ok'], 'auth' => self::authPayload($auth), 'status' => $session['status']]));
        $ex = $this->db->prepare("SELECT * FROM documents WHERE kind='pack' AND session_id=? AND content_hash=? ORDER BY id DESC LIMIT 1");
        $ex->execute([$session['id'], $key]);
        if ($d = $ex->fetch()) {
            $f = Config::storage('docs') . '/' . $d['code'] . '.pdf';
            if (is_file($f)) return ['code' => $d['code'], 'path' => $f, 'bytes' => (string)file_get_contents($f), 'entries' => (int)$totals['signed'], 'reused' => true];
        }
        $rows = array_map(static fn($u) => ['unit' => $u['code'], 'name' => $u['name'], 'registered' => $u['report']['registered'], 'signed' => count($u['report']['rows']),
            'absent' => count($u['report']['absent']), 'code' => $u['sheet']['code']], $units);
        $payload = [
            'kind' => 'pack', 'session' => $session['code'], 'period' => $session['period'], 'venue' => $session['venue'],
            'unit_code' => 'All papers', 'unit_name' => implode(', ', array_column($units, 'code')), 'supervisor' => $session['supervisor_name'],
            'opens_at' => $session['opens_at'], 'closes_at' => $session['closes_at'], 'status' => $session['status'],
            'rows' => $rows, 'totals' => $totals, 'chain' => ['ok' => $chain['ok'], 'entries' => $chain['entries']], 'authority' => self::authPayload($auth),
        ];
        $seal = $this->seal($payload);

        $p = new Pdf();
        $p->addPage();
        $sub = 'Examination Session Pack';
        $this->header($p, $sub);
        $p->text(48, 112, $p->fit($session['period'] . ' · ' . $session['venue'], 499, true, 17), self::NAVY, true, 17);
        $grid = [
            ['Chief supervisor', $session['supervisor_name']], ['Opened', Util::eat($session['opens_at']) . ' EAT'],
            ['Closed', $session['status'] === 'closed' ? Util::eat($session['closes_at']) . ' EAT' : 'Not closed yet'], ['Signatures', (string)$totals['signed']],
            ['Late arrivals', (string)$totals['late']], ['Exits recorded', (string)$totals['exits']], ['Signed offline', (string)$totals['offline']],
            ['Corrections', (string)$totals['corrections']], ['Incident cases', (string)$totals['incidents']],
            ['Photo-only identity checks', $totals['photo_only'] . ' (' . $totals['review_pending'] . ' awaiting review)'],
            ['Audit chain', $chain['ok'] ? 'Intact, ' . $chain['entries'] . ' entries' : 'BROKEN'],
        ];
        foreach ($grid as $i => [$l, $v]) {
            $x = 48 + ($i % 2) * 252; $yy = 140 + intdiv($i, 2) * 34;
            $p->text($x, $yy, strtoupper($l), self::GREY, true, 7.5);
            $p->text($x, $yy + 13, $p->fit($v, 236, false, 10.5), $l === 'Audit chain' && !$chain['ok'] ? self::RED : self::INK, $l === 'Audit chain' && !$chain['ok'], 10.5);
        }
        $y = 140 + 6 * 34 + 10;
        $cols = [['Paper', 175], ['Registered', 62], ['Signed', 52], ['Did not sign', 66], ['Sheet code', 144]];
        $y = $this->tableHead($p, $y, $cols);
        foreach ($rows as $r) {
            if ($y + 22 > 790) { $p->addPage(); $this->header($p, $sub); $y = $this->tableHead($p, 104, $cols); }
            $x = 48;
            foreach ([$r['unit'] . '  ' . $r['name'], (string)$r['registered'], (string)$r['signed'], (string)$r['absent'], $r['code']] as $ci => $val) {
                $p->text($x + 5, $y + 14, $p->fit($val, $cols[$ci][1] - 8, false, 8.5), self::INK, false, 8.5);
                $x += $cols[$ci][1];
            }
            $p->line(48, $y + 22, 547, $y + 22, self::LINE, 0.6);
            $y += 22;
        }
        $y = $this->room($p, $y + 10, 270, $sub);
        $p->text(48, $y + 2, 'Each paper has its own sealed sign sheet, printed after this page and listed above by code. The sheets are also stored in the school records.', self::GREY, false, 8);
        $end = $this->sealBlock($p, $y + 14, $seal, 'This cover page seals the totals above and the list of unit sheets. Each sheet that follows carries its own verification code and seal.');
        $this->authorityBlock($p, $end + 10, $auth);

        foreach ($units as $u) {
            $sc = $this->sheetContent($session, $u['code'], $u['name'], $u['report'], $u['incidents'], $auth);
            $this->drawSheet($p, $sc, $u['report']['rows'], $u['sheet']['seal'], $auth);
        }
        $this->footer($p);
        return $this->store($p, 'pack', $payload, $seal, (int)$session['id'], null, 'All papers', 'Session pack ' . $session['code'], $key, (int)$totals['signed'], $by, 'Sealed session pack');
    }

    // ----------------------------------------------------------------- verify

    /** Public verification by code. Returns only masked details. */
    public function verifyCode(string $code): array
    {
        $d = $this->find($code);
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

    public function find(string $code): ?array
    {
        $st = $this->db->prepare('SELECT * FROM documents WHERE code=?');
        $st->execute([strtoupper(trim($code))]);
        return $st->fetch() ?: null;
    }

    /** The stored sealed original, after checking it still matches the hash recorded when it was issued. */
    public function storedBytes(array $d): string
    {
        $f = Config::storage('docs') . '/' . $d['code'] . '.pdf';
        if (!is_file($f)) throw new HttpError(404, 'The stored original of this document is missing. Report it to ICT.');
        $b = (string)file_get_contents($f);
        if (!hash_equals($d['file_sha256'], hash('sha256', $b))) throw new HttpError(500, 'The stored original failed its integrity check. Report it to ICT.');
        return $b;
    }

    /** current | superseded (a newer receipt version, sheet or pack has been issued since). */
    public function status(array $d): string
    {
        if ($d['kind'] === 'receipt' && $d['signature_id']) {
            $pl = json_decode($d['payload'], true) ?: [];
            $v = $this->db->prepare('SELECT version FROM signatures WHERE id=?');
            $v->execute([$d['signature_id']]);
            $cur = $v->fetchColumn();
            return $cur !== false && (int)$cur > (int)($pl['version'] ?? 1) ? 'superseded' : 'current';
        }
        if ($d['kind'] === 'sheet') {
            $nx = $this->db->prepare("SELECT 1 FROM documents WHERE kind='sheet' AND session_id=? AND unit_code=? AND id>?");
            $nx->execute([$d['session_id'], $d['unit_code'], $d['id']]);
            return $nx->fetchColumn() ? 'superseded' : 'current';
        }
        if ($d['kind'] === 'pack') {
            $nx = $this->db->prepare("SELECT 1 FROM documents WHERE kind='pack' AND session_id=? AND id>?");
            $nx->execute([$d['session_id'], $d['id']]);
            return $nx->fetchColumn() ? 'superseded' : 'current';
        }
        return 'current';
    }

    private function describe(array $d, ?bool $fileMatches): array
    {
        $intact = hash_equals($d['payload_hash'], hash('sha256', $d['payload']))
            && openssl_verify($d['payload'], (string)base64_decode($d['rsa_signature']), $this->pubPem, OPENSSL_ALGO_SHA256) === 1;
        $stored = Config::storage('docs') . '/' . $d['code'] . '.pdf';
        $fileOk = $fileMatches ?? (is_file($stored) && hash_equals($d['file_sha256'], hash_file('sha256', $stored)));
        $pl = json_decode($d['payload'], true) ?: [];
        $status = $this->status($d);
        $out = [
            'found' => true, 'valid' => $intact && $fileOk, 'record_intact' => $intact, 'file_matches' => $fileMatches ?? $fileOk,
            'status' => $status, 'code' => $d['code'], 'kind' => $d['kind'], 'issued_at' => $d['created_at'],
            'period' => $pl['period'] ?? null, 'venue' => $pl['venue'] ?? null, 'unit' => trim(($pl['unit_code'] ?? '') . ' ' . ($pl['unit_name'] ?? '')),
        ];
        if ($d['kind'] === 'receipt') { $out['student'] = Util::maskAdm((string)($pl['adm_no'] ?? '')); $out['version'] = $pl['version'] ?? 1; }
        elseif ($d['kind'] === 'pack') $out['entries'] = (int)($pl['totals']['signed'] ?? 0);
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

    private function store(Pdf $p, string $kind, array $payload, array $seal, int $sessionId, ?int $sigId, string $unit, string $title, ?string $contentHash, int $entries, string $by, string $purpose): array
    {
        $bytes = $p->output(['title' => $title, 'subject' => 'Verification code ' . $seal['code'], 'keywords' => 'KCA, exam, attendance, ' . $seal['code']]);
        $path = Config::storage('docs') . '/' . $seal['code'] . '.pdf';
        file_put_contents($path, $bytes);
        @chmod($path, 0600);
        $this->db->prepare('INSERT INTO documents(code,kind,session_id,signature_id,unit_code,content_hash,payload_hash,rsa_signature,file_sha256,created_at,payload) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$seal['code'], $kind, $sessionId, $sigId, $unit, $contentHash ?? hash('sha256', Util::canonical($payload)), $seal['hash'], $seal['sig'], hash('sha256', $bytes), Util::now(), $seal['json']]);
        Features::keyUse($this->db, $seal['code'], $purpose, $by); // seal-key custody log: every use of the private key is recorded
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

    /** Draws the seal box and returns the y of its bottom edge. */
    private function sealBlock(Pdf $p, float $y, array $seal, string $note): float
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
        return $y + 128;
    }

    /** "Issued under the authority of the exam director": signature image, name, and the seal-key security pattern. Returns bottom y. */
    private function authorityBlock(Pdf $p, float $y, array $a): float
    {
        $p->rect(48, $y, 499, 78, '#FFFFFF', self::LINE, 0.7);
        $p->text(60, $y + 14, 'ISSUED UNDER THE AUTHORITY', self::GREY, true, 7);
        $p->text(60, $y + 23, 'OF THE EXAM DIRECTOR', self::GREY, true, 7);
        if ($a['png'] !== null) {
            try { $p->imageFit($a['png'], 60, $y + 27, 150, 31); } catch (\Throwable) { $p->text(60, $y + 42, '(signature unreadable)', self::GREY, false, 8); }
        } else {
            $p->text(60, $y + 42, '(signature not uploaded)', self::GREY, false, 8);
        }
        $p->line(60, $y + 60, 230, $y + 60, self::LINE, 0.6);
        $p->text(60, $y + 72, $p->fit($a['name'], 170, false, 8.5), self::INK, false, 8.5);
        $fp = $a['key_fingerprint'];
        $p->text(250, $y + 16, 'SEAL KEY FINGERPRINT', self::GREY, true, 7);
        $p->text(250, $y + 30, substr($fp, 0, 32), self::INK, false, 7.5);
        $p->text(250, $y + 40, substr($fp, 32), self::INK, false, 7.5);
        $p->text(250, $y + 56, 'The pattern is derived from the school seal key.', self::GREY, false, 6.5);
        $p->text(250, $y + 65, 'It is the same on every genuine KCA document.', self::GREY, false, 6.5);
        $this->pattern($p, 452, $y + 12, 9, $fp);
        $p->text(474, $y + 71, 'SECURITY PATTERN', self::GREY, true, 6.5, 'C');
        return $y + 78;
    }

    /** Symmetric 5x5 identicon from the key fingerprint (same algorithm as the demo). */
    private function pattern(Pdf $p, float $x, float $y, float $cell, string $fp): void
    {
        $hue = hexdec(substr($fp, 0, 3)) % 360;
        $bits = hexdec(substr($fp, 3, 5));
        [$r, $g, $b] = self::hsl($hue, 0.55, 0.30);
        $hex = sprintf('%02X%02X%02X', $r, $g, $b);
        $i = 0;
        for ($cx = 0; $cx < 3; $cx++) {
            for ($cy = 0; $cy < 5; $cy++) {
                if (($bits >> $i++) & 1) {
                    foreach ($cx === 2 ? [2] : [$cx, 4 - $cx] as $xx) $p->rect($x + $xx * $cell, $y + $cy * $cell, $cell, $cell, $hex);
                }
            }
        }
    }

    /** @return array{0:int,1:int,2:int} */
    private static function hsl(float $h, float $s, float $l): array
    {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;
        [$r, $g, $b] = match (true) {
            $h < 60 => [$c, $x, 0], $h < 120 => [$x, $c, 0], $h < 180 => [0, $c, $x],
            $h < 240 => [0, $x, $c], $h < 300 => [$x, 0, $c], default => [$c, 0, $x],
        };
        return [(int)round(($r + $m) * 255), (int)round(($g + $m) * 255), (int)round(($b + $m) * 255)];
    }
}
