<?php
declare(strict_types=1);
namespace Kca\Portal;

/**
 * The ONLY place the e-sign sheet touches KCA's portal. KCA ICT implements this
 * (HttpPortal.php is a starting template); the rest of the app never changes.
 */
interface PortalAdapter
{
    /** @return array{email:string,name:string,role:string,school?:string}|null  role: supervisor|invigilator|exam_director|coordinator */
    public function authenticateStaff(string $email, string $password): ?array;

    /** @return array{email:string,name:string,role:string}|null */
    public function findStaff(string $email): ?array;

    /** @return list<array{adm_no:string,full_name:string,programme:string}> */
    public function searchStudents(string $query, int $limit = 8): array;

    /** @return array{adm_no:string,full_name:string,programme:string,school_email:string,phone:?string,cleared:bool,reason:?string}|null */
    public function getStudent(string $admNo): ?array;

    /** Units the student is cleared to sit in the given exam period. @return list<array{code:string,name:string}> */
    public function clearedUnits(string $admNo, string $period): array;

    /**
     * Every staff account that can be put on an exam timetable (chief supervisors, invigilators and the people who
     * teach units). `teaches` lists unit codes the person lectures, so the system never makes a lecturer invigilate their own paper.
     * @return list<array{email:string,name:string,role:string,school:string,teaches:list<string>}>
     */
    public function listStaff(): array;

    /** Units on the portal's timetable, used to check and normalise uploaded timetables. @return list<array{code:string,name:string,school:string}> */
    public function listUnits(): array;

    /** Students registered for a unit this period, cleared or not (used for the absent report and expected counts). @return list<array{adm_no:string,full_name:string,cleared:bool,reason:?string}> */
    public function registeredStudents(string $unitCode): array;

    /** Portal photo for the "Is this you?" card: a data: URI or https URL, or null when there is none. */
    public function studentPhoto(string $admNo): ?string;
}
