<?php

namespace App\Modules\Chloe\Console\Commands;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyAppealApproval;
use App\Modules\Chloe\Models\CandidacyAppealDocument;
use App\Modules\Chloe\Models\CandidacyAppealEvent;
use App\Modules\Chloe\Models\CandidacyDepartment;
use App\Modules\Chloe\Models\CandidacyStaffAssignment;
use App\Modules\Chloe\Models\CandidacyStaffProfile;
use App\Modules\Chloe\Models\DemoStudentProfile;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Services\CandidacyMemoService;
use App\Modules\Chloe\Services\CandidacySignatureService;
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

    protected const DEPARTMENTS = [
        'CIS' => 'Computer & Information Sciences', 'CEE' => 'Civil & Environmental Engineering', 'EEE' => 'Electrical & Electronic Engineering',
        'CHE' => 'Chemical Engineering', 'MEC' => 'Mechanical Engineering', 'GPE' => 'Geosciences & Petroleum Engineering',
    ];

    /** Which department a demo student's programme belongs to. */
    protected const PROGRAMME_DEPT = [
        'MSc Computer Science (Full-Time)' => 'CIS', 'MSc Information Technology (Full-Time)' => 'CIS', 'PhD Computer & Information Sciences (Full-Time)' => 'CIS',
        'PhD Civil Engineering (Full-Time)' => 'CEE', 'PhD Electrical & Electronic Engineering (Full-Time)' => 'EEE', 'PhD Chemical Engineering (Full-Time)' => 'CHE',
        'MSc Mechanical Engineering (Full-Time)' => 'MEC', 'MSc Petroleum Engineering (Full-Time)' => 'GPE',
    ];

    protected const LECTURERS = [
        'CIS' => ['Dr. Rohani Idris', 'Dr. Kevin Tan Boon Keat'], 'CEE' => ['Dr. Nurul Huda Zainal', 'Dr. Ahmad Syukri Latif', 'Dr. Priya Subramaniam'],
        'EEE' => ['Dr. Faridah Osman', 'Dr. Lee Chong Wei', 'Dr. Muthu Rajan'], 'CHE' => ['Dr. Salmah Yusof', 'Dr. Tan Ai Ling', 'Dr. Khairul Anuar'],
        'MEC' => ['Dr. Zainuddin Hamid', 'Dr. Rajesh Menon', 'Dr. Noraini Baharom'], 'GPE' => ['Dr. Hasnul Hadi', 'Dr. Wong Mei Fong', 'Dr. Ismail Sabri'],
    ];

    protected const CHAIRS = ['CEE' => 'Assoc. Prof. Dr. Aminah Daud', 'EEE' => 'Assoc. Prof. Dr. Gan Kok Seng', 'CHE' => 'Assoc. Prof. Dr. Maznah Jamil', 'MEC' => 'Assoc. Prof. Dr. Shahrir Abdullah', 'GPE' => 'Assoc. Prof. Dr. Hanis Roslan'];

    protected const DEANS = ['CEE' => 'Prof. Dr. Ramli Hashim', 'EEE' => 'Prof. Dr. Lau Hock Seng', 'CHE' => 'Prof. Dr. Siti Aishah Karim', 'MEC' => 'Prof. Dr. Faizul Rahman', 'GPE' => 'Prof. Dr. Johan Ahmad'];

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
        mt_srand(self::SEED);
        $this->created = 0;
        $this->now = Carbon::now('Asia/Kuala_Lumpur');

        DB::transaction(function () use ($signatures) {
            $this->seedStaff($signatures);
            $this->alignRealCandidacies();
        });

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

        $this->info("Demo appeals created this run: {$this->created}. Demo appeals now: ".CandidacyAppeal::where('is_demo', true)->count().'.');

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------ staff and departments

    protected function seedStaff(CandidacySignatureService $signatures): void
    {
        $profileOf = fn (?int $userId) => $userId ? CandidacyStaffProfile::where('user_id', $userId)->first() : null;
        $real = [
            'lecturer' => $profileOf(User::where('email', 'supervisor@utp.edu.my')->value('id')),
            'chair' => $profileOf(User::where('email', 'chair@utp.edu.my')->value('id')),
            'dean' => $profileOf(User::where('role', 'dean_pgr')->orderBy('id')->value('id')),
        ];

        foreach (self::DEPARTMENTS as $code => $name) {
            $dept = CandidacyDepartment::where('code', $code)->first() ?? CandidacyDepartment::create(['code' => $code, 'name' => $name, 'active' => true, 'is_demo' => true]);
            $this->departments[$code] = $dept;
            $lecturers = [];
            $chair = null;
            $dean = null;

            if ($code === 'CIS') {
                $real['lecturer'] && $lecturers[] = $this->assign($dept, $real['lecturer'], 'lecturer');
                $chair = $real['chair'] ? $this->assign($dept, $real['chair'], 'chair') : null;
                $dean = $real['dean'] ? $this->assign($dept, $real['dean'], 'dean') : null;
            }

            foreach (self::LECTURERS[$code] as $lecturerName) {
                $lecturers[] = $this->assign($dept, $this->demoProfile($lecturerName, "Lecturer, {$name}", $dept, $signatures), 'lecturer');
            }

            $chair ??= $this->assign($dept, $this->demoProfile(self::CHAIRS[$code], "Programme Chair, {$name}", $dept, $signatures), 'chair');
            $dean ??= $this->assign($dept, $this->demoProfile(self::DEANS[$code], 'Dean of Postgraduate and Research', $dept, $signatures), 'dean');
            $this->staff[$code] = ['lecturers' => $lecturers, 'chair' => $chair, 'dean' => $dean];
        }
    }

    protected function demoProfile(string $name, string $designation, CandidacyDepartment $dept, CandidacySignatureService $signatures): CandidacyStaffProfile
    {
        $profile = CandidacyStaffProfile::firstOrCreate(['full_name' => $name, 'is_demo' => true], ['designation' => $designation, 'department_id' => $dept->id]);

        if (! $profile->hasSignature()) {
            $signatures->placeholder($profile);
        }

        return $profile;
    }

    protected function assign(CandidacyDepartment $dept, CandidacyStaffProfile $profile, string $role): CandidacyStaffProfile
    {
        if ($role === 'chair' || $role === 'dean') {
            $current = CandidacyStaffAssignment::where('department_id', $dept->id)->where('role', $role)->first();

            if ($current && $current->staff_profile_id !== $profile->id) {
                return CandidacyStaffProfile::find($current->staff_profile_id); // the department already has its own; never replaced
            }
        }

        CandidacyStaffAssignment::firstOrCreate(['department_id' => $dept->id, 'staff_profile_id' => $profile->id, 'role' => $role]);

        return $profile;
    }

    /** Every real candidacy record gets a department and an original expiry date (consistent with the extension months used). */
    protected function alignRealCandidacies(): void
    {
        $cis = $this->departments['CIS'];

        foreach (StudyCandidacy::all() as $candidacy) {
            $candidacy->department_id ??= $cis->id;
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
            $code = self::PROGRAMME_DEPT[$profile->programme] ?? 'CIS';
            $byDept[$code][] = $profile;
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
        $s = fn (string $dept, int $i) => $students[$dept][$i];
        $list = [];
        $add = function (string $dept, int $i, string $state, int $months, int $wait, int $age, array $extra = []) use (&$list, $s) {
            $list[] = ['dept' => $dept, 'profile' => $s($dept, $i), 'state' => $state, 'months' => $months, 'wait' => $wait, 'age' => $age] + $extra;
        };

        // two approved appeals for one student, and one at exactly 12 of 12 months (6 + 6)
        $add('EEE', 0, 'approved', 6, 0, 190, ['seq' => 1, 'closed_days_ago' => 150]);
        $add('EEE', 0, 'approved', 6, 0, 120, ['seq' => 2, 'closed_days_ago' => 70]);
        $add('GPE', 0, 'approved', 3, 0, 170, ['seq' => 1, 'closed_days_ago' => 135]);
        $add('GPE', 0, 'approved', 4, 0, 90, ['seq' => 2, 'closed_days_ago' => 52]);
        // one approved and then a new appeal in flight at the Chair
        $add('CHE', 0, 'approved', 3, 0, 160, ['seq' => 1, 'closed_days_ago' => 125]);
        $add('CHE', 0, 'with_chair', 4, 6, 12, ['seq' => 2]);
        // single approved appeals (memo + stamps) in other departments
        $add('CEE', 0, 'approved', 2, 0, 80, ['closed_days_ago' => 50]);
        $add('MEC', 0, 'approved', 12, 0, 60, ['closed_days_ago' => 31]);
        $add('MEC', 1, 'approved', 5, 0, 45, ['closed_days_ago' => 20]);
        $add('CEE', 1, 'approved', 1, 0, 30, ['closed_days_ago' => 9]);
        // final rejections
        $add('EEE', 1, 'rejected', 4, 0, 100, ['closed_days_ago' => 68]);
        $add('CHE', 1, 'rejected', 6, 0, 75, ['closed_days_ago' => 41]);
        $add('GPE', 1, 'rejected', 3, 0, 50, ['closed_days_ago' => 22]);
        // returned: by each stage, and a Dean return waiting for the student
        $add('CIS', 0, 'returned', 5, 8, 20, ['by' => 'supervisor']);
        $add('CIS', 1, 'returned', 3, 5, 24, ['by' => 'chair']);
        $add('CEE', 2, 'returned', 6, 3, 27, ['by' => 'cgs']);
        $add('MEC', 2, 'returned', 4, 2, 33, ['by' => 'dean']);
        // waiting at the Supervisor (CIS ones go to the real lecturer)
        foreach ([['CIS', 2, 4, 0], ['CIS', 3, 6, 1], ['CIS', 4, 2, 3], ['EEE', 2, 3, 5], ['GPE', 2, 5, 8], ['CHE', 2, 4, 12]] as [$d, $i, $m, $w]) {
            $add($d, $i, 'with_supervisor', $m, $w, $w + 2);
        }
        // waiting at the Chair
        foreach ([['CIS', 5, 6, 0], ['CIS', 6, 3, 2], ['CEE', 3, 5, 6], ['MEC', 3, 2, 10], ['EEE', 3, 4, 14]] as [$d, $i, $m, $w]) {
            $add($d, $i, 'with_chair', $m, $w, $w + 6);
        }
        // waiting at CGS verification (one is a re-check after a Dean return)
        foreach ([['CIS', 7, 4, 1], ['GPE', 3, 6, 4], ['CHE', 3, 3, 7], ['CEE', 4, 5, 11]] as [$d, $i, $m, $w]) {
            $add($d, $i, 'with_cgs', $m, $w, $w + 12);
        }
        $add('MEC', 4, 'with_cgs', 4, 13, 38, ['recheck' => true]);
        // waiting at the Dean
        foreach ([['CIS', 8, 5, 0], ['CIS', 9, 3, 3], ['EEE', 4, 6, 6], ['GPE', 4, 2, 12]] as [$d, $i, $m, $w]) {
            $add($d, $i, 'with_dean', $m, $w, $w + 18);
        }
        // drafts
        foreach ([['CIS', 10, 3], ['CEE', 5, 4], ['GPE', 5, 2]] as [$d, $i, $m]) {
            $add($d, $i, 'draft', $m, 0, 2);
        }
        // closed because it was not resubmitted before the (real) candidacy expired
        if ($expired = User::where('email', 'student2@utp.edu.my')->first()) {
            if (StudyCandidacy::where('student_id', $expired->id)->exists()) {
                $list[] = ['dept' => 'CIS', 'student_id' => $expired->id, 'state' => 'closed_expired', 'months' => 3, 'wait' => 45, 'age' => 70, 'by' => 'supervisor', 'closed_days_ago' => 30];
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
        $supervisor = ($sc['dept'] === 'CIS' && in_array($state, ['with_supervisor', 'returned'], true)) ? $lecturers[0] : $lecturers[mt_rand(0, count($lecturers) - 1)];
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
            ($sc['dept'] === 'GPE' && ($sc['seq'] ?? 0) === 1 && $sc['state'] === 'approved') => 4,
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

        $this->approvalRow([
            'candidacy_appeal_id' => $appeal->id, 'round' => $round, 'stage' => 'dean', 'profile_id' => $dean->id, 'user_id' => $dean->user_id,
            'full_name' => $dean->full_name, 'designation' => $dean->designation, 'decision' => 'rejected', 'remark' => 'Progress is not satisfactory.',
            'decided_at' => $at, 'verification_code' => CandidacyVerification::code((string) $appeal->appeal_no, 'dean', $dean->id, $at), 'created_at' => $at, 'updated_at' => $at,
        ]);
        $this->event($appeal, $this->actor($appeal, $dean, $dean->full_name, 'dean', $dean->user_id), 'rejected', 'dean', null, 'rejected', $at, $round, ['progress_not_satisfactory'], 'Progress is not satisfactory.');
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
