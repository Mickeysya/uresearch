<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Exceptions\NotAssignedOfficer;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\CgsAssignment;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\CgsVerdict;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Norhanis\Models\TravelDetail;
use App\Modules\Nureen\Models\ApplicationReminder;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Models\GaExtensionDetail;
use App\Modules\Nureen\Models\SupervisionDetail;
use App\Modules\Nureen\Notifications\ApplicationAwaitingYou;
use App\Modules\Nureen\Support\StalledApplicationReminder;
use App\Modules\Nureen\Support\SubmissionNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Each module's Non-Executive CGS stage decided by one officer
 * (config/cgs_assignment.php, Core\Services\CgsAssignment).
 *
 * The officers are created here under the addresses the map names, so the
 * map is tested as shipped; a test that needs a different shape sets the
 * config itself.
 */
class CgsAssignmentTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected User $student;

    protected User $supervisor;

    /** @var array<string, User> short name => officer */
    protected array $officers = [];

    /** A Non-Executive CGS no module is assigned to: the general CGS account. */
    protected User $otherCgs;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->supervisor = $this->supervisor('aisyah@test.my');
        $this->student = $this->student('22001001', 'student@test.my', ['supervisor_id' => $this->supervisor->id, 'department' => 'Computing']);

        foreach (['norshahira' => 'Norshahira', 'zulkifly' => 'Zulkifly', 'syahmi' => 'Syahmi', 'amirul' => 'Amirul'] as $key => $name) {
            $this->officers[$key] = $this->user(Role::NON_EXEC_CGS, ['name' => $name, 'email' => "{$key}.cgs@utp.edu.my"]);
        }

        $this->otherCgs = $this->cgs();
    }

    /* ---------------------------------------------------------------
     | Fixtures
     |---------------------------------------------------------------*/

    protected function application(string $module, string $stage, array $attributes = []): Application
    {
        return Application::create($attributes + [
            'student_id' => $this->student->id,
            'submitted_by_id' => $this->student->id,
            'module_type' => $module,
            'status' => Application::STATUS_PENDING,
            'current_stage' => $stage,
            'submitted_at' => now(),
        ]);
    }

    /** A GA Extension in 'cgs' mode, sitting on CGS Staff's cgs_verify stage. */
    protected function gaExtensionAtCgs(array $attributes = []): Application
    {
        $application = $this->application('ga_extension', 'cgs_verify', $attributes);

        GaExtensionDetail::create([
            'application_id' => $application->id,
            'supervisor_id' => $this->supervisor->id,
            'current_end_date' => now()->addMonth()->toDateString(),
            'requested_new_end_date' => now()->addMonths(7)->toDateString(),
            'reason_for_extension' => 'Test fixture.',
            'final_approver_snapshot' => 'cgs',
        ]);

        return $application;
    }

    /** A travel application on Norhanis' cgs_review stage. */
    protected function travelAtCgs(array $attributes = []): Application
    {
        $application = $this->application('travel', 'cgs_review', $attributes);

        TravelDetail::create([
            'application_id' => $application->id,
            'type_of_request' => 'conference',
            'travel_start_date' => now()->addWeek(),
            'travel_end_date' => now()->addWeeks(2),
            'duration_days' => 8,
            'reason_for_travel' => 'Present a paper',
            'destination_address' => 'Kuala Lumpur',
            'is_international' => false,
        ]);

        return $application;
    }

    /** Puts the application on its current stage $days ago (the decision that moved it there). */
    protected function enteredStageDaysAgo(Application $application, int $days, string $fromStage = 'supervisor'): void
    {
        $application->forceFill(['submitted_at' => now()->subDays($days + 1)])->save();
        ApprovalHistory::where('application_id', $application->id)->delete();

        $history = ApprovalHistory::create([
            'application_id' => $application->id,
            'approver_id' => $this->supervisor->id,
            'stage_key' => $fromStage,
            'stage_label' => $fromStage,
            'decision' => 'endorsed',
        ]);
        $history->forceFill(['created_at' => now()->subDays($days)])->save();
    }

    /** The module's Non-Executive CGS stage, from its default chain. */
    protected function cgsStage(string $module): Stage
    {
        return collect(app(ModuleRegistry::class)->get($module)->stages(null))
            ->first(fn (Stage $s) => $s->role === Role::NON_EXEC_CGS);
    }

    /* ---------------------------------------------------------------
     | The map, one module group at a time
     |---------------------------------------------------------------*/

    public static function moduleGroups(): array
    {
        return [
            'Norhanis: travel' => ['travel', 'norshahira'],
            'Norhanis: publication' => ['publication', 'norshahira'],
            'Norhanis: claims' => ['claims_student', 'norshahira'],
            'Nureen: GA Extension' => ['ga_extension', 'zulkifly'],
            'Nureen: supervision' => ['supervision', 'zulkifly'],
            'Nureen: certification' => ['ga_certification', 'zulkifly'],
            'Nureen: attendance appeal' => ['attendance_appeal', 'zulkifly'],
            'Jason: appointment letter' => ['appointment_letter', 'syahmi'],
            'Chloe: candidacy appeal' => ['candidacy_appeal', 'amirul'],
        ];
    }

    #[DataProvider('moduleGroups')]
    public function test_only_the_assigned_officer_may_decide_each_module(string $module, string $officer): void
    {
        $assignment = app(CgsAssignment::class);
        $stage = $this->cgsStage($module);
        $application = $this->application($module, $stage->key);

        $this->assertTrue($assignment->officer($module)->is($this->officers[$officer]));

        $mine = $assignment->verdict($this->officers[$officer], $application, $stage);
        $this->assertTrue($mine->allowed());
        $this->assertSame(CgsVerdict::OFFICER, $mine->reason);

        foreach ([$this->otherCgs, ...array_values(array_filter($this->officers, fn ($o) => ! $o->is($this->officers[$officer])))] as $someoneElse) {
            $theirs = $assignment->verdict($someoneElse, $application, $stage);
            $this->assertFalse($theirs->allowed(), "{$someoneElse->email} must not decide {$module}");
            $this->assertSame(ucfirst($officer), $theirs->assigneeName());
        }
    }

    public function test_the_engine_refuses_another_cgs_officer_with_a_403_and_the_officers_name(): void
    {
        $application = $this->travelAtCgs();

        try {
            app(WorkflowEngine::class)->decide($application, $this->otherCgs, 'approve');
            $this->fail('Another CGS officer decided Norshahira\'s module.');
        } catch (NotAssignedOfficer $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('This module is assigned to Norshahira. You can view it but not decide on it.', $e->getMessage());
        }

        $this->assertSame('cgs_review', $application->fresh()->current_stage);
        $this->assertDatabaseCount('approval_history', 0);

        app(WorkflowEngine::class)->decide($application->fresh(), $this->officers['norshahira'], 'approve');
        $this->assertNotSame('cgs_review', $application->fresh()->current_stage);
    }

    public function test_return_to_an_earlier_stage_is_a_decision_too(): void
    {
        $application = $this->gaExtensionAtCgs();

        $this->expectException(NotAssignedOfficer::class);

        app(WorkflowEngine::class)->returnTo($application, $this->otherCgs, 'supervisor', 'Missing a document.');
    }

    /* ---------------------------------------------------------------
     | Over HTTP: decide, view, Section J, revoke
     |---------------------------------------------------------------*/

    public function test_another_cgs_officer_gets_a_403_on_the_decide_route_but_can_view_the_queue(): void
    {
        $application = $this->gaExtensionAtCgs();

        $this->actingAs($this->otherCgs)
            ->post(route('ga-extension.decide', $application), ['decision' => 'approve'])
            ->assertForbidden()
            ->assertSee('This module is assigned to Zulkifly. You can view it but not decide on it.');

        $this->assertSame(Application::STATUS_PENDING, $application->fresh()->status);

        $this->actingAs($this->otherCgs)
            ->get(route('ga-extension.queue', ['stage' => 'cgs_verify']))
            ->assertOk()
            ->assertSee('Assigned to: Zulkifly')
            ->assertSee('You can view it but not decide on it.')
            ->assertDontSee(route('ga-extension.decide', $application));

        $this->actingAs($this->otherCgs)
            ->get(route('nureen.application', $application))
            ->assertOk();
    }

    public function test_the_assigned_officer_sees_the_buttons_and_decides(): void
    {
        $application = $this->gaExtensionAtCgs();

        $this->actingAs($this->officers['zulkifly'])
            ->get(route('ga-extension.queue', ['stage' => 'cgs_verify']))
            ->assertOk()
            ->assertSee('Assigned to: Zulkifly')
            ->assertSee(route('ga-extension.decide', $application))
            ->assertDontSee('You can view it but not decide on it.');

        $this->actingAs($this->officers['zulkifly'])
            ->post(route('ga-extension.decide', $application), ['decision' => 'reject', 'remarks' => 'Not eligible.'])
            ->assertRedirect();

        $this->assertSame(Application::STATUS_REJECTED, $application->fresh()->status);
    }

    public function test_section_j_is_the_assigned_officers(): void
    {
        $application = $this->gaExtensionAtCgs();

        $this->actingAs($this->otherCgs)
            ->post(route('ga-extension.cgs-remarks', $application), ['cgs_remarks' => []])
            ->assertForbidden();

        $this->actingAs($this->officers['zulkifly'])
            ->post(route('ga-extension.cgs-remarks', $application), ['cgs_remarks' => []])
            ->assertRedirect();
    }

    public function test_revoking_a_letter_is_the_assigned_officers(): void
    {
        $supervision = $this->application('supervision', 'cgs_review', ['status' => Application::STATUS_APPROVED]);
        SupervisionDetail::create([
            'application_id' => $supervision->id, 'requested_supervisor_id' => $this->supervisor->id,
            'justification' => 'x', 'appointment_role' => SupervisionDetail::ROLE_CO, 'appointment_type' => 'new',
            'cgs_stage_snapshot' => 'endorse', 'letter_reference' => 'CGS/SVAPPT/2026/0001', 'letter_issued_at' => now(),
        ]);

        $certification = $this->application('ga_certification', 'senior_director', ['status' => Application::STATUS_APPROVED]);
        GaCertificationDetail::create([
            'application_id' => $certification->id, 'supervisor_id' => $this->supervisor->id, 'appointment_type' => 'GA',
            'period_start' => '2026-01-01', 'period_end' => '2026-06-30', 'purpose' => 'x', 'course_code' => 'C1',
            'course_name' => 'Course', 'course_owner_name' => 'Dr. X', 'semester' => 'Jan 2026', 'reference_number' => 'CGS/GACERT/2026/0001',
        ]);

        foreach ([['supervision.revoke', $supervision], ['ga-certification.revoke', $certification]] as [$route, $application]) {
            $this->actingAs($this->otherCgs)
                ->post(route($route, $application), ['revoked_reason' => 'Wrong programme.'])
                ->assertForbidden();

            $this->actingAs($this->officers['zulkifly'])
                ->post(route($route, $application), ['revoked_reason' => 'Wrong programme.'])
                ->assertRedirect();
        }

        $this->assertNotNull($supervision->fresh()->id);
        $this->assertNotNull(SupervisionDetail::where('application_id', $supervision->id)->value('letter_revoked_at'));
        $this->assertNotNull(GaCertificationDetail::where('application_id', $certification->id)->value('revoked_at'));
    }

    /* ---------------------------------------------------------------
     | Open modules and fallbacks
     |---------------------------------------------------------------*/

    public function test_both_hardbound_modules_accept_any_cgs_officer(): void
    {
        $assignment = app(CgsAssignment::class);

        foreach (['hardbound_submission', 'hardbound_appeal'] as $module) {
            $application = $this->application($module, 'cgs_review');

            foreach ([$this->otherCgs, ...array_values($this->officers)] as $cgs) {
                $verdict = $assignment->verdict($cgs, $application, $this->cgsStage($module));
                $this->assertTrue($verdict->allowed(), "{$cgs->email} must be able to decide {$module}");
                $this->assertSame(CgsVerdict::OPEN, $verdict->reason);
                $this->assertSame('Any CGS officer', $verdict->assigneeName());
            }
        }
    }

    public function test_a_module_missing_from_the_map_falls_back_to_any_cgs(): void
    {
        $modules = config('cgs_assignment.modules');
        unset($modules['travel']);
        config(['cgs_assignment.modules' => $modules]);

        $application = $this->travelAtCgs();

        app(WorkflowEngine::class)->decide($application, $this->otherCgs, 'approve');

        $this->assertNotSame('cgs_review', $application->fresh()->current_stage);
    }

    public function test_an_officer_without_a_non_executive_cgs_account_falls_back_to_any_cgs(): void
    {
        // The address exists, but as Senior Executive CGS, who cannot hold a
        // Non-Executive stage: rather than nobody, anyone may decide.
        $this->seniorExec();
        config(['cgs_assignment.modules.travel' => ['officer' => 'seniorexec@test.my']]);

        $assignment = app(CgsAssignment::class);
        $application = $this->travelAtCgs();

        $this->assertNull($assignment->officer('travel'));
        $this->assertTrue($assignment->canDecide($this->otherCgs, $application));
    }

    public function test_haziqs_modules_accept_any_non_executive_cgs_officer(): void
    {
        // Team decision, 2026-11-12: GA and GRA (and Stage Gates/Allowance,
        // checked against the same keys) are open to every officer.
        $assignment = app(CgsAssignment::class);

        foreach (['ga_application', 'gra_application'] as $module) {
            $this->assertSame('*', config("cgs_assignment.modules.{$module}"));

            $application = $this->application($module, 'cgs_review');

            foreach ([$this->otherCgs, ...array_values($this->officers)] as $cgs) {
                $verdict = $assignment->verdict($cgs, $application, $this->cgsStage($module));
                $this->assertSame(CgsVerdict::OPEN, $verdict->reason, "{$cgs->email} on {$module}");
                $this->assertSame('Any CGS officer', $verdict->assigneeName());

                // Module-level actions (Stage Gate, allowance) pass too.
                $assignment->authorizeModule($cgs, $module);
            }

            $everyone = User::where('role', Role::NON_EXEC_CGS)->pluck('id')->sort()->values()->all();
            $this->assertSame($everyone, $assignment->recipients($module)->pluck('id')->sort()->values()->all());
        }
    }

    public function test_senior_executive_cgs_stages_are_not_affected(): void
    {
        // Team decision, 2026-11-12: the map covers Non-Executive CGS stages
        // only. Hani's CGS desks are Senior Executive CGS.
        $assignment = app(CgsAssignment::class);
        $seniorExec = $this->seniorExec();
        $otherSeniorExec = $this->user(Role::SENIOR_EXEC_CGS, ['name' => 'Aqil', 'email' => 'aqil@test.my']);

        foreach (['examiner_nomination', 're_viva', 'viva_voce', 'viva_softbound'] as $module) {
            $this->assertArrayNotHasKey($module, config('cgs_assignment.modules'));

            $stages = collect(app(ModuleRegistry::class)->get($module)->stages(null));
            $this->assertFalse($stages->contains(fn (Stage $s) => $s->role === Role::NON_EXEC_CGS), "{$module} grew a Non-Executive CGS stage");

            foreach ($stages->where('role', Role::SENIOR_EXEC_CGS) as $stage) {
                $application = $this->application($module, $stage->key);

                foreach ([$seniorExec, $otherSeniorExec] as $user) {
                    $this->assertSame(CgsVerdict::NOT_APPLICABLE, $assignment->verdict($user, $application, $stage)->reason);
                }
            }
        }

        // Even a Senior Executive stage of a module that IS assigned is
        // left alone: only a Non-Executive CGS stage is ever restricted.
        $stage = new Stage('cgs_compile', 'Compile', Role::SENIOR_EXEC_CGS, 'compiled');
        $this->assertTrue($assignment->canDecide($seniorExec, $this->application('travel', 'cgs_compile'), $stage));
    }

    public function test_the_map_no_longer_names_akmar_or_waheeda(): void
    {
        $emails = collect(config('cgs_assignment.modules'))
            ->filter(fn ($entry) => is_array($entry))
            ->flatMap(fn (array $entry) => array_values($entry))
            ->all();

        $this->assertNotContains('akmar.cgs@utp.edu.my', $emails);
        $this->assertNotContains('waheda.cgs@utp.edu.my', $emails);
    }

    public function test_other_roles_stages_are_untouched(): void
    {
        $application = $this->travelAtCgs(['current_stage' => 'supervisor']);

        $verdict = app(CgsAssignment::class)->verdict($this->otherCgs, $application);

        $this->assertSame(CgsVerdict::NOT_APPLICABLE, $verdict->reason);
        $this->assertNull($verdict->assigneeName());
    }

    /* ---------------------------------------------------------------
     | Absence cover
     |---------------------------------------------------------------*/

    public function test_the_backup_may_decide_only_after_escalation(): void
    {
        config(['cgs_assignment.modules.ga_extension' => ['officer' => 'zulkifly.cgs@utp.edu.my', 'backup' => 'amirul.cgs@utp.edu.my']]);
        $assignment = app(CgsAssignment::class);
        $backup = $this->officers['amirul'];

        // On time: the backup is refused.
        $application = $this->gaExtensionAtCgs();
        $this->enteredStageDaysAgo($application, 0);
        $this->assertFalse($assignment->canDecide($backup, $application->fresh()));

        // Overdue, but only one reminder: still refused.
        $this->enteredStageDaysAgo($application, 20);
        $reminder = ApplicationReminder::create(['application_id' => $application->id, 'reminder_count' => 1, 'reminded_stage_key' => 'cgs_verify', 'last_reminded_at' => now()]);
        $this->assertTrue(app(ModuleRegistry::class)->get('ga_extension')->decisionIsOverdue($application->fresh()));
        $this->assertFalse($assignment->canDecide($backup, $application->fresh()));

        // Overdue and reminded twice: the backup may decide, nobody else may.
        $reminder->update(['reminder_count' => 2]);
        $verdict = $assignment->verdict($backup, $application->fresh());
        $this->assertSame(CgsVerdict::BACKUP, $verdict->reason);
        $this->assertSame('Overdue: backup may decide', $verdict->coverNote());
        $this->assertFalse($assignment->canDecide($this->otherCgs, $application->fresh()));

        app(WorkflowEngine::class)->decide($application->fresh(), $backup, 'reject', 'Covering for Zulkifly.');
        $this->assertSame(Application::STATUS_REJECTED, $application->fresh()->status);
    }

    public function test_with_no_backup_any_cgs_may_decide_after_escalation_and_the_row_says_why(): void
    {
        $application = $this->gaExtensionAtCgs();
        $this->enteredStageDaysAgo($application, 20);
        ApplicationReminder::create(['application_id' => $application->id, 'reminder_count' => 2, 'reminded_stage_key' => 'cgs_verify', 'last_reminded_at' => now()]);

        $verdict = app(CgsAssignment::class)->verdict($this->otherCgs, $application->fresh());
        $this->assertSame(CgsVerdict::ANY_AFTER_ESCALATION, $verdict->reason);

        $this->actingAs($this->otherCgs)
            ->get(route('ga-extension.queue', ['stage' => 'cgs_verify']))
            ->assertOk()
            ->assertSee('Overdue: any CGS officer may decide')
            ->assertSee(route('ga-extension.decide', $application));
    }

    public function test_a_module_with_neither_charter_nor_reminders_escalates_at_the_overdue_mark(): void
    {
        // Attendance Appeal supplies neither SuppliesOverdueStatus nor
        // SuppliesReminderCount: 14 days on the stage is enough.
        $application = $this->application('attendance_appeal', 'cgs_review');
        $assignment = app(CgsAssignment::class);

        $application->forceFill(['submitted_at' => now()->subDays(13)])->save();
        $this->assertFalse($assignment->isEscalated($application->fresh()));
        $this->assertFalse($assignment->canDecide($this->otherCgs, $application->fresh()));

        $application->forceFill(['submitted_at' => now()->subDays(14)])->save();
        $this->assertTrue($assignment->isEscalated($application->fresh()));
        $this->assertTrue($assignment->canDecide($this->otherCgs, $application->fresh()));
    }

    public function test_a_module_with_a_charter_but_no_reminder_counts_escalates_once_overdue(): void
    {
        // Norhanis' Travel has a Client Charter but sends no counted reminders.
        $application = $this->travelAtCgs();
        $assignment = app(CgsAssignment::class);

        $this->enteredStageDaysAgo($application, 0, 'chair');
        $this->assertFalse($assignment->isEscalated($application->fresh()));

        $this->enteredStageDaysAgo($application, 30, 'chair');
        $this->assertTrue($assignment->isEscalated($application->fresh()));
    }

    /* ---------------------------------------------------------------
     | Bulk decide
     |---------------------------------------------------------------*/

    public function test_bulk_decide_skips_another_officers_rows_and_names_the_officer(): void
    {
        config(['cgs_assignment.modules.ga_application' => ['officer' => 'syahmi.cgs@utp.edu.my']]);
        $application = $this->application('ga_application', 'cgs_review');

        $this->actingAs($this->otherCgs)
            ->post(route('queue.decide-bulk', 'ga_application'), ['ids' => [$application->id], 'decision' => 'approve'])
            ->assertRedirect()
            ->assertSessionHas('error', 'This module is assigned to Syahmi. You can view it but not decide on it.');

        $this->assertSame('cgs_review', $application->fresh()->current_stage);
    }

    /* ---------------------------------------------------------------
     | Emails
     |---------------------------------------------------------------*/

    public function test_awaiting_you_and_reminder_emails_go_only_to_the_assigned_officer(): void
    {
        Notification::fake();

        $appeal = $this->application('attendance_appeal', 'cgs_review');
        app(SubmissionNotifier::class)->notify($appeal);

        Notification::assertSentTo($this->officers['zulkifly'], ApplicationAwaitingYou::class);
        Notification::assertNotSentTo([$this->otherCgs, $this->officers['norshahira']], ApplicationAwaitingYou::class);

        $application = $this->gaExtensionAtCgs();
        $recipients = app(StalledApplicationReminder::class)->recipientsForCurrentStage($application);
        $this->assertSame([$this->officers['zulkifly']->id], $recipients->pluck('id')->all());
    }

    public function test_an_open_module_still_emails_every_cgs_officer(): void
    {
        $everyone = User::where('role', Role::NON_EXEC_CGS)->pluck('id')->sort()->values()->all();

        $this->assertSame($everyone, app(CgsAssignment::class)->recipients('hardbound_submission')->pluck('id')->sort()->values()->all());
        $this->assertSame([$this->officers['syahmi']->id], app(CgsAssignment::class)->recipients('appointment_letter')->pluck('id')->all());
    }

    /* ---------------------------------------------------------------
     | Dashboard
     |---------------------------------------------------------------*/

    public function test_the_cgs_dashboard_shows_how_many_are_assigned_to_me(): void
    {
        $this->gaExtensionAtCgs();
        $this->travelAtCgs();

        $this->actingAs($this->officers['zulkifly'])->get(route('dashboard'))
            ->assertOk()->assertSee('1 assigned to you');

        $this->actingAs($this->otherCgs)->get(route('dashboard'))
            ->assertOk()->assertSee('0 assigned to you');
    }
}
