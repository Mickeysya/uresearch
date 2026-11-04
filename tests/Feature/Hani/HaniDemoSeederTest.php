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
        $this->assertTrue(User::where('email', 'like', '%@example.com')->where('role', Role::STUDENT)->count() === 10);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('password', $this->student('DEMO-S01')->password));

        // The examiners S01's walkthrough expects are still free to suggest.
        $this->assertSame(Examiner::STATE_AVAILABLE, Examiner::where('email', 'farah.rosli@example.com')->sole()->state());
        $this->assertSame(Examiner::STATE_AVAILABLE, Examiner::where('email', 'melissa.teoh@example.org')->sole()->state());
    }

    /** Part 3: S09 under panel review for 22 working days, S10 at level 4 ready for round 2. */
    public function test_re_viva_rounds_for_s09_and_s10(): void
    {
        foreach (['2026-11-04', '2027-06-21'] as $day) {
            Carbon::setTestNow($day.' 09:00');
            $this->seed(HaniDemoSeeder::class);

            $s09 = \App\Modules\Hani\Support\ReVivaLogging::latestCycle($this->student('DEMO-S09')->id);
            $this->assertSame(1, $s09->cycle_number);
            $this->assertSame('under_panel_review', $s09->application->current_stage, $day);
            $this->assertFalse($s09->logged_outside_window, $day);
            $this->assertSame(22, \App\Modules\Hani\Support\WorkingDays::between($s09->sent_to_panel_at, today()), $day);
            $this->assertSame(2, $s09->staffDocuments()->where('kind', 'correction_letter')->count());
            $this->assertTrue(\App\Modules\Hani\Models\ReVivaReminderLog::where('re_viva_detail_id', $s09->id)->where('kind', 'panel_chase')->exists(), 'the 20-day reminder has fired');
            $this->assertFalse(\App\Modules\Hani\Models\ReVivaReminderLog::where('re_viva_detail_id', $s09->id)->where('kind', 'panel_escalate')->exists());
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

        $this->assertSame(2, \App\Modules\Hani\Models\ReVivaDetail::count(), 'rebuilt, not duplicated');
    }
}
