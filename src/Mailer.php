<?php
declare(strict_types=1);
namespace Kca;

final class Mailer
{
    /** @return array{status:string,detail:string} status: sent | outbox | failed */
    public function send(string $to, string $subject, string $text, ?array $attachment = null): array
    {
        if (preg_match('/[\r\n]/', $to . $subject)) return ['status' => 'failed', 'detail' => 'invalid header characters'];
        $from = (string)Config::get('MAIL_FROM', 'esign@kcau.ac.ke');
        $mime = $this->build($from, $to, $subject, $text, $attachment);

        if (Config::get('MAIL_MODE', 'outbox') !== 'smtp') {
            $dir = Config::storage('outbox');
            $file = $dir . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.eml';
            file_put_contents($file, $mime);
            @chmod($file, 0600);
            return ['status' => 'outbox', 'detail' => basename($file)];
        }
        try {
            $this->smtp($from, $to, $mime);
            return ['status' => 'sent', 'detail' => 'smtp'];
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'detail' => $e->getMessage()];
        }
    }

    private function build(string $from, string $to, string $subject, string $text, ?array $att): string
    {
        $b = 'kca_' . bin2hex(random_bytes(8));
        $h = [
            'From: KCA University e-Sign Sheet <' . $from . '>', 'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'Date: ' . date('r'), 'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . (explode('@', $from)[1] ?? 'kcau.ac.ke') . '>',
            'MIME-Version: 1.0', 'Content-Type: multipart/mixed; boundary="' . $b . '"',
        ];
        $body = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text));
        if ($att) {
            $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $att['name']);
            $body .= "--$b\r\nContent-Type: application/pdf; name=\"$name\"\r\nContent-Transfer-Encoding: base64\r\n"
                . "Content-Disposition: attachment; filename=\"$name\"\r\n\r\n" . chunk_split(base64_encode($att['data']));
        }
        return implode("\r\n", $h) . "\r\n\r\n" . $body . "--$b--\r\n";
    }

    private function smtp(string $from, string $to, string $mime): void
    {
        $host = (string)Config::get('SMTP_HOST');
        $port = (int)Config::get('SMTP_PORT', '587');
        $sec = Config::get('SMTP_SECURE', 'tls');
        if ($host === '') throw new \RuntimeException('SMTP_HOST not set');
        $fp = stream_socket_client(($sec === 'ssl' ? 'ssl://' : 'tcp://') . "$host:$port", $en, $es, 15);
        if (!$fp) throw new \RuntimeException("SMTP connect failed: $es");
        stream_set_timeout($fp, 15);
        $rd = function () use ($fp): string {
            $r = '';
            while (($l = fgets($fp, 1024)) !== false) { $r .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; }
            return $r;
        };
        $cmd = function (string $c, array $ok) use ($fp, $rd): string {
            if ($c !== '') fwrite($fp, $c . "\r\n");
            $r = $rd();
            if (!in_array((int)substr($r, 0, 3), $ok, true)) throw new \RuntimeException('SMTP error: ' . trim($r));
            return $r;
        };
        try {
            $cmd('', [220]);
            $cmd('EHLO esign.kcau.ac.ke', [250]);
            if ($sec === 'tls') {
                $cmd('STARTTLS', [220]);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new \RuntimeException('STARTTLS failed');
                $cmd('EHLO esign.kcau.ac.ke', [250]);
            }
            $u = (string)Config::get('SMTP_USER', '');
            if ($u !== '') {
                $cmd('AUTH LOGIN', [334]);
                $cmd(base64_encode($u), [334]);
                $cmd(base64_encode((string)Config::get('SMTP_PASS', '')), [235]);
            }
            $cmd('MAIL FROM:<' . $from . '>', [250]);
            $cmd('RCPT TO:<' . $to . '>', [250, 251]);
            $cmd('DATA', [354]);
            fwrite($fp, preg_replace('/^\./m', '..', $mime) . "\r\n.\r\n");
            $cmd('', [250]);
            $cmd('QUIT', [221]);
        } finally {
            fclose($fp);
        }
    }
}
