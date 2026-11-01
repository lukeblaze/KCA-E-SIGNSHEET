<?php
declare(strict_types=1);
namespace Kca\Portal;

use Kca\{Config, Util};

/** Development stand-in reading data/mock_portal.json. Refuses to run in prod. */
final class MockPortal implements PortalAdapter
{
    private array $d;

    public function __construct()
    {
        if (Config::isProd()) throw new \RuntimeException('MockPortal must not be used when APP_ENV=prod');
        $this->d = json_decode((string)file_get_contents(ROOT . '/data/mock_portal.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function authenticateStaff(string $email, string $password): ?array
    {
        $u = $this->findRaw($email);
        if (!$u || !hash_equals($u['password'], $password)) return null;
        return ['email' => $u['email'], 'name' => $u['name'], 'role' => $u['role']];
    }

    public function findStaff(string $email): ?array
    {
        $u = $this->findRaw($email);
        return $u ? ['email' => $u['email'], 'name' => $u['name'], 'role' => $u['role']] : null;
    }

    public function searchStudents(string $query, int $limit = 8): array
    {
        $q = mb_strtolower(trim($query));
        $qa = Util::normAdm($query);
        $out = [];
        foreach ($this->d['students'] as $s) {
            $hitName = $q !== '' && str_contains(mb_strtolower($s['full_name']), $q);
            $hitAdm = $qa !== '' && str_contains(Util::normAdm($s['adm_no']), $qa);
            if ($hitName || $hitAdm) $out[] = ['adm_no' => $s['adm_no'], 'full_name' => $s['full_name'], 'programme' => $s['programme']];
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    public function getStudent(string $admNo): ?array
    {
        foreach ($this->d['students'] as $s) {
            if (Util::normAdm($s['adm_no']) === Util::normAdm($admNo)) {
                return [
                    'adm_no' => $s['adm_no'], 'full_name' => $s['full_name'], 'programme' => $s['programme'],
                    'school_email' => strtolower($s['school_email']), 'cleared' => (bool)$s['cleared'], 'reason' => $s['reason'] ?? null,
                ];
            }
        }
        return null;
    }

    public function clearedUnits(string $admNo, string $period): array
    {
        foreach ($this->d['students'] as $s) {
            if (Util::normAdm($s['adm_no']) === Util::normAdm($admNo)) {
                if (!$s['cleared']) return [];
                return array_map(fn($c) => ['code' => $c, 'name' => $this->d['units'][$c] ?? $c], $s['units']);
            }
        }
        return [];
    }

    private function findRaw(string $email): ?array
    {
        foreach ($this->d['staff'] as $u) if (strcasecmp($u['email'], trim($email)) === 0) return $u;
        return null;
    }
}
