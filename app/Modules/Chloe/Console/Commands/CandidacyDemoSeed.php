<?php

namespace App\Modules\Chloe\Console\Commands;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyAppealApproval;
use App\Modules\Chloe\Models\CandidacyAppealDocument;
use App\Modules\Chloe\Models\CandidacyAppealEvent;
use App\Modules\Chloe\Models\CandidacyDepartment;
use App\Modules\Chloe\Models\CandidacyDemoStudentStatus;
use App\Modules\Chloe\Models\CandidacyStaffAssignment;
use App\Modules\Chloe\Models\CandidacyStaffProfile;
use App\Modules\Chloe\Models\DemoStudentProfile;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Services\CandidacyMemoService;
use App\Modules\Chloe\Services\CandidacySignatureService;

use App\Modules\Chloe\Support\CandidacyDemoRoster;
use App\Modules\Chloe\Support\CandidacyDepartmentResolver;
use App\Modules\Chloe\Support\CandidacyStaffDirectory;
use App\Modules\Chloe\Support\CandidacyVerification;
use App\Modules\Core\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * About 40 coherent demo appeals for the Approval Portal, the student pages and the CGS pages, plus six departments with
 * named lecturers, chairs and deans. Idempotent and deterministic: a fixed seed decides every choice, an appeal that already
 * exists (by number) is left alone, and only the "days waiting" of in-flight appeals is moved to stay as designed relative
 * to today. Demo students are the existing Workstation demo profiles, whose expiry date is NOT changed (so "loan valid until"
 * and the seat totals stay as they are): the history is written backwards from each profile's current expiry date.
 * Everything made here carries is_demo, so candidacy:demo-clear removes exactly this and nothing else.
 */
class CandidacyDemoSeed extends Command
{
    protected $signature = 'candidacy:demo-seed';

    protected $description = 'Seed demo departments, demo staff and about 40 demo study-candidacy appeals (idempotent)';

    protected const SEED = 260201;

    protected const REASONS = [
        'My field data collection was delayed by about four months because the instrument calibration had to be repeated twice, and the thesis chapters depend on those results.',
        'I changed my research scope after the proposal defence on the advice of my supervisor, which added one more experimental phase that needs additional time to complete.',
        'A long equipment repair in the laboratory stopped my experiments for several months. I have rebuilt the schedule and need the extension to finish the analysis and write up.',
        'I had to be on medical leave for part of this year. I have since resumed full-time work and request time to complete the remaining chapters and the final submission.',
        'The industrial partner supplying my dataset released it much later than planned. The analysis chapters are in progress and need an additional semester.',
    ];

    protected const PUBLICATIONS = [
        ['Reservoir characterisation using sparse well data', 'Journal of Petroleum Science and Engineering', 'Published', 2025],
        ['A review of machine learning for process fault detection', 'Computers & Chemical Engineering', 'Under review', 2026],
        ['Structural health monitoring with low-cost sensors', 'Proceedings of the International Conference on Civil Engineering', 'Accepted', 2026],
        ['Optimising power converters for renewable microgrids', 'IEEE Access', 'Published', 2025],
        ['Heat transfer in compact heat exchangers', 'Applied Thermal Engineering', 'Submitted', 2026],
    ];

    protected const RETURN_REASONS = ['supervisor' => ['documents_missing', 'Please attach the latest progress report.'], 'chair' => ['duration_not_justified', 'The requested duration is not supported by the timeline.'], 'cgs' => ['documents_unclear', 'The official form is not legible. Please upload a clearer copy.'], 'dean' => ['clarification_needed', 'Please clarify the plan for the remaining chapters.']];

    protected Carbon $now;

    /** @var array<string, CandidacyDepartment> */
    protected array $departments = [];

    /** @var array<string, array{lecturers: list<CandidacyStaffProfile>, chair: CandidacyStaffProfile, dean: CandidacyStaffProfile}> */
    protected array $staff = [];

    protected int $created = 0;

    public function handle(CandidacySignatureService $signatures, CandidacyMemoService $memos): int
    {
        $this->created = 0;
        $this->now = Carbon::now('Asia/Kuala_Lumpur');

        DB::transaction(function () use ($signatures) {
            $this->seedStaff($signatures);
            $this->alignRealCandidacies();
        });

        mt_srand(self::SEED); // the appeals' own fixed draw (the roster used its own)

        $students = $this->pickStudents();
        $existing = CandidacyAppeal::where('is_demo', true)->pluck('appeal_no')->filter()->all();

        foreach ($this->scenarios($students) as $scenario) {
            $no = $scenario['no'] ?? null;

            if ($no !== null && in_array($no, $existing, true)) {
                $this->refreshWaiting($scenario);

                continue;
            }

            if ($no === null && $this->draftExists($scenario)) {
                continue;
            }

            DB::transaction(fn () => $this->build($scenario, $signatures, $memos));
            $this->created++;
        }

        $this->seedOverlay();
        $this->seedDismissalCases($signatures, $memos);
        $this->info("Demo appeals created this run: {$this->created}. Demo appeals now: ".CandidacyAppeal::where('is_demo', true)->count().'.');

        return self::SUCCESS;
    }

    /**
     * DEMO overlay rows (never a shared table): Softbound submitted, completed and inactive/deferred for several demo students that have no appeal,
     * the ones whose expiry date comes first, so the reminder rules have something to stop on. Deterministic (by expiry date, then id).
     */
    protected function seedOverlay(): void
    {
        $withAppeal = CandidacyAppeal::where('is_demo', true)->whereNotNull('demo_profile_id')->pluck('demo_profile_id')->all();
        $pool = DemoStudentProfile::whereNotIn('id', $withAppeal)->orderBy('candidacy_expiry_date')->orderBy('id')->limit(40)->get()->values();
        $plan = [['softbound', 4], ['softbound', 11], ['softbound', 19], ['softbound', 26], ['softbound', 33], ['softbound', 41], ['softbound', 52], ['completed', 0], ['completed', 0], ['completed', 0], ['inactive', 0], ['inactive', 0], ['inactive', 0]];

        foreach ($plan as $i => [$kind, $daysAgo]) {
            $profile = $pool[$i * 3 + 1] ?? null; // every third student, so neighbours stay ordinary

            if (! $profile) {
                continue;
            }

            \App\Modules\Chloe\Models\CandidacyDemoStudentStatus::updateOrCreate(['demo_profile_id' => $profile->id], [
                'softbound_submitted_at' => $kind === 'softbound' ? $this->now->copy()->subDays($daysAgo)->toDateString() : null,
                'completed' => $kind === 'completed', 'inactive' => $kind === 'inactive', 'label' => 'Demo data',
            ]);
        }
    }

    // ------------------------------------------------------------------ the dismissal demo (candidacy-only students)

    /**
     * Students that exist only for the Study Candidacy demo (demo_student_profiles.candidacy_only): Workstation never sees them (no seat, no key, hidden
     * from its lists and numbers), and no existing demo student is touched. 14 are dismissal candidates (expired with no appeal; after a Dean rejection;
     * returned appeal closed as expired; approved earlier and expired again; Master and PhD in all nine departments, two already exported), 8 are
     * excluded for a visible reason and 8 are Coursework students. Their expiry dates are relative to the day of seeding, so they stay expired.
     * [id, name, dept, level, case, days relative to today (expiry), extra]
     */
    protected function dismissalRoster(): array
    {
        return [
            // candidates
            ['21020001', 'Nurul Huda binti Samad', 'CHE', 'phd', 'none', -14, ['exported' => true]],
            ['21020002', 'Wong Jia Hui', 'CEE', 'master_research', 'none', -45, []],
            ['21020003', 'Mohd Ariff bin Salleh', 'GEO', 'phd', 'none', -100, []],
            ['21020004', 'Kamal a/l Subramaniam', 'MEC', 'master_research', 'none', -9, []],
            ['21020005', 'Lim Zhi Xuan', 'EEE', 'phd', 'rejected', -30, ['months' => 6, 'age' => 160, 'closed_days_ago' => 90, 'exported' => true]],
            ['21020006', 'Siti Aminah binti Daud', 'PET', 'master_research', 'rejected', -22, ['months' => 4, 'age' => 140, 'closed_days_ago' => 70]],
            ['21020007', 'Ravi a/l Chandran', 'APS', 'phd', 'rejected', -60, ['months' => 5, 'age' => 170, 'closed_days_ago' => 95]],
            ['21020008', 'Tan Mei Ling', 'CIS', 'master_research', 'closed_expired', -40, ['months' => 3, 'age' => 130, 'wait' => 75, 'closed_days_ago' => 39, 'by' => 'supervisor']],
            ['21020009', 'Hassan bin Ibrahim', 'MHU', 'phd', 'closed_expired', -25, ['months' => 4, 'age' => 110, 'wait' => 60, 'closed_days_ago' => 24, 'by' => 'chair']],
            ['21020010', 'Priyanka a/p Nair', 'CEE', 'phd', 'closed_expired', -70, ['months' => 6, 'age' => 150, 'wait' => 100, 'closed_days_ago' => 69, 'by' => 'supervisor']],
            ['21020011', 'Ong Wei Han', 'CHE', 'master_research', 'approved', -30, ['months' => 3, 'age' => 200, 'closed_days_ago' => 160]],
            ['21020012', 'Farhana binti Zulkifli', 'CIS', 'phd', 'approved', -55, ['months' => 2, 'age' => 220, 'closed_days_ago' => 190]],
            ['21020013', 'Daniel Lee Kok Wai', 'CIS', 'master_research', 'none', -5, []],
            ['21020014', 'Aisha binti Khalid', 'CIS', 'phd', 'none', -130, []],
            // excluded, with a visible reason
            ['21020015', 'Azmi bin Latif', 'MEC', 'phd', 'softbound', -20, []],
            ['21020016', 'Grace Tan Sze Yen', 'PET', 'master_research', 'softbound', -35, []],
            ['21020017', 'Vignesh a/l Murugan', 'EEE', 'master_research', 'inactive', -50, []],
            ['21020018', 'Noraini binti Hashim', 'GEO', 'master_research', 'completed', -15, []],
            ['21020019', 'Chia Boon Keat', 'CEE', 'phd', 'in_review', -3, ['state' => 'with_chair', 'months' => 4, 'age' => 3, 'wait' => 2]],
            ['21020020', 'Syafiqah binti Anuar', 'CIS', 'master_research', 'in_review', -10, ['state' => 'with_dean', 'months' => 3, 'age' => 14, 'wait' => 5]],
            ['21020021', 'Rajesh a/l Pillai', 'CHE', 'phd', 'dismissed', -200, ['dismissed_days_ago' => 60]],
            ['21020022', 'Lau Siew Mei', 'MHU', 'master_research', 'dismissed', -250, ['dismissed_days_ago' => 90]],
            // Coursework (not reminded, not listed)
            ['21020023', 'Hafizuddin bin Roslan', 'CHE', 'coursework:MSc in Process Safety', 'coursework', 40, []],
            ['21020024', 'Cheah Li Ying', 'CEE', 'coursework:MSc in Offshore Engineering', 'coursework', 75, []],
            ['21020025', 'Nadiah binti Omar', 'CIS', 'coursework:MSc in Data Science', 'coursework', 20, []],
            ['21020026', 'Kavin a/l Rajendran', 'CHE', 'coursework:MSc in Process Integration', 'coursework', 500, []],
            ['21020027', 'Amirul bin Jamal', 'CEE', 'coursework:MSc in Industrial Environmental Engineering', 'coursework', 700, []],
            ['21020028', 'Yap Xin Rui', 'CIS', 'coursework:Master of Science in Predictive Analytics', 'coursework', -30, []],
            ['21020029', 'Zulaikha binti Mansor', 'CEE', 'coursework:MSc in Asset Management & Maintenance', 'coursework', -90, []],
            ['21020030', 'Ng Kah Hou', 'CIS', 'coursework:MSc in Applied Computing', 'coursework', -10, []],
        ];
    }

    protected function seedDismissalCases(CandidacySignatureService $signatures, CandidacyMemoService $memos): void
    {
        $today = $this->now->copy()->startOfDay();
        $cgs = User::whereIn('role', (array) config('candidacy.cgs_roles'))->orderBy('id')->first();
        $registry = User::where('role', \App\Modules\Core\Support\Role::REGISTRY)->orderBy('id')->first();

        foreach ($this->dismissalRoster() as [$id, $name, $code, $level, $case, $days, $x]) {
            if (str_starts_with($level, 'coursework:')) {
                $programme = substr($level, 11).' (Coursework)';
            } else {
                $entry = collect((array) config('candidacy.programmes'))->first(fn ($e) => $e[2] === $code && $e[1] === $level);
                $programme = $entry[0].' (Full-Time)';
            }

            $profile = DemoStudentProfile::withoutGlobalScopes()->firstOrNew(['matric_no' => $id]);

            if ($profile->exists && $profile->candidacy_only === false) {
                continue; // never touch an ordinary demo student
            }

            $expiry = $today->copy()->addDays($days);
            $profile->forceFill([
                'name' => $name, 'email' => strtolower(explode(' ', $name)[0]).'_'.$id.'@utp.edu.my', 'gender' => 'male', 'programme' => $programme,
                'programme_start_date' => $expiry->copy()->subYears(3)->toDateString(), 'candidacy_expiry_date' => $expiry->toDateString(),
                'status' => $case === 'dismissed' ? 'dismissed' : 'active', 'candidacy_only' => true,
                'dismissed_at' => $case === 'dismissed' ? $today->copy()->subDays($x['dismissed_days_ago'])->setTime(10, 0) : null,
                'dismissed_by_id' => $case === 'dismissed' ? $registry?->id : null,
            ])->save();

            match ($case) {
                'softbound' => CandidacyDemoStudentStatus::updateOrCreate(['demo_profile_id' => $profile->id], ['softbound_submitted_at' => $expiry->copy()->subDays(30)->toDateString(), 'completed' => false, 'inactive' => false, 'label' => 'Demo data']),
                'completed' => CandidacyDemoStudentStatus::updateOrCreate(['demo_profile_id' => $profile->id], ['softbound_submitted_at' => null, 'completed' => true, 'inactive' => false, 'label' => 'Demo data']),
                'inactive' => CandidacyDemoStudentStatus::updateOrCreate(['demo_profile_id' => $profile->id], ['softbound_submitted_at' => null, 'completed' => false, 'inactive' => true, 'label' => 'Demo data']),
                default => null,
            };

            if (in_array($case, ['rejected', 'closed_expired', 'approved', 'in_review'], true)) {
                $no = sprintf('SCA-%s-01', $id);

                if (! CandidacyAppeal::where('appeal_no', $no)->exists()) {
                    $state = $x['state'] ?? $case;
                    $sc = ['dept' => $code, 'profile' => $profile, 'state' => $state, 'months' => $x['months'], 'wait' => $x['wait'] ?? 0, 'age' => $x['age'], 'no' => $no, 'seq' => 1]
                        + (isset($x['closed_days_ago']) ? ['closed_days_ago' => $x['closed_days_ago']] : []) + (isset($x['by']) ? ['by' => $x['by']] : []);
                    DB::transaction(fn () => $this->build($sc, $signatures, $memos));
                    $this->created++;
                }
            }

            $this->seedHistoricReminders($profile, $expiry, $case);

            if (! empty($x['exported']) && $cgs) {
                \App\Modules\Chloe\Models\CandidacyDismissalExport::firstOrCreate(['demo_profile_id' => $profile->id], ['exported_at' => $this->now->copy()->subDays(2)->setTime(15, 30), 'exported_by' => $cgs->id]);
            }
        }
    }

    /**
     * Past reminders for a candidacy-only demo student, so the Insights "Reminders" chart has six months of history. Each milestone date that is already
     * past gets a "sent" row dated that day, unless the student had already submitted an appeal or the Softbound by then (the engine would have stopped).
     * The students are expired, so the engine never reads these rows again: today's dry-run output is unchanged. candidacy:demo-clear removes them with the students.
     */
    protected function seedHistoricReminders(DemoStudentProfile $profile, Carbon $expiry, string $case): void
    {
        if (str_contains((string) $profile->programme, 'Coursework')) {
            return;
        }

        $firstAppeal = CandidacyAppeal::where('demo_profile_id', $profile->id)->min('submitted_at');
        $stop = $firstAppeal ? Carbon::parse($firstAppeal, 'Asia/Kuala_Lumpur')->startOfDay() : ($case === 'softbound' ? $expiry->copy()->subDays(30) : null);
        $today = $this->now->copy()->startOfDay();

        foreach ([3, 2, 1] as $months) {
            $date = $expiry->copy()->subMonthsNoOverflow($months)->startOfDay();

            if ($date->gte($today) || ($stop && $date->gte($stop)) || $expiry->lt($today) === false) {
                continue;
            }

            \App\Modules\Chloe\Models\CandidacyReminderLog::firstOrCreate(
                ['demo_profile_id' => $profile->id, 'expiry_date' => $expiry->toDateString(), 'milestone' => $months],
                ['variant' => 'A', 'status' => 'sent', 'sent_at' => $date->copy()->setTime(8, 0)],
            );
        }
    }

    // ------------------------------------------------------------------ staff and departments

    protected function seedStaff(CandidacySignatureService $signatures): void
    {
        $profileOf = fn (?int $userId) => $userId ? CandidacyStaffProfile::where('user_id', $userId)->first() : null;
        $realLecturer = $profileOf(User::where('email', 'supervisor@utp.edu.my')->value('id'));
        $realChair = $profileOf(User::where('email', 'chair@utp.edu.my')->value('id'));
        $dean = CandidacyStaffDirectory::defaultDean();

        if (! $dean) {
            throw new \RuntimeException('There is no profile for the account with the Dean role.');
        }

        // ONE Dean for the whole university, no department: no department holds a Dean assignment (our own table only)
        CandidacyStaffAssignment::where('role', CandidacyStaffAssignment::ROLE_DEAN)->delete();
        $dean->forceFill(['department_id' => null])->save();

        $codes = array_keys((array) config('candidacy.departments'));
        $roster = CandidacyDemoRoster::generate($codes, array_merge(User::pluck('name')->all(), CandidacyStaffProfile::where('is_demo', false)->pluck('full_name')->all()));

        foreach ((array) config('candidacy.departments') as $code => [$name, $faculty]) {
            $dept = CandidacyDepartment::where('code', $code)->first();
            $dept = $dept ? tap($dept)->update(['name' => $name, 'faculty' => $faculty, 'active' => true]) : CandidacyDepartment::create(['code' => $code, 'name' => $name, 'faculty' => $faculty, 'active' => true, 'is_demo' => true]);
            $this->departments[$code] = $dept;

            $lecturers = [];

            if ($code === 'CIS' && $realLecturer) {
                $lecturers[] = $this->assign($dept, $realLecturer, 'lecturer');
            }

            foreach ($roster['lecturers'][$code] as $fullName) {
                $lecturers[] = $this->assign($dept, $this->demoProfile($fullName, "Lecturer, {$name}", $dept, $signatures), 'lecturer');
            }

            $chair = $code === 'CIS' && $realChair
                ? $this->assign($dept, $realChair, 'chair')
                : $this->assign($dept, $this->demoProfile($roster['chairs'][$code], "Department Chair, {$name}", $dept, $signatures), 'chair');

            $this->staff[$code] = ['lecturers' => $lecturers, 'chair' => $chair, 'dean' => $dean];
        }
    }

    /** A fake staff member (no login account). Found again by name on a re-run; gets a placeholder signature drawn by GD (marked demo). */
    protected function demoProfile(string $name, string $designation, CandidacyDepartment $dept, CandidacySignatureService $signatures): CandidacyStaffProfile
    {
        $profile = CandidacyStaffProfile::firstOrCreate(['full_name' => $name, 'is_demo' => true], ['designation' => $designation, 'department_id' => $dept->id, 'user_id' => null]);

        if (! $profile->hasSignature()) {
            $signatures->placeholder($profile);
        }

        return $profile;
    }

    /** A person is a lecturer OR a chair of ONE department, never both and never two departments. */
    protected function assign(CandidacyDepartment $dept, CandidacyStaffProfile $profile, string $role): CandidacyStaffProfile
    {
        $other = CandidacyStaffAssignment::where('staff_profile_id', $profile->id)->where(fn ($q) => $q->where('role', '!=', $role)->orWhere('department_id', '!=', $dept->id))->exists();

        if ($other) {
            throw new \RuntimeException("{$profile->full_name} already holds another role or department.");
        }

        if ($role === 'chair') {
            $current = CandidacyStaffAssignment::where('department_id', $dept->id)->where('role', 'chair')->first();

            if ($current && $current->staff_profile_id !== $profile->id) {
                return CandidacyStaffProfile::find($current->staff_profile_id);
            }
        }

        CandidacyStaffAssignment::firstOrCreate(['department_id' => $dept->id, 'staff_profile_id' => $profile->id, 'role' => $role]);
        $profile->forceFill(['department_id' => $dept->id])->save();

        return $profile;
    }

    /** Every candidacy record gets a department (the live demo accounts: Computer & Information Sciences) and an original expiry date. */
    protected function alignRealCandidacies(): void
    {
        foreach (StudyCandidacy::with('student')->get() as $candidacy) {
            $code = $candidacy->student ? CandidacyDepartmentResolver::codeForUser($candidacy->student) : 'CIS';
            $candidacy->department_id = $this->departments[$code]->id;
            $candidacy->original_expiry_date ??= $candidacy->candidacy_expiry_date->copy()->subMonthsNoOverflow($candidacy->allowanceUsed());
            $candidacy->save();
        }
    }

    // ------------------------------------------------------------------ the cast of demo students

    /** @return array<string, list<DemoStudentProfile>> demo students per department, ordered by expiry date descending then id */
    protected function pickStudents(): array
    {
        $byDept = [];

        foreach (DemoStudentProfile::orderBy('id')->get() as $profile) {
            $byDept[CandidacyDepartmentResolver::codeFor($profile->programme, $profile->matric_no)][] = $profile;
        }

        foreach ($byDept as &$list) {
            usort($list, fn ($a, $b) => [$b->candidacy_expiry_date, $a->id] <=> [$a->candidacy_expiry_date, $b->id]);
        }

        return $byDept;
    }

    /**
     * The fixed script of demo appeals. Each entry: dept, student index in that department's list, state, months, days waiting at
     * the current step (in-flight), age in days of the appeal, and optional flags. Appeal numbers follow the student, so a student
     * with two appeals lists them as sequence 1 then 2.
     *
     * @param  array<string, list<DemoStudentProfile>>  $students
     * @return list<array<string, mixed>>
     */
    protected function scenarios(array $students): array
    {
        $list = [];
        $add = function (string $dept, int $i, string $state, int $months, int $wait, int $age, array $extra = []) use (&$list, $students) {
            $list[] = ['dept' => $dept, 'profile' => $students[$dept][$i], 'state' => $state, 'months' => $months, 'wait' => $wait, 'age' => $age] + $extra;
        };

        // two approved appeals for one student, and one at exactly 12 of 12 months (6 + 6); a second student with 3 + 4
        $add('EEE', 0, 'approved', 6, 0, 190, ['seq' => 1, 'closed_days_ago' => 150]);
        $add('EEE', 0, 'approved', 6, 0, 120, ['seq' => 2, 'closed_days_ago' => 70]);
        $add('PET', 0, 'approved', 3, 0, 170, ['seq' => 1, 'closed_days_ago' => 135]);
        $add('PET', 0, 'approved', 4, 0, 90, ['seq' => 2, 'closed_days_ago' => 52]);
        // one approved and then a new appeal in flight at the Chair
        $add('CHE', 0, 'approved', 3, 0, 160, ['seq' => 1, 'closed_days_ago' => 125]);
        $add('CHE', 0, 'with_chair', 4, 6, 12, ['seq' => 2]);
        // single approved appeals (memo + stamps)
        $add('CEE', 0, 'approved', 2, 0, 80, ['closed_days_ago' => 50]);
        $add('MEC', 0, 'approved', 12, 0, 60, ['closed_days_ago' => 31]);
        $add('MEC', 1, 'approved', 5, 0, 45, ['closed_days_ago' => 20]);
        $add('CEE', 1, 'approved', 1, 0, 30, ['closed_days_ago' => 9]);
        // final rejections (with reasons)
        $add('EEE', 1, 'rejected', 4, 0, 100, ['closed_days_ago' => 68]);
        $add('CHE', 1, 'rejected', 6, 0, 75, ['closed_days_ago' => 41]);
        $add('PET', 1, 'rejected', 3, 0, 50, ['closed_days_ago' => 22]);
        // returned: by the Supervisor and the Chair (Computer & Information Sciences, so the real accounts see them), by CGS and by the Dean elsewhere
        $add('CIS', 0, 'returned', 5, 8, 20, ['by' => 'supervisor']);
        $add('CIS', 1, 'returned', 3, 5, 24, ['by' => 'chair']);
        $add('CEE', 2, 'returned', 6, 3, 27, ['by' => 'cgs']);
        $add('MEC', 2, 'returned', 4, 2, 33, ['by' => 'dean']);
        // waiting at the Supervisor (the real lecturer for Computer & Information Sciences)
        foreach ([['CIS', 2, 4, 0], ['CIS', 3, 6, 2], ['CIS', 4, 2, 6], ['CIS', 5, 5, 11], ['EEE', 2, 3, 5], ['PET', 2, 5, 8], ['CHE', 2, 4, 12]] as [$d, $i, $m, $w]) {
            $add($d, $i, 'with_supervisor', $m, $w, $w + 2);
        }
        // waiting at the Department Chair (the real chair for Computer & Information Sciences)
        foreach ([['CIS', 6, 6, 0], ['CIS', 7, 3, 3], ['CIS', 8, 4, 7], ['CIS', 9, 5, 14], ['CEE', 3, 5, 6], ['MEC', 3, 2, 10], ['EEE', 3, 4, 14]] as [$d, $i, $m, $w]) {
            $add($d, $i, 'with_chair', $m, $w, $w + 6);
        }
        // waiting at CGS verification (one is a re-check after a Dean return)
        foreach ([['CIS', 10, 4, 1], ['CIS', 11, 6, 5], ['PET', 3, 6, 4], ['CHE', 3, 3, 7], ['CEE', 4, 5, 11]] as [$d, $i, $m, $w]) {
            $add($d, $i, 'with_cgs', $m, $w, $w + 12);
        }
        $add('MEC', 4, 'with_cgs', 4, 13, 38, ['recheck' => true]);
        // waiting at the Dean: from several departments
        foreach ([['CIS', 12, 5, 0], ['CIS', 13, 3, 3], ['CIS', 14, 4, 9], ['EEE', 4, 6, 6], ['PET', 4, 2, 12], ['CEE', 5, 3, 1], ['MEC', 5, 4, 8], ['GEO', 2, 2, 4]] as [$d, $i, $m, $w]) {
            $add($d, $i, 'with_dean', $m, $w, $w + 18);
        }
        // Computer & Information Sciences with a final result (the real lecturer, chair and Dean's History tabs)
        $add('CIS', 15, 'approved', 3, 0, 120, ['closed_days_ago' => 85]);
        $add('CIS', 16, 'approved', 6, 0, 70, ['closed_days_ago' => 40]);
        $add('CIS', 17, 'approved', 2, 0, 35, ['closed_days_ago' => 12]);
        $add('CIS', 18, 'rejected', 5, 0, 95, ['closed_days_ago' => 58]);
        // the three departments that had no appeals: Geoscience, Fundamental & Applied Sciences, Management & Humanities (two or more each)
        $add('GEO', 0, 'approved', 3, 0, 100, ['closed_days_ago' => 64]);
        $add('GEO', 1, 'with_supervisor', 4, 3, 5);
        $add('APS', 0, 'returned', 3, 4, 18, ['by' => 'chair']);
        $add('APS', 1, 'with_chair', 5, 9, 15);
        $add('APS', 2, 'rejected', 4, 0, 60, ['closed_days_ago' => 29]);
        $add('MHU', 0, 'approved', 6, 0, 55, ['closed_days_ago' => 24]);
        $add('MHU', 1, 'with_cgs', 4, 3, 15);
        // drafts
        foreach ([['MHU', 2, 2], ['CEE', 6, 4], ['PET', 5, 2]] as [$d, $i, $m]) {
            $add($d, $i, 'draft', $m, 0, 2);
        }
        // closed because it was not resubmitted before the (real) candidacy expired
        if ($expired = User::where('email', 'student2@utp.edu.my')->first()) {
            if (StudyCandidacy::where('student_id', $expired->id)->exists()) {
                $list[] = ['dept' => CandidacyDepartmentResolver::codeForUser($expired), 'student_id' => $expired->id, 'state' => 'closed_expired', 'months' => 3, 'wait' => 45, 'age' => 70, 'by' => 'supervisor', 'closed_days_ago' => 30];
            }
        }

        // sequence numbers: the n-th submitted appeal of each student, in the order written above
        $seq = [];
        foreach ($list as &$entry) {
            $key = isset($entry['student_id']) ? 'u'.$entry['student_id'] : 'd'.$entry['profile']->id;

            if ($entry['state'] === 'draft') {
                continue;
            }

            $seq[$key] = ($seq[$key] ?? 0) + 1;
            $entry['seq'] = $entry['seq'] ?? $seq[$key];
            $matric = isset($entry['student_id']) ? User::find($entry['student_id'])->matric_no : $entry['profile']->matric_no;
            $entry['no'] = sprintf('SCA-%s-%02d', $matric, $entry['seq']);
        }

        return $list;
    }

    // ------------------------------------------------------------------ building one appeal

    /** @param array<string, mixed> $sc */
    protected function build(array $sc, CandidacySignatureService $signatures, CandidacyMemoService $memos): void
    {
        $state = $sc['state'];
        $dept = $this->departments[$sc['dept']];
        $staff = $this->staff[$sc['dept']];
        $profile = $sc['profile'] ?? null;
        $realStudent = isset($sc['student_id']) ? User::find($sc['student_id']) : null;
        $identity = $profile ? ['demo_profile_id' => $profile->id] : ['student_id' => $realStudent->id, 'study_candidacy_id' => StudyCandidacy::where('student_id', $realStudent->id)->value('id')];
        $name = $profile?->name ?? $realStudent->name;
        $matric = $profile?->matric_no ?? $realStudent->matric_no;

        // the supervisor is one of the department's lecturers, chosen by the student; the first (real in CIS) is used for in-flight CIS work
        $lecturers = $staff['lecturers'];
        // in Computer & Information Sciences the real lecturer is the supervisor of every appeal that waits for the Supervisor or was returned, and of most others
        $supervisor = ($sc['dept'] === 'CIS' && ($lecturers[0]->user_id !== null) && (in_array($state, ['with_supervisor', 'returned'], true) || mt_rand(0, 3) > 0)) ? $lecturers[0] : $lecturers[mt_rand(0, count($lecturers) - 1)];
        $start = $this->now->copy()->subDays($sc['age']);
        $months = $sc['months'];
        $isDraft = $state === 'draft';

        // expiry: written backwards from the student's CURRENT expiry date so the Workstation data stays as it is
        $expiryNow = $profile ? Carbon::parse($profile->candidacy_expiry_date) : Carbon::parse(StudyCandidacy::find($identity['study_candidacy_id'])->candidacy_expiry_date);
        $expiryAfter = $expiryNow->copy()->subMonthsNoOverflow($this->approvedAfter($sc));
        $expiryAtSubmission = $state === 'approved' ? $expiryAfter->copy()->subMonthsNoOverflow($months) : $expiryAfter;

        if ($expiryAtSubmission->lt($start->copy()->startOfDay())) {
            $this->warn("{$sc['no']}: the history would start after the original expiry date; pick a student with a later expiry.");
        }

        $attrs = $identity + [
            'appeal_no' => $sc['no'] ?? null, 'sequence_no' => $sc['seq'] ?? null, 'department_id' => $dept->id,
            'supervisor_profile_id' => $supervisor->id, 'chair_profile_id' => $staff['chair']->id, 'dean_profile_id' => $staff['dean']->id,
            'requested_months' => $months, 'expiry_at_submission' => $isDraft ? null : $expiryAtSubmission->toDateString(),
            'reason' => self::REASONS[mt_rand(0, count(self::REASONS) - 1)], 'phase' => ['writing', 'simulation', 'experiment', 'data_analysis'][mt_rand(0, 3)],
            'percent_complete' => mt_rand(3, 18) * 5, 'rcs_status' => mt_rand(0, 1) ? 'done' : 'pending', 'gsc_extension' => false, 'vc_extension' => false,
            'declaration_accepted_at' => $start, 'is_demo' => true, 'round' => 1, 'status' => 'draft',
        ];
        $attrs['rcs_date'] = $attrs['rcs_status'] === 'done' ? $start->copy()->subDays(mt_rand(60, 200))->toDateString() : null;
        $attrs['rcs_expected_date'] = $attrs['rcs_status'] === 'pending' ? $this->now->copy()->addDays(mt_rand(20, 90))->toDateString() : null;
        $attrs['rcs_category'] = ['Category A', 'Category B', null][mt_rand(0, 2)];

        $appeal = CandidacyAppeal::create($attrs);
        $this->addPublications($appeal);
        $this->addDocuments($appeal, $start);

        if ($isDraft) {
            $appeal->forceFill(['declaration_accepted_at' => null, 'created_at' => $start, 'updated_at' => $start])->save();

            return;
        }

        $order = ['supervisor', 'chair', 'cgs', 'dean'];
        $stageProfile = fn (string $stage) => match ($stage) { 'supervisor' => $supervisor, 'chair' => $staff['chair'], 'dean' => $staff['dean'], default => null };
        $clock = $start->copy();
        $round = 1;
        $appeal->forceFill(['submitted_at' => $clock, 'round' => 1]);
        $this->event($appeal, $this->actor($appeal, null, $name, 'student'), 'submitted', null, 'draft', 'with_supervisor', $clock, 1);

        // stages the appeal has passed: all stages before the current one; an approved appeal passed all four
        $current = match ($state) {
            'with_supervisor' => 'supervisor', 'with_chair' => 'chair', 'with_cgs' => 'cgs', 'with_dean' => 'dean',
            'returned', 'closed_expired' => $sc['by'], default => 'dean',
        };
        $passUntil = array_search($current, $order, true);
        $stageTimes = $this->stageTimes($state, $sc, $start, $passUntil);

        foreach ($order as $i => $stage) {
            $isLastStep = $i === $passUntil;
            $passed = $i < $passUntil || ($state === 'approved' && $stage === 'dean');

            if ($passed) {
                $this->approve($appeal, $stage, $stageProfile($stage), $round, $stageTimes[$i], $signatures);
            }

            if ($isLastStep) {
                break;
            }
        }

        // a re-check after a Dean return: the appeal went to the Dean once, was returned, resubmitted to CGS
        if (! empty($sc['recheck'])) {
            $round = 2;
            $deanTime = $this->now->copy()->subDays($sc['wait'] + 3);
            $this->approve($appeal, 'cgs', null, 1, $deanTime->copy()->subHours(10), $signatures, 'verified');
            $this->returnIt($appeal, 'dean', $stageProfile('dean'), 1, $deanTime);
            $this->event($appeal, $this->actor($appeal, null, $name, 'student'), 'resubmitted', 'cgs', 'returned', 'with_cgs', $deanTime->copy()->addDays(2), 2);
            $appeal->round = 2;
        }

        $entered = $this->now->copy()->subDays($sc['wait'])->subHours(2);

        switch ($state) {
            case 'with_supervisor': case 'with_chair': case 'with_cgs': case 'with_dean':
                $appeal->forceFill(['status' => $state, 'current_stage' => $current, 'stage_entered_at' => $entered, 'needs_cgs_recheck' => ! empty($sc['recheck'])]);
                break;
            case 'returned': case 'closed_expired':
                $this->returnIt($appeal, $current, $stageProfile($current), $round, $entered);
                $appeal->forceFill(['status' => 'returned', 'current_stage' => null, 'returned_by_stage' => $current, 'stage_entered_at' => $entered, 'needs_cgs_recheck' => $current === 'dean']);

                if ($state === 'closed_expired') {
                    $closed = $this->now->copy()->subDays($sc['closed_days_ago']);
                    $this->event($appeal, $this->actor($appeal, null, 'System', 'system'), 'closed_expired', $current, 'returned', 'closed_expired', $closed, $round);
                    $appeal->forceFill(['status' => 'closed_expired', 'returned_by_stage' => $current, 'final_outcome' => 'closed_expired', 'closed_at' => $closed, 'needs_cgs_recheck' => false]);
                }
                break;
            case 'approved':
                $closed = $this->now->copy()->subDays($sc['closed_days_ago']);
                $appeal->forceFill(['status' => 'approved', 'current_stage' => null, 'approved_months' => $months, 'new_expiry_date' => $expiryAfter->toDateString(), 'final_outcome' => 'approved', 'closed_at' => $closed]);
                break;
            case 'rejected':
                $closed = $this->now->copy()->subDays($sc['closed_days_ago']);
                $this->rejectIt($appeal, $stageProfile('dean'), $round, $closed);
                $appeal->forceFill(['status' => 'rejected', 'current_stage' => null, 'final_outcome' => 'rejected_final', 'closed_at' => $closed]);
                break;
        }

        $appeal->forceFill(['last_action_at' => $appeal->closed_at ?? $appeal->stage_entered_at ?? $clock, 'created_at' => $start, 'updated_at' => $appeal->closed_at ?? $appeal->stage_entered_at ?? $clock])->save();

        if ($state === 'approved') {
            $this->finishApproved($appeal, $current ?? 'dean', $memos, $sc);
        }
    }

    /** Months approved on LATER appeals of the same student (so the history chains back from today's expiry date). */
    protected function approvedAfter(array $sc): int
    {
        return match (true) {
            ($sc['dept'] === 'EEE' && ($sc['seq'] ?? 0) === 1 && $sc['state'] === 'approved') => 6,
            ($sc['dept'] === 'PET' && ($sc['seq'] ?? 0) === 1 && $sc['state'] === 'approved') => 4,
            ($sc['dept'] === 'CHE' && ($sc['seq'] ?? 0) === 1 && $sc['state'] === 'approved') => 0,
            default => 0,
        };
    }

    /** Times at which each passed stage decided, spread from the submission towards the moment the appeal reached its present state. */
    protected function stageTimes(string $state, array $sc, Carbon $start, int $passUntil): array
    {
        $closedAgo = $sc['closed_days_ago'] ?? null;
        $end = $closedAgo !== null && in_array($state, ['approved', 'rejected'], true) ? $this->now->copy()->subDays($closedAgo) : $this->now->copy()->subDays($sc['wait'])->subHours(2);
        $passed = $passUntil + ($state === 'approved' ? 1 : 0);
        $den = $passed + ($state === 'approved' ? 0 : 1);
        $span = max(1, (int) $start->diffInMinutes($end, false));
        $times = [];

        for ($i = 0; $i < 4; $i++) {
            $times[$i] = $start->copy()->addMinutes((int) round($span * ($i + 1) / max(1, $den)));
        }

        return $times;
    }

    protected function approve(CandidacyAppeal $appeal, string $stage, ?CandidacyStaffProfile $profile, int $round, Carbon $at, CandidacySignatureService $signatures, ?string $decision = null): void
    {
        $decision ??= $stage === 'cgs' ? 'verified' : 'approved';
        $at = $at->copy()->startOfSecond();
        $cgs = $stage === 'cgs' ? $this->cgsActor() : null;
        [$path, $hash] = $profile ? $signatures->snapshot($profile, $appeal->id.'-r'.$round.'-'.$stage) : [null, null];

        $this->approvalRow([
            'candidacy_appeal_id' => $appeal->id, 'round' => $round, 'stage' => $stage, 'profile_id' => $profile?->id, 'user_id' => $profile?->user_id ?? $cgs?->id,
            'full_name' => $profile?->full_name ?? $cgs?->name ?? 'Centre for Graduate Studies', 'designation' => $profile?->designation ?? 'Centre for Graduate Studies (CGS)',
            'signature_snapshot_path' => $path, 'signature_snapshot_sha256' => $hash, 'decision' => $decision, 'documents_complete' => $stage === 'cgs' ? true : null,
            'remark' => null, 'decided_at' => $at, 'verification_code' => CandidacyVerification::code((string) $appeal->appeal_no, $stage, $profile?->id, $at),
            'created_at' => $at, 'updated_at' => $at,
        ]);

        $this->event($appeal, $this->actor($appeal, $profile, $profile?->full_name ?? $cgs?->name ?? 'CGS', $stage === 'cgs' ? 'cgs' : $this->kind($stage), $profile?->user_id ?? $cgs?->id), $decision, $stage, null, null, $at, $round);
    }

    protected function returnIt(CandidacyAppeal $appeal, string $stage, ?CandidacyStaffProfile $profile, int $round, Carbon $at): void
    {
        [$reason, $remark] = self::RETURN_REASONS[$stage];
        $at = $at->copy()->startOfSecond();
        $cgs = $stage === 'cgs' ? $this->cgsActor() : null;

        $this->approvalRow([
            'candidacy_appeal_id' => $appeal->id, 'round' => $round, 'stage' => $stage, 'profile_id' => $profile?->id, 'user_id' => $profile?->user_id ?? $cgs?->id,
            'full_name' => $profile?->full_name ?? $cgs?->name ?? 'Centre for Graduate Studies', 'designation' => $profile?->designation ?? 'Centre for Graduate Studies (CGS)',
            'decision' => 'returned', 'documents_complete' => $stage === 'cgs' ? false : null, 'remark' => $remark, 'decided_at' => $at,
            'verification_code' => CandidacyVerification::code((string) $appeal->appeal_no, $stage, $profile?->id, $at), 'created_at' => $at, 'updated_at' => $at,
        ]);
        $this->event($appeal, $this->actor($appeal, $profile, $profile?->full_name ?? $cgs?->name ?? 'CGS', $this->kind($stage), $profile?->user_id ?? $cgs?->id), 'returned', $stage, null, 'returned', $at, $round, [$reason], $remark);
    }

    protected function rejectIt(CandidacyAppeal $appeal, CandidacyStaffProfile $dean, int $round, Carbon $at): void
    {
        $at = $at->copy()->startOfSecond();
        // every demo rejection carries reasons from the Dean's list (chosen from the appeal number, so the seed stays deterministic)
        $keys = array_values(array_diff(array_keys(config('candidacy.reasons.dean.reject')), ['other']));
        $h = crc32((string) $appeal->appeal_no);
        $reasons = array_values(array_unique([$keys[$h % count($keys)], ...($h % 2 === 0 ? [$keys[($h >> 3) % count($keys)]] : [])]));
        $remark = 'The reasons above were recorded by the Dean.';

        $this->approvalRow([
            'candidacy_appeal_id' => $appeal->id, 'round' => $round, 'stage' => 'dean', 'profile_id' => $dean->id, 'user_id' => $dean->user_id,
            'full_name' => $dean->full_name, 'designation' => $dean->designation, 'decision' => 'rejected', 'remark' => $remark,
            'decided_at' => $at, 'verification_code' => CandidacyVerification::code((string) $appeal->appeal_no, 'dean', $dean->id, $at), 'created_at' => $at, 'updated_at' => $at,
        ]);
        $this->event($appeal, $this->actor($appeal, $dean, $dean->full_name, 'dean', $dean->user_id), 'rejected', 'dean', null, 'rejected', $at, $round, $reasons, $remark);
    }

    protected function finishApproved(CandidacyAppeal $appeal, string $stage, CandidacyMemoService $memos, array $sc): void
    {
        [$path, $name] = $memos->generate($appeal->fresh());
        $appeal->forceFill(['memo_path' => $path, 'memo_filename' => $name, 'memo_generated_at' => $appeal->closed_at])->save();
    }

    // ------------------------------------------------------------------ small helpers

    /** @param array<string, mixed> $attributes created_at / updated_at are the (back-dated) decision time, so the row is force-filled */
    protected function approvalRow(array $attributes): CandidacyAppealApproval
    {
        $row = (new CandidacyAppealApproval)->forceFill($attributes);
        $row->save();

        return $row;
    }

    protected function kind(string $stage): string
    {
        return ['supervisor' => 'lecturer', 'chair' => 'chair', 'cgs' => 'cgs', 'dean' => 'dean'][$stage];
    }

    protected function cgsActor(): ?User
    {
        return User::where('email', 'cgs@utp.edu.my')->first();
    }

    /** @return array{kind:string,name:string,user:?int,role:?string} */
    protected function actor(CandidacyAppeal $appeal, ?CandidacyStaffProfile $profile, string $name, string $kind, ?int $userId = null): array
    {
        return ['kind' => $kind, 'name' => $name, 'user' => $userId ?? $profile?->user_id, 'role' => $userId ? User::find($userId)?->role : null];
    }

    /** @param list<string>|null $reasons */
    protected function event(CandidacyAppeal $appeal, array $actor, string $action, ?string $stage, ?string $from, ?string $to, Carbon $at, int $round, ?array $reasons = null, ?string $remark = null): void
    {
        CandidacyAppealEvent::record([
            'candidacy_appeal_id' => $appeal->id, 'occurred_at' => $at, 'actor_kind' => $actor['kind'], 'actor_user_id' => $actor['user'], 'actor_name' => $actor['name'],
            'actor_role' => $actor['role'], 'action' => $action, 'stage' => $stage, 'round' => $round, 'reason_codes' => $reasons, 'remark' => $remark,
            'from_status' => $from, 'to_status' => $to,
        ]);
    }

    protected function addPublications(CandidacyAppeal $appeal): void
    {
        foreach (range(0, mt_rand(0, 2)) as $n) {
            [$title, $venue, $status, $year] = self::PUBLICATIONS[mt_rand(0, count(self::PUBLICATIONS) - 1)];
            $appeal->publications()->create(['title' => $title, 'venue' => $venue, 'status' => $status, 'year' => $year]);
        }
    }

    protected function addDocuments(CandidacyAppeal $appeal, Carbon $at): void
    {
        $zip = tempnam(sys_get_temp_dir(), 'dx');
        $archive = new ZipArchive;
        $archive->open($zip, ZipArchive::OVERWRITE);
        $archive->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $archive->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"/>');
        $archive->close();
        $xlsx = (string) file_get_contents($zip);
        @unlink($zip);

        $files = [[CandidacyAppealDocument::KIND_OFFICIAL_FORM, 'Appeal for Extension of Candidacy form.xlsx', 'xlsx', $xlsx, CandidacyAppeal::class]];

        if (mt_rand(0, 1)) {
            $files[] = [CandidacyAppealDocument::KIND_SUPPORTING, 'Progress report.pdf', 'pdf', "%PDF-1.4\n% demo placeholder\n", null];
        }

        foreach ($files as [$kind, $label, $ext, $bytes]) {
            $path = 'candidacy/appeals/'.$appeal->id.'/'.Str::random(32).'.'.$ext;
            Storage::disk('local')->put($path, $bytes);
            CandidacyAppealDocument::create([
                'candidacy_appeal_id' => $appeal->id, 'kind' => $kind, 'original_name' => $label, 'stored_path' => $path, 'mime' => \App\Modules\Chloe\Services\CandidacyDocumentService::MIME[$ext],
                'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'round_uploaded' => 1, 'uploaded_at' => $at,
            ]);
        }
    }

    /** A second run only moves the "days waiting" of in-flight appeals so they stay as designed relative to today. */
    protected function refreshWaiting(array $sc): void
    {
        if (in_array($sc['state'], ['with_supervisor', 'with_chair', 'with_cgs', 'with_dean', 'returned'], true)) {
            CandidacyAppeal::where('appeal_no', $sc['no'])->where('is_demo', true)->update(['stage_entered_at' => $this->now->copy()->subDays($sc['wait'])->subHours(2)]);
        }
    }

    protected function draftExists(array $sc): bool
    {
        return CandidacyAppeal::where('is_demo', true)->where('status', 'draft')->where('demo_profile_id', $sc['profile']->id)->exists();
    }
}
