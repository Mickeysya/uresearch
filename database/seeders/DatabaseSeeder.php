<?php

namespace Database\Seeders;

use App\Modules\Chloe\Models\LockerKey;
use App\Modules\Chloe\Models\StudentGender;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationBlock;
use App\Modules\Chloe\Models\WorkstationLocation;
use App\Modules\Chloe\Models\WorkstationRequest;
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

    protected static ?string $hash = null;

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

        // Who sits at which CGS desk, corrected 2026-09-22: Puan Waheeda is
        // the Senior Executive and M Syahmi Ifwat M Jafri is the
        // Non-Executive. They were seeded the other way round, which put the
        // examiner-nomination compilation and the final release on the wrong
        // two desks. The addresses stay with the ROLE, not the person, so
        // every doc, test and route that names them still holds.
        $this->user('M Syahmi Ifwat M Jafri', 'cgs@utp.edu.my', Role::NON_EXEC_CGS, ['department' => 'CGS']);
        // Compiles the faculty examiner list and holds the finalised report
        // (ExaminerNominationWorkflow), and rules on a hardbound appeal after
        // the Non-Executive prepares the Dean PFR report.
        $this->user('Puan Waheeda', 'seniorexec@utp.edu.my', Role::SENIOR_EXEC_CGS, ['department' => 'CGS']);
        $this->user('Norshahirah', 'manager@utp.edu.my', Role::MANAGER_CGS, ['department' => 'CGS']);
        $this->user('En Zulkifly', 'director@utp.edu.my', Role::SENIOR_DIRECTOR_CGS, ['department' => 'CGS']);
        $this->user('Prof. Dr. Hafiz Osman', 'dean@utp.edu.my', Role::DEAN_PGR, ['department' => 'PGR']);
        $this->user('Siti Academic Exec', 'ae@utp.edu.my', Role::ACADEMIC_EXEC, [
            'department' => 'Computing',
            'faculty' => 'FSMC',
        ]);
        $this->user('Faculty Office', 'faculty@utp.edu.my', Role::FACULTY, [
            'department' => 'Faculty of Engineering', 'faculty' => 'FOE',
        ]);
        $this->user('Registry Officer', 'registry@utp.edu.my', Role::REGISTRY, ['department' => 'Registry']);
        // Haziq's GA interview stage; the role lives in his module, not Role.
        $this->user('Research Centre Officer', 'researchcentre@utp.edu.my', \App\Modules\Haziq\Support\Roles::RESEARCH_CENTRE, ['department' => 'Research Centre']);
        $this->user('System Admin', 'admin@utp.edu.my', Role::ADMIN);

        // Students, all attached to the supervisor above so the supervisee
        // relationship can actually be exercised.
        $student = $this->user('Ahmad Danial', 'student@utp.edu.my', Role::STUDENT, [
            'matric_no' => '22001001',
            'programme' => 'MSc Full-Time',
            'department' => 'Computing',
            'faculty' => 'FSMC',
            'supervisor_id' => $supervisor->id,
        ]);

        $student2 = $this->user('Nur Farah Adilah', 'student2@utp.edu.my', Role::STUDENT, [
            'matric_no' => '22001002',
            'programme' => 'PhD Part-Time',
            'department' => 'Computing',
            'faculty' => 'FSMC',
            'supervisor_id' => $supervisor->id,
        ]);

        $this->cohort();
        $this->examiners();
        $this->workstations();
        $this->studyCandidacies();

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

    /**
     * One Chair per department, for the same reason as the Academic
     * Executives above: the Chair queue is department-scoped, so a department
     * without one strands every request at the Chair stage. Computing is
     * chair@utp.edu.my; Chemical keeps DemoDataSeeder's Dr. Mei Ling Tan.
     */
    protected const CHAIRS = [
        'Foundation in Business Management & Computing' => ['Dr. Rosnah Abdul Karim', 'chair.foundation.business'],
        'Foundation in Engineering & Science' => ['Dr. Kamarul Ariffin', 'chair.foundation.engineering'],
        'Chemical Engineering' => ['Dr. Mei Ling Tan', 'meiling.tan'],
        'Civil & Environmental Engineering' => ['Dr. Hisham Mohamad', 'chair.civil'],
        'Electrical & Electronics Engineering' => ['Dr. Rajesh Kumar Nair', 'chair.electrical'],
        'Integrated Engineering' => ['Dr. Azlan Shah Ibrahim', 'chair.integrated'],
        'Mechanical Engineering' => ['Dr. Tan Boon Huat', 'chair.mechanical'],
        'Petroleum Engineering' => ['Dr. Nurul Huda Zainal', 'chair.petroleum'],
        'Applied Sciences' => ['Dr. Lakshmi Devi Subramaniam', 'chair.applied'],
        'Geosciences' => ['Dr. Mohd Fauzi Hassan', 'chair.geosciences'],
        'Management' => ['Dr. Wong Pei Shan', 'chair.management'],
    ];

    /**
     * Two supervisors per department (24). Computing's first is the roster's
     * supervisor@utp.edu.my; Kumaran, Farid and Siti are DemoDataSeeder's,
     * kept on the same addresses so the two seeders agree.
     */
    protected const SUPERVISORS = [
        'Foundation in Business Management & Computing' => [['Dr. Hasliza Omar', 'hasliza.omar'], ['Mr. Daniel Lee Chee Wai', 'daniel.lee']],
        'Foundation in Engineering & Science' => [['Dr. Suhaimi Yahya', 'suhaimi.yahya'], ['Dr. Kalaivani Murugan', 'kalaivani.murugan']],
        'Chemical Engineering' => [['Dr. Farid Kamal', 'farid.kamal'], ['Dr. Lau Kok Keong', 'lau.kokkeong']],
        'Civil & Environmental Engineering' => [['Dr. Noor Azlina Hamid', 'azlina.hamid'], ['Dr. Teo Wee Liang', 'teo.weeliang']],
        'Electrical & Electronics Engineering' => [['Dr. Siti Nurhaliza', 'siti.nurhaliza'], ['Dr. Balachandran Ramasamy', 'bala.ramasamy']],
        'Integrated Engineering' => [['Dr. Khairil Anwar Rosli', 'khairil.rosli'], ['Dr. Ooi Mei Fong', 'ooi.meifong']],
        'Mechanical Engineering' => [['Dr. Faizal Mustapha', 'faizal.mustapha'], ['Dr. Ng Siew Ling', 'ng.siewling']],
        'Petroleum Engineering' => [['Dr. Zulkifli Hamzah', 'zulkifli.hamzah'], ['Dr. Anitha Pillai', 'anitha.pillai']],
        'Applied Sciences' => [['Dr. Rohana Ismail', 'rohana.ismail'], ['Dr. Chua Boon Leng', 'chua.boonleng']],
        'Computing' => [['Dr. Aisyah Rahman', 'supervisor'], ['Prof. Dr. Kumaran Vellu', 'kumaran.vellu']],
        'Geosciences' => [['Dr. Mohd Rizal Ahmad', 'rizal.ahmad'], ['Dr. Yeap Siew Hoon', 'yeap.siewhoon']],
        'Management' => [['Dr. Nor Hafizah Aziz', 'hafizah.aziz'], ['Dr. Sanjay Menon', 'sanjay.menon']],
    ];

    /**
     * The CGS officers (was database/sql/cgs_officers.sql), with the role the
     * CGS Staff Directory gives them: its Senior Executives are Senior
     * Executive CGS, its Senior Assistants Non-Executive CGS. Six are one per
     * team member's modules; Aqil is the directory's third Senior Executive.
     * Core scopes queues by role, so each still sees every queue of that
     * role; a module that emails only its own officer says so itself
     * (Norhanis' Config/cgs.php).
     */
    protected const CGS_OFFICERS = [
        ['Puan Waheeda', 'waheda.cgs', Role::SENIOR_EXEC_CGS], // Hani
        ['Akmar', 'akmar.cgs', Role::SENIOR_EXEC_CGS],         // Haziq
        ['Aqil', 'aqil.cgs', Role::SENIOR_EXEC_CGS],
        ['Norshahira', 'norshahira.cgs', Role::NON_EXEC_CGS], // Norhanis
        ['Amirul', 'amirul.cgs', Role::NON_EXEC_CGS],       // Chloe
        ['Zulkifly', 'zulkifly.cgs', Role::NON_EXEC_CGS],   // Nureen
        ['Syahmi', 'syahmi.cgs', Role::NON_EXEC_CGS],       // Jason
    ];

    /** Per department, in FACULTY_DEPARTMENTS order: [male, female]. 109 + 71 = 180. */
    protected const COHORT = [[9, 6], [8, 7], [10, 5], [9, 6], [8, 7], [9, 6], [10, 5], [9, 6], [8, 7], [10, 5], [9, 6], [10, 5]];

    protected const NAMES = [
        'male' => [
            'malay' => [['Ahmad Faris', 'Muhammad Irfan', 'Amirul Hakim', 'Haziq Danial', 'Syafiq Aiman', 'Firdaus Hakimi', 'Aiman Hafiz', 'Luqman Hakim', 'Nabil Arif', 'Hafiy Iskandar', 'Zikri Asyraf', 'Ikhwan Zarif', 'Adam Rayyan', 'Danish Haikal', 'Arif Fahmi'], ['Ismail', 'Abdullah', 'Rahman', 'Hassan', 'Yusof', 'Ibrahim', 'Osman', 'Zainal', 'Kamarudin', 'Salleh']],
            'chinese' => [['Tan', 'Lim', 'Lee', 'Wong', 'Ng', 'Ong', 'Chong', 'Chan', 'Goh', 'Yap', 'Teh', 'Loh', 'Yeoh', 'Chin', 'Koh'], ['Wei Jie', 'Jun Hao', 'Zhi Hao', 'Kai Xiang', 'Jia Hao', 'Wen Kai', 'Hong Wei', 'Chee Keong', 'Boon Kiat', 'Yong Sheng']],
            'indian' => [['Arjun', 'Karthik', 'Vikram', 'Pravin', 'Suresh', 'Ganesh', 'Ravi', 'Dinesh', 'Harish', 'Naveen'], ['Subramaniam', 'Krishnan', 'Muthu', 'Rajendran', 'Ganesan', 'Pillai', 'Nair', 'Raman', 'Selvam', 'Kumar']],
        ],
        'female' => [
            'malay' => [['Nur Aisyah', 'Siti Hajar', 'Nurul Izzah', 'Farah Nadhirah', 'Aina Sofea', 'Nur Insyirah', 'Alya Maisarah', 'Hani Batrisya', 'Syazwani Amira', 'Nadia Huda', 'Puteri Balqis', 'Iman Safiyya', 'Athirah Husna', 'Liyana Qistina', 'Damia Irdina'], ['Ismail', 'Abdullah', 'Rahman', 'Hassan', 'Yusof', 'Ibrahim', 'Osman', 'Zainal', 'Kamarudin', 'Salleh']],
            'chinese' => [['Tan', 'Lim', 'Lee', 'Wong', 'Ng', 'Ong', 'Chong', 'Chan', 'Goh', 'Yap', 'Teh', 'Loh', 'Yeoh', 'Chin', 'Koh'], ['Xin Yi', 'Hui Min', 'Mei Ling', 'Jia Wen', 'Pei Shan', 'Yi Ting', 'Siew Mei', 'Li Ying', 'Shu Fen', 'Wen Qi']],
            'indian' => [['Priya', 'Kavitha', 'Divya', 'Shalini', 'Revathi', 'Anusha', 'Deepa', 'Nisha', 'Meena', 'Thivya'], ['Subramaniam', 'Krishnan', 'Muthu', 'Rajendran', 'Ganesan', 'Pillai', 'Nair', 'Raman', 'Selvam', 'Kumar']],
        ],
    ];

    /** @return array<string, string> department => faculty */
    protected function facultyOf(): array
    {
        $facultyOf = [];

        foreach (self::FACULTY_DEPARTMENTS as $faculty => $names) {
            foreach ($names as $name) {
                $facultyOf[$name] = $faculty;
            }
        }

        return $facultyOf;
    }

    /** Academic Executives, Chairs, Supervisors and the CGS officers. The faculty each inherits is the department's own. */
    protected function academicExecs(): void
    {
        $facultyOf = $this->facultyOf();

        foreach ([Role::ACADEMIC_EXEC => self::ACADEMIC_EXECS, Role::CHAIR => self::CHAIRS] as $role => $staff) {
            foreach ($staff as $department => [$name, $handle]) {
                $this->user($name, $handle.'@utp.edu.my', $role, [
                    'department' => $department,
                    'faculty' => $facultyOf[$department] ?? null,
                ]);
            }
        }

        foreach (self::SUPERVISORS as $department => $pair) {
            foreach ($pair as [$name, $handle]) {
                $this->user($name, $handle.'@utp.edu.my', Role::SUPERVISOR, [
                    'department' => $department,
                    'faculty' => $facultyOf[$department] ?? null,
                ]);
            }
        }

        foreach (self::CGS_OFFICERS as [$name, $handle, $role]) {
            $this->user($name, $handle.'@utp.edu.my', $role, ['department' => 'CGS']);
        }
    }

    /**
     * 180 students, 15 per department (COHORT), matric 2410DDNN (DD the
     * department's place in FACULTY_DEPARTMENTS, NN 01-15), email
     * <name>_<matric>@utp.edu.my. Names are a pure function of the gender's
     * running count, so every run produces the same people. Supervisors
     * alternate between the department's two. Gender goes in
     * student_genders: `users` gets no gender column (rule 2).
     */
    protected function cohort(): void
    {
        $facultyOf = $this->facultyOf();
        $programmes = ['MSc Full-Time', 'PhD Full-Time', 'MSc Part-Time', 'PhD Part-Time'];
        $count = ['male' => 0, 'female' => 0];
        $d = 0;

        foreach ($facultyOf as $department => $faculty) {
            [$male, $female] = self::COHORT[$d++];
            $supervisors = collect(self::SUPERVISORS[$department])
                ->map(fn ($s) => User::where('email', $s[1].'@utp.edu.my')->value('id'))->all();
            $genders = array_merge(array_fill(0, $male, 'male'), array_fill(0, $female, 'female'));

            foreach ($genders as $n => $gender) {
                $name = $this->cohortName($gender, $count[$gender]++);
                $matric = sprintf('2410%02d%02d', $d, $n + 1);
                $slug = preg_replace('/[^a-z]/', '', str_replace([' bin ', ' binti '], ' ', strtolower($name)));

                $student = $this->user($name, "{$slug}_{$matric}@utp.edu.my", Role::STUDENT, [
                    'matric_no' => $matric,
                    'programme' => $programmes[$n % 4],
                    'department' => $department,
                    'faculty' => $faculty,
                    'supervisor_id' => $supervisors[$n % 2],
                ]);

                StudentGender::updateOrCreate(['student_id' => $student->id], ['gender' => $gender]);

                // Workstation and Study Candidacy both need an active candidacy with a future expiry
                // (WorkstationEligibility). Expiry spread 3-35 months out; firstOrCreate so a re-run
                // never moves dates CGS or a demo has since changed.
                $expiry = now()->startOfDay()->addMonths(3 + ($n * 7 + $d) % 33);
                StudyCandidacy::firstOrCreate(['student_id' => $student->id], [
                    'programme_start_date' => $expiry->copy()->subMonths(str_starts_with($programmes[$n % 4], 'PhD') ? 48 : 30),
                    'candidacy_expiry_date' => $expiry,
                    'status' => StudyCandidacy::STATUS_ACTIVE,
                ]);
            }
        }
    }

    /** The i-th name of a gender: Malay, Chinese, Indian in turn. Pairs never repeat within the 109/71 a gender needs. */
    protected function cohortName(string $gender, int $i): string
    {
        $k = intdiv($i, 3);

        return match ($i % 3) {
            0 => (fn ($p) => $p[0][$k % 15].($gender === 'male' ? ' bin ' : ' binti ').$p[1][($k + intdiv($k, 15)) % 10])(self::NAMES[$gender]['malay']),
            1 => (fn ($p) => $p[0][$k % 15].' '.$p[1][($k + intdiv($k, 15)) % 10])(self::NAMES[$gender]['chinese']),
            2 => (fn ($p) => $p[0][$k % 10].' '.$p[1][($k + intdiv($k, 10)) % 10])(self::NAMES[$gender]['indian']),
        };
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
            // One bcrypt for the whole run: ~250 accounts x a fresh hash is most of a minute.
            'password' => self::$hash ??= Hash::make(self::PASSWORD),
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
            ['Dr. Chandra Segaran', 'chandra@utp.edu.my', 'Civil & Environmental Engineering', 'FOE', 'internal', true, '-200 days', null, []],
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

            // One available internal in each department the seeded candidates
            // actually belong to. The internal half of a panel has to come
            // from the candidate's own department -- see
            // ExaminerNominationController::store() -- so without these there
            // is no legal nomination to file for most of them.
            ['Dr. Lim Kah Meng', 'lim.kahmeng@utp.edu.my', 'Computing', 'FSMC', 'internal', true, null, null, []],
            ['Dr. Noraini Abdul Ghani', 'noraini.ghani@utp.edu.my', 'Chemical Engineering', 'FOE', 'internal', true, null, null, []],
            ['Dr. Hafiz Zainuddin', 'hafiz.zainuddin@utp.edu.my', 'Electrical & Electronics Engineering', 'FOE', 'internal', true, null, null, []],

            // An AVAILABLE external. Without one there is no legal panel to
            // file at all: every nomination needs an external main, and the
            // two externals above are deliberately parked in "assigned" and
            // "on gap" to show those states.
            ['Prof. Dr. Ariffin Samsuri', 'ariffin@utm.edu.my', 'External', null, 'external', true, null, null, [
                'institution' => 'Universiti Teknologi Malaysia (UTM)',
                'sector' => 'research',
                'faculty_approval' => '3.2025',
                'utp_cluster' => 'Petroleum Engineering',
                'expertise' => '1. Reservoir Engineering'."\n".'2. Enhanced Oil Recovery',
                'years_experience' => 22,
                'msc_graduated' => 20,
                'phd_graduated' => 9,
                'first_examination_date' => '-3 years',
            ]],
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

    /**
     * The real CGS seat catalogue (see roomDefinitions()), with every seat
     * the reference PDF itself showed occupied or under maintenance seeded
     * to match — plus one of those occupied seats given a real
     * WorkstationRequest and an overdue locker key, so the CGS operations
     * screen and `workstation:remind-locker-keys` have something to show
     * immediately. The other ~147 occupied seats have no request behind
     * them, because the reference recorded occupancy, not who — matching
     * that is more honest than inventing occupants. See
     * Workstation::activeRequest().
     */
    protected function workstations(): void
    {
        foreach (['J2' => 'Block J2', 'N2' => 'Block N2'] as $code => $name) {
            WorkstationBlock::updateOrCreate(['code' => $code], ['name' => $name]);
        }

        // Both entrances were photographed on site.
        WorkstationBlock::where('code', 'J2')->update(['photo_path' => 'images/workstation/block-j2.jpg']);
        WorkstationBlock::where('code', 'N2')->update(['photo_path' => 'images/workstation/block-n2.jpg']);

        foreach ($this->roomDefinitions() as $definition) {
            $location = WorkstationLocation::updateOrCreate(
                ['room_code' => $definition['room_code']],
                [
                    'block' => $definition['block'],
                    'gender' => $definition['gender'],
                    'name' => $definition['name'],
                    'description' => $definition['description'] ?? null,
                    'photo_path' => $definition['photo_path'] ?? null,
                ]
            );

            $position = 0;

            // Each cluster is a list of rows (left-to-right reading order
            // per row, top-to-bottom), not a flat list -- several clusters
            // on the reference are a 1- or 2-wide column of several rows,
            // not one wide row, and grouping them as a single flat list
            // rendered them as one long horizontal strip instead. `cluster`
            // on Workstation stays the grouping key the view renders one
            // row from, so a multi-row cluster is stored under one DB
            // "cluster" value per row, suffixed "Row N", rather than all of
            // it under one value.
            foreach ($definition['clusters'] as $clusterLabel => $rows) {
                $multiRow = count($rows) > 1;

                foreach ($rows as $rowIndex => $seatNumbers) {
                    $rowLabel = $multiRow ? "{$clusterLabel} Row " . ($rowIndex + 1) : $clusterLabel;

                    foreach ($seatNumbers as $seatNumber) {
                        $position++;

                        Workstation::updateOrCreate(
                            ['workstation_location_id' => $location->id, 'seat_code' => (string) $seatNumber],
                            ['cluster' => $rowLabel, 'position' => $position]
                            // Deliberately no 'status' here: updateOrCreate only
                            // sets it on first insert (default 'available'), so
                            // re-running the seeder never clobbers a seat a demo
                            // run has since allocated, force-assigned or
                            // disabled. The two passes below set status
                            // explicitly, every run, to match the reference.
                        );
                    }
                }
            }
        }

        foreach ($this->occupiedSeatNumbers() as $roomCode => $seatNumbers) {
            if (empty($seatNumbers)) {
                continue;
            }

            Workstation::whereHas('location', fn ($q) => $q->where('room_code', $roomCode))
                ->whereIn('seat_code', array_map('strval', $seatNumbers))
                ->update(['status' => Workstation::STATUS_OCCUPIED]);
        }

        foreach ($this->maintenanceSeatNumbers() as $roomCode => $seatNumbers) {
            Workstation::whereHas('location', fn ($q) => $q->where('room_code', $roomCode))
                ->whereIn('seat_code', array_map('strval', $seatNumbers))
                ->update(['status' => Workstation::STATUS_DISABLED]);
        }

        // Seat 11 (N2-02-01) is already marked occupied by the pass
        // above; this attaches the one demo WorkstationRequest + overdue
        // LockerKey so there's a concrete "known occupant" example too.
        $demoStudent = User::where('email', 'student2@utp.edu.my')->first();
        $demoSeat = Workstation::whereHas('location', fn ($q) => $q->where('room_code', 'N2-02-01'))
            ->where('seat_code', '11')
            ->first();

        if ($demoStudent && $demoSeat && ! WorkstationRequest::where('workstation_id', $demoSeat->id)
                ->where('status', WorkstationRequest::STATUS_CONFIRMED)->exists()) {
            $request = WorkstationRequest::create([
                'workstation_id' => $demoSeat->id,
                'student_id' => $demoStudent->id,
                'status' => WorkstationRequest::STATUS_CONFIRMED,
                'requested_at' => now()->subDays(6),
            ]);

            LockerKey::create([
                'workstation_request_id' => $request->id,
                'student_id' => $demoStudent->id,
                'status' => LockerKey::STATUS_REQUESTED,
                'requested_at' => now()->subDays(5),
            ]);
        }

        // Demo gender assignments — `users` has no gender column (Hard Rule
        // 2), so this module tracks it itself. See StudentGender.
        $student = User::where('email', 'student@utp.edu.my')->first();

        if ($student) {
            StudentGender::updateOrCreate(['student_id' => $student->id], ['gender' => 'male']);
        }

        if ($demoStudent) {
            StudentGender::updateOrCreate(['student_id' => $demoStudent->id], ['gender' => 'female']);
        }
    }

    /**
     * Every seat the reference PDF itself showed red ("NOT AVAILABLE"),
     * keyed by room_code, transcribed slide by slide alongside
     * roomDefinitions(). Seats not listed here stay at the 'available'
     * default.
     *
     * @return array<string, array<int, int>>
     */
    protected function occupiedSeatNumbers(): array
    {
        return [
            // Seat 2 was missing from this list before -- the reference
            // shows the whole bottom two rows red except seat 17, so it
            // belongs here alongside the rest of 1-16.
            'N2-02-01' => [16, 15, 14, 13, 12, 11, 6, 7, 8, 9, 10, 5, 4, 3, 2, 1],
            'N2-02-03' => [28, 29, 30, 32, 33, 34, 27, 25, 23, 19, 20, 21, 22],
            'N2-02-05' => [51, 50, 49, 46, 45, 40, 41, 42, 44, 39, 38, 37, 35],
            'N2-02-07' => [61, 60, 53, 54, 55, 56],
            'N2-02-11' => [81, 82],
            'N2-02-12' => [119, 92, 93, 96],
            'N2-02-14' => [],
            // Re-verified against the reference: 175 and 158 were missing
            // from this list, and 173/168/174/167/184/185/187/186 were
            // wrongly listed as occupied when the reference shows them
            // green (available).
            'J2-01-06' => [
                179, 175, 180, 176, 181, 177, 182, 171, 170, 172, 169,
                155, 156, 157, 158, 192, 193, 190, 189, 166, 165, 163,
                159, 160, 161, 162,
            ],
            // Confirmed by Chloe against her own copy of the reference
            // (restated available list matched exactly): the complement of
            // {197,198,200,201,202,213,218,221,223,225,226,228,229,230,231,
            // 232,233,234,235,239,241,246} within 194-251.
            'J2-01-05' => [
                194, 195, 196, 199, 203, 204, 205, 206, 207, 208, 209, 210, 211, 212,
                214, 215, 216, 217, 219, 220, 222, 224, 227, 236, 237, 238, 240, 242,
                243, 244, 245, 247, 248, 249, 250, 251,
            ],
            // Confirmed by Chloe: the complement of
            // {255,259,261,263,265,267,269,271,280,284,295,297,304,305,306,
            // 307,308,312,313,314} and the maintenance set below, within
            // 252-315.
            'J2-01-03' => [
                252, 253, 254, 256, 257, 260, 262, 264, 266, 268, 270, 272, 273, 274,
                275, 276, 277, 278, 279, 281, 282, 283, 285, 287, 288, 289, 290, 291,
                292, 293, 294, 296, 298, 299, 300, 301, 302, 309, 310, 311, 315,
            ],
        ];
    }

    /**
     * Every seat the reference PDF showed yellow ("MAINTAINANCE"), keyed by
     * room_code. Mapped onto Workstation::STATUS_DISABLED — see
     * Workstation::statuses().
     *
     * @return array<string, array<int, int>>
     */
    protected function maintenanceSeatNumbers(): array
    {
        return [
            'J2-01-03' => [258, 286, 303],
        ];
    }

    /**
     * The real CGS workstation catalogue — every block, room, gender
     * designation, desk cluster and seat number, transcribed from
     * "Workstation seat map.pdf" (docs/scope/, 13 slides). Seat numbers run
     * 1-315 continuously across both blocks, not restarted per room; cluster
     * order and seat order within each cluster match the reference exactly,
     * since neither can be derived from the seat numbers alone (several
     * rows count down, not up).
     *
     * @return array<int, array{block: string, room_code: string, gender: string, name: string, description?: string, photo_path?: string, clusters: array<string, array<int, array<int, int>>>}>
     */
    protected function roomDefinitions(): array
    {
        return [
            // --- Block N2 -----------------------------------------------
            [
                'block' => 'N2', 'room_code' => 'N2-02-01', 'gender' => 'male', 'name' => 'N2-02-01',
                'photo_path' => 'images/workstation/rooms/n2-02-01.jpg',
                'clusters' => [
                    'Cluster 1' => [[17, 16, 15, 14, 13, 12, 11]],
                    'Cluster 2' => [[6, 7, 8, 9, 10]],
                    'Cluster 3' => [[5, 4, 3, 2, 1]],
                ],
            ],
            [
                'block' => 'N2', 'room_code' => 'N2-02-03', 'gender' => 'male', 'name' => 'N2-02-03',
                'photo_path' => 'images/workstation/rooms/n2-02-03.jpg',
                'clusters' => [
                    'Cluster 1' => [[28, 29, 30, 31, 32, 33, 34]],
                    'Cluster 2' => [[27, 26, 25, 24, 23]],
                    'Cluster 3' => [[18, 19, 20, 21, 22]],
                ],
            ],
            [
                'block' => 'N2', 'room_code' => 'N2-02-05', 'gender' => 'female', 'name' => 'N2-02-05',
                'photo_path' => 'images/workstation/rooms/n2-02-05.jpg',
                'clusters' => [
                    'Cluster 1' => [[51, 50, 49, 48, 47, 46, 45]],
                    'Cluster 2' => [[40, 41, 42, 43, 44]],
                    'Cluster 3' => [[39, 38, 37, 36, 35]],
                ],
            ],
            [
                'block' => 'N2', 'room_code' => 'N2-02-07', 'gender' => 'female', 'name' => 'N2-02-07',
                'photo_path' => 'images/workstation/rooms/n2-02-07.jpg',
                'clusters' => [
                    'Cluster 1' => [[66, 67, 68, 69, 70, 71, 72, 73, 74]],
                    'Cluster 2' => [[65, 64, 63, 62, 61, 60, 59]],
                    'Cluster 3' => [[52, 53, 54, 55, 56, 57, 58]],
                ],
            ],
            [
                'block' => 'N2', 'room_code' => 'N2-02-11', 'gender' => 'female', 'name' => 'N2-02-11',
                'photo_path' => 'images/workstation/rooms/n2-02-11.jpg',
                'clusters' => [
                    'Cluster 1' => [[91, 90, 89, 88, 87, 86, 85]],
                    'Cluster 2' => [[80, 81, 82, 83, 84]],
                    'Cluster 3' => [[79, 78, 77, 76, 75]],
                ],
            ],
            [
                'block' => 'N2', 'room_code' => 'N2-02-12', 'gender' => 'male', 'name' => 'N2-02-12',
                'photo_path' => 'images/workstation/rooms/n2-02-12.jpg',
                'clusters' => [
                    'Cluster 1' => [[110, 111, 112, 113, 114, 115, 116, 117, 118, 119]],
                    'Cluster 2' => [[109, 108, 107, 106, 105, 104, 103, 102, 101]],
                    'Cluster 3' => [[92, 93, 94, 95, 96, 97, 98, 99, 100]],
                ],
            ],
            [
                'block' => 'N2', 'room_code' => 'N2-02-14', 'gender' => 'male', 'name' => 'N2-02-14',
                'photo_path' => 'images/workstation/rooms/n2-02-14.jpg',
                'clusters' => [
                    'Cluster 1' => [[142, 143, 144, 145, 146, 147, 148, 149, 150, 151, 152, 153, 154]],
                    'Cluster 2' => [[141, 140, 139, 138, 137, 136, 135, 134, 133, 132, 131]],
                    'Cluster 3' => [[120, 121, 122, 123, 124, 125, 126, 127, 128, 129, 130]],
                ],
            ],

            // --- Block J2 -----------------------------------------------
            //
            // Unlike N2, most of J2's clusters are a narrow 1- or 2-seat-wide
            // column running several rows deep, not one wide row -- each
            // inner array below is one row of that column, read top to
            // bottom, exactly as laid out on the reference.
            [
                'block' => 'J2', 'room_code' => 'J2-01-06', 'gender' => 'male', 'name' => 'J2-01-06',
                'description' => 'PG Lab Male 1.9 -- table and pantry along the west wall.',
                'photo_path' => 'images/workstation/rooms/j2-01-06.jpg',
                'clusters' => [
                    'Cluster 1' => [[179, 175], [180, 176], [181, 177], [182, 178]],
                    'Cluster 2' => [[171, 170], [172, 169], [173, 168], [174, 167]],
                    'Cluster 3' => [[155], [156], [157], [158]],
                    'Cluster 4' => [[191], [192], [193]],
                    'Cluster 5' => [[190, 183], [189, 184], [188, 185], [187, 186]],
                    'Cluster 6' => [[166], [165], [164], [163]],
                    'Cluster 7' => [[159], [160], [161], [162]],
                ],
            ],
            [
                'block' => 'J2', 'room_code' => 'J2-01-05', 'gender' => 'male', 'name' => 'J2-01-05',
                'description' => 'PG Lab Male 1.2 -- prayer room, table, pantry and stairs along this room\'s walls.',
                'photo_path' => 'images/workstation/rooms/j2-01-05.jpg',
                'clusters' => [
                    'Cluster 1' => [[248, 247], [249, 246], [250, 245], [251, 244]],
                    'Cluster 2' => [[232, 231], [233, 230], [234, 229], [235, 228]],
                    'Cluster 3' => [[220, 219], [221, 218], [222, 217], [223, 216]],
                    'Cluster 4' => [[204, 203], [205, 202], [206, 201], [207, 200]],
                    'Cluster 5' => [[243, 236], [242, 237], [241, 238], [240, 239]],
                    'Cluster 6' => [[227, 224], [226, 225]],
                    'Cluster 7' => [[215, 208], [214, 209], [213, 210], [212, 211]],
                    // Ragged: the reference shows 199 and 198 each alone in
                    // their own row, only 197/194 and 196/195 actually pair
                    // up -- not a uniform 2-wide block like the clusters
                    // above.
                    'Cluster 8' => [[199], [198], [197, 194], [196, 195]],
                ],
            ],
            [
                'block' => 'J2', 'room_code' => 'J2-01-03', 'gender' => 'female', 'name' => 'J2-01-03',
                'description' => 'PG Lab Female 1.1 -- two discussion tables and a discussion room along the south wall.',
                'photo_path' => 'images/workstation/rooms/j2-01-03.jpg',
                'clusters' => [
                    'Cluster 1' => [[271]],
                    'Cluster 2' => [[260], [259], [258], [257], [256], [255], [254], [253], [252]],
                    'Cluster 3' => [[261, 270], [262, 269], [263, 268], [264, 267], [265, 266]],
                    // The last row (276, 277) sits apart from this block,
                    // past the discussion room, but is read in the same
                    // left-to-right pair order as the rows above it.
                    'Cluster 4' => [[272, 281], [273, 280], [274, 279], [275, 278], [276, 277]],
                    'Cluster 5' => [[282, 289], [283, 288], [284, 287], [285, 286]],
                    'Cluster 6' => [[290, 297], [291, 296], [292, 295], [293, 294]],
                    // Same as Cluster 4 -- the last row (303, 304) is the
                    // separate pair past the discussion room.
                    'Cluster 7' => [[298, 309], [299, 308], [300, 307], [301, 306], [302, 305], [303, 304]],
                    'Cluster 8' => [[310], [311], [312], [313], [314], [315]],
                ],
            ],
        ];
    }

    /**
     * Two candidacies, each set up so the matching scheduled command has
     * real work to do rather than something pre-faked into place — run
     * `candidacy:remind` and `candidacy:generate-dismissals` to see them
     * fire. Demo path: Ahmad submits an appeal from the reminder, walks it
     * through Supervisor -> Chair -> CGS -> Dean; Nur Farah's already-expired,
     * never-appealed candidacy shows up on the dismissal list untouched.
     */
    protected function studyCandidacies(): void
    {
        $ahmad = User::where('email', 'student@utp.edu.my')->first();
        $nurFarah = User::where('email', 'student2@utp.edu.my')->first();

        if ($ahmad) {
            StudyCandidacy::updateOrCreate(
                ['student_id' => $ahmad->id],
                [
                    'programme_start_date' => now()->subYears(2),
                    // Inside the 90-day reminder window (see
                    // SendCandidacyReminders) the moment the command runs.
                    'candidacy_expiry_date' => now()->addDays(60),
                    'status' => StudyCandidacy::STATUS_ACTIVE,
                ]
            );
        }

        if ($nurFarah) {
            StudyCandidacy::updateOrCreate(
                ['student_id' => $nurFarah->id],
                [
                    'programme_start_date' => now()->subYears(4),
                    'candidacy_expiry_date' => now()->subDays(10),
                    'status' => StudyCandidacy::STATUS_ACTIVE,
                ]
            );
        }
    }
}
