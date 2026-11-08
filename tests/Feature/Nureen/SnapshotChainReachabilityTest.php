<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Models\GaExtensionDetail;
use App\Modules\Nureen\Models\SupervisionDetail;
use App\Modules\Nureen\Notifications\ApplicationStalled;
use App\Modules\Nureen\Workflows\GaExtensionWorkflow;
use App\Modules\Nureen\Workflows\SupervisionWorkflow;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesUsers;
use Tests\Feature\Nureen\Concerns\FilesGaExtensions;
use Tests\Feature\Nureen\Concerns\KeepsJobsOffTheDevQueue;
use Tests\TestCase;

/**
 * An application runs the chain snapshotted onto it at submission, so the
 * role holding its current stage must be able to reach it whatever chain
 * the config currently defaults to. Core's ModuleRegistry::queuesForRole()
 * builds queues from stages(null), which per Core's contract is "every
 * stage this module can use", not the live default chain.
 *
 * Also: GA Extension's supervisor stage belongs to the student's own
 * supervisor, snapshotted at submission, the same as Supervision and
 * Certification -- not to everyone holding the Supervisor role.
 */
class SnapshotChainReachabilityTest extends TestCase
{
    use FilesGaExtensions;
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected function gaExtension(User $student, string $stage, string $mode, ?int $supervisorId = null): Application
    {
        $application = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'ga_extension',
            'status' => Application::STATUS_PENDING,
            'current_stage' => $stage,
            'submitted_at' => now()->subDays(10),
        ]);
        GaExtensionDetail::create([
            'application_id' => $application->id,
            'supervisor_id' => $supervisorId ?? $student->supervisor_id,
            'current_end_date' => '2026-12-31',
            'requested_new_end_date' => '2027-06-30',
            'reason_for_extension' => 'Submitted under the old three-stage chain.',
            'final_approver_snapshot' => $mode,
        ]);

        return $application;
    }

    /* -----------------------------------------------------------------
     | stages(null) is the superset; stages($application) the snapshot
     |------------------------------------------------------------------*/

    public function test_stages_without_an_application_cover_both_chains_of_each_module(): void
    {
        $keys = fn (array $stages) => array_map(fn ($s) => $s->key, $stages);

        config(['nureen.ga_extension.final_approver' => 'cgs', 'nureen.supervision.cgs_stage' => 'automatic']);
        $this->assertSame(['supervisor', 'cgs_verify', 'senior_director'], $keys(app(GaExtensionWorkflow::class)->stages()));
        $this->assertSame(['supervisor', 'chair', 'cgs_review'], $keys(app(SupervisionWorkflow::class)->stages()));

        // Per-application chains are still the snapshot, never the superset.
        $student = $this->student(attributes: ['supervisor_id' => $this->supervisor()->id]);
        $this->assertSame(['supervisor', 'cgs_verify'], $keys(app(GaExtensionWorkflow::class)->stages($this->gaExtension($student, 'supervisor', 'cgs'))));
    }

    /* -----------------------------------------------------------------
     | GA Extension: an old 'senior_director' application in 'cgs' mode
     |------------------------------------------------------------------*/

    public function test_the_senior_director_can_see_and_decide_an_old_ga_extension_in_cgs_default_mode(): void
    {
        config(['nureen.ga_extension.final_approver' => 'cgs']);
        Notification::fake();

        $director = $this->user(Role::SENIOR_DIRECTOR_CGS);
        $student = $this->student('22007777', attributes: ['supervisor_id' => $this->supervisor()->id]);
        $application = $this->gaExtension($student, 'senior_director', 'senior_director');

        $this->assertContains('senior_director', collect(app(ModuleRegistry::class)->queuesForRole(Role::SENIOR_DIRECTOR_CGS))
            ->filter(fn ($q) => $q['module']->key() === 'ga_extension')
            ->map(fn ($q) => $q['stage']->key)
            ->all());

        $this->actingAs($director)
            ->get(route('ga-extension.queue', ['stage' => 'senior_director']))
            ->assertOk()
            ->assertSee('22007777');

        $this->actingAs($director)
            ->post(route('ga-extension.decide', $application), ['decision' => 'approve'])
            ->assertSessionHas('status');

        $this->assertSame(Application::STATUS_APPROVED, $application->fresh()->status);
    }

    public function test_a_new_ga_extension_still_ends_at_cgs_in_cgs_mode(): void
    {
        config(['nureen.ga_extension.final_approver' => 'cgs']);
        Notification::fake();

        $student = $this->student(attributes: ['supervisor_id' => $this->supervisor()->id]);
        $application = $this->gaExtension($student, 'cgs_verify', 'cgs');

        $this->actingAs($this->cgs())->post(route('ga-extension.decide', $application), ['decision' => 'approve']);

        // The superset adds a Senior Director stage to queues, never to a
        // 'cgs' application's own chain.
        $this->assertSame(Application::STATUS_APPROVED, $application->fresh()->status);
    }

    /* -----------------------------------------------------------------
     | Supervision: an old 'manual' application in 'automatic' mode
     |------------------------------------------------------------------*/

    public function test_cgs_can_see_and_decide_an_old_supervision_request_at_cgs_review_in_automatic_mode(): void
    {
        config(['nureen.supervision.cgs_stage' => 'automatic']);
        Storage::fake('local');
        Notification::fake();

        $cgs = $this->cgs();
        $supervisor = $this->supervisor();
        $student = $this->student('22008888');

        $application = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'supervision',
            'status' => Application::STATUS_PENDING,
            'current_stage' => 'cgs_review',
            'submitted_at' => now()->subDays(10),
        ]);
        SupervisionDetail::create([
            'application_id' => $application->id,
            'requested_supervisor_id' => $supervisor->id,
            'justification' => 'Submitted before the Chair stage was final.',
            'appointment_role' => SupervisionDetail::ROLE_MAIN,
            'cgs_stage_snapshot' => 'manual',
        ]);

        $this->actingAs($cgs)
            ->get(route('supervision.queue', ['stage' => 'cgs_review']))
            ->assertOk()
            ->assertSee('22008888');

        $this->actingAs($cgs)->post(route('supervision.decide', $application), ['decision' => 'approve']);

        $this->assertSame(Application::STATUS_APPROVED, $application->fresh()->status);
        // CGS's approval is final on a 'manual' application, so it issues the letter.
        $this->assertNotNull(SupervisionDetail::where('application_id', $application->id)->sole()->letter_reference);
        $this->assertSame($supervisor->id, $student->fresh()->supervisor_id);
    }

    /* -----------------------------------------------------------------
     | GA Extension's supervisor stage: the student's own supervisor only
     |------------------------------------------------------------------*/

    public function test_a_different_supervisor_cannot_see_or_decide_another_students_ga_extension(): void
    {
        Notification::fake();

        $own = $this->supervisor('own@test.my');
        $other = $this->supervisor('other@test.my');
        $student = $this->student('22009999', attributes: ['supervisor_id' => $own->id]);
        $application = $this->gaExtension($student, 'supervisor', 'cgs');

        $this->actingAs($other)
            ->get(route('ga-extension.queue', ['stage' => 'supervisor']))
            ->assertOk()
            ->assertDontSee('22009999');

        $this->actingAs($other)
            ->post(route('ga-extension.decide', $application), ['decision' => 'approve'])
            ->assertForbidden();
        $this->assertSame('supervisor', $application->fresh()->current_stage);

        $this->actingAs($own)
            ->get(route('ga-extension.queue', ['stage' => 'supervisor']))
            ->assertSee('22009999');

        $this->actingAs($own)->post(route('ga-extension.decide', $application), ['decision' => 'approve']);
        $this->assertSame('cgs_verify', $application->fresh()->current_stage);
    }

    public function test_the_application_stays_with_the_supervisor_named_at_submission(): void
    {
        Notification::fake();

        $original = $this->supervisor('original@test.my');
        $replacement = $this->supervisor('replacement@test.my');
        $student = $this->student(attributes: ['supervisor_id' => $original->id]);
        $application = $this->gaExtension($student, 'supervisor', 'cgs');

        $student->update(['supervisor_id' => $replacement->id]);

        $this->actingAs($replacement)
            ->post(route('ga-extension.decide', $application), ['decision' => 'approve'])
            ->assertForbidden();
    }

    public function test_submission_snapshots_the_students_supervisor_and_refuses_without_one(): void
    {
        Storage::fake('local');
        Notification::fake();

        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);
        $this->approveGaApplication($student);

        $this->actingAs($student)->post(route('ga-extension.store'), $this->payload());
        $this->assertSame($supervisor->id, GaExtensionDetail::sole()->supervisor_id);

        $unassigned = $this->student('22001002', 'unassigned@test.my');
        $this->approveGaApplication($unassigned);
        $this->actingAs($unassigned)
            ->post(route('ga-extension.store'), $this->payload())
            ->assertSessionHas('error');
        $this->assertSame(1, Application::where('module_type', 'ga_extension')->count());
    }

    public function test_the_supervisor_stage_reminder_goes_only_to_the_named_supervisor(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-11-06 10:00'));

        $own = $this->supervisor('own@test.my');
        $other = $this->supervisor('other@test.my');
        $student = $this->student(attributes: ['supervisor_id' => $own->id]);
        $this->gaExtension($student, 'supervisor', 'cgs');

        $this->artisan('ga-extension:remind-stalled')->assertSuccessful();

        Notification::assertSentTo($own, ApplicationStalled::class);
        Notification::assertNotSentTo($other, ApplicationStalled::class);
    }

    protected function payload(): array
    {
        return $this->gaExtensionFields(['reason_for_extension' => 'Fieldwork delayed by flooding; analysis needs one more semester.'])
            + $this->gaExtensionDocuments();
    }
}
