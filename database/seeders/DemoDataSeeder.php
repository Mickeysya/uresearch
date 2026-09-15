<?php

namespace Database\Seeders;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Norhanis\Models\Candidacy;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerNomination;
use App\Modules\Norhanis\Models\TravelDetail;
use App\Modules\Nureen\Models\AttendanceAppealDetail;
use App\Modules\Nureen\Models\AttendanceRecord;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Models\GaExtensionDetail;
use App\Modules\Nureen\Models\SupervisionDetail;
use App\Modules\Nureen\Support\AttendanceRiskEvaluator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Populates every figure on all three dashboards (student, CGS, admin) so a
 * demo doesn't open onto empty panels and "0" everywhere. NOT part of the
 * normal setup path -- DatabaseSeeder stays the minimal, fast, one-account-
 * per-role seeder setup.sh runs. This is opt-in, for showing the app off:
 *
 *   php artisan demo:seed             (the "default" scenario below)
 *   php artisan demo:seed heavy       (any key in SCENARIOS)
 *
 * Every application goes through WorkflowEngine::submit()/decide(), exactly
 * as a real submission would -- never a direct write to applications.status
 * or current_stage (rule 1). Date::setTestNow() is set around each engine
 * call so the history reads as though it happened over the last couple of
 * months, then cleared -- the rest of the app must never see a frozen clock.
 * Raw SQL against those two columns would be simpler, but it is exactly the
 * shortcut rule 1 exists to rule out, demo data included.
 *
 * Safe to run more than once: staff/students are matched on email
 * (updateOrCreate), so re-running adds another batch of applications and
 * attendance periods on top rather than erroring on duplicates. If you want
 * a clean slate first, run ./reset.sh, then re-seed.
 *
 * TO ADD YOUR OWN VERSION: copy one of the arrays below, rename the key, and
 * `php artisan demo:seed your-name` runs it -- nothing else in this file
 * needs to change.
 */
class DemoDataSeeder extends Seeder
{
    /**
     * @var array<string, array{
     *     extra_students: int,
     *     attendance_months: int,
     *     cap: ?int,
     *     repeat: int,
     *     force_outcome: ?string,
     *     label: string,
     * }>
     *
     * extra_students     how many students to add beyond student@/student2@
     * attendance_months  how many monthly attendance periods per student
     * cap                use only the first N entries of each module's
     *                    built-in list (null = all of them)
     * repeat             run every module's list this many times over, each
     *                    pass backdated further into the past -- a cheap way
     *                    to get real volume without writing new content
     * force_outcome      'approve' walks every chain to the end and approves
     *                    it, ignoring the built-in mix of pending/rejected;
     *                    null uses that mix as written
     */
    public const SCENARIOS = [
        'light' => [
            'extra_students' => 6,
            'attendance_months' => 3,
            'cap' => 3,
            'repeat' => 1,
            'force_outcome' => null,
            'label' => 'A handful of everything -- quick to look at, not overwhelming.',
        ],
        'default' => [
            'extra_students' => 16,
            'attendance_months' => 5,
            'cap' => null,
            'repeat' => 1,
            'force_outcome' => null,
            'label' => 'The standard mix used for a general walkthrough.',
        ],
        'heavy' => [
            'extra_students' => 40,
            'attendance_months' => 6,
            'cap' => null,
            'repeat' => 3,
            'force_outcome' => null,
            'label' => 'A busy portal -- lots of students, a deep backlog in every queue.',
        ],
        'mostly_approved' => [
            'extra_students' => 16,
            'attendance_months' => 5,
            'cap' => null,
            'repeat' => 1,
            'force_outcome' => 'approve',
            'label' => 'A clean "it works" walkthrough -- almost everything approved, few pending, nothing rejected.',
        ],
    ];

    protected WorkflowEngine $engine;

    /** @var array{extra_students: int, attendance_months: int, cap: ?int, repeat: int, force_outcome: ?string, label: string} */
    protected array $scenario;

    /** @var array<string, User> fixed role => the one account that decides at that stage */
    protected array $deciders = [];

    /** Stage-role chains, in decision order. Mirrors each Workflow::stages(). */
    protected const CHAINS = [
        'travel_local' => [Role::SUPERVISOR, Role::CHAIR],
        'travel_intl' => [Role::SUPERVISOR, Role::CHAIR, Role::NON_EXEC_CGS, Role::DEAN_PGR],
        'ga_extension' => [Role::SUPERVISOR, Role::NON_EXEC_CGS, Role::SENIOR_DIRECTOR_CGS],
        'supervision' => [Role::SUPERVISOR, Role::NON_EXEC_CGS],
        'ga_certification' => [Role::NON_EXEC_CGS, Role::SENIOR_DIRECTOR_CGS],
        'attendance_appeal' => [Role::NON_EXEC_CGS],
        'examiner_nomination' => [Role::ACADEMIC_EXEC],
    ];

    public function run(?string $scenario = null): void
    {
        $scenario ??= 'default';

        if (! array_key_exists($scenario, self::SCENARIOS)) {
            $this->command->error(sprintf(
                "No scenario '%s'. Available: %s",
                $scenario,
                implode(', ', array_keys(self::SCENARIOS))
            ));

            return;
        }

        $this->scenario = self::SCENARIOS[$scenario];
        $this->engine = app(WorkflowEngine::class);

        $this->command->info("Scenario '{$scenario}': ".$this->scenario['label']);

        // Guarantees the fixed accounts every chain above decides through
        // (supervisor@, chair@, cgs@, director@, dean@, ae@, the examiners)
        // exist, without duplicating that roster here.
        $this->call(DatabaseSeeder::class);

        $this->loadDeciders();
        $extraStaff = $this->extraStaff();
        $students = $this->students();

        $this->attendance($students);
        $this->candidacies($students);
        $applications = $this->applications($students);

        Date::setTestNow(); // belt and braces -- nothing after this may run on fake time

        $this->command->newLine();
        $this->command->info(sprintf(
            'Demo data seeded: %d students, %d staff, %d applications, %d attendance periods.',
            $students->count(),
            $extraStaff->count() + count($this->deciders),
            $applications,
            $students->count() * $this->scenario['attendance_months']
        ));
        $this->command->info('Log in as any account from the table above (password: '.DatabaseSeeder::PASSWORD.').');
    }

    /* ---------------------------------------------------------------
     | Staff
     |---------------------------------------------------------------*/

    protected function loadDeciders(): void
    {
        $this->deciders = [
            Role::SUPERVISOR => User::where('email', 'supervisor@utp.edu.my')->firstOrFail(),
            Role::CHAIR => User::where('email', 'chair@utp.edu.my')->firstOrFail(),
            Role::NON_EXEC_CGS => User::where('email', 'cgs@utp.edu.my')->firstOrFail(),
            Role::SENIOR_DIRECTOR_CGS => User::where('email', 'director@utp.edu.my')->firstOrFail(),
            Role::DEAN_PGR => User::where('email', 'dean@utp.edu.my')->firstOrFail(),
            Role::ACADEMIC_EXEC => User::where('email', 'ae@utp.edu.my')->firstOrFail(),
        ];
    }

    /**
     * Accounts that exist purely so "Faculty Members" and the department mix
     * aren't a single row each -- none of these ever decide anything, so the
     * chains above stay on the fixed roster regardless of how many of these
     * are added.
     *
     * @return Collection<int, User>
     */
    protected function extraStaff(): Collection
    {
        $rows = [
            ['Dr. Farid Kamal', 'farid.kamal@utp.edu.my', Role::SUPERVISOR, 'Chemical Engineering', 'FOE'],
            ['Dr. Siti Nurhaliza', 'siti.nurhaliza@utp.edu.my', Role::SUPERVISOR, 'Electrical & Electronic Engineering', 'FOE'],
            ['Prof. Dr. Kumaran Vellu', 'kumaran.vellu@utp.edu.my', Role::SUPERVISOR, 'Computer & Information Sciences', 'FSMC'],
            ['Dr. Mei Ling Tan', 'meiling.tan@utp.edu.my', Role::CHAIR, 'Chemical Engineering', 'FOE'],
            ['Dr. Zulhilmi Rahman', 'zulhilmi.rahman@utp.edu.my', Role::ACADEMIC_EXEC, 'Electrical & Electronic Engineering', 'FOE'],
        ];

        return collect($rows)->map(fn ($r) => $this->user($r[0], $r[1], $r[2], [
            'department' => $r[3], 'faculty' => $r[4],
        ]));
    }

    protected function user(string $name, string $email, string $role, array $extra = []): User
    {
        return User::updateOrCreate(['email' => $email], array_merge([
            'name' => $name,
            'role' => $role,
            'password' => Hash::make(DatabaseSeeder::PASSWORD),
        ], $extra));
    }

    /* ---------------------------------------------------------------
     | Students
     |---------------------------------------------------------------*/

    /** @return Collection<int, User> the two seeded by DatabaseSeeder plus a scenario-sized batch */
    protected function students(): Collection
    {
        $programmes = ['MSc Full-Time', 'MSc Part-Time', 'PhD Full-Time', 'PhD Part-Time'];

        $departments = [
            ['Computer & Information Sciences', 'FSMC'],
            ['Chemical Engineering', 'FOE'],
            ['Electrical & Electronic Engineering', 'FOE'],
        ];

        $supervisors = [
            $this->deciders[Role::SUPERVISOR],
            User::where('email', 'farid.kamal@utp.edu.my')->firstOrFail(),
            User::where('email', 'siti.nurhaliza@utp.edu.my')->firstOrFail(),
            User::where('email', 'kumaran.vellu@utp.edu.my')->firstOrFail(),
        ];

        // A real name for the first pass through this list; a numbered
        // variant once a scenario (e.g. "heavy") asks for more students than
        // there are names, so emails and matric numbers stay unique.
        $names = [
            'Nur Aina Batrisyia', 'Muhammad Haziq Irfan', 'Tan Wei Jian', 'Divya Sri Kumar',
            'Muhammad Amirul Aiman', 'Lee Xin Yi', 'Nurul Iman Shafiqah', 'Balamurugan Raj',
            'Siti Khadijah Yusof', 'Ong Chee Kiat', 'Aishah Humaira', 'Ravindran Subramaniam',
            'Farhana Izzati', 'Chong Mei Xuan', 'Ahmad Zaki Hilmi', 'Priya Dharshini',
        ];

        $students = collect([
            User::where('email', 'student@utp.edu.my')->first(),
            User::where('email', 'student2@utp.edu.my')->first(),
        ])->filter();

        for ($i = 0; $i < $this->scenario['extra_students']; $i++) {
            $cycle = intdiv($i, count($names)) + 1; // 1 on the first pass through the name pool
            $name = $names[$i % count($names)];
            $email = $cycle === 1
                ? Str::slug($name, '.').'@utp.edu.my'
                : Str::slug($name, '.').'.'.$cycle.'@utp.edu.my';

            [$dept, $faculty] = $departments[$i % count($departments)];

            $students->push($this->user($name, $email, Role::STUDENT, [
                'matric_no' => sprintf('220%05d', 1010 + $i),
                'programme' => $programmes[$i % count($programmes)],
                'department' => $dept,
                'faculty' => $faculty,
                'supervisor_id' => $supervisors[$i % count($supervisors)]->id,
            ]));
        }

        return $students->values();
    }

    /* ---------------------------------------------------------------
     | Attendance -- attendance_months periods per student, spread across
     | all three bands so the gauge, the CGS bands and the admin average
     | all have something other than a single flat number to show.
     |---------------------------------------------------------------*/

    /**
     * RPD candidacies — the masterlist Norhanis' three flows read from.
     *
     * Spread deliberately across the four tones the masterlist paints, so the
     * demo shows a list worth filtering rather than fifteen identical green
     * rows: a couple already overdue (which is what makes "Open a Dismissal"
     * reachable), a couple inside the one-month warning, several in the
     * three-month notice window, and the rest comfortable.
     */
    protected function candidacies(Collection $students): void
    {
        // Days until the RPD deadline, cycled across the cohort.
        $offsets = [-42, -11, 9, 26, 55, 78, 96, 124, 168, 203];

        $students->values()->each(function (User $student, int $i) use ($offsets) {
            $type = ['phd_ft', 'msc_ft', 'phd_pt', 'msc_pt'][$i % 4];
            $days = $offsets[$i % count($offsets)];

            $deadline = Carbon::today()->addDays($days);
            $start = $deadline->copy()->subMonthsNoOverflow(Candidacy::WINDOW_MONTHS[$type]);

            // Every fourth student has already taken an extension, so the
            // "Extended" status and a non-zero ceiling both appear in the list.
            $used = $i % 4 === 2 ? 3 : 0;

            Candidacy::updateOrCreate(
                ['student_id' => $student->id],
                [
                    'programme_type' => $type,
                    'candidature_start_date' => $start,
                    'rpd_deadline' => $deadline,
                    'status' => $used > 0 ? Candidacy::STATUS_EXTENDED : Candidacy::STATUS_ACTIVE,
                    'extension_months_used' => $used,
                ]
            );
        });
    }

    protected function attendance(Collection $students): void
    {
        // Index into this cycle decides each student's trajectory: steady
        // good, steady warning, steady critical, improving, or declining
        // (the last two are what AttendanceRiskEvaluator's early-warning
        // half actually looks for).
        $profiles = [
            fn ($m) => 92 - $m,             // comfortably good throughout
            fn ($m) => 80 + $m,              // warning, recovering
            fn ($m) => 68 + $m,              // critical, recovering
            fn ($m) => 90 - $m * 4,          // good, declining toward warning
            fn ($m) => 83 - $m * 2,          // borderline, declining -- early-warning territory
        ];

        $months = $this->scenario['attendance_months'];

        foreach ($students as $i => $student) {
            $profile = $profiles[$i % count($profiles)];
            $record = null;

            // Oldest period first: at_risk's trend rule looks backward, so
            // periods must exist in chronological order before it can see one.
            for ($monthsAgo = $months - 1; $monthsAgo >= 0; $monthsAgo--) {
                $periodEnd = now()->subMonths($monthsAgo)->endOfMonth();
                $pct = max(40, min(100, $profile($monthsAgo)));
                $total = 40;
                $attended = (int) round($total * $pct / 100);

                $record = AttendanceRecord::recordPeriod($student->id, $periodEnd->toDateString(), $attended, $total);
                $record->at_risk = AttendanceRiskEvaluator::isAtRisk($record);
                $record->save();
            }
        }
    }

    /* ---------------------------------------------------------------
     | Applications
     |---------------------------------------------------------------*/

    protected function applications(Collection $students): int
    {
        $count = 0;
        $baseAnchor = now();

        for ($rep = 0; $rep < $this->scenario['repeat']; $rep++) {
            // Each extra pass is backdated further back, so a "heavy" run is
            // a deeper backlog rather than a pile of applications from today.
            $anchor = $baseAnchor->copy()->subDays($rep * 80);

            $count += $this->travelApplications($students, $anchor);
            $count += $this->gaExtensionApplications($students, $anchor);
            $count += $this->supervisionApplications($students, $anchor);
            $count += $this->certificationApplications($students, $anchor);
            $count += $this->attendanceAppeals($students, $anchor);
            $count += $this->examinerNominations($students, $anchor);
        }

        return $count;
    }

    /**
     * Runs $fn with now() frozen at $when, for every engine call this method
     * makes -- submitted_at, approval_history.created_at and the activity
     * log all fall out of the same frozen clock, so a chain's steps land in
     * the right order without touching any of those columns directly.
     */
    protected function at(Carbon $when, callable $fn): mixed
    {
        Date::setTestNow($when);

        try {
            return $fn();
        } finally {
            Date::setTestNow();
        }
    }

    /**
     * The built-in decisions for this entry, unless force_outcome says
     * otherwise -- 'approve' walks the whole chain and approves every stage,
     * regardless of what the module's own list says.
     */
    protected function decisionsFor(string $chainKey, array $default): array
    {
        if ($this->scenario['force_outcome'] === 'approve') {
            return array_fill(0, count(self::CHAINS[$chainKey]), 'approve');
        }

        return $default;
    }

    /**
     * Submit an application, then walk it through as many stages of its
     * chain as $decisions has entries -- fewer than the full chain leaves it
     * genuinely pending at the next stage, a 'reject' anywhere stops it
     * there, same as a real decision would.
     *
     * @param  string[]  $decisions  'approve' or 'reject', in chain order
     */
    protected function runChain(
        string $chainKey,
        Application $application,
        array $decisions,
        Carbon $submittedAt,
        int $gapDays = 4
    ): Application {
        $this->at($submittedAt, fn () => $this->engine->submit($application));

        $when = $submittedAt->copy();

        foreach ($decisions as $i => $decision) {
            $role = self::CHAINS[$chainKey][$i];
            $when = $when->copy()->addDays($gapDays);
            $actor = $role === Role::SUPERVISOR ? $application->student->supervisor : $this->deciders[$role];

            $this->at($when, fn () => $this->engine->decide($application, $actor, $decision));

            if ($decision === 'reject') {
                break;
            }
        }

        return $application->fresh();
    }

    /** Spreads submissions across the last ~65 days so this-month/last-month deltas are both populated. */
    protected function daysAgo(Carbon $anchor, int $i, int $spread = 65): Carbon
    {
        return $anchor->copy()->subDays(3 + ($i * 37) % $spread)->setTime(9, 0);
    }

    /** How many entries of a module's built-in list to actually use this run. */
    protected function take(array $items): array
    {
        return $this->scenario['cap'] === null ? $items : array_slice($items, 0, $this->scenario['cap'], true);
    }

    protected function travelApplications(Collection $students, Carbon $anchor): int
    {
        $destinations = $this->take([
            ['Kuala Lumpur Convention Centre', 'Present at the National Postgraduate Symposium', false],
            ['Universiti Malaya, Kuala Lumpur', 'Joint supervision meeting', false],
            ['PETRONAS Twin Towers, KL', 'Industry data collection visit', false],
            ['Singapore Expo, Singapore', 'IEEE International Conference', true],
            ['National University of Singapore', 'Research attachment, 2 weeks', true],
            ['Bangkok, Thailand', 'ASEAN Postgraduate Research Forum', true],
            ['Genting Highlands', 'Faculty research retreat', false],
            ['Penang Science Park', 'Field visit — semiconductor fabrication plant', false],
            ['Kyoto University, Japan', 'International joint symposium', true],
            ['Johor Bahru', 'Site visit for data collection', false],
            ['University of Melbourne, Australia', 'Visiting scholar programme', true],
            ['Kuching, Sarawak', 'Training on remote sensing tools', false],
        ]);

        // Deliberately varied so the queues aren't all sitting at the same
        // stage. Ignored entirely when force_outcome is set.
        $outcomes = [
            ['approve', 'approve'],
            ['approve'],
            [],
            ['approve', 'approve', 'approve', 'approve'],
            ['approve', 'approve'],
            [],
            ['approve', 'reject'],
            ['approve', 'approve'],
            ['approve', 'approve', 'reject'],
            ['approve'],
            ['approve', 'approve', 'approve'],
            [],
        ];

        $count = 0;

        foreach ($destinations as $i => [$destination, $reason, $intl]) {
            $student = $students[$i % $students->count()];
            $submittedAt = $this->daysAgo($anchor, $i);
            $chainKey = $intl ? 'travel_intl' : 'travel_local';

            $application = Application::create([
                'student_id' => $student->id,
                'module_type' => 'travel',
                'status' => Application::STATUS_DRAFT,
            ]);

            $start = $submittedAt->copy()->addDays(20);

            TravelDetail::create([
                'application_id' => $application->id,
                'type_of_request' => $intl ? 'training_seminar_talk' : 'field_visit',
                'travel_start_date' => $start,
                'travel_end_date' => $start->copy()->addDays(3),
                'duration_days' => 4,
                'reason_for_travel' => $reason,
                'destination_address' => $destination,
                'is_international' => $intl,
            ]);

            $this->runChain($chainKey, $application, $this->decisionsFor($chainKey, $outcomes[$i]), $submittedAt);
            $count++;
        }

        return $count;
    }

    protected function gaExtensionApplications(Collection $students, Carbon $anchor): int
    {
        $reasons = $this->take([
            'Data collection delayed by equipment procurement.',
            'Additional experiments required after committee feedback.',
            'Fieldwork rescheduled due to site access restrictions.',
            'Manuscript under a second round of journal review.',
            'Supervisor on sabbatical for one semester.',
            'Lab access disrupted by facility maintenance.',
            'Scope expanded to include a second case study.',
            'Recovering from medical leave, documentation on file.',
        ]);

        $outcomes = [
            ['approve', 'approve', 'approve'],
            ['approve', 'approve'],
            ['approve'],
            [],
            ['approve', 'approve', 'approve'],
            ['approve', 'reject'],
            ['approve'],
            [],
        ];

        $count = 0;

        foreach ($reasons as $i => $reason) {
            $student = $students[($i * 3 + 1) % $students->count()];
            $submittedAt = $this->daysAgo($anchor, $i + 5);

            $application = Application::create([
                'student_id' => $student->id,
                'module_type' => 'ga_extension',
                'status' => Application::STATUS_DRAFT,
            ]);

            GaExtensionDetail::create([
                'application_id' => $application->id,
                'current_end_date' => $submittedAt->copy()->addDays(10),
                'requested_new_end_date' => $submittedAt->copy()->addDays(10 + 90),
                'reason_for_extension' => $reason,
            ]);

            $this->runChain('ga_extension', $application, $this->decisionsFor('ga_extension', $outcomes[$i]), $submittedAt);
            $count++;
        }

        return $count;
    }

    protected function supervisionApplications(Collection $students, Carbon $anchor): int
    {
        $justifications = $this->take([
            'Requesting a change of supervisor to better align with my new research direction.',
            'Current supervisor going on long leave; requesting continuity supervisor.',
            'Co-supervision requested to cover the statistical methods component.',
            'Requesting formal supervisor assignment after confirming research area.',
            'Change requested following a shift from qualitative to lab-based methodology.',
            'Requesting co-supervision with a faculty member from the partner department.',
        ]);

        $outcomes = [
            ['approve', 'approve'],
            ['approve'],
            [],
            ['approve', 'reject'],
            ['approve', 'approve'],
            [],
        ];

        $count = 0;

        foreach ($justifications as $i => $justification) {
            $student = $students[($i * 5 + 2) % $students->count()];
            $submittedAt = $this->daysAgo($anchor, $i + 11);
            $requested = $this->deciders[Role::SUPERVISOR];

            $application = Application::create([
                'student_id' => $student->id,
                'module_type' => 'supervision',
                'status' => Application::STATUS_DRAFT,
            ]);

            SupervisionDetail::create([
                'application_id' => $application->id,
                'requested_supervisor_id' => $requested->id,
                'justification' => $justification,
            ]);

            $this->runChain('supervision', $application, $this->decisionsFor('supervision', $outcomes[$i]), $submittedAt);
            $count++;
        }

        return $count;
    }

    protected function certificationApplications(Collection $students, Carbon $anchor): int
    {
        $purposes = $this->take([
            ['GA', 'Scholarship application supporting document'],
            ['GRA', 'Visa renewal supporting document'],
            ['GA', 'Bank loan application'],
            ['GRA', 'Conference travel grant application'],
            ['GA', 'Study loan deferment application'],
            ['GRA', 'Employer verification request'],
        ]);

        $outcomes = [
            ['approve', 'approve'],
            ['approve', 'approve'],
            ['approve'],
            [],
            ['approve', 'approve'],
            ['approve'],
        ];

        $count = 0;

        foreach ($purposes as $i => [$type, $purpose]) {
            $student = $students[($i * 7 + 3) % $students->count()];
            $submittedAt = $this->daysAgo($anchor, $i + 17);

            $application = Application::create([
                'student_id' => $student->id,
                'module_type' => 'ga_certification',
                'status' => Application::STATUS_DRAFT,
            ]);

            GaCertificationDetail::create([
                'application_id' => $application->id,
                'appointment_type' => $type,
                'period_start' => $submittedAt->copy()->subMonths(6),
                'period_end' => $submittedAt->copy(),
                'purpose' => $purpose,
            ]);

            $this->runChain('ga_certification', $application, $this->decisionsFor('ga_certification', $outcomes[$i]), $submittedAt);
            $count++;
        }

        return $count;
    }

    protected function attendanceAppeals(Collection $students, Carbon $anchor): int
    {
        $reasons = $this->take([
            'Several sessions were recorded absent due to a device sync issue on my end.',
            'Attended a University-approved conference; sessions were not marked as excused.',
            'Medical leave for the affected period, MC attached in the appeal form.',
            'Sessions clash with an approved fieldwork trip already on record.',
            'Attendance sheet appears to be missing an entire week of my sessions.',
        ]);

        $outcomes = [
            ['approve'],
            ['approve'],
            [],
            ['reject'],
            [],
        ];

        $count = 0;

        // At-risk students are the ones this feature actually exists for --
        // pick the profiles that trend into warning/critical.
        $atRisk = $students->filter(function ($student) {
            return AttendanceRecord::where('attendance_records.student_id', $student->id)->latestPerStudent()->value('at_risk');
        })->values();

        $pool = $atRisk->isEmpty() ? $students : $atRisk;

        foreach ($reasons as $i => $reason) {
            $student = $pool[$i % $pool->count()];
            $submittedAt = $this->daysAgo($anchor, $i + 23);
            $record = AttendanceRecord::where('student_id', $student->id)->latest('period_end')->first();

            $application = Application::create([
                'student_id' => $student->id,
                'module_type' => 'attendance_appeal',
                'status' => Application::STATUS_DRAFT,
            ]);

            AttendanceAppealDetail::create([
                'application_id' => $application->id,
                'attendance_record_id' => $record?->id,
                'reason' => $reason,
            ]);

            $this->runChain('attendance_appeal', $application, $this->decisionsFor('attendance_appeal', $outcomes[$i]), $submittedAt);
            $count++;
        }

        return $count;
    }

    protected function examinerNominations(Collection $students, Carbon $anchor): int
    {
        $titles = $this->take([
            'Machine Learning Approaches for Predictive Maintenance in Upstream Oil & Gas Facilities',
            'A Rule-Based Framework for Postgraduate Application Workflow Automation',
            'Techno-Economic Analysis of Carbon Capture Retrofits for Existing Refineries',
            'Deep Learning for Seismic Facies Classification in Heterogeneous Reservoirs',
            'Optimisation of Membrane Bioreactor Performance for Industrial Wastewater Treatment',
            'Cybersecurity Risk Modelling for SCADA Systems in Critical Infrastructure',
        ]);

        // rosli and chandra are the seeded examiners in "available" states;
        // nabila is the backup used across the board.
        $main = Examiner::whereIn('email', ['rosli@utp.edu.my', 'chandra@utp.edu.my'])->get()->keyBy('email')->values();
        $backup = Examiner::where('email', 'nabila@um.edu.my')->first();

        $outcomes = [
            ['approve'],
            ['approve'],
            [],
            ['approve'],
            ['reject'],
            [],
        ];

        $count = 0;

        foreach ($titles as $i => $title) {
            $student = $students[($i * 9 + 4) % $students->count()];
            $submittedAt = $this->daysAgo($anchor, $i + 29);
            $mainExaminer = $main[$i % $main->count()];

            $application = Application::create([
                'student_id' => $student->id,
                'module_type' => 'examiner_nomination',
                'status' => Application::STATUS_DRAFT,
            ]);

            ExaminerNomination::create([
                'application_id' => $application->id,
                'main_examiner_id' => $mainExaminer->id,
                'backup_examiner_id' => $backup?->id,
                'thesis_title' => $title,
            ]);

            $this->runChain('examiner_nomination', $application, $this->decisionsFor('examiner_nomination', $outcomes[$i]), $submittedAt);
            $count++;
        }

        return $count;
    }
}
