<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Nureen\Models\GaAppointmentRecord;
use App\Modules\Nureen\Models\SupervisionDetail;
use App\Modules\Nureen\Support\GaStatus;
use App\Modules\Nureen\Support\LetterRenderer;
use App\Modules\Nureen\Workflows\SupervisionWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Nureen\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Supervision against the Postgraduate Handbook 2025:
 *   - a GA changing Main Supervisor needs PGFAC approval first: the
 *     "PGFAC Approval Memo" upload, asked for only then (GA from
 *     GaAppointmentRecord, or the student's declaration with no record);
 *   - config('nureen.supervision.co_supervisor_approver'): the Chair by
 *     default, the Dean of PGR (Core's Role::DEAN_PGR) when switched,
 *     for Co-Supervisor requests only.
 */
class SupervisionHandbookTest extends TestCase
{
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected User $current;

    protected User $requested;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00'));

        $this->current = $this->supervisor('current@test.my');
        $this->requested = $this->supervisor('requested@test.my');
    }

    protected function payload(array $overrides = []): array
    {
        return $overrides + [
            'appointment_role' => SupervisionDetail::ROLE_MAIN,
            'appointment_type' => 'new',
            'requested_supervisor_id' => $this->requested->id,
            'justification' => 'We share a research interest in reservoir simulation and modelling.',
            'supporting_documents' => [UploadedFile::fake()->create('proposal.pdf', 120, 'application/pdf')],
        ];
    }

    protected function memo(): array
    {
        return ['pgfac_memo_documents' => [UploadedFile::fake()->create('pgfac-memo.pdf', 60, 'application/pdf')]];
    }

    protected function studentWithSupervisor(): User
    {
        return $this->student(attributes: ['supervisor_id' => $this->current->id]);
    }

    protected function appoint(User $student, string $type, string $from, string $to): void
    {
        GaAppointmentRecord::create(['student_id' => $student->id, 'appointment_type' => $type, 'period_start' => $from, 'period_end' => $to]);
    }

    protected function detail(): SupervisionDetail
    {
        return SupervisionDetail::sole();
    }

    /* -----------------------------------------------------------------
     | GA status
     |------------------------------------------------------------------*/

    public function test_ga_status_comes_from_an_active_ga_record(): void
    {
        $student = $this->student();
        $this->assertNull(GaStatus::fromRecord($student));

        $this->appoint($student, 'GA', '2025-01-01', '2025-12-31');
        $this->assertFalse(GaStatus::fromRecord($student), 'an expired GA appointment');

        $this->appoint($student, 'GRA', '2026-01-01', '2027-06-30');
        $this->assertFalse(GaStatus::fromRecord($student), 'a current GRA, not a GA');

        $this->appoint($student, 'GA', '2026-07-01', '2027-06-30');
        $this->assertTrue(GaStatus::fromRecord($student));
    }

    /* -----------------------------------------------------------------
     | PGFAC memo: required only for a GA changing Main Supervisor
     |------------------------------------------------------------------*/

    public function test_a_ga_on_record_changing_supervisor_must_attach_the_pgfac_memo(): void
    {
        $student = $this->studentWithSupervisor();
        $this->appoint($student, 'GA', '2026-07-01', '2027-06-30');

        $this->actingAs($student)->post(route('supervision.store'), $this->payload())
            ->assertSessionHasErrors(['pgfac_memo_documents' => 'As a GA changing supervisor, attach the PGFAC Approval Memo (PGFAC approval, with your supervisor\'s memo).']);
        $this->assertSame(0, Application::count());

        $this->actingAs($student)->post(route('supervision.store'), $this->payload() + $this->memo())->assertSessionHasNoErrors();

        $detail = $this->detail();
        $this->assertTrue($detail->is_change_of_supervisor);
        $this->assertTrue($detail->is_ga);
        $this->assertSame('record', $detail->ga_status_source);
        $this->assertTrue($detail->needsPgfacMemo());
        $this->assertSame(1, Application::sole()->documents()->where('doc_type', 'PGFAC Approval Memo')->count());

        // The requested supervisor's System checks show it attached.
        $this->actingAs($this->requested)->get(route('supervision.queue'))
            ->assertSee('System checks')
            ->assertSee('Change of Main Supervisor')
            ->assertSee('Graduate Assistant (from the GA appointment record)')
            ->assertSee('✓ PGFAC Approval Memo', false);
    }

    public function test_a_student_with_no_ga_record_declares_it_and_a_yes_needs_the_memo(): void
    {
        $student = $this->studentWithSupervisor();

        // A change with no record: the form asks.
        $this->actingAs($student)->post(route('supervision.store'), $this->payload())
            ->assertSessionHasErrors('declares_ga');

        $this->actingAs($student)->post(route('supervision.store'), $this->payload(['declares_ga' => '1']))
            ->assertSessionHasErrors('pgfac_memo_documents');

        $this->actingAs($student)->post(route('supervision.store'), $this->payload(['declares_ga' => '1']) + $this->memo())
            ->assertSessionHasNoErrors();
        $this->assertSame('declared', $this->detail()->ga_status_source);
        $this->assertTrue($this->detail()->is_ga);
    }

    public function test_a_declared_non_ga_changing_supervisor_needs_no_memo(): void
    {
        $student = $this->studentWithSupervisor();

        $this->actingAs($student)->post(route('supervision.store'), $this->payload(['declares_ga' => '0']))->assertSessionHasNoErrors();

        $this->assertFalse($this->detail()->needsPgfacMemo());
        $this->actingAs($this->requested)->get(route('supervision.queue'))
            ->assertSee('Not needed: only for a GA changing Main Supervisor.');
    }

    public function test_a_non_ga_on_record_changing_supervisor_needs_no_memo_and_is_not_asked(): void
    {
        $student = $this->studentWithSupervisor();
        $this->appoint($student, 'GRA', '2026-07-01', '2027-06-30');

        $this->actingAs($student)->get(route('supervision.create'))->assertDontSee('Are you currently a Graduate Assistant (GA)?');
        $this->actingAs($student)->post(route('supervision.store'), $this->payload())->assertSessionHasNoErrors();

        $this->assertFalse($this->detail()->is_ga);
        $this->assertSame('record', $this->detail()->ga_status_source);
    }

    public function test_a_ga_whose_request_is_not_a_change_needs_no_memo(): void
    {
        // A first Main Supervisor: nobody on record to change from.
        $first = $this->student('22000001', 'first@test.my');
        $this->appoint($first, 'GA', '2026-07-01', '2027-06-30');
        $this->actingAs($first)->post(route('supervision.store'), $this->payload())->assertSessionHasNoErrors();
        $this->assertFalse(SupervisionDetail::latest('id')->first()->is_change_of_supervisor);

        // A Co-Supervisor: an addition, not a change.
        $co = $this->student('22000002', 'co@test.my', ['supervisor_id' => $this->current->id]);
        $this->appoint($co, 'GA', '2026-07-01', '2027-06-30');
        $this->actingAs($co)->post(route('supervision.store'), $this->payload(['appointment_role' => SupervisionDetail::ROLE_CO]))->assertSessionHasNoErrors();

        $detail = SupervisionDetail::latest('id')->first();
        $this->assertFalse($detail->is_change_of_supervisor);
        $this->assertFalse($detail->needsPgfacMemo());
        $this->assertSame(0, Application::find($detail->application_id)->documents()->where('doc_type', 'PGFAC Approval Memo')->count());
    }

    public function test_the_form_asks_about_ga_only_when_nothing_is_on_record(): void
    {
        $student = $this->studentWithSupervisor();

        $this->actingAs($student)->get(route('supervision.create'))
            ->assertSee('Are you currently a Graduate Assistant (GA)?')
            ->assertSee('PGFAC Approval Memo');
    }

    public function test_an_older_request_shows_that_it_predates_the_check(): void
    {
        $student = $this->studentWithSupervisor();
        $this->actingAs($student)->post(route('supervision.store'), $this->payload(['declares_ga' => '0']));
        $this->detail()->update(['is_change_of_supervisor' => null, 'is_ga' => null, 'ga_status_source' => null]);

        $this->actingAs($this->requested)->get(route('supervision.queue'))->assertSee('Submitted before these were recorded.');
    }

    /* -----------------------------------------------------------------
     | Co-Supervisor approver
     |------------------------------------------------------------------*/

    protected function coRequest(): Application
    {
        $student = $this->student(attributes: ['supervisor_id' => $this->current->id]);
        $this->actingAs($student)->post(route('supervision.store'), $this->payload(['appointment_role' => SupervisionDetail::ROLE_CO]))->assertSessionHasNoErrors();

        return Application::latest('id')->first();
    }

    public function test_by_default_the_chair_approves_a_co_supervisor(): void
    {
        $this->assertSame('chair', config('nureen.supervision.co_supervisor_approver'));
        $application = $this->coRequest();

        $this->assertSame('chair', $this->detail()->co_supervisor_approver_snapshot);
        $this->assertSame(['supervisor', 'chair', 'cgs_review'], array_map(fn ($s) => $s->key, app(SupervisionWorkflow::class)->stages($application)));
        $this->assertNotContains('dean_pgr', array_map(fn ($s) => $s->key, app(SupervisionWorkflow::class)->stages()));
    }

    public function test_switched_to_dean_pgr_the_dean_approves_a_co_supervisor_and_the_chair_cannot(): void
    {
        config(['nureen.supervision.co_supervisor_approver' => 'dean_pgr']);
        $application = $this->coRequest();
        $dean = $this->dean();
        $chair = $this->chair();

        $this->assertSame('dean_pgr', $this->detail()->co_supervisor_approver_snapshot);
        $this->assertSame(['supervisor', 'dean_pgr', 'cgs_review'], array_map(fn ($s) => $s->key, app(SupervisionWorkflow::class)->stages($application)));

        $this->actingAs($this->requested)->post(route('supervision.decide', $application), ['decision' => 'approve']);
        $this->assertSame('dean_pgr', $application->fresh()->current_stage);

        $this->actingAs($chair)->post(route('supervision.decide', $application), ['decision' => 'approve']);
        $this->assertSame('dean_pgr', $application->fresh()->current_stage, 'the Chair is not on this chain');

        $this->actingAs($dean)->get(route('supervision.queue', ['stage' => 'dean_pgr']))->assertOk()->assertSee('#'.$application->id);
        $this->actingAs($dean)->post(route('supervision.decide', $application), ['decision' => 'approve']);
        $this->assertSame('cgs_review', $application->fresh()->current_stage);
    }

    public function test_a_main_supervisor_request_still_goes_to_the_chair_when_switched(): void
    {
        config(['nureen.supervision.co_supervisor_approver' => 'dean_pgr']);
        $student = $this->student();

        $this->actingAs($student)->post(route('supervision.store'), $this->payload())->assertSessionHasNoErrors();

        $this->assertSame(['supervisor', 'chair', 'cgs_review'], array_map(fn ($s) => $s->key, app(SupervisionWorkflow::class)->stages(Application::sole())));
    }

    public function test_the_snapshot_keeps_an_in_flight_request_on_its_chain_when_config_changes(): void
    {
        config(['nureen.supervision.co_supervisor_approver' => 'dean_pgr']);
        $application = $this->coRequest();
        $this->actingAs($this->requested)->post(route('supervision.decide', $application), ['decision' => 'approve']);

        // CGS turns it back off; the request already waiting for the Dean stays there.
        config(['nureen.supervision.co_supervisor_approver' => 'chair']);
        $this->assertSame('dean_pgr', app(SupervisionWorkflow::class)->stages($application)[1]->key);
        $this->assertContains('dean_pgr', array_map(fn ($s) => $s->key, (new SupervisionWorkflow)->stages()), 'the Dean queue stays reachable');

        $this->actingAs($this->dean())->post(route('supervision.decide', $application), ['decision' => 'approve']);
        $this->assertSame('cgs_review', $application->fresh()->current_stage);
    }

    public function test_in_automatic_mode_the_letter_names_the_dean(): void
    {
        config(['nureen.supervision.co_supervisor_approver' => 'dean_pgr', 'nureen.supervision.cgs_stage' => 'automatic']);
        $application = $this->coRequest();
        $dean = $this->dean();

        $this->actingAs($this->requested)->post(route('supervision.decide', $application), ['decision' => 'approve']);
        $this->actingAs($dean)->post(route('supervision.decide', $application), ['decision' => 'approve']);

        $this->assertSame(Application::STATUS_APPROVED, $application->fresh()->status);
        $letter = app(LetterRenderer::class)->appointmentData($application->fresh());
        $this->assertStringContainsString('following approval by the Dean of PGR, Prof. Dr. Hafiz Osman', $letter['paragraphs'][0]);
    }
}
