<?php
declare(strict_types=1);
namespace Kca\Portal;

use Kca\Config;

/**
 * TEMPLATE for the real portal. The endpoint paths and JSON shapes below are
 * ASSUMPTIONS: replace them with whatever KCA ICT exposes (REST/SOAP/DB view).
 * Use a read-only service account; never give this app write access to the portal.
 */
final class HttpPortal implements PortalAdapter
{
    private string $base;
    private string $token;

    public function __construct()
    {
        $this->base = rtrim((string)Config::get('PORTAL_API_URL', ''), '/');
        $this->token = (string)Config::get('PORTAL_API_TOKEN', '');
        if ($this->base === '' || !str_starts_with($this->base, 'https://')) {
            throw new \RuntimeException('PORTAL_API_URL must be an https:// URL for the http portal driver');
        }
    }

    public function authenticateStaff(string $email, string $password): ?array
    {
        // Ideal: redirect to the portal's own SSO and receive a signed assertion instead of handling passwords here.
        $r = $this->call('POST', '/staff/authenticate', ['email' => $email, 'password' => $password]);
        return $r && isset($r['email'], $r['name'], $r['role']) ? $r : null;
    }

    public function findStaff(string $email): ?array { return $this->call('GET', '/staff/' . rawurlencode($email)); }

    public function searchStudents(string $query, int $limit = 8): array
    {
        return $this->call('GET', '/students?q=' . rawurlencode($query) . '&limit=' . $limit) ?? [];
    }

    public function getStudent(string $admNo): ?array { return $this->call('GET', '/students/' . rawurlencode($admNo)); }

    public function clearedUnits(string $admNo, string $period): array
    {
        return $this->call('GET', '/students/' . rawurlencode($admNo) . '/exam-units?period=' . rawurlencode($period)) ?? [];
    }

    private function call(string $method, string $path, ?array $body = null): ?array
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->token, 'Accept: application/json', 'Content-Type: application/json'],
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($res === false || $code === 404 || $code === 401) return null;
        if ($code >= 500) throw new \RuntimeException('Portal unavailable');
        $j = json_decode((string)$res, true);
        return is_array($j) ? $j : null;
    }
}
