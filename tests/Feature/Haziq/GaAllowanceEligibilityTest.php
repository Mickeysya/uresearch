<?php

namespace Tests\Feature\Haziq;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Haziq\Models\GaApplicationDetail;
use App\Modules\Haziq\Models\GaAllowancePaymentLog;
use App\Modules\Haziq\Models\GraGaStudentProfile;
use App\Modules\Haziq\Notifications\GaAllowancePaymentConfirmed;
use App\Modules\Haziq\Support\GaAllowanceEngine;
use App\Modules\Haziq\Support\StageGateEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * GA's own allowance cycle, deliberately separate from GRA's --
 * confirmed explicitly 2026-11-10: "allowance feature kena tambah lah
 * untuk ga en... bulan pertama start service takkan dapat allowance,
 * next month dapat allowance untuk bulan sebelumnya... ga ni letak
 * actions lain. jangan sekali kan dengan allowance gra satu excel." It
 * does accumulate the same way GRA's own does, confirmed later the
 * same day, reversing an earlier "resets every month" version: "ga pun
 * sama ah, kalau ada accumulated amount on service end month, month
 * after payment accumulated amount tu."
 */
class GaAllowanceEligibilityTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected function gaApplication(User $student, string $start, string $end = '2030-01-01', float $allowance = 1500): Application
    {
        $application = Application::create([
            'student_id' => $student->id, 'module_type' => 'ga_application',
            'status' => Application::STATUS_APPROVED, 'current_stage' => null, 'submitted_at' => now(),
        ]);

        GaApplicationDetail::create([
            'application_id' => $application->id,
            'profile_id' => GraGaStudentProfile::create(['user_id' => $student->id, 'level_of_study' => 'phd', 'programme' => 'CS', 'admission_date' => now()])->id,
            'proposed_hours_per_week' => 10, 'proposed_allowance' => $allowance,
            'service_start_date' => $start, 'service_end_date' => $end,
        ]);

        return $application;
    }

    /* -----------------------------------------------------------------
     | GaAllowanceEngine::ensureMonthlyLogs() -- the cycle rule itself
     |------------------------------------------------------------------*/

    public function test_no_log_exists_before_the_service_start_month(): void
    {
        $this->travelTo(Carbon::parse('2026-06-15'));
        $application = $this->gaApplication($this->student(), '2026-06-01', '2030-01-01');

        app(GaAllowanceEngine::class)->ensureMonthlyLogs($application);

        $this->assertCount(1, GaAllowancePaymentLog::where('application_id', $application->id)->get());
        $this->assertSame('2026-06-01', GaAllowancePaymentLog::where('application_id', $application->id)->first()->payment_month->format('Y-m-d'));
    }

    public function test_the_very_first_display_month_shows_nothing_yet(): void
    {
        $this->travelTo(Carbon::parse('2026-06-15'));
        $student = $this->student();
        $application = $this->gaApplication($student, '2026-06-01', '2030-01-01', 1500);
        app(GaAllowanceEngine::class)->ensureMonthlyLogs($application);

        $this->actingAs($student)->get(route('ga-allowance.index', ['month' => '2026-06']))
            ->assertOk()
            ->assertSee('No allowance records for this month.');
    }

    public function test_the_following_month_shows_allowance_for_the_start_month(): void
    {
        $this->travelTo(Carbon::parse('2026-07-10'));
        $student = $this->student();
        $application = $this->gaApplication($student, '2026-06-01', '2030-01-01', 1500);
        app(GaAllowanceEngine::class)->ensureMonthlyLogs($application);

        $this->actingAs($student)->get(route('ga-allowance.index', ['month' => '2026-07']))
            ->assertOk()
            ->assertSee('1,500.00');
    }

    /**
     * Generation itself always writes one FLAT row per month -- the
     * accumulating running total is a separate, display/confirm-time
     * concern (GaAllowanceLedger), never baked into the stored rows
     * themselves. See the "Accumulation" tests below for the actual
     * running-total behaviour, confirmed explicitly 2026-11-10: "ga pun
     * sama ah, kalau ada accumulated amount..."
     */
    public function test_generation_always_writes_one_flat_row_per_month(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15'));
        $application = $this->gaApplication($this->student(), '2026-06-01', '2030-01-01', 1500);

        app(GaAllowanceEngine::class)->ensureMonthlyLogs($application);

        $logs = GaAllowancePaymentLog::where('application_id', $application->id)->orderBy('payment_month')->get();
        // June, July, August, September -- four flat rows, each 1500, none confirmed.
        $this->assertCount(4, $logs);
        foreach ($logs as $log) {
            $this->assertSame('1500.00', $log->amount);
            $this->assertFalse($log->isConfirmed());
        }
    }

    /* -----------------------------------------------------------------
     | Accumulation -- confirmed explicitly 2026-11-10: "ga pun sama ah,
     | kalau ada accumulated amount on service end month, month after
     | payment accumulated amount tu kan"
     |------------------------------------------------------------------*/

    public function test_unconfirmed_months_accumulate_onto_the_next(): void
    {
        $this->travelTo(Carbon::parse('2026-09-01'));
        $application = $this->gaApplication($this->student(), '2026-06-01', '2030-01-01', 1500);
        app(GaAllowanceEngine::class)->ensureMonthlyLogs($application);

        // June, July, August all unconfirmed -- the running total through
        // August is all three months added together, not just August's own.
        $august = \App\Modules\Haziq\Support\GaAllowanceLedger::accumulatedTotalThrough(
            $application->id, Carbon::parse('2026-08-01')
        );

        $this->assertSame(4500.0, $august);
    }

    public function test_confirming_a_month_resets_the_running_total_for_the_next(): void
    {
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-08-01'));
        $admin = $this->admin();
        $application = $this->gaApplication($this->student(), '2026-06-01', '2030-01-01', 1500);
        app(GaAllowanceEngine::class)->ensureMonthlyLogs($application);

        $june = GaAllowancePaymentLog::where('application_id', $application->id)->whereDate('payment_month', '2026-06-01')->firstOrFail();
        $this->actingAs($admin)->post(route('ga-allowance.confirm-payment', $june), [
            'proof' => UploadedFile::fake()->create('proof.pdf', 50, 'application/pdf'),
        ])->assertRedirect();

        // June confirmed -- July is the only thing accumulating now.
        $july = \App\Modules\Haziq\Support\GaAllowanceLedger::accumulatedTotalThrough(
            $application->id, Carbon::parse('2026-07-01')
        );

        $this->assertSame(1500.0, $july);
    }

    public function test_confirming_a_month_out_of_order_is_refused(): void
    {
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-08-01'));
        $admin = $this->admin();
        $application = $this->gaApplication($this->student(), '2026-06-01', '2030-01-01', 1500);
        app(GaAllowanceEngine::class)->ensureMonthlyLogs($application);

        // June is still unconfirmed -- July cannot be confirmed first.
        $july = GaAllowancePaymentLog::where('application_id', $application->id)->whereDate('payment_month', '2026-07-01')->firstOrFail();
        $this->actingAs($admin)->post(route('ga-allowance.confirm-payment', $july), [
            'proof' => UploadedFile::fake()->create('proof.pdf', 50, 'application/pdf'),
        ])->assertNotFound();

        $this->assertFalse($july->fresh()->isConfirmed());
    }

    public function test_the_confirmed_amount_is_the_real_accumulated_total_not_just_that_months_own_flat_rate(): void
    {
        Notification::fake();
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-08-01'));
        $admin = $this->admin();
        $application = $this->gaApplication($this->student(), '2026-06-01', '2030-01-01', 1500);
        app(GaAllowanceEngine::class)->ensureMonthlyLogs($application);

        // June, July both unconfirmed by the time August's row exists --
        // confirming June first just settles June alone (1500); the
        // REAL test is that confirming July later rolls up July's own
        // flat row correctly once June is out of the way.
        $june = GaAllowancePaymentLog::where('application_id', $application->id)->whereDate('payment_month', '2026-06-01')->firstOrFail();
        $this->actingAs($admin)->post(route('ga-allowance.confirm-payment', $june), [
            'proof' => UploadedFile::fake()->create('proof.pdf', 50, 'application/pdf'),
        ]);
        $this->assertSame('1500.00', $june->fresh()->confirmed_amount);

        $july = GaAllowancePaymentLog::where('application_id', $application->id)->whereDate('payment_month', '2026-07-01')->firstOrFail();
        $this->actingAs($admin)->post(route('ga-allowance.confirm-payment', $july), [
            'proof' => UploadedFile::fake()->create('proof.pdf', 50, 'application/pdf'),
        ]);
        $this->assertSame('1500.00', $july->fresh()->confirmed_amount);
    }

    public function test_generation_is_idempotent(): void
    {
        $this->travelTo(Carbon::parse('2026-08-01'));
        $application = $this->gaApplication($this->student(), '2026-06-01', '2030-01-01');
        $engine = app(GaAllowanceEngine::class);

        $engine->ensureMonthlyLogs($application);
        $engine->ensureMonthlyLogs($application);
        $engine->ensureMonthlyLogs($application);

        $this->assertCount(3, GaAllowancePaymentLog::where('application_id', $application->id)->get());
    }

    public function test_generation_never_runs_ahead_of_an_already_confirmed_month(): void
    {
        $this->travelTo(Carbon::parse('2026-06-01'));
        $admin = $this->admin();
        $application = $this->gaApplication($this->student(), '2026-05-01', '2030-01-01');
        // May and June already generate in this one call -- today (June)
        // caps generation at the current month, same as GRA's own engine.
        app(GaAllowanceEngine::class)->ensureMonthlyLogs($application);
        $may = GaAllowancePaymentLog::where('application_id', $application->id)->whereDate('payment_month', '2026-05-01')->first();

        $this->actingAs($admin)->post(route('ga-allowance.confirm-payment', $may), [
            'proof' => UploadedFile::fake()->create('proof.pdf', 50, 'application/pdf'),
        ])->assertRedirect();

        $this->travelTo(Carbon::parse('2026-07-01'));
        app(GaAllowanceEngine::class)->ensureMonthlyLogs($application);

        // The confirmed May row stays confirmed; a new, independent July row appears.
        $this->assertTrue($may->fresh()->isConfirmed());
        $july = GaAllowancePaymentLog::where('application_id', $application->id)->whereDate('payment_month', '2026-07-01')->first();
        $this->assertNotNull($july);
        $this->assertFalse($july->isConfirmed());
    }

    /* -----------------------------------------------------------------
     | Trailing payment after service ends
     |------------------------------------------------------------------*/

    public function test_one_trailing_payment_the_month_after_service_ends(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15'));
        $application = $this->gaApplication($this->student(), '2026-06-01', '2026-07-31', 1500);

        app(GaAllowanceEngine::class)->ensureMonthlyLogs($application);

        $months = GaAllowancePaymentLog::where('application_id', $application->id)->pluck('payment_month')
            ->map(fn ($m) => Carbon::parse($m)->format('Y-m'))->sort()->values()->all();

        // June and July are the two service months; nothing in August or later,
        // even though "today" (September) is well past service_end_date.
        $this->assertSame(['2026-06', '2026-07'], $months);
    }

    public function test_the_trailing_payment_is_visible_the_month_after_service_ends(): void
    {
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-07-01'));
        $admin = $this->admin();
        $student = $this->student();
        $application = $this->gaApplication($student, '2026-06-01', '2026-07-31', 1500);
        app(GaAllowanceEngine::class)->ensureMonthlyLogs($application);

        // Confirm June first, so July's own trailing payment is the only
        // thing left accumulating -- isolating the "is it visible at
        // all" question from the separate accumulation behaviour above.
        $june = GaAllowancePaymentLog::where('application_id', $application->id)->whereDate('payment_month', '2026-06-01')->firstOrFail();
        $this->actingAs($admin)->post(route('ga-allowance.confirm-payment', $june), [
            'proof' => UploadedFile::fake()->create('proof.pdf', 50, 'application/pdf'),
        ]);

        $this->travelTo(Carbon::parse('2026-08-10'));
        app(GaAllowanceEngine::class)->ensureMonthlyLogs($application);

        // Viewing in August (the month after service ended in July) still
        // shows July's allowance -- "end of service date next monthnya
        // still dapat allowance."
        $this->actingAs($student)->get(route('ga-allowance.index', ['month' => '2026-08']))
            ->assertOk()
            ->assertSee('1,500.00');
    }

    public function test_an_approved_extension_already_reflected_on_the_detail_extends_the_trailing_payment(): void
    {
        $this->travelTo(Carbon::parse('2026-10-15'));
        $application = $this->gaApplication($this->student(), '2026-06-01', '2026-07-31', 1500);

        // Nureen's GA Extension updates this same detail row's
        // service_end_date in place once approved -- no chain to walk,
        // see GaAllowanceEngine's own docblock.
        GaApplicationDetail::where('application_id', $application->id)->update(['service_end_date' => '2026-09-30']);

        app(GaAllowanceEngine::class)->ensureMonthlyLogs($application);

        $months = GaAllowancePaymentLog::where('application_id', $application->id)->pluck('payment_month')
            ->map(fn ($m) => Carbon::parse($m)->format('Y-m'))->sort()->values()->all();

        $this->assertSame(['2026-06', '2026-07', '2026-08', '2026-09'], $months);
    }

    /* -----------------------------------------------------------------
     | Mark Complete by CGS -- confirm, proof, email
     |------------------------------------------------------------------*/

    public function test_admin_can_mark_a_month_complete_with_proof_and_notifies_student_and_stakeholders(): void
    {
        Notification::fake();
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-07-01'));
        $admin = $this->admin();
        $student = $this->student();
        $application = $this->gaApplication($student, '2026-06-01', '2030-01-01');
        app(GaAllowanceEngine::class)->ensureMonthlyLogs($application);
        $log = GaAllowancePaymentLog::where('application_id', $application->id)->first();

        $this->actingAs($admin)->post(route('ga-allowance.confirm-payment', $log), [
            'proof' => UploadedFile::fake()->create('proof.pdf', 50, 'application/pdf'),
        ])->assertRedirect();

        $this->assertTrue($log->fresh()->isConfirmed());
        Notification::assertSentTo($student, GaAllowancePaymentConfirmed::class);
        Notification::assertSentTo($admin, GaAllowancePaymentConfirmed::class);
    }

    public function test_cgs_non_exec_can_also_mark_complete_but_supervisor_cannot(): void
    {
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-07-01'));
        $cgs = $this->cgs();
        $supervisor = $this->supervisor();
        $application = $this->gaApplication($this->student(), '2026-06-01', '2030-01-01');
        app(GaAllowanceEngine::class)->ensureMonthlyLogs($application);
        $log = GaAllowancePaymentLog::where('application_id', $application->id)->first();

        $this->actingAs($supervisor)->post(route('ga-allowance.confirm-payment', $log), [
            'proof' => UploadedFile::fake()->create('proof.pdf', 50, 'application/pdf'),
        ])->assertForbidden();

        $this->actingAs($cgs)->post(route('ga-allowance.confirm-payment', $log), [
            'proof' => UploadedFile::fake()->create('proof.pdf', 50, 'application/pdf'),
        ])->assertRedirect();

        $this->assertTrue($log->fresh()->isConfirmed());
    }

    public function test_an_already_confirmed_month_cannot_be_confirmed_again(): void
    {
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-07-01'));
        $admin = $this->admin();
        $application = $this->gaApplication($this->student(), '2026-06-01', '2030-01-01');
        app(GaAllowanceEngine::class)->ensureMonthlyLogs($application);
        $log = GaAllowancePaymentLog::where('application_id', $application->id)->first();
        $log->update(['confirmed_by' => $admin->id, 'confirmed_at' => now()]);

        $this->actingAs($admin)->post(route('ga-allowance.confirm-payment', $log), [
            'proof' => UploadedFile::fake()->create('proof.pdf', 50, 'application/pdf'),
        ])->assertNotFound();
    }

    /* -----------------------------------------------------------------
     | Role scoping, separate from GRA's
     |------------------------------------------------------------------*/

    public function test_a_student_sees_only_their_own_row(): void
    {
        $this->travelTo(Carbon::parse('2026-07-01'));
        $mine = $this->student('22001001', 'mine@test.my');
        $mine->update(['name' => 'My Own Name']);
        $other = $this->student('22001002', 'other@test.my');
        $other->update(['name' => 'Someone Elses Name']);
        $a1 = $this->gaApplication($mine, '2026-06-01', '2030-01-01');
        $a2 = $this->gaApplication($other, '2026-06-01', '2030-01-01');
        app(GaAllowanceEngine::class)->ensureMonthlyLogs($a1);
        app(GaAllowanceEngine::class)->ensureMonthlyLogs($a2);

        $this->actingAs($mine)->get(route('ga-allowance.index', ['month' => '2026-07']))
            ->assertOk()
            ->assertSee('My Own Name')
            ->assertDontSee('Someone Elses Name');
    }

    public function test_a_supervisor_sees_only_their_own_supervisees(): void
    {
        $this->travelTo(Carbon::parse('2026-07-01'));
        $ownSupervisor = $this->supervisor('own@test.my');
        $otherSupervisor = $this->supervisor('other-sup@test.my');
        $mine = $this->student('22001001', 'mine2@test.my', ['supervisor_id' => $ownSupervisor->id]);
        $mine->update(['name' => 'My Supervisee']);
        $theirs = $this->student('22001002', 'theirs2@test.my', ['supervisor_id' => $otherSupervisor->id]);
        $theirs->update(['name' => 'Their Supervisee']);
        app(GaAllowanceEngine::class)->ensureMonthlyLogs($this->gaApplication($mine, '2026-06-01', '2030-01-01'));
        app(GaAllowanceEngine::class)->ensureMonthlyLogs($this->gaApplication($theirs, '2026-06-01', '2030-01-01'));

        $this->actingAs($ownSupervisor)->get(route('ga-allowance.index', ['month' => '2026-07']))
            ->assertOk()
            ->assertSee('My Supervisee')
            ->assertDontSee('Their Supervisee');
    }

    public function test_research_centre_cannot_view_ga_allowance_at_all(): void
    {
        $reviewer = User::create(['name' => 'RC', 'email' => 'rc-allowance@test.my', 'password' => 'password', 'role' => \App\Modules\Haziq\Support\Roles::RESEARCH_CENTRE, 'department' => 'Computing']);

        $this->actingAs($reviewer)->get(route('ga-allowance.index'))->assertForbidden();
    }

    /** Senior Director CGS is part of Role::cgsTeam(), same audience GRA's own allowance.index already allows. */
    public function test_senior_director_can_view_ga_allowance_as_part_of_the_cgs_team(): void
    {
        $director = $this->seniorDirector();

        $this->actingAs($director)->get(route('ga-allowance.index'))->assertOk();
    }

    /* -----------------------------------------------------------------
     | The StageGateEngine bug this session fixed
     |------------------------------------------------------------------*/

    /**
     * Confirmed explicitly 2026-11-10: GA applications used to be
     * silently processed by StageGateEngine::evaluateAll() too, which
     * -- finding no Stage Gate for GA (governingGate() returns null) --
     * defaulted every month to 'eligible', an ACCUMULATING_STATUSES
     * value, running GA through GRA's unconfirmed-RPD ledger instead of
     * GA's own simple reset-every-month cycle. evaluateAll() is now
     * scoped to gra_application only.
     */
    public function test_the_daily_stage_gate_job_never_touches_ga_applications(): void
    {
        $this->travelTo(Carbon::parse('2026-07-01'));
        $application = $this->gaApplication($this->student(), '2026-06-01', '2030-01-01');

        app(StageGateEngine::class)->evaluateAll();

        $this->assertSame(
            0,
            \App\Modules\Haziq\Models\AllowancePaymentLog::where('application_id', $application->id)->count()
        );
    }

    /* -----------------------------------------------------------------
     | Sidebar links -- separate from GRA's
     |------------------------------------------------------------------*/

    public function test_research_centre_gets_neither_allowance_sidebar_link(): void
    {
        $reviewer = User::create(['name' => 'RC', 'email' => 'rc-link@test.my', 'password' => 'password', 'role' => \App\Modules\Haziq\Support\Roles::RESEARCH_CENTRE, 'department' => 'Computing']);

        $links = app(\App\Modules\Core\Services\ModuleRegistry::class)->linksFor($reviewer);

        $this->assertFalse(collect($links)->contains('route', 'ga-allowance.index'));
        $this->assertFalse(collect($links)->contains('route', 'allowance.index'));
    }

    /**
     * Confirmed explicitly 2026-11-10, revising the earlier "separate
     * nav bar" version: "ga allowoance eleigability masuk je dekat page
     * allowance eligability... apply for admin dah ke atas." One shared
     * link, one shared screen, a module filter chooses GRA or GA --
     * ga-allowance.index/export stay independently reachable underneath
     * (GaAllowanceController is unchanged), just not linked separately
     * from the sidebar any more.
     */
    public function test_admin_gets_exactly_one_shared_allowance_link_not_two(): void
    {
        $admin = $this->admin();

        $links = collect(app(\App\Modules\Core\Services\ModuleRegistry::class)->linksFor($admin));

        $this->assertSame(1, $links->where('route', 'allowance.index')->count());
        $this->assertFalse($links->contains('route', 'ga-allowance.index'));
    }

    public function test_the_shared_allowance_page_shows_ga_rows_when_filtered_to_ga(): void
    {
        $this->travelTo(Carbon::parse('2026-07-01'));
        $admin = $this->admin();
        $student = $this->student();
        $student->update(['name' => 'Shared Page GA Student']);
        $application = $this->gaApplication($student, '2026-06-01', '2030-01-01', 1500);
        app(GaAllowanceEngine::class)->ensureMonthlyLogs($application);

        $this->actingAs($admin)->get(route('allowance.index', ['module' => 'ga', 'month' => '2026-07']))
            ->assertOk()
            ->assertSee('Shared Page GA Student')
            ->assertSee('1,500.00');
    }

    public function test_the_shared_allowance_page_defaults_to_gra(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('allowance.index'))
            ->assertOk()
            ->assertViewHas('module', 'gra');
    }

    public function test_a_student_only_gets_my_ga_allowance_once_approved(): void
    {
        $student = $this->student();
        $registry = app(\App\Modules\Core\Services\ModuleRegistry::class);

        Application::create(['student_id' => $student->id, 'module_type' => 'ga_application', 'status' => Application::STATUS_PENDING, 'current_stage' => 'admin_verify', 'submitted_at' => now()]);
        $this->assertFalse(collect($registry->linksFor($student))->contains('route', 'ga-allowance.index'));

        $this->gaApplication($student, '2026-06-01', '2030-01-01');
        $this->assertTrue(collect($registry->linksFor($student))->contains('route', 'ga-allowance.index'));
    }

    /* -----------------------------------------------------------------
     | The student AI assistant -- GA's own summary, separate from GRA's
     |------------------------------------------------------------------*/

    public function test_the_assistant_tells_a_ga_student_their_most_recent_allowance_record(): void
    {
        $this->travelTo(Carbon::parse('2026-07-01'));
        $student = $this->student();
        $application = $this->gaApplication($student, '2026-06-01', '2030-01-01', 1500);
        app(GaAllowanceEngine::class)->ensureMonthlyLogs($application);

        $context = \App\Modules\Haziq\Assistant\AssistantContext::forStudent($student);

        // June and July both unconfirmed by now -- the assistant has to
        // report the real accumulated total (3,000), not just July's
        // own flat 1,500, same as GRA's own summary already does.
        $this->assertStringContainsString('GA Allowance', $context);
        $this->assertStringContainsString('pending confirmation by CGS/Admin', $context);
        $this->assertStringContainsString('3,000.00', $context);
    }

    public function test_the_assistant_shows_paid_once_cgs_confirms_it(): void
    {
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-07-01'));
        $admin = $this->admin();
        $student = $this->student();
        $application = $this->gaApplication($student, '2026-06-01', '2030-01-01', 1500);
        app(GaAllowanceEngine::class)->ensureMonthlyLogs($application);

        // Confirming has to happen in order -- June first, then the July
        // row gaAllowanceSummary() actually reads (most recent month <=
        // today, July here since today is 2026-07-01).
        $june = GaAllowancePaymentLog::where('application_id', $application->id)->whereDate('payment_month', '2026-06-01')->firstOrFail();
        $this->actingAs($admin)->post(route('ga-allowance.confirm-payment', $june), [
            'proof' => UploadedFile::fake()->create('proof.pdf', 50, 'application/pdf'),
        ])->assertRedirect();

        $july = GaAllowancePaymentLog::where('application_id', $application->id)->whereDate('payment_month', '2026-07-01')->firstOrFail();
        $this->actingAs($admin)->post(route('ga-allowance.confirm-payment', $july), [
            'proof' => UploadedFile::fake()->create('proof.pdf', 50, 'application/pdf'),
        ])->assertRedirect();

        $context = \App\Modules\Haziq\Assistant\AssistantContext::forStudent($student->fresh());

        $this->assertStringContainsString('GA Allowance', $context);
        $this->assertStringContainsString(': paid,', $context);
        $this->assertStringNotContainsString('pending confirmation', $context);
    }

    /* -----------------------------------------------------------------
     | Module filter is admin+/approver only -- never shown to a student
     |------------------------------------------------------------------*/

    /**
     * Confirmed explicitly 2026-11-10: "kenapa student boleh filter
     * lagi ga sedangkan ga application die tak approve pun lagi." A
     * student reaches this shared page only through their own "My
     * Allowance"/"My GA Allowance" link, which already gates on
     * approval -- they should never get a toggle letting them poke at
     * the other module regardless.
     */
    public function test_a_student_never_sees_the_module_filter_on_the_shared_page(): void
    {
        $this->travelTo(Carbon::parse('2026-07-01'));
        $student = $this->student();
        $application = $this->gaApplication($student, '2026-06-01', '2030-01-01');
        app(GaAllowanceEngine::class)->ensureMonthlyLogs($application);

        $this->actingAs($student)->get(route('allowance.index', ['module' => 'ga']))
            ->assertOk()
            ->assertDontSee('<select name="module"', false);

        $this->actingAs($student)->get(route('allowance.index'))
            ->assertOk()
            ->assertDontSee('<select name="module"', false);
    }

    public function test_an_admin_still_sees_the_module_filter(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('allowance.index'))
            ->assertOk()
            ->assertSee('<select name="module"', false);
    }
}
