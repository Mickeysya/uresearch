<?php

namespace Tests\Feature\Hani;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\VivaExaminerProgress;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Hani\Concerns\BuildsVivas;
use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use Tests\TestCase;

/**
 * Process Analytics (Prompt 4b): stage times from approval_history, the
 * slowest stage, the period filter, role scoping in the page and the export,
 * report turnaround and pool utilisation.
 */
class ProcessAnalyticsTest extends TestCase
{
    /** notice_received: index 1, after the supervisor's endorsement stage (UAT 11 Nov 2026, item E). */
    protected const AE_STAGE = 1;

    use BuildsVivas;
    use KeepsJobsOffTheDevQueue;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['hani.scheduling.public_holidays' => []]);
        Carbon::setTestNow('2027-11-15 09:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function person(string $role, string $email, ?string $department = null): User
    {
        return User::firstOrCreate(['email' => $email], ['name' => Role::label($role).' '.$email, 'password' => 'password', 'role' => $role, 'department' => $department]);
    }

    /** A notice filed $filedDaysAgo and acknowledged $waited days later. */
    protected function acknowledgedNotice(string $matric, int $filedDaysAgo, int $waited, string $department = 'Computing'): Application
    {
        $now = now()->copy();
        $ae = $this->academicExecFor($department);
        $student = User::create([
            'name' => 'Fictional Student '.$matric, 'email' => "s{$matric}@test.my", 'password' => 'password',
            'role' => Role::STUDENT, 'matric_no' => $matric, 'programme' => 'MSc Full-Time', 'department' => $department,
        ]);

        Carbon::setTestNow($now->copy()->subDays($filedDaysAgo));
        $notice = app(WorkflowEngine::class)->submit(Application::create(['student_id' => $student->id, 'module_type' => 'viva_voce', 'status' => Application::STATUS_DRAFT]));
        Carbon::setTestNow($now->copy()->subDays($filedDaysAgo - $waited));
        app(WorkflowEngine::class)->decide($notice, $ae, 'approve');
        Carbon::setTestNow($now);

        return $notice;
    }

    protected function analytics(User $user, int $months = 12): array
    {
        return $this->actingAs($user)->get(route('process-analytics', ['months' => $months]))->assertOk()->original->getData();
    }

    public function test_average_and_median_days_per_stage_come_from_approval_history(): void
    {
        $this->acknowledgedNotice('22001001', 30, 2);
        $this->acknowledgedNotice('22001002', 30, 4);
        $this->acknowledgedNotice('22001003', 30, 9);

        $data = $this->analytics($this->person(Role::SENIOR_EXEC_CGS, 'senior@test.my'));
        $stage = $data['stages']['viva_voce']['stages'][self::AE_STAGE];

        $this->assertSame(3, $stage['count']);
        $this->assertEquals(5.0, $stage['average']);
        $this->assertEquals(4.0, $stage['median']);
        $this->assertSame(100, $stage['share']);
    }

    public function test_the_slowest_stage_is_highlighted_with_its_share_of_the_workflows_time(): void
    {
        $ae = $this->academicExecFor('Computing');
        $senior = $this->person(Role::SENIOR_EXEC_CGS, 'senior@test.my');
        $student = User::create(['name' => 'Candidate', 'email' => 'c@test.my', 'password' => 'password', 'role' => Role::STUDENT, 'department' => 'Computing']);
        $engine = app(WorkflowEngine::class);

        Carbon::setTestNow('2027-10-01 09:00');
        $nomination = $engine->submit(Application::create(['student_id' => $student->id, 'module_type' => 'examiner_nomination', 'status' => Application::STATUS_DRAFT]));
        Carbon::setTestNow('2027-10-03 09:00');
        $engine->decide($nomination, $ae, 'approve');
        Carbon::setTestNow('2027-10-09 09:00');
        $engine->decide($nomination, $senior, 'approve');
        Carbon::setTestNow('2027-11-15 09:00');

        $response = $this->actingAs($senior)->get(route('process-analytics'))->assertOk();
        $data = $response->original->getData();

        $this->assertSame('cgs_compile', $data['slowest']['perModule']['examiner_nomination']);
        $compile = collect($data['stages']['examiner_nomination']['stages'])->firstWhere('key', 'cgs_compile');
        $this->assertSame(75, $compile['share']);
        $response->assertSee('Slowest stage:', false)->assertSee('CGS Compilation');
    }

    public function test_the_period_filter_limits_decisions_to_the_last_three_six_or_twelve_months(): void
    {
        $this->acknowledgedNotice('22001001', 240, 5);   // decided about 8 months ago
        $this->acknowledgedNotice('22001002', 20, 3);

        $senior = $this->person(Role::SENIOR_EXEC_CGS, 'senior@test.my');

        $this->assertSame(2, $this->analytics($senior, 12)['stages']['viva_voce']['stages'][self::AE_STAGE]['count']);
        $this->assertSame(1, $this->analytics($senior, 6)['stages']['viva_voce']['stages'][self::AE_STAGE]['count']);
        $this->assertSame(1, $this->analytics($senior, 3)['stages']['viva_voce']['stages'][self::AE_STAGE]['count']);

        $this->actingAs($senior)->get(route('process-analytics', ['months' => 7]))->assertSessionHasErrors('months');
    }

    public function test_an_academic_executive_sees_only_their_department_on_the_page_and_in_the_export(): void
    {
        $this->acknowledgedNotice('22001001', 30, 2, 'Computing');
        $this->acknowledgedNotice('22002001', 30, 8, 'Petroleum Engineering');
        // Petroleum's notice also waits, overdue, at notice_received.
        Carbon::setTestNow(now()->subDays(40));
        $waiting = User::create(['name' => 'Petroleum Waiting', 'email' => 'pw@test.my', 'password' => 'password', 'role' => Role::STUDENT, 'department' => 'Petroleum Engineering']);
        app(WorkflowEngine::class)->submit(Application::create(['student_id' => $waiting->id, 'module_type' => 'viva_voce', 'status' => Application::STATUS_DRAFT]));
        Carbon::setTestNow('2027-11-15 09:00');

        $ae = $this->academicExecFor('Computing');
        $data = $this->analytics($ae);

        $this->assertSame('Computing', $data['department']);
        $this->assertSame(1, $data['stages']['viva_voce']['stages'][self::AE_STAGE]['count']);
        $this->assertEquals(2.0, $data['stages']['viva_voce']['stages'][self::AE_STAGE]['average']);
        $this->assertSame([], $data['overdue']);

        $csv = $this->actingAs($ae)->get(route('process-analytics.export', ['format' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Computing', $csv);
        $this->assertStringNotContainsString('Petroleum Waiting', $csv);

        // The Senior Executive sees both, and the overdue notice by name.
        $senior = $this->person(Role::SENIOR_EXEC_CGS, 'senior@test.my');
        $all = $this->analytics($senior);
        $this->assertSame(2, $all['stages']['viva_voce']['stages'][self::AE_STAGE]['count']);
        $this->assertSame('Petroleum Waiting', $all['overdue'][0]['student']);
        $this->assertStringContainsString('Petroleum Waiting', $this->actingAs($senior)->get(route('process-analytics.export', ['format' => 'csv']))->assertOk()->streamedContent());
    }

    public function test_students_and_supervisors_are_refused_and_the_non_executive_reads_everything(): void
    {
        $this->acknowledgedNotice('22001001', 30, 2);

        $this->actingAs(User::where('role', Role::STUDENT)->first())->get(route('process-analytics'))->assertForbidden();
        $this->actingAs($this->person(Role::STUDENT, 's2@test.my'))->get(route('process-analytics.export'))->assertForbidden();
        $this->actingAs($this->person(Role::SUPERVISOR, 'sv@test.my', 'Computing'))->get(route('process-analytics'))->assertForbidden();

        foreach ([Role::NON_EXEC_CGS, Role::SENIOR_DIRECTOR_CGS, Role::DEAN_PGR] as $role) {
            $data = $this->analytics($this->person($role, $role.'@test.my'));
            $this->assertNull($data['department'], $role);
            $this->assertSame(1, $data['stages']['viva_voce']['stages'][self::AE_STAGE]['count'], $role);
        }

        // Read-only: no form anywhere in the page's own content (the layout's
        // sign-out form is outside it).
        $html = $this->actingAs($this->person(Role::NON_EXEC_CGS, 'non_exec_cgs@test.my'))->get(route('process-analytics'))->getContent();
        $content = str($html)->after('class="card-container-inline"')->before('js/hani/process-analytics.js');
        $this->assertStringNotContainsStringIgnoringCase('<form', (string) $content);
    }

    public function test_the_xlsx_export_downloads_one_sheet_per_table(): void
    {
        $this->acknowledgedNotice('22001001', 30, 2);

        $response = $this->actingAs($this->person(Role::SENIOR_EXEC_CGS, 'senior@test.my'))
            ->get(route('process-analytics.export', ['format' => 'xlsx', 'months' => 6]))
            ->assertOk();

        $this->assertStringContainsString('process-analytics-all-6m-', $response->headers->get('content-disposition'));

        $path = $response->baseResponse->getFile()->getPathname();
        $sheets = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getSheetNames();
        $this->assertSame(['Stage times', 'Overdue now', 'Viva pipeline', 'Re-viva by stage', 'Re-viva outcomes', 'Report turnaround', 'Pool utilisation'], $sheets);
    }

    public function test_report_turnaround_splits_msc_and_phd_against_the_limit_and_utilisation_counts_main_seats(): void
    {
        $msc = $this->viva('22001001', 'MSc Full-Time');
        $phd = $this->viva('22001002', 'PhD Full-Time');

        foreach ([[$msc, 20], [$phd, 50]] as [$viva, $days]) {
            foreach (['internal_main_id', 'external_main_id'] as $seat) {
                VivaExaminerProgress::create([
                    'viva_application_id' => $viva['notice']->id, 'nomination_id' => $viva['panel']->id,
                    'seat' => $seat, 'examiner_id' => $viva['panel']->{$seat},
                    'thesis_received_at' => now()->subDays($days + 5), 'report_received_at' => now()->subDays(5),
                ]);
            }
        }

        $data = $this->analytics($this->person(Role::SENIOR_EXEC_CGS, 'senior@test.my'));

        $this->assertSame(2, $data['turnaround']['MSC']['count']);
        $this->assertEquals(20.0, $data['turnaround']['MSC']['average']);
        $this->assertSame(0, $data['turnaround']['MSC']['late']);
        $this->assertSame(42, $data['turnaround']['PHD']['limit']);
        $this->assertSame(2, $data['turnaround']['PHD']['late']);

        // Both panels were released this month: four mains of eight examiners.
        $month = collect($data['utilisation'])->last();
        $this->assertSame('2027-11', $month['month']);
        $this->assertSame(4, $month['busy']);
        $this->assertSame(8, $month['pool']);
        $this->assertSame(50, $month['percent']);
        $this->assertCount(13, $data['utilisation']);

        // The viva pipeline: both notices filed this period, both softbounds.
        $this->assertSame([2, 2, 2, 0, 0], array_column($data['pipeline']['steps'], 'count'));
    }

    public function test_the_examiner_dashboard_links_to_it_and_every_chart_states_its_source_and_period(): void
    {
        $senior = $this->person(Role::SENIOR_EXEC_CGS, 'senior@test.my');

        $this->actingAs($senior)->get(route('examiner-dashboard'))->assertOk()->assertSee(route('process-analytics'), false);

        $page = $this->actingAs($senior)->get(route('process-analytics', ['months' => 3]))->assertOk();
        $page->assertSee('js/hani/process-analytics.js', false)
            ->assertSee('id="process-analytics-data"', false)
            ->assertSee('the last 3 months', false);
        $this->assertGreaterThanOrEqual(7, substr_count($page->getContent(), 'Computed from'));
    }
}
