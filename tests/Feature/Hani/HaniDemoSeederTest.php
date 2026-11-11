<?php

namespace Tests\Feature\Hani;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\Department;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Database\Seeders\HaniDemoSeeder;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerPublication;
use App\Modules\Hani\Models\VivaDetail;
use App\Modules\Hani\Models\VivaSoftboundCheck;
use App\Modules\Hani\Support\ExaminerDivergence;
use App\Modules\Hani\Support\VivaProgress;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/** HaniDemoSeeder: the pool (part 1) and the viva scenarios (part 2), relative to the day it runs. */
class HaniDemoSeederTest extends TestCase
{
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();

        foreach ([
            'Computing' => 'FSMC', 'Management' => 'FSMC', 'Applied Sciences' => 'FSMC', 'Geosciences' => 'FSMC',
            'Chemical Engineering' => 'FOE', 'Petroleum Engineering' => 'FOE', 'Civil & Environmental Engineering' => 'FOE',
            'Mechanical Engineering' => 'FOE', 'Electrical & Electronics Engineering' => 'FOE',
        ] as $name => $faculty) {
            Department::create(['name' => $name, 'faculty' => $faculty]);
        }

        // A shared test account, which the seeder must leave alone.
        $this->user(Role::ACADEMIC_EXEC, ['name' => 'Siti Academic Exec', 'email' => 'ae@utp.edu.my', 'department' => 'Computing']);
    }

    protected function student(string $key): User
    {
        $email = collect(json_decode(file_get_contents(HaniDemoSeeder::DATASET), true)['students'])->firstWhere('key', $key)['email'];

        return User::where('email', $email)->sole();
    }

    public function test_it_gives_the_dean_demo_account_the_deans_name(): void
    {
        $dean = $this->user(Role::DEAN_PGR, ['name' => 'Prof. Dr. Hafiz Osman', 'email' => 'dean@utp.edu.my']);

        $this->seed(HaniDemoSeeder::class);

        $this->assertSame('Prof. Ts. Dr. M. Fadzil Hassan', $dean->refresh()->name);
        $this->assertSame('dean@utp.edu.my', $dean->email);
    }

    public function test_it_loads_the_pool_through_the_import_and_is_idempotent(): void
    {
        $before = md5_file(HaniDemoSeeder::DATASET);
        $ae = User::where('email', 'ae@utp.edu.my')->sole()->getAttributes();

        $this->seed(HaniDemoSeeder::class);
        $users = User::count();
        $applications = Application::count();

        $this->assertSame(41, Examiner::count());
        $this->assertSame(15, ExaminerPublication::count());
        $this->assertSame('Computing', Examiner::where('email', 'farah.rosli@example.com')->value('department'));
        $this->assertFalse((bool) Examiner::where('email', 'zulkifli.hassan@example.com')->value('is_active'));
        $this->assertStringStartsWith('Adjunct', Examiner::where('email', 'azlina.mansor@example.org')->value('current_utp_appointment'));

        $this->seed(HaniDemoSeeder::class);

        $this->assertSame(41, Examiner::count(), 'Running it again adds nobody.');
        $this->assertSame(15, ExaminerPublication::count());
        $this->assertSame($users, User::count(), 'Running it again adds no accounts.');
        $this->assertSame($applications, Application::count(), 'The scenarios are rebuilt, not duplicated.');
        $this->assertSame($ae, User::where('email', 'ae@utp.edu.my')->sole()->getAttributes(), 'Shared test accounts are not touched.');
        $this->assertSame($before, md5_file(HaniDemoSeeder::DATASET), 'The dataset is read, never written.');
    }

    public function test_dates_follow_the_day_it_runs(): void
    {
        foreach (['2026-11-03', '2027-06-20'] as $day) {
            Carbon::setTestNow($day.' 09:00');
            $this->seed(HaniDemoSeeder::class);

            // Rajesh examined 44 days before the dataset's anchor: On Gap whatever the day.
            $rajesh = Examiner::where('email', 'rajesh.s@example.com')->sole();
            $this->assertSame(Carbon::parse($day)->subDays(44)->toDateString(), $rajesh->last_examination_date->toDateString(), $day);
            $this->assertSame(Examiner::STATE_ON_GAP, $rajesh->state(), $day);

            $s07 = VivaProgress::for($this->student('DEMO-S07'));
            $this->assertSame(Carbon::parse($day)->addDays(7)->toDateString(), $s07->detail->viva_at->toDateString(), 'viva in 7 days, '.$day);
        }

        Carbon::setTestNow();
    }

    public function test_each_student_is_at_their_stated_stage(): void
    {
        $this->seed(HaniDemoSeeder::class);

        $this->assertNull(VivaProgress::for($this->student('DEMO-S01'))->notice, 'S01 starts from nothing');
        $this->assertSame(Application::STATUS_PENDING, VivaProgress::for($this->student('DEMO-S02'))->notice->status);

        $this->assertSame('cgs_compile', VivaProgress::for($this->student('DEMO-S03'))->nomination->current_stage);
        $this->assertSame('senior_director', VivaProgress::for($this->student('DEMO-S04'))->nomination->current_stage);

        $s05 = VivaProgress::for($this->student('DEMO-S05'));
        $this->assertSame('Turnitin report is older than 30 days; please upload a current report.', $s05->softboundReturnReason());
        $this->assertSame('warning', collect(VivaSoftboundCheck::where('application_id', $s05->softbound->id)->sole()->results)->firstWhere('key', 'report_age')['status']);

        $s06 = VivaProgress::for($this->student('DEMO-S06'));
        $this->assertTrue($s06->isReadyToSchedule());
        $this->assertSame('major', ExaminerDivergence::forNotices([$s06->notice->id])[$s06->notice->id]['level']);
        $this->assertSame('extracted', \App\Modules\Hani\Models\VivaSoftboundDetail::where('application_id', $s06->softbound->id)->value('similarity_source'));

        $s08 = VivaProgress::for($this->student('DEMO-S08'));
        $this->assertSame(2, $s08->detail->result_category);
        $this->assertSame($s08->detail->viva_at->copy()->startOfDay()->addMonthsNoOverflow(3)->toDateString(), $s08->detail->corrections_due_at->toDateString());
        $this->assertSame(Examiner::STATE_ON_GAP, Examiner::where('email', 'kevin.tan@example.com')->sole()->state());

        $s09 = VivaProgress::for($this->student('DEMO-S09'));
        $this->assertSame(VivaDetail::REEXAMINATION, $s09->detail->result_category);
        $this->assertSame(Examiner::STATE_ASSIGNED, Examiner::where('email', 'ain.ismail@example.com')->sole()->state());

        $this->assertSame(Application::STATUS_APPROVED, VivaProgress::for($this->student('DEMO-S10'))->nomination->status);
        $this->assertSame(VivaDetail::REEXAMINATION, VivaProgress::for($this->student('DEMO-S10'))->detail->result_category);

        // Demo accounts are example.com, with the test accounts' password.
        $this->assertTrue(User::where('email', 'like', '%@example.com')->where('email', 'not like', 'history.%')->where('email', 'not like', 'reviva.%')->where('role', Role::STUDENT)->count() === 10);
        // The re-viva walkthrough students have their own supervisor.
        $reVivaSupervisors = User::where('email', 'like', 'reviva.%')->where('role', Role::STUDENT)->pluck('supervisor_id')->unique();
        $this->assertEquals([User::where('email', 'reviva.supervisor@example.com')->value('id')], $reVivaSupervisors->values()->all());
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('password', $this->student('DEMO-S01')->password));

        // The examiners S01's walkthrough expects are still free to suggest.
        $this->assertSame(Examiner::STATE_AVAILABLE, Examiner::where('email', 'farah.rosli@example.com')->sole()->state());
        $this->assertSame(Examiner::STATE_AVAILABLE, Examiner::where('email', 'melissa.teoh@example.org')->sole()->state());
    }

    /** S09 under panel review for 5 working days, S10 at level 4 ready for round 2. */
    public function test_re_viva_rounds_for_s09_and_s10(): void
    {
        foreach (['2026-11-04', '2027-06-21'] as $day) {
            Carbon::setTestNow($day.' 09:00');
            $this->seed(HaniDemoSeeder::class);

            $s09 = \App\Modules\Hani\Support\ReVivaLogging::latestCycle($this->student('DEMO-S09')->id);
            $this->assertSame(1, $s09->cycle_number);
            $this->assertSame('under_panel_review', $s09->application->current_stage, $day);
            $this->assertFalse($s09->logged_outside_window, $day);
            $this->assertSame(5, \App\Modules\Hani\Support\WorkingDays::between($s09->sent_to_panel_at, today()), $day);
            $this->assertSame(2, $s09->staffDocuments()->where('kind', 'correction_letter')->count());
            $this->assertSame(Examiner::STATE_ASSIGNED, Examiner::where('email', 'ain.ismail@example.com')->sole()->state());

            $s10Student = $this->student('DEMO-S10');
            $s10 = \App\Modules\Hani\Support\ReVivaLogging::latestCycle($s10Student->id);
            $this->assertSame(1, $s10->cycle_number);
            $this->assertSame(Application::STATUS_APPROVED, $s10->application->status);
            $this->assertSame(4, $s10->outcome_level);
            $this->assertNull($s10->suggested_level, 'internal 3 and external 4 + YES diverge');
            $this->assertSame(['external_main_id' => 4, 'internal_main_id' => 3], $s10->evaluations->pluck('category', 'seat')->sortKeys()->all());
            $this->assertTrue($s10->evaluations->firstWhere('seat', 'external_main_id')->viva_again);
            $this->assertSame(today()->subDays(3)->toDateString(), $s10->outcome_recorded_at->toDateString());
            $this->assertNull(\App\Modules\Hani\Support\ReVivaLogging::blockReason($s10), 'round 2 may be logged');
            $this->assertTrue(\App\Modules\Hani\Support\ReVivaWindow::for($s10Student, $s10)->contains(today()));
        }

        Carbon::setTestNow();

        $this->assertSame(2, \App\Modules\Hani\Models\ReVivaDetail::whereHas('application.student', fn ($q) => $q->where('email', 'not like', 'history.%')->where('email', 'not like', 'reviva.%'))->count(), 'rebuilt, not duplicated');
    }

    /**
     * Part 4 (Prompt 4c): the fictional history. Completed, idempotent,
     * enough for every analytics panel over 3, 6 and 12 months, and invisible
     * to the live scenarios: the same pool states, no new clashes, nothing
     * for any reminder command to send.
     */
    public function test_the_fictional_history_fills_the_analytics_without_touching_the_live_scenarios(): void
    {
        Carbon::setTestNow('2026-11-04 09:00');

        // Parts 1-3 only, for comparison.
        $withoutHistory = new class extends HaniDemoSeeder
        {
            protected function steps(): array
            {
                return array_values(array_diff(parent::steps(), ['historicalCases']));
            }
        };
        $withoutHistory->run();
        $states = Examiner::orderBy('id')->get()->map(fn ($e) => [$e->email, $e->state()])->all();
        $clashes = $this->liveClashes();

        $this->seed(HaniDemoSeeder::class);
        $history = fn () => \App\Modules\Core\Models\ApprovalHistory::whereHas('application.student', fn ($q) => $q->where('email', 'like', 'history.%'))
            ->orderBy('created_at')->orderBy('stage_key')->get()->map(fn ($h) => $h->stage_key.'@'.$h->created_at)->all();
        $first = $history();
        $this->seed(HaniDemoSeeder::class);

        // Idempotent: the same decisions at the same moments.
        $this->assertSame($first, $history());
        $this->assertSame(24, User::where('email', 'like', 'history.%')->count());

        // Completed: nothing pending, every viva has a result.
        $apps = Application::whereHas('student', fn ($q) => $q->where('email', 'like', 'history.%'));
        $this->assertSame(0, (clone $apps)->where('status', Application::STATUS_PENDING)->count());
        $this->assertSame(24, (clone $apps)->where('module_type', 'viva_voce')->whereHas('history')->count());
        $this->assertSame(24, VivaDetail::whereIn('application_id', (clone $apps)->where('module_type', 'viva_voce')->pluck('id'))->whereNotNull('result_recorded_at')->count());
        $this->assertSame(4, \App\Modules\Hani\Models\ReVivaDetail::whereIn('application_id', (clone $apps)->pluck('id'))->whereNotNull('outcome_recorded_at')->count());
        $this->assertTrue(\App\Modules\Core\Models\ApprovalHistory::whereIn('application_id', (clone $apps)->pluck('id'))->where('decision', 'returned')->exists(), 'some lists were sent back');
        $this->assertTrue(\App\Modules\Core\Models\ApprovalHistory::whereIn('application_id', (clone $apps)->pluck('id'))->where('decision', 'rejected')->exists(), 'some softbounds were returned');

        // Invisible to the live scenarios.
        $this->assertSame($states, Examiner::orderBy('id')->get()->map(fn ($e) => [$e->email, $e->state()])->all(), 'pool states unchanged');
        // The scenario panels clash exactly as before (S09 shows one), and
        // the walkthrough re-viva panels, which stay held, add no clash.
        $after = $this->liveClashes();
        $this->assertSame($clashes, array_intersect_key($after, $clashes), 'no clash from a finished or walkthrough panel');
        foreach (array_diff_key($after, $clashes) as $email => $seats) {
            $this->assertStringStartsWith('reviva.', $email);
            $this->assertSame([0, 0, 0, 0], $seats, "{$email}'s panel clashes with another department");
        }
        foreach (['viva:reminders', 're-viva:monitor', 'examiners:sweep', 'hani:sla-reminders'] as $command) {
            \Illuminate\Support\Facades\Artisan::call($command, ['--dry-run' => true]);
            $output = \Illuminate\Support\Facades\Artisan::output();
            foreach (self::historyNames() as $name) {
                $this->assertStringNotContainsString($name, $output, "{$command} would remind about a finished history case");
            }
        }

        // Every analytics panel has data, over each period.
        $senior = $this->user(Role::SENIOR_EXEC_CGS, ['email' => 'senior.analytics@test.my']);
        foreach ([3, 6, 12] as $months) {
            $data = app(\App\Modules\Hani\Support\ProcessAnalytics::class)->for($senior, $months);
            foreach (['examiner_nomination', 'viva_voce', 'viva_softbound', 're_viva'] as $module) {
                $this->assertGreaterThan(0, collect($data['stages'][$module]['stages'])->sum('count'), "{$module}, {$months} months");
            }
            // The pipeline is a cohort (notices FILED in the period); a viva
            // takes longer than three months, so results show from 6 months.
            $this->assertGreaterThan(0, $data['pipeline']['notices'], "notices, {$months} months");
            if ($months >= 6) {
                $this->assertGreaterThan(0, $data['pipeline']['steps'][4]['count'], "results, {$months} months");
            }
            $this->assertGreaterThan(0, collect($data['turnaround'])->sum('count'), "turnaround, {$months} months");
            $this->assertGreaterThan(0, collect($data['utilisation'])->max('busy'), "utilisation, {$months} months");
        }
        $year = app(\App\Modules\Hani\Support\ProcessAnalytics::class)->for($senior, 12);
        $this->assertGreaterThan(0, $year['turnaround']['MSC']['count']);
        $this->assertGreaterThan(0, $year['turnaround']['PHD']['count']);
        $this->assertGreaterThanOrEqual(3, $year['reviva']['decided']);
        $this->assertNotNull($year['slowest']['overall']);

        // No invented waits: the live scenarios' notices are acknowledged
        // days after filing, and their chains move every few days.
        $this->assertLessThan(10, $year['stages']['viva_voce']['stages'][0]['average']);
        $this->assertLessThan(15, collect($year['stages']['examiner_nomination']['stages'])->max('average'));
        foreach ($year['utilisation'] as $month) {
            $this->assertLessThanOrEqual(100, $month['percent']);
            $this->assertGreaterThan(0, $month['pool']);
        }

        Carbon::setTestNow();
    }

    /** @return array<int, string> */
    protected static function historyNames(): array
    {
        return (new \ReflectionClassConstant(HaniDemoSeeder::class, 'HISTORY_NAMES'))->getValue();
    }

    /** Every live demo nomination's clash flags, by student email. */
    protected function liveClashes(): array
    {
        $clashes = app(\App\Modules\Hani\Support\CrossDepartmentClashes::class);

        return \App\Modules\Hani\Models\ExaminerNomination::with('application.student')->get()
            ->filter(fn ($n) => ! str_starts_with((string) $n->application?->student?->email, 'history.'))
            ->mapWithKeys(fn ($n) => [$n->application->student->email => collect(\App\Modules\Hani\Models\ExaminerNomination::SLOTS)->keys()
                ->map(fn ($seat) => $n->{$seat} ? count($clashes->elsewhere($n->{$seat}, $n->application->student->department, $n->application_id)) : 0)->all()])
            ->sortKeys()->all();
    }
}
