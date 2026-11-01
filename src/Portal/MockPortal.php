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
        return ['email' => $u['email'], 'name' => $u['name'], 'role' => $u['role'], 'school' => $u['school'] ?? ''];
    }

    public function findStaff(string $email): ?array
    {
        $u = $this->findRaw($email);
        return $u ? ['email' => $u['email'], 'name' => $u['name'], 'role' => $u['role'], 'school' => $u['school'] ?? ''] : null;
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
                    'school_email' => strtolower($s['school_email']), 'phone' => $s['phone'] ?? null, 'cleared' => (bool)$s['cleared'], 'reason' => $s['reason'] ?? null,
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

    public function listStaff(): array
    {
        $out = [];
        foreach ($this->d['staff'] as $u) {
            if (!in_array($u['role'], ['supervisor', 'invigilator'], true)) continue;
            $out[] = ['email' => strtolower($u['email']), 'name' => $u['name'], 'role' => $u['role'], 'school' => $u['school'] ?? '', 'teaches' => $u['teaches'] ?? []];
        }
        return $out;
    }

    public function listUnits(): array
    {
        $school = ['BSD' => 'School of Technology', 'BIT' => 'School of Technology', 'BBM' => 'School of Business', 'BED' => 'School of Education'];
        $out = [];
        foreach ($this->d['units'] as $code => $name) $out[] = ['code' => $code, 'name' => $name, 'school' => $school[substr($code, 0, 3)] ?? 'Other'];
        return $out;
    }

    public function registeredStudents(string $unitCode): array
    {
        $out = [];
        foreach ($this->d['students'] as $s) {
            $units = $s['cleared'] ? ($s['units'] ?? []) : ($s['registered'] ?? []);
            if (in_array($unitCode, $units, true)) $out[] = ['adm_no' => $s['adm_no'], 'full_name' => $s['full_name'], 'cleared' => (bool)$s['cleared'], 'reason' => $s['reason'] ?? null];
        }
        return $out;
    }

    public function studentPhoto(string $admNo): ?string
    {
        // The mock portal draws a neutral avatar. The real portal returns the student's registration photo.
        $k = 0;
        foreach (str_split($admNo) as $c) $k = ($k * 31 + ord($c)) % 360;
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 150"><rect width="120" height="150" fill="hsl(' . $k . ',35%,82%)"/><circle cx="60" cy="58" r="26" fill="hsl(' . $k . ',30%,45%)"/><path d="M12 150c4-42 26-58 48-58s44 16 48 58z" fill="hsl(' . $k . ',30%,45%)"/></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    private function findRaw(string $email): ?array
    {
        foreach ($this->d['staff'] as $u) if (strcasecmp($u['email'], trim($email)) === 0) return $u;
        return null;
    }
}
