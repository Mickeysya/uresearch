<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Contracts\DescribesApplicationProgress;
use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Models\GaExtensionDetail;
use App\Modules\Nureen\Support\LetterRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Track My Applications: module-neutral tabs, status filters and cards.
 *
 * Two test-only modules are registered here so the page is proven against
 * modules Core has never heard of: one implements NO optional contract,
 * the other only DescribesApplicationProgress (to say a rejection expects
 * resubmission). Nureen's real modules cover the snapshot chain and the
 * issued-letter card.
 */
class TrackApplicationsTest extends TestCase
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
        $registry->register(new PlainTestModule());
        $registry->register(new ResubmittableTestModule());

        $this->me = $this->student('22001001', 'me@test.my');
    }

    protected function application(string $module, string $status, ?string $stage, string $submitted = '2026-10-01 09:00', ?User $student = null): Application
    {
        $student ??= $this->me;

        return Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => $module,
            'status' => $status,
            'current_stage' => $stage,
            'submitted_at' => $submitted,
        ]);
    }

    protected function decided(Application $application, string $stage, string $label, string $decision, string $at, ?string $remarks = null): void
    {
        $row = ApprovalHistory::create([
            'application_id' => $application->id,
            'approver_id' => $this->supervisor('approver-'.uniqid().'@test.my')->id,
            'stage_key' => $stage,
            'stage_label' => $label,
            'decision' => $decision,
            'remarks' => $remarks,
        ]);
        DB::table('approval_history')->where('id', $row->id)->update(['created_at' => $at]);
    }

    protected function gaExtension(string $status, string $stage, string $mode, ?User $student = null): Application
    {
        $application = $this->application('ga_extension', $status, $stage, '2026-10-01 09:00', $student);
        GaExtensionDetail::create([
            'application_id' => $application->id,
            'current_end_date' => '2026-12-31',
            'requested_new_end_date' => '2027-06-30',
            'reason_for_extension' => 'Test fixture.',
            'final_approver_snapshot' => $mode,
        ]);

        return $application;
    }

    protected function page(array $query = [])
    {
        return $this->actingAs($this->me)->get(route('applications.index', $query))->assertOk();
    }

    /* -----------------------------------------------------------------
     | Tabs and counts
     |------------------------------------------------------------------*/

    public function test_tabs_come_from_each_modules_category_with_correct_counts(): void
    {
        $this->gaExtension(Application::STATUS_PENDING, 'supervisor', 'cgs');
        $this->gaExtension(Application::STATUS_APPROVED, 'cgs_verify', 'cgs');
        $this->application('plain_test', Application::STATUS_PENDING, 'first');
        $this->application('resubmittable_test', Application::STATUS_REJECTED, 'only');

        $this->page()
            // Assistantships (config label for 'assistantships'), the test
            // module's unlisted 'brand-new-category' (headline of its key),
            // and Other last for the module with no CATEGORY at all.
            ->assertSeeInOrder(['All', '4', 'Assistantships', '2', 'Brand New Category', '1', 'Other', '1'])
            ->assertSeeInOrder(['All statuses', '4', 'Needs you', '1', 'In progress', '2', 'Approved', '1', 'Rejected', '1']);
    }

    public function test_only_tabs_with_applications_are_shown_and_tones_are_fixed_per_category(): void
    {
        $this->gaExtension(Application::STATUS_PENDING, 'supervisor', 'cgs');

        $html = $this->page()->assertDontSee('Supervision &amp; Examination', false)->getContent();

        // Assistantships is blue for every student, whatever else is shown.
        $this->assertStringContainsString('trk-icon trk-tone-blue', $html);
        $this->assertSame(['key' => 'other', 'label' => 'Other', 'tone' => 'neutral', 'icon' => 'doc'], app(ModuleRegistry::class)->categoryFor('plain_test'));
    }

    public function test_the_tab_and_status_filters_work_from_the_query_string(): void
    {
        $this->gaExtension(Application::STATUS_PENDING, 'supervisor', 'cgs');
        $this->application('plain_test', Application::STATUS_APPROVED, 'second');
        $this->application('plain_test', Application::STATUS_PENDING, 'first');

        $this->page(['tab' => 'other'])
            ->assertSee('Showing <b>2</b> of 2 applications', false)
            ->assertSee('aria-current="page"', false)
            ->assertDontSee('GA EXTENSION')
            ->assertDontSee('Extend to');

        $this->page(['tab' => 'other', 'status' => 'approved'])
            ->assertSee('Showing <b>1</b> of 2 applications', false)
            ->assertSee('Approved');

        // The filter links keep the other half of the state.
        $this->page(['tab' => 'other'])->assertSee(route('applications.index', ['tab' => 'other', 'status' => 'approved']));

        // An unknown tab falls back to All; a bad status is refused.
        $this->page(['tab' => 'no-such-tab'])->assertSee('Showing <b>3</b> of 3 applications', false);
        $this->actingAs($this->me)->get(route('applications.index', ['status' => 'whatever']))->assertSessionHasErrors('status');
    }

    public function test_a_student_sees_only_their_own_applications(): void
    {
        $other = $this->student('22001002', 'other@test.my');
        $this->application('plain_test', Application::STATUS_PENDING, 'first', '2026-10-01 09:00', $other);
        $mine = $this->application('plain_test', Application::STATUS_PENDING, 'first');

        $this->page()
            ->assertSee($mine->reference())
            ->assertSee('Showing <b>1</b> of 1 application', false);
    }

    /* -----------------------------------------------------------------
     | Cards
     |------------------------------------------------------------------*/

    public function test_the_stepper_follows_each_applications_snapshot_chain(): void
    {
        $cgs = $this->gaExtension(Application::STATUS_PENDING, 'cgs_verify', 'cgs');
        $this->decided($cgs, 'supervisor', 'Lecturer/Supervisor', 'endorsed', '2026-10-13 10:00');

        $director = $this->gaExtension(Application::STATUS_PENDING, 'cgs_verify', 'senior_director');
        $this->decided($director, 'supervisor', 'Lecturer/Supervisor', 'endorsed', '2026-10-14 10:00');

        $html = $this->page()->getContent();
        preg_match_all('/<article class="trk-card.*?<\/article>/s', $html, $cards);
        [$second, $first] = $cards[0]; // newest activity first: $director, then $cgs

        // 'cgs' mode: Submitted, Admin, Supervisor, then CGS decides; no
        // director. GaExtensionWorkflow::chainFor() gained its own Admin
        // stage 2026-11-10, one position ahead of every stage below --
        // see that class's own docblock.
        $this->assertStringContainsString('Endorsed 13 Oct', $second);
        $this->assertStringNotContainsString('Senior Director CGS', $second);
        $this->assertStringContainsString('Step 4 of 4, current:', $second);

        // 'senior_director' mode: a fifth step, still to come.
        $this->assertStringContainsString('Endorsed 14 Oct', $first);
        $this->assertStringContainsString('Senior Director CGS', $first);
        $this->assertStringContainsString('Step 5 of 5, next:', $first);
        // Reached CGS on 14 Oct: 13 working days to Monday 2 Nov.
        $this->assertStringContainsString('Waiting 13 working days', $first);
    }

    public function test_a_rejected_card_quotes_the_reason_and_offers_a_new_application(): void
    {
        $rejected = $this->application('plain_test', Application::STATUS_REJECTED, 'second');
        $this->decided($rejected, 'first', 'Lecturer/Supervisor', 'endorsed', '2026-10-08 10:00');
        $this->decided($rejected, 'second', 'Chair of Department', 'rejected', '2026-10-20 10:00', 'Conference acceptance letter is missing.');

        $this->page(['status' => 'rejected'])
            ->assertSee('Reason from Chair of Department: “Conference acceptance letter is missing.”', false)
            ->assertSee('Rejected 20 Oct')
            ->assertSee('Start a new application')
            ->assertSee('View details')
            // A plain rejection is not something the student must act on.
            ->assertSeeInOrder(['Needs you', '0']);
    }

    public function test_an_approved_certification_offers_its_letter_by_its_real_reference(): void
    {
        $application = $this->application('ga_certification', Application::STATUS_APPROVED, 'senior_director');
        GaCertificationDetail::create([
            'application_id' => $application->id,
            'appointment_type' => 'GA',
            'period_start' => '2026-01-01',
            'period_end' => '2026-06-30',
            'purpose' => 'Test fixture.',
            'reference_number' => 'CGS/GACERT/2026/0042',
        ]);
        $this->decided($application, 'senior_director', 'Senior Director CGS', 'approved', '2026-07-15 10:00');
        Storage::disk('local')->put('letters/cert.pdf', '%PDF-1.4 test');
        $letter = ApplicationDocument::create([
            'application_id' => $application->id,
            'doc_type' => LetterRenderer::CERTIFICATION_DOC_TYPE,
            'original_name' => 'letter.pdf',
            'path' => 'letters/cert.pdf',
        ]);

        $this->page(['status' => 'needs-you'])
            ->assertSee('Letter CGS/GACERT/2026/0042')
            ->assertSee('Approved by Senior Director CGS on 15 Jul 2026. Your letter (CGS/GACERT/2026/0042) is ready.')
            ->assertSee('href="'.route('documents.show', $letter).'" class="btn">Download letter (PDF)</a>', false)
            ->assertSee('Showing <b>1</b> of 1 application', false);

        // Once opened through the normal download it no longer needs the
        // student -- but the download stays on the card.
        $this->actingAs($this->me)->get(route('documents.show', $letter))->assertOk();
        $this->page()->assertSeeInOrder(['Needs you', '0'])->assertSee('Download letter (PDF)');
    }

    public function test_returned_and_resubmission_expected_count_as_needs_you(): void
    {
        $returned = $this->application('plain_test', Application::STATUS_RETURNED, 'second');
        $this->decided($returned, 'second', 'Chair of Department', 'returned', '2026-10-30 10:00', 'Please attach the budget.');
        $this->application('resubmittable_test', Application::STATUS_REJECTED, 'only');
        $this->application('plain_test', Application::STATUS_REJECTED, 'first');

        $this->page(['status' => 'needs-you'])
            ->assertSee('Showing <b>2</b> of 3 applications', false)
            ->assertSee('Returned to you by Chair of Department: “Please attach the budget.”', false)
            ->assertSee('Review and resubmit');
    }

    public function test_a_module_with_no_optional_contract_still_renders_a_full_card(): void
    {
        $application = $this->application('plain_test', Application::STATUS_PENDING, 'second', '2026-10-28 09:00');
        $this->decided($application, 'first', 'Lecturer/Supervisor', 'endorsed', '2026-10-30 10:00');

        $this->page()
            ->assertSee('Plain Test Module')
            ->assertSee('Plain summary #'.$application->id)
            ->assertSee('Endorsed 30 Oct')
            ->assertSee('Since 30 Oct')
            ->assertSee('Now with CGS Staff. No action needed from you.')
            ->assertSee('Step 3 of 3, current:')
            ->assertDontSee('Expected decision by')
            ->assertDontSee('Taking longer than usual');
    }

    public function test_an_overdue_charter_stage_is_marked_calmly(): void
    {
        $this->gaExtension(Application::STATUS_PENDING, 'supervisor', 'cgs');

        $this->page()
            ->assertSee('Taking longer than usual')
            ->assertSee('This step is taking longer than usual; you will be notified as soon as it moves.')
            ->assertSee('Expected decision by');
    }

    /* -----------------------------------------------------------------
     | Empty states, paging, cost
     |------------------------------------------------------------------*/

    public function test_empty_states(): void
    {
        $this->page()->assertSee("You haven't filed any applications yet.", false);

        $this->application('plain_test', Application::STATUS_PENDING, 'first');
        $this->page(['status' => 'approved'])->assertSee('No applications match this filter.');
    }

    public function test_twenty_cards_a_page_in_a_bounded_number_of_queries(): void
    {
        foreach (range(1, 25) as $i) {
            $application = $this->gaExtension(Application::STATUS_PENDING, 'cgs_verify', 'cgs');
            $this->decided($application, 'supervisor', 'Lecturer/Supervisor', 'endorsed', '2026-10-13 10:00');
        }

        $this->actingAs($this->me);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = $this->get(route('applications.index'))->assertOk()->getContent();
        $log = DB::getQueryLog();
        $queries = count($log);
        if (getenv('TRACK_QUERY_DUMP')) {
            fwrite(STDERR, collect($log)->map(fn ($q) => preg_replace('/\d+/', 'N', $q['query']))->countBy()->sortDesc()->map(fn ($n, $q) => "{$n}x {$q}")->implode("\n"));
        }
        DB::disableQueryLog();

        $this->assertSame(20, substr_count($html, '<article class="trk-card'));
        $this->assertStringContainsString('Showing <b>1–20</b> of 25 applications', $html);
        $this->assertStringContainsString('Page 1 of 2', $html);
        // Fixed eager loads plus a few per card for the module's own
        // detail/charter reads. Recorded for the report:
        fwrite(STDERR, "\n[track-my-applications] queries for 20 cards (25 applications): {$queries}\n");
        $this->assertLessThan(160, $queries);

        $this->get(route('applications.index', ['page' => 2]))->assertOk()->assertSee('Showing <b>21–25</b> of 25 applications', false);
    }
}

/** A module Core has never heard of, with no optional contract and no CATEGORY. */
class PlainTestModule implements WorkflowModule
{
    public function key(): string { return 'plain_test'; }

    public function label(): string { return 'Plain Test Module'; }

    public function stages(?Application $application = null): array
    {
        return [
            new Stage('first', 'Lecturer/Supervisor', Role::SUPERVISOR, 'endorsed'),
            new Stage('second', 'CGS Staff', Role::NON_EXEC_CGS, 'approved'),
        ];
    }

    public function summary(Application $application): string { return 'Plain summary #'.$application->id; }

    public function createRoute(): ?string { return 'dashboard'; }

    public function queueRoute(): string { return 'dashboard'; }
}

/** Declares a category the config does not list, and expects resubmission after a rejection. */
class ResubmittableTestModule implements WorkflowModule, DescribesApplicationProgress
{
    public const CATEGORY = 'brand-new-category';

    public function key(): string { return 'resubmittable_test'; }

    public function label(): string { return 'Resubmittable Test Module'; }

    public function stages(?Application $application = null): array
    {
        return [new Stage('only', 'CGS Staff', Role::NON_EXEC_CGS, 'approved')];
    }

    public function summary(Application $application): string { return 'Resubmittable #'.$application->id; }

    public function createRoute(): ?string { return null; }

    public function queueRoute(): string { return 'dashboard'; }

    public function describeProgress(Application $application): array
    {
        return ['resubmission_expected' => true];
    }
}
