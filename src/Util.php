<?php
declare(strict_types=1);
namespace Kca;

final class Util
{
    public static function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }

    /** Parse any ISO-8601 string (with offset) to UTC storage format, or null. */
    public static function toUtc(string $s): ?string
    {
        try { return (new \DateTimeImmutable($s))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'); }
        catch (\Throwable) { return null; }
    }

    public static function eat(string $utc, string $fmt = 'd M Y, H:i'): string
    {
        try { return (new \DateTimeImmutable($utc))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format($fmt); }
        catch (\Throwable) { return $utc; }
    }

    public static function normAdm(string $s): string { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $s) ?? ''); }

    public static function code(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I
        $b = random_bytes(12);
        $out = '';
        for ($i = 0; $i < 12; $i++) $out .= $alphabet[ord($b[$i]) % 32];
        return 'KCA-' . substr($out, 0, 4) . '-' . substr($out, 4, 4) . '-' . substr($out, 8, 4);
    }

    public static function canonical(array $data): string
    {
        $sort = static function (&$a) use (&$sort): void {
            if (!is_array($a)) return;
            if (!array_is_list($a)) ksort($a);
            foreach ($a as &$v) $sort($v);
        };
        $sort($data);
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function maskAdm(string $adm): string
    {
        return strlen($adm) <= 4 ? '***' : substr($adm, 0, 4) . str_repeat('*', max(3, strlen($adm) - 4));
    }
}
