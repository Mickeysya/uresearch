<?php

namespace Database\Seeders;

use App\Modules\Core\Models\Department;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Faculty;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\Examiner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Test accounts for every role, so anybody can exercise a full approval chain
 * on a fresh database without touching phpMyAdmin.
 *
 * Every account uses the password below. This seeder only ever runs locally --
 * it replaces the legacy fix_test_passwords.php, which was a web-reachable
 * script that reset EVERY user's password with an unfiltered UPDATE.
 */
class DatabaseSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public function run(): void
    {
        $this->departments();
        $this->academicExecs();

        $supervisor = $this->user('Dr. Aisyah Rahman', 'supervisor@utp.edu.my', Role::SUPERVISOR, [
            'department' => 'Computing',
            'faculty' => 'FSMC',
        ]);

        $this->user('Dr. Lim Wei Chun', 'chair@utp.edu.my', Role::CHAIR, [
            'department' => 'Computing',
            'faculty' => 'FSMC',
        ]);

        $this->user('Puan Waheeda', 'cgs@utp.edu.my', Role::NON_EXEC_CGS, ['department' => 'CGS']);
        // Rules on a hardbound appeal after the Non-Executive compiles the
        // Dean PFR report -- the last stage of HardboundAppealWorkflow, which
        // had nobody able to act on it until this account existed.
        $this->user('Puan Hasnah', 'seniorexec@utp.edu.my', Role::SENIOR_EXEC_CGS, ['department' => 'CGS']);
        $this->user('Norshahirah', 'manager@utp.edu.my', Role::MANAGER_CGS, ['department' => 'CGS']);
        $this->user('En Zulkifly', 'director@utp.edu.my', Role::SENIOR_DIRECTOR_CGS, ['department' => 'CGS']);
        $this->user('Prof. Dr. Hafiz Osman', 'dean@utp.edu.my', Role::DEAN_PGR, ['department' => 'PGR']);
        $this->user('Siti Academic Exec', 'ae@utp.edu.my', Role::ACADEMIC_EXEC, [
            'department' => 'Computing',
            'faculty' => 'FSMC',
        ]);
        // Faculty signs off an RPD dismissal between the Dean's endorsement
        // and the Registry's termination email -- see RpdDismissalWorkflow.
        $this->user('Faculty Office', 'faculty@utp.edu.my', Role::FACULTY, [
            'department' => 'Faculty of Engineering', 'faculty' => 'FOE',
        ]);
        $this->user('Registry Officer', 'registry@utp.edu.my', Role::REGISTRY, ['department' => 'Registry']);
        $this->user('System Admin', 'admin@utp.edu.my', Role::ADMIN);

        // Students, all attached to the supervisor above so the supervisee
        // relationship can actually be exercised.
        $this->user('Ahmad Danial', 'student@utp.edu.my', Role::STUDENT, [
            'matric_no' => '22001001',
            'programme' => 'MSc Full-Time',
            'department' => 'Computing',
            'faculty' => 'FSMC',
            'supervisor_id' => $supervisor->id,
        ]);

        $this->user('Nur Farah Adilah', 'student2@utp.edu.my', Role::STUDENT, [
            'matric_no' => '22001002',
            'programme' => 'PhD Part-Time',
            'department' => 'Computing',
            'faculty' => 'FSMC',
            'supervisor_id' => $supervisor->id,
        ]);

        $this->examiners();

        $this->command->newLine();
        $this->command->info('Seeded. Every account uses the password: '.self::PASSWORD);
        $this->command->table(
            ['Role', 'Email'],
            User::orderBy('id')->get(['role', 'email'])->map(fn ($u) => [Role::label($u->role), $u->email])->all()
        );
    }

    /**
     * The university's own list: the two foundation streams, the Faculty of
     * Engineering's six departments and FSMC's four, each filed under its
     * faculty so the picker and the admin list can group them. CGS has none
     * of its own -- it routes MSc and PhD candidates into these same
     * departments at postgraduate level.
     */
    protected const FACULTY_DEPARTMENTS = [
        Faculty::CFS => [
            'Foundation in Business Management & Computing',
            'Foundation in Engineering & Science',
        ],
        Faculty::FOE => [
            'Chemical Engineering',
            'Civil & Environmental Engineering',
            'Electrical & Electronics Engineering',
            'Integrated Engineering',
            'Mechanical Engineering',
            'Petroleum Engineering',
        ],
        Faculty::FSMC => [
            'Applied Sciences',
            'Computing',
            'Geosciences',
            'Management',
        ],
    ];

    /**
     * What the flat seventeen-name list called them, and the canonical name
     * each one became. Accounts move with the name: `users.department` is
     * free text, so an account left under a name nothing lists any more falls
     * out of the Chair and Academic Executive queues that filter on it (see
     * Role::isDepartmentScoped()).
     */
    protected const RENAMED_DEPARTMENTS = [
        'Computer & Information Science' => 'Computing',
        'Computer & Information Sciences' => 'Computing',
        'Information Technology' => 'Computing',
        'Electrical & Electronic Engineering' => 'Electrical & Electronics Engineering',
        'Civil Engineering' => 'Civil & Environmental Engineering',
        'Applied Science' => 'Applied Sciences',
        'Fundamental & Applied Science' => 'Applied Sciences',
        'Geoscience' => 'Geosciences',
        'Petroleum Geoscience' => 'Geosciences',
        'Management & Humanities' => 'Management',
    ];

    /** Left over from that list, and not a department of anything. */
    protected const DROPPED_DEPARTMENTS = [
        'Science',
        'Faculty of Science, Management & Computing',
    ];

    /**
     * One Academic Executive per department, because the examiner-nomination
     * queue is department-scoped (Role::isDepartmentScoped()): a department
     * with nobody on this desk has a queue nothing can clear, which is what
     * the departments screen calls out in amber.
     *
     * Computing is not here -- ae@utp.edu.my, the roster account above,
     * covers it. The rest are addressed ae.<department>@utp.edu.my so the one
     * you want is guessable from the department name.
     */
    protected const ACADEMIC_EXECS = [
        'Foundation in Business Management & Computing' => ['Puan Raihanah Mokhtar', 'ae.foundation.business'],
        'Foundation in Engineering & Science' => ['En Faizal Ramli', 'ae.foundation.engineering'],
        'Chemical Engineering' => ['Puan Hafizah Malik', 'ae.chemical'],
        'Civil & Environmental Engineering' => ['En Khairul Anuar', 'ae.civil'],
        'Electrical & Electronics Engineering' => ['Puan Suraya Ismail', 'ae.electrical'],
        'Integrated Engineering' => ['En Yusri Abdullah', 'ae.integrated'],
        'Mechanical Engineering' => ['En Danial Hakimi', 'ae.mechanical'],
        'Petroleum Engineering' => ['Puan Norazlina Samad', 'ae.petroleum'],
        'Applied Sciences' => ['Puan Vimala Krishnan', 'ae.applied'],
        'Geosciences' => ['En Amirul Hakim', 'ae.geosciences'],
        'Management' => ['Puan Lee Siew Mei', 'ae.management'],
    ];

    /** The faculty each one inherits is the department's own, not a second list. */
    protected function academicExecs(): void
    {
        $facultyOf = [];

        foreach (self::FACULTY_DEPARTMENTS as $faculty => $names) {
            foreach ($names as $name) {
                $facultyOf[$name] = $faculty;
            }
        }

        foreach (self::ACADEMIC_EXECS as $department => [$name, $handle]) {
            $this->user($name, $handle.'@utp.edu.my', Role::ACADEMIC_EXEC, [
                'department' => $department,
                'faculty' => $facultyOf[$department] ?? null,
            ]);
        }
    }

    /**
     * Brings the department list to the canonical one above, so the picker on
     * the "add a user" screen (UserAdminController) and the department screen
     * (DepartmentAdminController) show the university's actual shape.
     *
     * Renaming is the same two-step write DepartmentAdminController::update()
     * does -- the row and every account under the old name, together.
     */
    protected function departments(): void
    {
        foreach (self::RENAMED_DEPARTMENTS as $old => $new) {
            Department::where('name', $old)->delete();
            User::where('department', $old)->update(['department' => $new]);
        }

        foreach (self::FACULTY_DEPARTMENTS as $faculty => $names) {
            foreach ($names as $name) {
                Department::updateOrCreate(['name' => $name], ['faculty' => $faculty, 'is_active' => true]);
            }
        }

        // Deleted rather than retired: nothing is filed under these, so there
        // is no history to keep them valid for. Anything an admin added by
        // hand is left alone -- only the names this seeder itself wrote.
        Department::whereIn('name', self::DROPPED_DEPARTMENTS)
            ->whereNotIn('name', User::whereNotNull('department')->distinct()->pluck('department'))
            ->delete();
    }

    protected function user(string $name, string $email, string $role, array $extra = []): User
    {
        return User::updateOrCreate(['email' => $email], array_merge([
            'name' => $name,
            'role' => $role,
            'password' => Hash::make(self::PASSWORD),
        ], $extra));
    }

    /**
     * One examiner in each of the four states, so the rules are visible --
     * and both lists, so the internal/external split on the pool screen has
     * something to show. The external rows carry the extra record CGS keeps
     * for anyone from outside UTP; the internal ones deliberately do not.
     */
    protected function examiners(): void
    {
        $rows = [
            // Available: never examined, no assignment.
            ['Prof. Dr. Rosli Hamid', 'rosli@utp.edu.my', 'Petroleum Engineering', 'FOE', 'internal', true, null, null, []],
            // Available: last examined well beyond the 90-day gap.
            ['Dr. Chandra Segaran', 'chandra@utp.edu.my', 'Civil Engineering', 'FOE', 'internal', true, '-200 days', null, []],
            // On gap: examined 30 days ago.
            ['Prof. Madya Dr. Nabila Yusof', 'nabila@um.edu.my', 'Computing', null, 'external', true, '-30 days', null, [
                'institution' => 'Universiti Malaya (UM)',
                'sector' => 'research',
                'faculty_approval' => '2.2023',
                'utp_cluster' => 'Intelligent Systems',
                'expertise' => '1. Machine Learning'."\n".'2. Computer Vision',
                'years_experience' => 18,
                'msc_graduated' => 12,
                'phd_graduated' => 6,
                'first_examination_date' => '-2 years',
            ]],
            // Assigned: tied to an active case.
            ['Dr. Tan Boon Keat', 'tan@usm.edu.my', 'Computing', null, 'external', true, null, '+45 days', [
                'institution' => 'Universiti Sains Malaysia (USM)',
                'sector' => 'technical',
                'faculty_approval' => '1.2024',
                'utp_cluster' => 'Software Engineering',
                'expertise' => '1. Software Verification',
                'years_experience' => 11,
                'msc_graduated' => 5,
                'phd_graduated' => 5,
                'first_examination_date' => '-14 months',
            ]],
            // Unavailable: retired.
            ['Prof. Dr. Ismail Bakar', 'ismail@utp.edu.my', 'Chemical Engineering', 'FOE', 'internal', false, null, null, []],
        ];

        foreach ($rows as [$name, $email, $dept, $faculty, $type, $active, $lastExam, $assigned, $external]) {
            if (isset($external['first_examination_date'])) {
                $external['first_examination_date'] = now()->modify($external['first_examination_date']);
            }

            Examiner::updateOrCreate(['email' => $email], $external + [
                'name' => $name,
                'department' => $dept,
                'faculty' => $faculty,
                'type' => $type,
                'is_active' => $active,
                'last_examination_date' => $lastExam ? now()->modify($lastExam) : null,
                'assigned_until' => $assigned ? now()->modify($assigned) : null,
            ]);
        }
    }
}
