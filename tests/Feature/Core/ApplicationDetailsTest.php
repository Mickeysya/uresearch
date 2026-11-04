<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Contracts\DescribesApplicationProgress;
use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Nureen\Models\ApplicationReminder;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Models\GaExtensionDetail;
use App\Modules\Nureen\Models\SupervisionDetail;
use App\Modules\Nureen\Support\LetterRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The student's application details page ("View details"), for any module.
 * Today is Monday 2 November 2026 throughout.
 */
class ApplicationDetailsTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected User $me;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00'));

        $registry = app(ModuleRegistry::class);
        $registry->register(new DetailsPlainModule());
        $registry->register(new DetailsReturnableModule());

        $this->me = $this->student('22001001', 'me@test.my');
    }

    protected function application(string $module, string $status, ?string $stage, string $submitted = '2026-10-05 09:00'): Application
    {
        return Application::create([
            'student_id' => $this->me->id,
            'submitted_by_id' => $this->me->id,
            'module_type' => $module,
            'status' => $status,
            'current_stage' => $stage,
            'submitted_at' => $submitted,
        ]);
    }

    protected function decided(Application $application, string $stage, string $label, string $decision, string $at, ?string $remarks = null, string $by = 'Dr. Aisyah Rahman'): void
    {
        $approver = User::create(['name' => $by, 'email' => uniqid().'@test.my', 'password' => 'password', 'role' => Role::SUPERVISOR]);
        $row = ApprovalHistory::create([
            'application_id' => $application->id,
            'approver_id' => $approver->id,
            'stage_key' => $stage,
            'stage_label' => $label,
            'decision' => $decision,
            'remarks' => $remarks,
        ]);
        DB::table('approval_history')->where('id', $row->id)->update(['created_at' => $at]);
    }

    protected function certification(string $status, ?string $stage, array $detail = [], string $submitted = '2026-10-05 09:00'): Application
    {
        $application = $this->application('ga_certification', $status, $stage, $submitted);
        GaCertificationDetail::create($detail + [
            'application_id' => $application->id,
            'appointment_type' => 'GA',
            'period_start' => '2026-01-01',
            'period_end' => '2026-06-30',
            'purpose' => 'Scholarship application.',
            'course_code' => 'TFB2023',
            'course_name' => 'Algorithms',
            'course_owner_name' => 'Dr. Lim',
            'semester' => 'Jan 2026',
        ]);

        return $application;
    }

    protected function show(Application $application, array $query = [])
    {
        return $this->actingAs($this->me)->get(route('applications.show', ['application' => $application] + $query))->assertOk();
    }

    /** One step's <li> from the Progress list. */
    protected function step(string $html, string $label): string
    {
        $this->assertSame(1, preg_match('/<li class="trk-step [^"]*"[^>]*>(?:(?!<\/li>).)*?'.preg_quote($label, '/').'.*?<\/li>/s', $html, $m), "step {$label}");

        return $m[0];
    }

    /* -----------------------------------------------------------------
     | The #144 bug: a stage never gone through is never "done"
     |------------------------------------------------------------------*/

    public function test_a_stage_added_after_the_application_moved_on_is_not_required_never_done(): void
    {
        // Submitted on the OLD chain (straight to CGS), before the
        // Supervisor stage existed: current_stage is past it, and no
        // approval_history row exists for it.
        $application = $this->certification(Application::STATUS_PENDING, 'cgs_verify', [], '2026-10-11 17:00');

        $states = collect(app(WorkflowEngine::class)->progress($application))->mapWithKeys(fn ($s) => [$s['stage']->key => $s['state']]);
        $this->assertSame(['supervisor' => 'skipped', 'cgs_verify' => 'current', 'senior_director' => 'upcoming'], $states->all());

        $html = $this->show($application)->getContent();
        $supervisor = $this->step($html, 'Lecturer/Supervisor');

        $this->assertStringContainsString('is-skipped', $supervisor);
        $this->assertStringContainsString('Not required for this application', $supervisor);
        $this->assertStringContainsString('not required:', $supervisor);
        $this->assertStringNotContainsString('is-completed', $supervisor);
        $this->assertStringNotContainsString('Endorsed', $supervisor);

        // The current stage is CGS, and nothing claims a decision happened.
        $this->assertStringContainsString('aria-current="step"', $this->step($html, 'CGS Staff'));
        $this->assertStringContainsString('Pending · with CGS Staff', $html);

        // The list's card agrees.
        $this->actingAs($this->me)->get(route('applications.index'))->assertSee('Not required')->assertDontSee('Endorsed');
    }

    public function test_a_decided_stage_is_still_done(): void
    {
        $application = $this->certification(Application::STATUS_PENDING, 'cgs_verify');
        $this->decided($application, 'supervisor', 'Lecturer/Supervisor', 'endorsed', '2026-10-07 10:00');

        $supervisor = $this->step($this->show($application)->getContent(), 'Lecturer/Supervisor');

        $this->assertStringContainsString('is-completed', $supervisor);
        $this->assertStringContainsString('Dr. Aisyah Rahman', $supervisor);
        $this->assertStringContainsString('Endorsed 7 Oct 2026', $supervisor);
    }

    /* -----------------------------------------------------------------
     | Working days against the charter
     |------------------------------------------------------------------*/

    public function test_working_days_are_shown_against_each_stages_target(): void
    {
        $application = $this->certification(Application::STATUS_PENDING, 'cgs_verify');
        // Mon 5 Oct -> Wed 7 Oct: 2 of the supervisor's 3 working days.
        $this->decided($application, 'supervisor', 'Lecturer/Supervisor', 'endorsed', '2026-10-07 10:00');

        $html = $this->show($application)->getContent();

        $this->assertStringContainsString('2 of 3 working days', $this->step($html, 'Lecturer/Supervisor'));

        // At CGS since Wed 7 Oct: 18 working days to Mon 2 Nov, target 2.
        $cgs = $this->step($html, 'CGS Staff');
        $this->assertStringContainsString('Waiting since 7 Oct 2026', $cgs);
        $this->assertStringContainsString('trk-step-timing is-late', $cgs);
        $this->assertStringContainsString('18 working days · target 2', $cgs);
        $this->assertStringContainsString('(over target)', $cgs);

        // Still to come, with its target.
        $this->assertStringContainsString('Next · target 2 working days', $this->step($html, 'Senior Director CGS'));

        // The header's expected-date card says it calmly.
        $this->assertStringContainsString('Expected decision by', $html);
        $this->assertStringContainsString('Taking longer than usual', $html);
    }

    public function test_a_stage_with_no_charter_figure_shows_plain_working_days(): void
    {
        $application = $this->application('ga_extension', Application::STATUS_PENDING, 'senior_director');
        GaExtensionDetail::create([
            'application_id' => $application->id,
            'current_end_date' => '2026-12-31',
            'requested_new_end_date' => '2027-06-30',
            'reason_for_extension' => 'Lab equipment delayed.',
            'final_approver_snapshot' => 'senior_director',
        ]);
        $this->decided($application, 'supervisor', 'Lecturer/Supervisor', 'endorsed', '2026-10-06 10:00');
        $this->decided($application, 'cgs_verify', 'CGS Staff', 'reviewed', '2026-10-08 10:00');

        $html = $this->show($application)->getContent();

        $this->assertStringContainsString('2 of 2 working days', $this->step($html, 'CGS Staff'));
        // The Senior Director has no charter figure: days, no target.
        $director = $this->step($html, 'Senior Director CGS');
        $this->assertStringContainsString('17 working days', $director);
        $this->assertStringNotContainsString('target', $director);

        // "Your application" from details(), as a description list.
        $this->assertStringContainsString('<dt>Reason for extension</dt>', $html);
        $this->assertStringContainsString('<dd>Lab equipment delayed.</dd>', $html);
    }

    /* -----------------------------------------------------------------
     | Outcomes
     |------------------------------------------------------------------*/

    public function test_an_approved_certification_shows_its_letter_by_its_real_reference(): void
    {
        $application = $this->certification(Application::STATUS_APPROVED, 'senior_director', ['reference_number' => 'CGS/GACERT/2026/0042']);
        $this->decided($application, 'supervisor', 'Lecturer/Supervisor', 'endorsed', '2026-10-07 10:00');
        $this->decided($application, 'cgs_verify', 'CGS Staff', 'reviewed', '2026-10-08 10:00');
        $this->decided($application, 'senior_director', 'Senior Director CGS', 'approved', '2026-10-09 10:00', null, 'En Zulkifly');
        Storage::disk('local')->put('letters/cert.pdf', '%PDF-1.4 test');
        $letter = ApplicationDocument::create(['application_id' => $application->id, 'doc_type' => LetterRenderer::CERTIFICATION_DOC_TYPE, 'original_name' => 'letter.pdf', 'path' => 'letters/cert.pdf', 'size_bytes' => 48000]);

        $html = $this->show($application)
            ->assertSee('· Letter CGS/GACERT/2026/0042')
            ->assertSee('Reference CGS/GACERT/2026/0042')
            ->assertSee(route('certificate.verify', 'CGS/GACERT/2026/0042'))
            ->assertSee($letter->url())
            ->assertSee('Letter issued')
            ->assertSee('Approved by En Zulkifly (Senior Director CGS)')
            // GA: the course fields; never the appointment check result.
            ->assertSee('Graduate Assistant (GA)')
            ->assertSee('TFB2023')
            ->assertDontSee('Verified')
            ->assertDontSee('Mismatch')
            // Unopened: the download is the header's primary action.
            ->assertSee('Needs you')
            ->getContent();

        // The issued letter is not repeated among the student's uploads.
        $this->assertStringNotContainsString('>letter.pdf<', $html);
        $this->assertStringNotContainsString('aria-label="Your uploads"', $html);
    }

    public function test_a_revoked_letter_says_so_with_its_reason_but_never_the_internal_note(): void
    {
        $application = $this->application('supervision', Application::STATUS_APPROVED, 'cgs_review');
        SupervisionDetail::create([
            'application_id' => $application->id,
            'requested_supervisor_id' => $this->supervisor('sup@test.my')->id,
            'justification' => 'Shared research area.',
            'appointment_role' => SupervisionDetail::ROLE_MAIN,
            'appointment_type' => 'new',
            'letter_reference' => 'CGS/SVAPPT/2026/0009',
            'letter_issued_at' => '2026-10-20 10:00',
            'letter_revoked_at' => '2026-10-28 10:00',
            'letter_revoked_reason' => 'Issued to the wrong programme.',
            'letter_revoke_note' => 'INTERNAL: checked with registry',
        ]);
        Storage::disk('local')->put('letters/sv.pdf', '%PDF-1.4 test');
        ApplicationDocument::create(['application_id' => $application->id, 'doc_type' => LetterRenderer::APPOINTMENT_DOC_TYPE, 'original_name' => 'sv.pdf', 'path' => 'letters/sv.pdf']);

        $this->show($application)
            ->assertSee('Revoked')
            ->assertSee('Issued to the wrong programme.')
            ->assertSee('Letter revoked')
            ->assertSee('Main Supervisor')
            ->assertDontSee('INTERNAL: checked with registry')
            ->assertDontSee('Verification page');
    }

    public function test_a_rejected_application_shows_the_reason_in_the_header_and_timeline(): void
    {
        $application = $this->application('details_plain', Application::STATUS_REJECTED, 'second');
        $this->decided($application, 'first', 'Lecturer/Supervisor', 'endorsed', '2026-10-08 10:00');
        $this->decided($application, 'second', 'Chair of Department', 'rejected', '2026-10-20 10:00', 'Conference acceptance letter is missing.', 'Dr. Lim Wei Chun');

        $html = $this->show($application)->getContent();

        $this->assertMatchesRegularExpression('/trk-detail-reason.*Reason from Chair of Department:.*<q>Conference acceptance letter is missing.<\/q>/s', $html);
        $this->assertMatchesRegularExpression('/Rejected by Dr. Lim Wei Chun \(Chair of Department\).*<blockquote class="trk-event-quote">Conference acceptance letter is missing.<\/blockquote>/s', $html);
        $this->assertStringContainsString('This application is closed', $html);
    }

    public function test_a_returned_application_puts_the_students_action_first(): void
    {
        $application = $this->application('details_returnable', Application::STATUS_RETURNED, 'only');
        $this->decided($application, 'only', 'CGS Staff', 'returned', '2026-10-30 10:00', 'Please attach the budget.');

        $this->show($application)
            ->assertSeeInOrder(['page-header-actions', 'href="'.route('dashboard').'?fix=1" class="btn">Edit and resubmit</a>'], false)
            ->assertSee('Returned · with you')
            ->assertSee('Returned to you by')
            ->assertSee('Please attach the budget.')
            ->assertSee('this needs you');
    }

    /* -----------------------------------------------------------------
     | Generic behaviour
     |------------------------------------------------------------------*/

    public function test_a_module_without_details_or_any_contract_still_renders(): void
    {
        $application = $this->application('details_plain', Application::STATUS_PENDING, 'first', '2026-10-30 09:00');

        $this->show($application)
            ->assertSee('Plain details summary')
            ->assertSee('Pending · with Lecturer/Supervisor')
            ->assertSee('Submitted')
            ->assertSee('Lecturer/Supervisor will review it next.')
            ->assertSee("You don't need to do anything right now")
            ->assertDontSee('Your application')
            ->assertDontSee('Expected decision by')
            ->assertSee('Need help?')
            ->assertSee($application->reference());
    }

    public function test_reminders_name_the_role_never_the_person(): void
    {
        $application = $this->certification(Application::STATUS_PENDING, 'cgs_verify');
        $this->decided($application, 'supervisor', 'Lecturer/Supervisor', 'endorsed', '2026-10-07 10:00');
        ApplicationReminder::create(['application_id' => $application->id, 'reminder_count' => 2, 'reminded_stage_key' => 'cgs_verify', 'last_reminded_at' => '2026-10-15 08:00']);

        $this->show($application)
            ->assertSee('CGS Staff reminded')
            ->assertSee('2 reminders sent for this step');
    }

    public function test_the_timeline_is_newest_first_even_when_events_share_a_timestamp(): void
    {
        $application = $this->application('details_plain', Application::STATUS_PENDING, 'second', '2026-10-05 09:00');
        $this->decided($application, 'first', 'Lecturer/Supervisor', 'endorsed', '2026-10-05 09:00');

        $this->show($application)->assertSeeInOrder(['Endorsed by Dr. Aisyah Rahman (Lecturer/Supervisor)', 'Submitted']);
    }

    public function test_uploads_are_listed_with_type_and_size(): void
    {
        $application = $this->application('details_plain', Application::STATUS_PENDING, 'first');
        Storage::disk('local')->put('uploads/a.pdf', 'x');
        $doc = ApplicationDocument::create(['application_id' => $application->id, 'doc_type' => 'Supporting Document', 'original_name' => 'budget.pdf', 'path' => 'uploads/a.pdf', 'size_bytes' => 2_400_000]);

        $this->show($application)
            ->assertSee($doc->url())
            ->assertSee('budget.pdf')
            ->assertSee('Supporting Document · 2.3 MB');
    }

    public function test_the_breadcrumb_returns_to_the_same_tab_and_filter(): void
    {
        $application = $this->application('details_plain', Application::STATUS_PENDING, 'first');

        $this->show($application, ['tab' => 'other', 'status' => 'in-progress'])
            ->assertSee(route('applications.index', ['tab' => 'other', 'status' => 'in-progress']));

        // And the list's View details links carry them.
        $this->actingAs($this->me)->get(route('applications.index', ['tab' => 'other', 'status' => 'in-progress']))
            ->assertSee(route('applications.show', ['application' => $application, 'tab' => 'other', 'status' => 'in-progress']));
    }

    public function test_a_student_cannot_open_another_students_application(): void
    {
        $other = $this->student('22001002', 'other@test.my');
        $theirs = Application::create(['student_id' => $other->id, 'submitted_by_id' => $other->id, 'module_type' => 'details_plain', 'status' => Application::STATUS_PENDING, 'current_stage' => 'first', 'submitted_at' => now()]);

        $this->actingAs($this->me)->get(route('applications.show', $theirs))->assertForbidden();
        // Approvers never had this page; that is unchanged.
        $this->actingAs($this->cgs())->get(route('applications.show', $theirs))->assertForbidden();
    }

    public function test_the_page_needs_a_handful_of_queries(): void
    {
        $application = $this->certification(Application::STATUS_PENDING, 'cgs_verify');
        foreach (range(1, 3) as $i) {
            $this->decided($application, 'supervisor', 'Lecturer/Supervisor', 'endorsed', "2026-10-0{$i} 10:00");
        }

        $this->actingAs($this->me);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('applications.show', $application))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        fwrite(STDERR, "\n[application-details] queries for one certification page: {$queries}\n");
        $this->assertLessThan(30, $queries);
    }
}

/** No optional contract at all. */
class DetailsPlainModule implements WorkflowModule
{
    public function key(): string { return 'details_plain'; }

    public function label(): string { return 'Plain Details Module'; }

    public function stages(?Application $application = null): array
    {
        return [
            new Stage('first', 'Lecturer/Supervisor', Role::SUPERVISOR, 'endorsed'),
            new Stage('second', 'Chair of Department', Role::CHAIR, 'approved'),
        ];
    }

    public function summary(Application $application): string { return 'Plain details summary'; }

    public function createRoute(): ?string { return null; }

    public function queueRoute(): string { return 'dashboard'; }
}

/** Offers the student's own resubmit action when returned. */
class DetailsReturnableModule implements WorkflowModule, DescribesApplicationProgress
{
    public function key(): string { return 'details_returnable'; }

    public function label(): string { return 'Returnable Details Module'; }

    public function stages(?Application $application = null): array
    {
        return [new Stage('only', 'CGS Staff', Role::NON_EXEC_CGS, 'approved')];
    }

    public function summary(Application $application): string { return 'Returnable details summary'; }

    public function createRoute(): ?string { return null; }

    public function queueRoute(): string { return 'dashboard'; }

    public function describeProgress(Application $application): array
    {
        return $application->status === Application::STATUS_RETURNED
            ? ['actions' => [['label' => 'Edit and resubmit', 'url' => route('dashboard').'?fix=1', 'primary' => true]]]
            : [];
    }
}
