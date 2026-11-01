<?php
declare(strict_types=1);
namespace Kca\Portal;

/**
 * The ONLY place the e-sign sheet touches KCA's portal. KCA ICT implements this
 * (HttpPortal.php is a starting template); the rest of the app never changes.
 */
interface PortalAdapter
{
    /** @return array{email:string,name:string,role:string}|null  role: supervisor|invigilator|exam_director */
    public function authenticateStaff(string $email, string $password): ?array;

    /** @return array{email:string,name:string,role:string}|null */
    public function findStaff(string $email): ?array;

    /** @return list<array{adm_no:string,full_name:string,programme:string}> */
    public function searchStudents(string $query, int $limit = 8): array;

    /** @return array{adm_no:string,full_name:string,programme:string,school_email:string,phone:?string,cleared:bool,reason:?string}|null */
    public function getStudent(string $admNo): ?array;

    /** Units the student is cleared to sit in the given exam period. @return list<array{code:string,name:string}> */
    public function clearedUnits(string $admNo, string $period): array;
}
