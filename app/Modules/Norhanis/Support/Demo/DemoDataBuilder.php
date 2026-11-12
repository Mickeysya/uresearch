<?php

namespace App\Modules\Norhanis\Support\Demo;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Norhanis\Models\ClaimsDetail;
use App\Modules\Norhanis\Models\PublicationDetail;
use App\Modules\Norhanis\Models\PublicationEvaluation;
use App\Modules\Norhanis\Models\SavedDraft;
use App\Modules\Norhanis\Models\TravelDetail;
use App\Modules\Norhanis\Models\TravelTaPlan;
use App\Modules\Norhanis\Notifications\AuthorshipCertificationSigned;
use App\Modules\Norhanis\Notifications\PaperEvaluationReceived;
use App\Modules\Norhanis\Support\ApplicantAuthor;
use App\Modules\Norhanis\Support\ApproverNotifier;
use App\Modules\Norhanis\Support\CgsOfficer;
use App\Modules\Norhanis\Support\PublicationFollowUp;
use App\Modules\Norhanis\Support\PublicationSignOffs;
use App\Modules\Norhanis\Support\StageDurations;
use App\Modules\Norhanis\Support\TaStatus;
use App\Modules\Norhanis\Support\TravelDates;
use App\Modules\Norhanis\Workflows\ClaimsWorkflow;
use App\Modules\Norhanis\Workflows\PublicationWorkflow;
use App\Modules\Norhanis\Workflows\TravelWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the Travel, Publication and Student Claims demo data for
 * norhanis:demo-seed, through the module's own services: WorkflowEngine
 * submit()/decide() for every status and stage (never a direct write to
 * applications.status or current_stage), ApproverNotifier for the "awaiting
 * your action" notifications, PublicationSignOffs for authors' and
 * reviewers' sign-offs and their generated PDFs, PublicationFollowUp for
 * the final-approval follow-ups, and DocumentStore for every file.
 *
 * Time: each step runs with the clock set to when it "happened"
 * (Carbon::setTestNow), so submitted_at, every approval_history row, the
 * notifications and the documents carry consistent dates. Stage durations
 * are working days (weekends skipped, as StageDurations counts them),
 * around CGS's charter: mostly on time, some over, a few stalled.
 *
 * Uses only existing users. Every plan is fixed below, so the mix of
 * statuses and stages is the same on every run; who and when are random,
 * seeded by $seed.
 */
class DemoDataBuilder
{
    /** Working days back the oldest submission may reach (about 5 months). */
    protected const WINDOW = 105;

    /** @var array<string, array<int, int>> module_type => application ids */
    public array $created = ['travel' => [], 'publication' => [], 'claims_student' => []];

    /** @var array<int, string> lines for the command to print */
    public array $notes = [];

    protected CarbonImmutable $now;

    /** @var Collection<string, Collection<int, User>> department => students with a supervisor */
    protected Collection $students;

    /** @var Collection<string, User> department => its Chair */
    protected Collection $chairs;

    protected User $officer;

    protected User $director;

    protected User $dean;

    protected User $manager;

    protected ?User $gaStudent = null;

    /** @var array<string, bool> departments whose Chair has a row waiting */
    protected array $chairCovered = [];

    /** @var array<int, bool> supervisors who have a row waiting */
    protected array $supervisorCovered = [];

    /** @var array<int, array{application: Application, student: User, approved_at: CarbonImmutable}> approved Pay and Claims journals */
    protected array $claimable = [];

    protected int $reviewerCursor = 0;

    public function __construct(
        protected WorkflowEngine $engine,
        protected DocumentStore $documents,
        protected PublicationSignOffs $signOffs,
        protected ApproverNotifier $notifier,
        protected DemoFiles $files,
        protected int $seed = 20261112,
    ) {}

    public function build(): void
    {
        mt_srand($this->seed);
        $this->now = CarbonImmutable::now();
        $this->loadPeople();

        try {
            $this->travel();
            $this->publications();
            $this->claims();
        } finally {
            $this->clock(null);
        }
    }

    // ---- People --------------------------------------------------------------------

    protected function loadPeople(): void
    {
        $this->chairs = User::where('role', Role::CHAIR)->whereNotNull('department')->get()->keyBy('department');

        $this->students = User::where('role', Role::STUDENT)
            ->whereNotNull('supervisor_id')->whereNotNull('department')
            ->whereIn('department', $this->chairs->keys())
            ->with('supervisor')
            ->orderBy('id')
            ->get()
            ->filter(fn (User $s) => $s->supervisor !== null)
            ->groupBy('department');

        $this->officer = app(CgsOfficer::class)->user() ?? User::where('role', Role::NON_EXEC_CGS)->orderBy('id')->firstOrFail();
        $this->director = User::where('role', Role::SENIOR_DIRECTOR_CGS)->orderBy('id')->firstOrFail();
        $this->dean = User::where('role', Role::DEAN_PGR)->orderBy('id')->firstOrFail();
        $this->manager = User::where('role', Role::MANAGER_CGS)->orderBy('id')->firstOrFail();

        // The only students the Travel form treats as GA/GRA are those the
        // GA/GRA module says hold an appointment (TaStatus).
        $this->gaStudent = $this->students->flatten()->first(fn (User $s) => app(TaStatus::class)->for($s)['type'] !== null);
    }

    /** The approver who acts at $stage for this student. */
    protected function actor(Stage $stage, User $student): User
    {
        return match ($stage->role) {
            Role::SUPERVISOR => $student->supervisor,
            Role::CHAIR => $this->chairs[$student->department],
            Role::NON_EXEC_CGS => $this->officer,
            Role::SENIOR_DIRECTOR_CGS => $this->director,
            Role::DEAN_PGR => $this->dean,
            Role::MANAGER_CGS => $this->manager,
        };
    }

    /**
     * A student for an application that ends waiting at $stageKey (or null
     * when it is not waiting): a department whose Chair has nothing yet for
     * the Chair stage, a supervisor with nothing yet for the Supervisor
     * stage, otherwise in proportion to each department's students.
     */
    protected function pickStudent(?string $stageKey, ?callable $accept = null): User
    {
        $accept ??= fn () => true;

        if ($stageKey === 'chair') {
            foreach ($this->students->keys()->shuffle(mt_rand()) as $department) {
                if (! isset($this->chairCovered[$department]) && $student = $this->students[$department]->shuffle(mt_rand())->first($accept)) {
                    return $this->cover($student, $stageKey);
                }
            }
        }

        if ($stageKey === 'supervisor') {
            foreach ($this->students->flatten()->shuffle(mt_rand()) as $student) {
                if (! isset($this->supervisorCovered[$student->supervisor_id]) && $accept($student)) {
                    return $this->cover($student, $stageKey);
                }
            }
        }

        for ($try = 0; $try < 50; $try++) {
            $student = $this->weightedStudent();
            if ($accept($student)) {
                return $this->cover($student, $stageKey);
            }
        }

        return $this->cover($this->weightedStudent(), $stageKey);
    }

    protected function cover(User $student, ?string $stageKey): User
    {
        if ($stageKey === 'chair') {
            $this->chairCovered[$student->department] = true;
        }
        if ($stageKey === 'supervisor') {
            $this->supervisorCovered[$student->supervisor_id] = true;
        }

        return $student;
    }

    /** A student, departments weighted by how many students each has. */
    protected function weightedStudent(): User
    {
        $all = $this->students->flatten();

        return $all[mt_rand(0, $all->count() - 1)];
    }

    // ---- Time ----------------------------------------------------------------------

    protected function clock(?CarbonImmutable $at): void
    {
        Carbon::setTestNow($at ? Carbon::instance($at) : null);
        CarbonImmutable::setTestNow($at);
    }

    /** $days working days before $from, weekends skipped. */
    protected static function subWorkingDays(CarbonImmutable $from, float $days): CarbonImmutable
    {
        $cursor = $from;

        while ($days > 0) {
            $cursor = $cursor->subHours(1);
            if (! $cursor->isWeekend()) {
                $days -= 1 / 24;
            }
        }

        return $cursor;
    }

    /** During office hours on the same working day, never before $after. */
    protected function officeHours(CarbonImmutable $at, ?CarbonImmutable $after = null): CarbonImmutable
    {
        // Before 8am: that morning. After 6pm: the next working morning.
        // Only ever forward, so a stage is never shorter than planned.
        if ($at->hour < 8) {
            $at = $at->setTime(8, mt_rand(0, 59));
        } elseif ($at->hour >= 18) {
            $at = $at->addDay()->setTime(8, mt_rand(0, 59));
        }

        while ($at->isWeekend()) {
            $at = $at->addDay()->setTime(8, mt_rand(0, 59));
        }

        if ($after && $at->lessThanOrEqualTo($after)) {
            $at = $after->addMinutes(mt_rand(20, 90));
        }

        return $at->greaterThan($this->now) ? $this->now->subMinutes(mt_rand(5, 60)) : $at;
    }

    /** A completed stage's working days, around its charter: mostly on time, some late. */
    protected function stageDays(int $charter): float
    {
        $roll = mt_rand(1, 100);

        return round($charter * match (true) {
            $roll <= 70 => mt_rand(20, 100) / 100,
            $roll <= 92 => mt_rand(110, 250) / 100,
            default => mt_rand(300, 600) / 100,
        }, 2);
    }

    /** How long the current stage has waited so far: mostly within charter, some over, a few stalled. */
    protected function waitingDays(int $charter, bool $stalled = false): float
    {
        if ($stalled) {
            return mt_rand(800, 2200) / 100;
        }

        return mt_rand(1, 100) <= 70
            ? round($charter * mt_rand(10, 90) / 100, 2)
            : round($charter * mt_rand(120, 300) / 100, 2);
    }

    /**
     * When each stage acted, oldest first, ending now for a waiting
     * application (its current stage has waited $wait) or at a recent
     * moment for a finished one. $earliest keeps it after something else
     * (a claim after its publication's approval).
     *
     * @param  array<int, float>  $durations  working days per stage that acted
     * @return array{0: CarbonImmutable, 1: array<int, CarbonImmutable>} submission, then each stage's action
     */
    protected function timeline(array $durations, ?float $wait, ?CarbonImmutable $earliest = null, ?float $endBackMin = null): array
    {
        $total = array_sum($durations) + ($wait ?? 0);

        if ($wait !== null) {
            $end = $this->now;
        } elseif ($earliest) {
            // After $earliest, with room for every stage: somewhere between
            // the earliest it could finish and today, more often recent.
            $room = StageDurations::workingDaysBetween($earliest, $this->now) - $total - 0.5;
            $end = $room > 0
                ? self::subWorkingDays($this->now, $room * (mt_rand(0, 1000) / 1000) ** 2)
                : $this->now;
        } else {
            // More in recent weeks: the gap from today is squared towards 0.
            $room = max(0.5, self::WINDOW - $total);
            $min = min($endBackMin ?? 0, $room);
            $end = self::subWorkingDays($this->now, $min + ($room - $min) * (mt_rand(0, 1000) / 1000) ** 2);
        }

        $submitted = self::subWorkingDays($end, $total);

        if ($earliest && $submitted->lessThanOrEqualTo($earliest)) {
            // Squeeze into the room after $earliest, keeping the order.
            $available = max(0.3, StageDurations::workingDaysBetween($earliest, $end) - 0.2);
            $scale = $available / max($total, 0.01);
            $durations = array_map(fn ($d) => round($d * $scale, 2), $durations);
            $wait = $wait !== null ? $wait * $scale : null;
            $submitted = self::subWorkingDays($end, array_sum($durations) + ($wait ?? 0));
        }

        $submitted = $this->officeHours($submitted);
        $moments = [];
        $cursor = $submitted;
        foreach ($durations as $days) {
            $cursor = $this->officeHours(CarbonImmutable::instance(StageDurations::addWorkingDays($cursor, $days)), $cursor);
            $moments[] = $cursor;
        }

        return [$submitted, $moments];
    }

    // ---- The approval chain -------------------------------------------------------------

    /**
     * Submit, then decide stage by stage until the plan's end: 'approved'
     * (every stage), 'pending' (waiting at $stopAt), 'rejected' (rejected
     * at $stopAt). Notifies the next approver after each step, as the
     * module's controllers do.
     *
     * @param  array<int, CarbonImmutable>  $moments
     */
    protected function walk(Application $application, User $student, string $outcome, ?string $stopAt, CarbonImmutable $submitted, array $moments, bool $alreadySubmitted = false): Application
    {
        if (! $alreadySubmitted) {
            $this->clock($submitted);
            $application = $this->engine->submit($application);
            $this->notifier->submitted($application);
        }

        foreach ($moments as $i => $at) {
            $application->refresh();
            $stage = $application->currentStage();
            $this->clock($at);

            $reject = $outcome === 'rejected' && $stage->key === $stopAt;
            $this->engine->decide(
                $application,
                $this->actor($stage, $student),
                $reject ? 'reject' : 'approve',
                $reject ? (DemoContent::REJECTION_REMARKS[$stage->key] ?? 'Not supported at this stage.') : (mt_rand(1, 4) === 1 ? 'Checked and in order.' : null),
            );

            $application->refresh();
            $this->notifier->arrived($application);

            if ($application->module_type === 'publication' && $application->status === Application::STATUS_APPROVED) {
                app(PublicationFollowUp::class)->afterFinalApproval($application);
            }

            if ($reject) {
                break;
            }
        }

        return $application->refresh();
    }

    /** Stage keys up to the plan's end, and how many decisions that takes. */
    protected function decisions(array $stages, string $outcome, ?string $stopAt): int
    {
        $keys = array_map(fn (Stage $s) => $s->key, $stages);

        return match ($outcome) {
            'approved' => count($keys),
            'pending' => array_search($stopAt, $keys, true),
            'rejected' => array_search($stopAt, $keys, true) + 1,
        };
    }

    protected function durationsFor(string $module, array $stages, int $count): array
    {
        $durations = [];
        foreach (array_slice($stages, 0, $count) as $stage) {
            $durations[] = $this->stageDays((int) config("norhanis.charter.{$module}.{$stage->key}", 2));
        }

        return $durations;
    }

    protected function attach(Application $application, string $docType, string $filename, ?string $bytes = null, string $mime = 'application/pdf'): void
    {
        $this->documents->storeGenerated($application, $bytes ?? $this->files->pdf($docType), $filename, $docType, $mime);
    }

    protected function draft(User $student, string $module, array $payload, int $step, CarbonImmutable $at): Application
    {
        $this->clock($at);
        $application = Application::create(['student_id' => $student->id, 'module_type' => $module, 'status' => Application::STATUS_DRAFT]);
        SavedDraft::create(['application_id' => $application->id, 'payload' => $payload, 'step' => $step]);
        $this->created[$module][] = $application->id;

        return $application;
    }

    protected function recent(int $minDays = 1, int $maxDays = 20): CarbonImmutable
    {
        return $this->officeHours(self::subWorkingDays($this->now, mt_rand($minDays * 10, $maxDays * 10) / 10));
    }

    // ---- Travel ---------------------------------------------------------------------------

    protected function travel(): void
    {
        $plans = [];
        // Local (24): 8 approved, 12 waiting, 2 rejected, 2 drafts.
        foreach (range(1, 8) as $i) $plans[] = ['intl' => false, 'outcome' => 'approved'];
        foreach (['supervisor', 'supervisor', 'supervisor', 'chair', 'chair', 'chair', 'chair', 'cgs_review', 'cgs_review', 'cgs_review', 'senior_director', 'senior_director'] as $stage) {
            $plans[] = ['intl' => false, 'outcome' => 'pending', 'stage' => $stage];
        }
        $plans[] = ['intl' => false, 'outcome' => 'rejected', 'stage' => 'supervisor'];
        $plans[] = ['intl' => false, 'outcome' => 'rejected', 'stage' => 'cgs_review'];
        $plans[] = ['intl' => false, 'outcome' => 'draft'];
        $plans[] = ['intl' => false, 'outcome' => 'draft'];
        // International (6): 2 approved, 2 waiting (Supervisor, Dean), 1 rejected by the Dean, 1 draft.
        $plans[] = ['intl' => true, 'outcome' => 'approved'];
        $plans[] = ['intl' => true, 'outcome' => 'approved'];
        $plans[] = ['intl' => true, 'outcome' => 'pending', 'stage' => 'supervisor'];
        $plans[] = ['intl' => true, 'outcome' => 'pending', 'stage' => 'dean'];
        $plans[] = ['intl' => true, 'outcome' => 'rejected', 'stage' => 'dean'];
        $plans[] = ['intl' => true, 'outcome' => 'draft'];

        // Urgent: three of the local Supervisor rows and one Chair row leave
        // in 1-3 days, before a decision can arrive. Comfortable: two CGS
        // rows and one Senior Director row leave in 5-8 weeks.
        $plans[11]['urgent'] = $plans[8]['urgent'] = $plans[9]['urgent'] = $plans[10]['urgent'] = true;
        $plans[15]['comfortable'] = $plans[16]['comfortable'] = $plans[18]['comfortable'] = true;
        // A few "Others" with the description.
        $plans[0]['others'] = $plans[12]['others'] = $plans[22]['others'] = true;
        // The GA/GRA student, if there is one: one approved trip, one waiting at the Chair.
        if ($this->gaStudent) {
            $plans[1]['ga'] = $plans[12]['ga'] = true;
        }
        // Stalled: one waiting at the Senior Director far past its charter.
        $plans[19]['stalled'] = true;

        $workflow = app(TravelWorkflow::class);
        $types = array_keys(DemoContent::TRAVEL_REASONS);

        foreach ($plans as $n => $plan) {
            $stage = $plan['stage'] ?? null;
            $student = ($plan['ga'] ?? false)
                ? $this->cover($this->gaStudent, $plan['outcome'] === 'pending' ? $stage : null)
                : $this->pickStudent($plan['outcome'] === 'pending' ? $stage : null);

            $type = ($plan['others'] ?? false) ? 'others' : $types[$n % count($types)];
            $destinations = $plan['intl'] ? DemoContent::TRAVEL_INTERNATIONAL : DemoContent::TRAVEL_LOCAL;
            $contact = DemoContent::CONTACTS[mt_rand(0, count(DemoContent::CONTACTS) - 1)];
            $fields = [
                'type_of_request' => $type,
                'other_request_specify' => $type === 'others' ? DemoContent::OTHER_REQUESTS[$n % 3] : null,
                'reason_for_travel' => $type === 'others' ? DemoContent::OTHER_REQUESTS[$n % 3] : DemoContent::TRAVEL_REASONS[$type],
                'destination_address' => $destinations[mt_rand(0, count($destinations) - 1)],
                'is_international' => $plan['intl'],
                'cost_centre' => mt_rand(0, 2) === 0 ? 'Research grant 015LC0-'.mt_rand(100, 999) : null,
                'contact_person_name' => $contact,
                'contact_person_no' => '012-000 '.mt_rand(1000, 9999),
                'contact_person_email' => DemoContent::contactEmail($contact),
                'is_ga_gra' => (bool) ($plan['ga'] ?? false),
            ];

            if ($plan['outcome'] === 'draft') {
                $at = $this->recent(1, 15);
                $start = $at->addDays(mt_rand(14, 45))->startOfDay();
                $this->draft($student, 'travel', array_filter([
                    'type_of_request' => $fields['type_of_request'],
                    'other_request_specify' => $fields['other_request_specify'],
                    'is_international' => $plan['intl'] ? '1' : '0',
                    'travel_start_date' => $start->toDateString(),
                    'travel_end_date' => $start->addDays(mt_rand(1, 4))->toDateString(),
                    'reason_for_travel' => $fields['reason_for_travel'],
                ], fn ($v) => $v !== null), 1, $at);

                continue;
            }

            $stages = $workflow->routes()[$plan['intl'] ? 'international' : 'local'];
            $count = $this->decisions($stages, $plan['outcome'], $stage);
            $durations = $this->durationsFor('travel', $stages, $count);
            $wait = $plan['outcome'] === 'pending'
                ? (($plan['urgent'] ?? false) ? mt_rand(5, 60) / 100 : $this->waitingDays((int) config("norhanis.charter.travel.{$stage}", 1), (bool) ($plan['stalled'] ?? false)))
                : null;
            [$submitted, $moments] = $this->timeline($durations, $wait);

            // Never before the submission (the form refuses a past start).
            $start = match (true) {
                (bool) ($plan['urgent'] ?? false) => $this->now->addDays(mt_rand(1, 3))->startOfDay(),
                (bool) ($plan['comfortable'] ?? false) => $this->now->addDays(mt_rand(35, 56))->startOfDay(),
                $plan['outcome'] === 'pending' => $this->now->addDays(mt_rand(10, 30))->startOfDay(),
                default => $submitted->addDays(mt_rand(8, 35))->startOfDay(),
            };
            $end = $start->addDays(mt_rand(0, $plan['intl'] ? 6 : 3));

            $this->clock($submitted);
            $application = Application::create(['student_id' => $student->id, 'module_type' => 'travel', 'status' => Application::STATUS_DRAFT]);
            $this->created['travel'][] = $application->id;

            TravelDetail::create($fields + [
                'application_id' => $application->id,
                'travel_start_date' => $start,
                'travel_end_date' => $end,
                'duration_days' => TravelDates::durationDays($start, $end),
            ]);

            if ($fields['is_ga_gra']) {
                $done = min((float) (app(TaStatus::class)->for($student)['hoursCompleted'] ?? 0), (float) config('norhanis.ta.hours_required'));
                $method = $n % 2 ? 'rescheduled_after_travel' : 'completed_before_travel';
                $taPlan = TravelTaPlan::create([
                    'application_id' => $application->id,
                    'total_hours_required' => (int) config('norhanis.ta.hours_required'),
                    'hours_completed' => $done,
                    'hours_during_travel' => mt_rand(3, 8),
                    'management_method' => $method,
                ]);
                if (in_array($method, TravelTaPlan::methodsNeedingDates(), true)) {
                    $taPlan->dates()->create(['date' => $end->addDays(mt_rand(3, 10))->toDateString()]);
                }
            }

            $this->attach($application, 'Supporting Document', 'supporting-document.pdf');
            $this->walk($application, $student, $plan['outcome'], $stage, $submitted, $moments);
        }
    }

    // ---- Publication ----------------------------------------------------------------------

    protected function publications(): void
    {
        $plans = [];
        // Journals (45): 40 Pay and Claims approved (the claims need them), one
        // LOU and one Split Memo approved, Others and LOU waiting, a Split
        // Memo still waiting for a co-author.
        foreach (range(1, 40) as $i) $plans[] = ['type' => 'journal', 'cost' => PublicationDetail::PAY_AND_CLAIMS, 'outcome' => 'approved', 'forClaim' => $i <= 35];
        $plans[] = ['type' => 'journal', 'cost' => PublicationDetail::LETTER_OF_UNDERTAKING, 'outcome' => 'approved'];
        $plans[] = ['type' => 'journal', 'cost' => PublicationDetail::SPLIT_MEMO, 'outcome' => 'approved'];
        $plans[] = ['type' => 'journal', 'cost' => PublicationDetail::OTHERS, 'outcome' => 'pending', 'stage' => 'cgs_review'];
        $plans[] = ['type' => 'journal', 'cost' => PublicationDetail::LETTER_OF_UNDERTAKING, 'outcome' => 'pending', 'stage' => 'senior_director', 'stalled' => true];
        $plans[] = ['type' => 'journal', 'cost' => PublicationDetail::SPLIT_MEMO, 'outcome' => 'signoff', 'signoff' => 'coauthor'];
        // Conferences (25), local and international alternating.
        foreach (range(1, 6) as $i) $plans[] = ['type' => 'conference', 'outcome' => 'approved'];
        foreach (['supervisor', 'supervisor', 'supervisor', 'chair', 'chair', 'chair', 'cgs_review', 'senior_director'] as $stage) {
            $plans[] = ['type' => 'conference', 'outcome' => 'pending', 'stage' => $stage];
        }
        foreach (['coauthor', 'corrections', 'declined', 'reviewer', 'coauthor'] as $variant) {
            $plans[] = ['type' => 'conference', 'outcome' => 'signoff', 'signoff' => $variant];
        }
        foreach (['supervisor', 'chair', 'cgs_review', 'senior_director'] as $stage) {
            $plans[] = ['type' => 'conference', 'outcome' => 'rejected', 'stage' => $stage];
        }
        $plans[] = ['type' => 'conference', 'outcome' => 'draft'];
        $plans[] = ['type' => 'conference', 'outcome' => 'draft'];

        // One Pay and Claims journal per department first, so the claims
        // can reach every Chair; the rest by department size.
        $departments = $this->students->keys()->shuffle(mt_rand())->values();
        $conferenceIndex = 0;

        foreach ($plans as $n => $plan) {
            $stage = $plan['stage'] ?? null;
            $student = ($plan['forClaim'] ?? false) && $n < $departments->count()
                ? $this->students[$departments[$n]]->shuffle(mt_rand())->first()
                : $this->pickStudent($plan['outcome'] === 'pending' ? $stage : null);

            $conference = $plan['type'] === 'conference';
            $scope = $conference ? (($conferenceIndex++ % 2) ? 'international' : 'local') : null;
            $this->publication($student, $plan, $scope, $n);
        }
    }

    protected function publication(User $student, array $plan, ?string $scope, int $n): void
    {
        $conference = $plan['type'] === 'conference';
        $type = $conference ? PublicationDetail::CONFERENCE : PublicationDetail::JOURNAL;
        [$topics, $journals, $conferences] = DemoContent::field($student->department);
        $title = $topics[$n % count($topics)];
        $venueTitle = $conference ? $conferences[$n % count($conferences)].' '.(2026 + ($n % 2)) : $journals[$n % count($journals)];
        $usd = $conference ? $scope === 'international' : mt_rand(0, 2) === 0;
        $fee = $usd ? mt_rand(30, 250) * 10 : mt_rand(8, 60) * 100;
        $cost = $conference ? PublicationDetail::CONFERENCE_COST_CENTRE : $plan['cost'];

        $fields = [
            'type_of_request' => $type,
            'conference_scope' => $scope,
            'title_of_paper' => $title,
            'title_of_conference_journal' => $venueTitle,
            'organizer_publisher' => $conference ? DemoContent::ORGANISERS[$n % count(DemoContent::ORGANISERS)] : DemoContent::PUBLISHERS[$n % count(DemoContent::PUBLISHERS)],
            'conference_journal_fee' => $fee,
            'currency_type' => $usd ? 'USD' : 'MYR',
            'cost_centre' => $cost,
            'cost_centre_other' => $conference ? 'Research grant YUTP 015LC0-'.mt_rand(100, 999) : ($cost === PublicationDetail::OTHERS ? 'Department research fund' : null),
            'wants_letter_of_undertaking' => $cost === PublicationDetail::LETTER_OF_UNDERTAKING,
        ];

        if ($plan['outcome'] === 'draft') {
            $this->draft($student, 'publication', [
                'type_of_request' => $type,
                'conference_scope' => $scope,
                'title_of_paper' => $title,
                'title_of_conference_journal' => $venueTitle,
                'cost_centre_other' => $fields['cost_centre_other'],
            ], 0, $this->recent(1, 12));

            return;
        }

        $stages = app(PublicationWorkflow::class)->stages();
        $stage = $plan['stage'] ?? null;
        $decided = $plan['outcome'] === 'signoff' ? 0 : $this->decisions($stages, $plan['outcome'], $stage);
        $durations = $this->durationsFor('publication', $stages, $decided);
        $wait = match ($plan['outcome']) {
            'pending' => $this->waitingDays((int) config("norhanis.charter.publication.{$stage}", 2), (bool) ($plan['stalled'] ?? false)),
            'signoff' => mt_rand(20, 600) / 100,
            default => null,
        };
        // A claimed journal is approved early enough for its claim to follow.
        [$submitted, $moments] = $this->timeline($durations, $wait, null, ($plan['forClaim'] ?? false) ? 30 : null);
        $signOffDays = mt_rand(150, 700) / 100;
        $filed = $this->officeHours(self::subWorkingDays($submitted, $signOffDays));

        $this->clock($filed);
        $application = Application::create(['student_id' => $student->id, 'module_type' => 'publication', 'status' => Application::STATUS_DRAFT]);
        $this->created['publication'][] = $application->id;

        $travel = [];
        if ($conference) {
            $start = $filed->addDays(mt_rand(20, 70))->startOfDay();
            $end = $start->addDays(mt_rand(1, 4));
            $venues = $scope === 'international' ? DemoContent::INTERNATIONAL_VENUES : DemoContent::LOCAL_VENUES;
            $contact = DemoContent::CONTACTS[mt_rand(0, count(DemoContent::CONTACTS) - 1)];
            $travel = [
                'travel_start_date' => $start,
                'travel_end_date' => $end,
                'duration_days' => TravelDates::durationDays($start, $end),
                'reason_for_travel' => 'Present the paper at the conference',
                'destination_address' => $venues[mt_rand(0, count($venues) - 1)],
                'contact_person_name' => $contact,
                'contact_person_no' => '012-000 '.mt_rand(1000, 9999),
                'contact_person_email' => DemoContent::contactEmail($contact),
            ];
        }

        $detail = PublicationDetail::create($fields + $travel + ['application_id' => $application->id]);

        // Author 1 is the student, who signs on the form itself.
        // Prefilled the way the form prefills Author 1 (Support\ApplicantAuthor).
        $own = $detail->authors()->create(['email' => strtolower($student->email)] + ApplicantAuthor::for($student) + [
            'role_contribution' => 'Conceptualisation, methodology and writing',
        ]);
        $this->signOffs->sign($own, $this->files->signature(), null, '127.0.0.1');

        $coAuthors = [];
        foreach (array_slice(DemoContent::CO_AUTHORS, $n % 5, mt_rand(1, 2)) as [$name, $designation, $organisation]) {
            $coAuthors[] = $detail->authors()->create([
                'author_name' => $name,
                'email' => DemoContent::externalEmail($name),
                'designation' => $designation,
                'organisation' => $organisation,
                'role_contribution' => 'Data analysis and review of the manuscript',
            ]);
        }

        $required = PublicationDetail::reviewersRequired($type, $scope);
        $evaluations = [];
        foreach (range(1, $required) as $position) {
            $name = DemoContent::REVIEWERS[$this->reviewerCursor++ % count(DemoContent::REVIEWERS)];
            $evaluations[] = $detail->evaluations()->create([
                'position' => $position,
                'reviewer_name' => $name,
                'reviewer_email' => DemoContent::reviewerEmail($name),
            ]);
        }

        foreach ([
            'invoice' => 'Invoice', 'index_proof' => 'Index Proof', 'turnitin_report' => 'Turnitin Report',
            'letter_of_acceptance' => 'Letter of Acceptance', 'conference_journal_details' => 'Conference/Journal Details',
            'response_to_reviewer_comment' => 'Response to Reviewer Comment',
            'corrected_conference_journal_paper' => 'Corrected Conference/Journal Paper',
            'manuscript' => PublicationSignOffs::MANUSCRIPT,
        ] as $field => $label) {
            $this->attach($application, $label, str_replace('_', '-', $field).'.pdf');
        }

        foreach ($coAuthors as $author) {
            $this->signOffs->invite($author, $detail, $student->name);
        }
        foreach ($evaluations as $evaluation) {
            $this->signOffs->inviteReviewer($evaluation, $detail, $student->name);
        }

        // Sign-offs between filing and submission, in order.
        $variant = $plan['signoff'] ?? null;
        $steps = [];
        foreach ($coAuthors as $i => $author) {
            if (! ($variant === 'coauthor' && $i === 0)) {
                $steps[] = ['author', $author];
            }
        }
        foreach ($evaluations as $i => $evaluation) {
            $last = $i === count($evaluations) - 1;
            $steps[] = match (true) {
                $variant === 'corrections' && $last => ['corrections', $evaluation],
                $variant === 'declined' && $last => ['declined', $evaluation],
                $variant === 'reviewer' && $last => ['none', $evaluation],
                default => ['review', $evaluation],
            };
        }

        $end = $plan['outcome'] === 'signoff' ? $this->now->subMinutes(30) : $submitted;
        $span = max(0.2, StageDurations::workingDaysBetween($filed, $end));
        $cursor = $filed;
        $signedCount = 1;
        foreach ($steps as $k => [$kind, $who]) {
            // The last sign-off lands on the submission itself.
            $at = $k === count($steps) - 1 && $plan['outcome'] !== 'signoff'
                ? $submitted
                : $this->officeHours(CarbonImmutable::instance(StageDurations::addWorkingDays($filed, $span * ($k + 1) / count($steps))), $cursor);
            $cursor = $at;
            $this->clock($at);

            if ($kind === 'author') {
                $this->signOffs->sign($who, $this->files->signature(), null, '127.0.0.1');
                $signedCount++;
                $form = $plan['outcome'] === 'signoff' ? null : $this->signOffs->finishIfComplete($application);
                $student->notify(new AuthorshipCertificationSigned($application, $who->author_name, $signedCount, count($coAuthors) + 1, $form));
            } elseif ($kind === 'review' || $kind === 'corrections') {
                $sheet = $this->sheet($kind === 'corrections' ? PublicationEvaluation::CORRECTIONS : PublicationEvaluation::RECOMMENDED);
                $document = $this->signOffs->recordEvaluation($who, $sheet, $this->files->signature(), '127.0.0.1');
                $submittedNow = $plan['outcome'] === 'signoff' ? false : $this->signOffs->finishIfComplete($application) !== null;
                $student->notify(new PaperEvaluationReceived($application, $who->fresh(), $document, $submittedNow));
            } elseif ($kind === 'declined') {
                $this->signOffs->decline($who, 'On sabbatical until next semester.');
                $student->notify(new PaperEvaluationReceived($application, $who->fresh()));
                $name = DemoContent::REVIEWERS[$this->reviewerCursor++ % count(DemoContent::REVIEWERS)];
                $replacement = $this->signOffs->replaceReviewer($who, $name, DemoContent::reviewerEmail($name));
                $this->signOffs->inviteReviewer($replacement, $detail, $student->name);
            }
        }

        if ($plan['outcome'] === 'signoff') {
            return;
        }

        // Everything in: finishIfComplete() submitted it on the last
        // sign-off. Make sure it did, at the planned moment, then walk on.
        $this->clock($submitted);
        if ($application->fresh()->status === Application::STATUS_DRAFT) {
            $this->signOffs->finishIfComplete($application);
        }
        $application->refresh();
        $application = $this->walk($application, $student, $plan['outcome'], $stage, $submitted, $moments, alreadySubmitted: true);

        if (($plan['forClaim'] ?? false) || ($plan['cost'] ?? null) === PublicationDetail::PAY_AND_CLAIMS) {
            $this->claimable[] = ['application' => $application, 'student' => $student, 'approved_at' => end($moments), 'reserved' => (bool) ($plan['forClaim'] ?? false)];
        }
    }

    /** A filled-in Paper Evaluation Sheet. */
    protected function sheet(string $recommendation): array
    {
        $sheet = ['recommendation' => $recommendation];
        foreach (array_keys(PublicationEvaluation::CRITERIA) as $criterion) {
            $sheet["{$criterion}_rating"] = mt_rand(3, 5);
            $sheet["{$criterion}_comment"] = mt_rand(0, 2) ? null : 'Clear and well supported.';
        }
        $sheet['additional_comments'] = $recommendation === PublicationEvaluation::CORRECTIONS
            ? 'Please justify the sampling interval and add the uncertainty analysis to Section 4.'
            : (mt_rand(0, 1) ? 'Recommended for submission.' : null);

        return $sheet;
    }

    // ---- Student Claims -------------------------------------------------------------------

    protected function claims(): void
    {
        $plans = [];
        foreach (range(1, 12) as $i) $plans[] = ['outcome' => 'approved'];
        foreach (['supervisor', 'supervisor', 'supervisor', 'supervisor', 'supervisor', 'chair', 'chair', 'chair', 'chair', 'chair', 'cgs_review', 'cgs_review', 'cgs_review', 'manager', 'manager', 'manager'] as $stage) {
            $plans[] = ['outcome' => 'pending', 'stage' => $stage];
        }
        foreach (['supervisor', 'chair', 'cgs_review', 'manager'] as $stage) $plans[] = ['outcome' => 'rejected', 'stage' => $stage];
        foreach (range(1, 3) as $i) $plans[] = ['outcome' => 'draft'];
        $plans[22]['stalled'] = true; // one CGS review far past its charter

        // Chair rows choose first (each department has one claimable
        // journal set aside for this), then Supervisor rows, then the rest,
        // so the queues with nothing waiting are filled before the pool runs low.
        $rank = fn (array $plan) => match (true) {
            $plan['outcome'] === 'pending' && $plan['stage'] === 'chair' => 0,
            $plan['outcome'] === 'pending' && $plan['stage'] === 'supervisor' => 1,
            $plan['outcome'] === 'pending' => 2,
            default => 3,
        };
        uasort($plans, fn ($a, $b) => $rank($a) <=> $rank($b));

        $pool = collect($this->claimable)->where('reserved', true)->sortBy(fn ($c) => $c['approved_at'])->values();
        $workflow = app(ClaimsWorkflow::class);

        foreach ($plans as $plan) {
            $stage = $plan['stage'] ?? null;
            $index = $this->pickClaimable($pool, $plan['outcome'] === 'pending' ? $stage : null);
            if ($index === null) {
                $this->notes[] = 'Ran out of eligible publications for claims.';
                break;
            }
            ['application' => $publication, 'student' => $student, 'approved_at' => $approvedAt] = $pool[$index];
            $pool->forget($index);
            $pool = $pool->values();

            $detail = PublicationDetail::where('application_id', $publication->id)->first();
            $amount = $detail->currency_type === 'USD'
                ? round((float) $detail->conference_journal_fee * mt_rand(442, 472) / 100 + mt_rand(-15, 15), 2)
                : round((float) $detail->conference_journal_fee + mt_rand(-20, 20), 2);
            $bank = DemoContent::BANKS[mt_rand(0, count(DemoContent::BANKS) - 1)];
            $account = (string) mt_rand(1000, 9999).mt_rand(100000, 999999);

            if ($plan['outcome'] === 'draft') {
                $this->draft($student, 'claims_student', [
                    'publication_application_id' => (string) $publication->id,
                    'total_claims' => (string) $amount,
                    'bank_name' => $bank,
                ], 0, $this->officeHours($this->recent(1, 10), $approvedAt));

                continue;
            }

            $stages = $workflow->stages();
            $count = $this->decisions($stages, $plan['outcome'], $stage);
            $durations = $this->durationsFor('claims_student', $stages, $count);
            $wait = $plan['outcome'] === 'pending'
                ? $this->waitingDays((int) config("norhanis.charter.claims_student.{$stage}", 2), (bool) ($plan['stalled'] ?? false))
                : null;
            [$submitted, $moments] = $this->timeline($durations, $wait, $approvedAt->addHours(mt_rand(2, 72)));

            $this->clock($submitted);
            $application = Application::create(['student_id' => $student->id, 'module_type' => 'claims_student', 'status' => Application::STATUS_DRAFT]);
            $this->created['claims_student'][] = $application->id;

            ClaimsDetail::create([
                'application_id' => $application->id,
                'publication_application_id' => $publication->id,
                'bank_name' => $bank,
                'bank_account_no' => $account,
                'total_claims' => $amount,
            ]);

            $this->attach($application, 'Receipt', 'receipt.png', $this->files->png(), 'image/png');
            $this->attach($application, 'Proof of Payment', 'proof-of-payment.pdf');
            $this->attach($application, 'Invoice', 'invoice.pdf');

            $this->walk($application, $student, $plan['outcome'], $stage, $submitted, $moments);
        }
    }

    /** Which eligible publication to claim next: one whose student fills a gap in the Chair or Supervisor queues, if any. */
    protected function pickClaimable(Collection $pool, ?string $stageKey): ?int
    {
        if ($pool->isEmpty()) {
            return null;
        }

        foreach ($pool as $i => $entry) {
            $student = $entry['student'];
            if ($stageKey === 'chair' && ! isset($this->chairCovered[$student->department])) {
                $this->cover($student, 'chair');

                return $i;
            }
            if ($stageKey === 'supervisor' && ! isset($this->supervisorCovered[$student->supervisor_id])) {
                $this->cover($student, 'supervisor');

                return $i;
            }
        }

        // Every gap is filled already: take the oldest approval.
        if ($stageKey) {
            $this->cover($pool[0]['student'], $stageKey);
        }

        return 0;
    }
}
