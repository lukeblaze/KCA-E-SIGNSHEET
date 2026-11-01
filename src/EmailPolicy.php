<?php
declare(strict_types=1);
namespace Kca;

/**
 * Decides whether an email may receive a student's signed copy.
 * All checks run (so the UI can show each one); `ok` is true only if every check passes.
 */
final class EmailPolicy
{
    /** @return array{ok:bool,email:string,checks:list<array{id:string,label:string,passed:bool}>} */
    public static function check(string $input, ?string $portalEmail): array
    {
        $email = strtolower(trim($input));
        $valid = strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false && !preg_match('/[\r\n,;<>\s]/', $email);
        $domain = $valid ? substr(strrchr($email, '@'), 1) : '';
        $allowed = array_filter(array_map('trim', explode(',', strtolower((string)Config::get('ALLOWED_EMAIL_DOMAINS', 'students.kcau.ac.ke')))));
        $inDomain = $valid && in_array($domain, $allowed, true);               // exact match: blocks evil-students.kcau.ac.ke.com etc.
        $matches = $valid && $portalEmail !== null && hash_equals(strtolower($portalEmail), $email);
        $checks = [
            ['id' => 'format', 'label' => 'Email address is correctly formed', 'passed' => $valid],
            ['id' => 'domain', 'label' => 'Is a KCA University school email', 'passed' => $inDomain],
            ['id' => 'record', 'label' => "Matches the email on the student's portal record", 'passed' => $matches],
        ];
        if (Config::get('MX_CHECK', '0') === '1') {
            $checks[] = ['id' => 'mx', 'label' => 'Email domain can receive mail', 'passed' => $inDomain && checkdnsrr($domain . '.', 'MX')];
        }
        $ok = !in_array(false, array_column($checks, 'passed'), true);
        return ['ok' => $ok, 'email' => $email, 'checks' => $checks];
    }

    public static function phone(string $p): ?string
    {
        $d = preg_replace('/[\s\-().]/', '', $p) ?? '';
        if (preg_match('/^(?:\+?254|0)([17]\d{8})$/', $d, $m)) return '+254' . $m[1];
        return null;
    }
}
