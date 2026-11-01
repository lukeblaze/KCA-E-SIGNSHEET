<?php
declare(strict_types=1);
namespace Kca;

final class Config
{
    private static array $v = [];

    public static function load(): void
    {
        $f = ROOT . '/.env';
        if (!is_file($f)) return;
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
            [$k, $val] = explode('=', $line, 2);
            $val = preg_replace('/\s+#.*$/', '', $val) ?? $val; // trailing comment
            self::$v[trim($k)] = trim($val, " \t\"'");
        }
    }

    public static function get(string $k, ?string $default = null): ?string
    {
        $env = getenv($k);
        if ($env !== false && $env !== '') return $env;
        return self::$v[$k] ?? $default;
    }

    public static function isProd(): bool { return self::get('APP_ENV', 'dev') === 'prod'; }

    public static function storage(string $sub = ''): string
    {
        $base = self::get('STORAGE_DIR', ROOT . '/storage');
        $p = $sub === '' ? $base : $base . '/' . $sub;
        if (!is_dir($p)) @mkdir($p, 0700, true);
        return $p;
    }

    public static function secret(): string
    {
        $s = self::get('APP_SECRET', '');
        if (strlen($s) < 32) throw new \RuntimeException('APP_SECRET missing or too short. Run: php bin/setup.php');
        return $s;
    }
}
