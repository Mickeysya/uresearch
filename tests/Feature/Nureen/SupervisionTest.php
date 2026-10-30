<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Nureen\Models\SupervisionDetail;
use App\Modules\Nureen\Notifications\AppointmentLetterIssued;
use App\Modules\Nureen\Notifications\AppointmentLetterIssuedToCgs;
use App\Modules\Nureen\Notifications\AppointmentLetterRevoked;
use App\Modules\Nureen\Notifications\SupervisionFinalDecisionCc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Supervisor (Main or Co-) appointment requests — `docs/scope/nureen.md`
 * Module 3, realigned to CGS's confirmed UResearch process.
 *
 * The chain now has two shapes, chosen per application at submission and
 * never revisited -- see SupervisionWorkflow::stages():
 *   'automatic' DEFAULT. Supervisor endorses, Chair of Department approves,
 *               and that approval is final: the appointment letter issues
 *               immediately.
 *   'manual'    A cgs_review stage still exists after the Chair; the
 *               letter is generated only once CGS itself approves.
 *
 * The first block of tests covers document completeness and queue scoping,
 * unchanged from before this realignment. The rest covers the new chain,
 * the Main/Co-Supervisor split, the appointment letter, and revocation.
 */
class SupervisionTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    /** @return array<string, mixed> */
    protected function payload(array $overrides = []): array
    {
        return $overrides + [
            'appointment_role' => SupervisionDetail::ROLE_MAIN,
            'appointment_type' => 'new',
            'justification' => 'We share a research interest in reservoir simulation and modelling.',
            'supporting_documents' => [UploadedFile::fake()->create('proposal.pdf', 120, 'application/pdf')],
        ];
    }

    public function test_a_supervision_request_requires_at_least_one_document(): void
    {
        $supervisor = $this->supervisor();

        $this->actingAs($this->student());

        $this->post(route('supervision.store'), $this->payload([
            'requested_supervisor_id' => $supervisor->id,
            'supporting_documents' => null,
        ]))->assertSessionHasErrors('supporting_documents');

        $this->assertSame(0, Application::where('module_type', 'supervision')->count());
    }

    public function test_a_supervision_request_with_a_document_is_submitted_and_the_file_attached(): void
    {
        Storage::fake('local');

        $supervisor = $this->supervisor();

        $this->actingAs($this->student());

        $this->post(route('supervision.store'), $this->payload([
            'requested_supervisor_id' => $supervisor->id,
        ]))->assertRedirect(route('applications.index'));

        $application = Application::where('module_type', 'supervision')->sole();

        $this->assertSame(Application::STATUS_PENDING, $application->status);
        $this->assertSame('supervisor', $application->current_stage);

        $detail = SupervisionDetail::where('application_id', $application->id)->sole();
        $this->assertSame(SupervisionDetail::ROLE_MAIN, $detail->appointment_role);
        $this->assertSame('new', $detail->appointment_type);
        $this->assertSame('automatic', $detail->cgs_stage_snapshot);

        $document = $application->documents()->sole();
        $this->assertSame('proposal.pdf', $document->original_name);
        // Private disk, random name -- never under public/.
        $this->assertStringStartsWith("applications/{$application->id}/", $document->path);
        $this->assertStringNotContainsString('proposal', $document->path);
    }

    public function test_a_supervision_request_with_several_documents_attaches_them_all(): void
    {
        Storage::fake('local');

        $supervisor = $this->supervisor();

        $this->actingAs($this->student());

        $this->post(route('supervision.store'), $this->payload([
            'requested_supervisor_id' => $supervisor->id,
            'supporting_documents' => [
                UploadedFile::fake()->create('proposal.pdf', 120, 'application/pdf'),
                UploadedFile::fake()->create('transcript.png', 300, 'image/png'),
            ],
        ]))->assertRedirect(route('applications.index'));

        $application = Application::where('module_type', 'supervision')->sole();

        $this->assertSame(2, $application->documents()->count());
    }

    public function test_more_than_five_documents_is_rejected(): void
    {
        $supervisor = $this->supervisor();

        $this->actingAs($this->student());

        $files = array_map(
            fn ($i) => UploadedFile::fake()->create("doc-{$i}.pdf", 50, 'application/pdf'),
            range(1, 6),
        );

        $this->post(route('supervision.store'), $this->payload([
            'requested_supervisor_id' => $supervisor->id,
            'supporting_documents' => $files,
        ]))->assertSessionHasErrors('supporting_documents');

        $this->assertSame(0, Application::where('module_type', 'supervision')->count());
    }

    /**
     * A supervisor sees only the requests naming them.
     *
     * Narrowed in the QUERY, so the count is right too. It was briefly done
     * by filtering the rows that came back, which reported the unfiltered
     * total, paged over rows it then discarded, and -- because a paginator
     * forwards unknown calls to its collection -- handed the view a plain
     * Collection, which 500s the moment it is asked for a total.
     */
    public function test_a_supervisor_sees_only_the_requests_addressed_to_them(): void
    {
        $mine = $this->supervisor('mine@test.my');
        $theirs = $this->supervisor('theirs@test.my');

        foreach ([[$mine, 'Ahmad'], [$theirs, 'Siti']] as [$supervisor, $name]) {
            $student = $this->student(
                matric: '2200'.$supervisor->id,
                email: strtolower($name).'@test.my',
                attributes: ['name' => $name.' Binti Osman'],
            );

            $application = Application::create([
                'student_id' => $student->id,
                'submitted_by_id' => $student->id,
                'module_type' => 'supervision',
                'status' => Application::STATUS_PENDING,
                'current_stage' => 'supervisor',
                'submitted_at' => now()->subDay(),
            ]);

            SupervisionDetail::create([
                'application_id' => $application->id,
                'requested_supervisor_id' => $supervisor->id,
                'justification' => 'We share a research interest in reservoir simulation.',
                'appointment_role' => SupervisionDetail::ROLE_MAIN,
            ]);
        }

        $this->actingAs($mine)
            ->get(route('supervision.queue', ['stage' => 'supervisor']))
            ->assertOk()
            ->assertSee('Ahmad Binti Osman')
            ->assertDontSee('Siti Binti Osman')
            // The count follows the scoping. Two requests exist; one is mine.
            ->assertSee('1 application awaiting your decision.');
    }

    /* -----------------------------------------------------------------
     | Blocking a bad Co-Supervisor request at submission
     |------------------------------------------------------------------*/

    public function test_a_co_supervisor_request_naming_the_current_main_supervisor_is_blocked(): void
    {
        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);

        $this->actingAs($student)
            ->post(route('supervision.store'), $this->payload([
                'requested_supervisor_id' => $supervisor->id,
                'appointment_role' => SupervisionDetail::ROLE_CO,
            ]))
            ->assertSessionHasErrors('requested_supervisor_id');

        $this->assertSame(0, Application::where('module_type', 'supervision')->count());
    }

    public function test_a_second_request_for_the_same_active_co_supervisor_is_blocked(): void
    {
        $student = $this->student();
        $coSupervisor = $this->supervisor('co@test.my');

        // An existing, approved, unrevoked Co-Supervisor appointment for
        // this exact (student, supervisor) pair.
        $existingApplication = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'supervision',
            'status' => Application::STATUS_APPROVED,
            'current_stage' => 'chair',
        ]);
        SupervisionDetail::create([
            'application_id' => $existingApplication->id,
            'requested_supervisor_id' => $coSupervisor->id,
            'justification' => 'Already appointed.',
            'appointment_role' => SupervisionDetail::ROLE_CO,
        ]);

        $this->actingAs($student)
            ->post(route('supervision.store'), $this->payload([
                'requested_supervisor_id' => $coSupervisor->id,
                'appointment_role' => SupervisionDetail::ROLE_CO,
            ]))
            ->assertSessionHasErrors('requested_supervisor_id');

        $this->assertSame(1, Application::where('module_type', 'supervision')->count());
    }

    public function test_a_revoked_co_supervisor_appointment_does_not_block_a_new_request(): void
    {
        $student = $this->student();
        $coSupervisor = $this->supervisor('co@test.my');

        $priorApplication = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'supervision',
            'status' => Application::STATUS_APPROVED,
            'current_stage' => 'chair',
        ]);
        SupervisionDetail::create([
            'application_id' => $priorApplication->id,
            'requested_supervisor_id' => $coSupervisor->id,
            'justification' => 'Already appointed, since revoked.',
            'appointment_role' => SupervisionDetail::ROLE_CO,
            'letter_reference' => 'CGS/SVAPPT/'.now()->year.'/0001',
            'letter_revoked_at' => now(),
            'letter_revoked_reason' => 'No longer active.',
        ]);

        $this->actingAs($student)
            ->post(route('supervision.store'), $this->payload([
                'requested_supervisor_id' => $coSupervisor->id,
                'appointment_role' => SupervisionDetail::ROLE_CO,
            ]))
            ->assertRedirect(route('applications.index'));

        $this->assertSame(2, Application::where('module_type', 'supervision')->count());
    }

    /* -----------------------------------------------------------------
     | 'automatic' mode: Supervisor -> Chair, final, letter on approval
     |------------------------------------------------------------------*/

    public function test_automatic_mode_main_supervisor_chain_sets_supervisor_id_and_issues_the_letter(): void
    {
        Storage::fake('local');
        Notification::fake();

        $supervisor = $this->supervisor();
        $student = $this->student();
        $student->update(['department' => 'Chemical Engineering']);
        $chair = $this->user(\App\Modules\Core\Support\Role::CHAIR, ['department' => 'Chemical Engineering']);
        $cgs = $this->cgs();

        $this->actingAs($student)->post(route('supervision.store'), $this->payload([
            'requested_supervisor_id' => $supervisor->id,
        ]));
        $application = Application::where('module_type', 'supervision')->sole();

        $this->actingAs($supervisor)
            ->post(route('supervision.decide', $application), ['decision' => 'approve']);

        $application->refresh();
        $this->assertSame(Application::STATUS_PENDING, $application->status);
        $this->assertSame('chair', $application->current_stage);
        $this->assertNull($student->fresh()->supervisor_id);
        // Not issued yet -- nobody, including CGS, has been told of a
        // letter before the Chair has actually approved it.
        Notification::assertNotSentTo($cgs, AppointmentLetterIssuedToCgs::class);

        $this->actingAs($chair)
            ->post(route('supervision.decide', $application), ['decision' => 'approve']);

        $this->assertSame(Application::STATUS_APPROVED, $application->fresh()->status);
        $this->assertSame($supervisor->id, $student->fresh()->supervisor_id);

        $detail = SupervisionDetail::where('application_id', $application->id)->sole();
        $this->assertNotNull($detail->letter_reference);
        $this->assertStringStartsWith('CGS/SVAPPT/', $detail->letter_reference);

        Notification::assertSentTo($student, AppointmentLetterIssued::class);
        Notification::assertSentTo($supervisor, SupervisionFinalDecisionCc::class);
        // "Non-Executive CGS is notified of every issued letter" -- not
        // just able to find it on the Issued Appointment Letters list.
        Notification::assertSentTo($cgs, AppointmentLetterIssuedToCgs::class);
    }

    public function test_automatic_mode_co_supervisor_approval_never_touches_supervisor_id(): void
    {
        Storage::fake('local');
        Notification::fake();

        $mainSupervisor = $this->supervisor('main@test.my');
        $coSupervisor = $this->supervisor('co@test.my');
        $student = $this->student(attributes: ['supervisor_id' => $mainSupervisor->id]);
        $chair = $this->chair();
        $cgs = $this->cgs();

        $this->actingAs($student)->post(route('supervision.store'), $this->payload([
            'requested_supervisor_id' => $coSupervisor->id,
            'appointment_role' => SupervisionDetail::ROLE_CO,
        ]));
        $application = Application::where('module_type', 'supervision')->sole();

        $this->actingAs($coSupervisor)
            ->post(route('supervision.decide', $application), ['decision' => 'approve']);

        $this->actingAs($chair)
            ->post(route('supervision.decide', $application), ['decision' => 'approve']);

        $this->assertSame(Application::STATUS_APPROVED, $application->fresh()->status);
        // Unchanged -- a Co-Supervisor appointment must never overwrite it.
        $this->assertSame($mainSupervisor->id, $student->fresh()->supervisor_id);

        $detail = SupervisionDetail::where('application_id', $application->id)->sole();
        $this->assertNotNull($detail->letter_reference);
        $this->assertNull($detail->previous_supervisor_id);

        Notification::assertSentTo($cgs, AppointmentLetterIssuedToCgs::class);
    }

    public function test_rejection_requires_a_reason_and_notifies_the_student(): void
    {
        $supervisor = $this->supervisor();
        $student = $this->student();

        $this->actingAs($student)->post(route('supervision.store'), $this->payload([
            'requested_supervisor_id' => $supervisor->id,
        ]));
        $application = Application::where('module_type', 'supervision')->sole();

        $this->actingAs($supervisor)
            ->post(route('supervision.decide', $application), ['decision' => 'reject'])
            ->assertSessionHasErrors('remarks');

        $this->assertSame(Application::STATUS_PENDING, $application->fresh()->status);

        $this->actingAs($supervisor)
            ->post(route('supervision.decide', $application), [
                'decision' => 'reject',
                'remarks' => 'I do not have capacity for another student this semester.',
            ]);

        $this->assertSame(Application::STATUS_REJECTED, $application->fresh()->status);
    }

    /* -----------------------------------------------------------------
     | 'manual' mode: Supervisor -> Chair -> CGS, letter on CGS approval
     |------------------------------------------------------------------*/

    public function test_manual_mode_needs_all_three_approvals_and_issues_the_letter_only_at_cgs(): void
    {
        config(['nureen.supervision.cgs_stage' => 'manual']);

        Storage::fake('local');
        Notification::fake();

        $supervisor = $this->supervisor();
        $student = $this->student();
        $chair = $this->chair();
        $cgs = $this->cgs();

        $this->actingAs($student)->post(route('supervision.store'), $this->payload([
            'requested_supervisor_id' => $supervisor->id,
        ]));
        $application = Application::where('module_type', 'supervision')->sole();
        $this->assertSame('manual', SupervisionDetail::where('application_id', $application->id)->sole()->cgs_stage_snapshot);

        $this->actingAs($supervisor)->post(route('supervision.decide', $application), ['decision' => 'approve']);
        $application->refresh();
        $this->assertSame('chair', $application->current_stage);

        $this->actingAs($chair)->post(route('supervision.decide', $application), ['decision' => 'approve']);
        $application->refresh();
        $this->assertSame(Application::STATUS_PENDING, $application->status);
        $this->assertSame('cgs_review', $application->current_stage);
        // Not issued yet -- the Chair's approval is not final in this mode.
        $this->assertNull(SupervisionDetail::where('application_id', $application->id)->sole()->letter_reference);

        $this->actingAs($cgs)->post(route('supervision.decide', $application), ['decision' => 'approve']);

        $this->assertSame(Application::STATUS_APPROVED, $application->fresh()->status);
        $this->assertNotNull(SupervisionDetail::where('application_id', $application->id)->sole()->letter_reference);
        $this->assertSame($supervisor->id, $student->fresh()->supervisor_id);

        // Even in 'manual' mode -- where CGS is the one who just approved
        // it -- the rule is "every issued letter", not "every letter CGS
        // did not already know about".
        Notification::assertSentTo($cgs, AppointmentLetterIssuedToCgs::class);
    }

    /* -----------------------------------------------------------------
     | Revoking the appointment letter
     |------------------------------------------------------------------*/

    public function test_revoking_a_main_supervisor_letter_restores_the_previous_supervisor(): void
    {
        Storage::fake('local');
        Notification::fake();

        $previousSupervisor = $this->supervisor('previous@test.my');
        $newSupervisor = $this->supervisor('new@test.my');
        $student = $this->student(attributes: ['supervisor_id' => $previousSupervisor->id]);
        $chair = $this->chair();
        $cgs = $this->cgs();

        $this->actingAs($student)->post(route('supervision.store'), $this->payload([
            'requested_supervisor_id' => $newSupervisor->id,
            'appointment_type' => 'existing',
        ]));
        $application = Application::where('module_type', 'supervision')->sole();

        $this->actingAs($newSupervisor)->post(route('supervision.decide', $application), ['decision' => 'approve']);
        $this->actingAs($chair)->post(route('supervision.decide', $application), ['decision' => 'approve']);

        $this->assertSame($newSupervisor->id, $student->fresh()->supervisor_id);
        $detail = SupervisionDetail::where('application_id', $application->id)->sole();
        $this->assertSame($previousSupervisor->id, $detail->previous_supervisor_id);

        $this->actingAs($cgs)
            ->post(route('supervision.revoke', $application), ['revoked_reason' => 'Appointed in error.'])
            ->assertRedirect();

        $this->assertSame($previousSupervisor->id, $student->fresh()->supervisor_id);
        $detail->refresh();
        $this->assertTrue($detail->isRevoked());
        $this->assertNull($detail->letter_revoke_note);

        Notification::assertSentTo($student, AppointmentLetterRevoked::class);
        Notification::assertSentTo($newSupervisor, AppointmentLetterRevoked::class);
    }

    /**
     * If users.supervisor_id has since moved on to a LATER appointment,
     * revoking this one must not overwrite that later appointment --
     * see SupervisionController::revokeLetter().
     */
    public function test_revoking_a_main_supervisor_letter_does_not_overwrite_a_later_appointment(): void
    {
        Storage::fake('local');
        Notification::fake();

        $firstSupervisor = $this->supervisor('first@test.my');
        $secondSupervisor = $this->supervisor('second@test.my');
        $student = $this->student();
        $chair = $this->chair();

        // First appointment: the one we will revoke.
        $this->actingAs($student)->post(route('supervision.store'), $this->payload([
            'requested_supervisor_id' => $firstSupervisor->id,
        ]));
        $firstApplication = Application::where('module_type', 'supervision')->sole();
        $this->actingAs($firstSupervisor)->post(route('supervision.decide', $firstApplication), ['decision' => 'approve']);
        $this->actingAs($chair)->post(route('supervision.decide', $firstApplication), ['decision' => 'approve']);

        $this->assertSame($firstSupervisor->id, $student->fresh()->supervisor_id);

        // A second, later appointment supersedes the first.
        $this->actingAs($student)->post(route('supervision.store'), $this->payload([
            'requested_supervisor_id' => $secondSupervisor->id,
            'appointment_type' => 'existing',
        ]));
        $secondApplication = Application::where('id', '!=', $firstApplication->id)
            ->where('module_type', 'supervision')->sole();
        $this->actingAs($secondSupervisor)->post(route('supervision.decide', $secondApplication), ['decision' => 'approve']);
        $this->actingAs($chair)->post(route('supervision.decide', $secondApplication), ['decision' => 'approve']);

        $this->assertSame($secondSupervisor->id, $student->fresh()->supervisor_id);

        // Revoking the FIRST letter must not restore the student onto the
        // first supervisor, since the second appointment has superseded it.
        $cgs = $this->cgs();
        $firstDetail = SupervisionDetail::where('application_id', $firstApplication->id)->sole();

        $this->actingAs($cgs)
            ->post(route('supervision.revoke', $firstApplication), ['revoked_reason' => 'Housekeeping.'])
            ->assertRedirect();

        $this->assertSame($secondSupervisor->id, $student->fresh()->supervisor_id);

        $firstDetail->refresh();
        $this->assertTrue($firstDetail->isRevoked());
        $this->assertNotNull($firstDetail->letter_revoke_note);
    }
}
