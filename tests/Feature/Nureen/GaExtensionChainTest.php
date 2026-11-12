<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\ApplicationDecided;
use App\Modules\Core\Services\CgsAssignment;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\CgsVerdict;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Haziq\Models\GaApplicationDetail;
use App\Modules\Haziq\Workflows\GaApplicationWorkflow;
use App\Modules\Nureen\Models\GaExtensionDetail;
use App\Modules\Nureen\Notifications\ApplicationAwaitingYou;
use App\Modules\Nureen\Notifications\GaExtensionFinalDecisionCc;
use App\Modules\Nureen\Notifications\GaExtensionFinalDecisionInformational;
use App\Modules\Nureen\Support\ClientCharter;
use App\Modules\Nureen\Support\LetterRenderer;
use App\Modules\Nureen\Workflows\GaExtensionWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Nureen\Concerns\FilesGaExtensions;
use Tests\Feature\Nureen\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * GA Extension's default chain since 2026-11-12, confirmed by Puan Akmar
 * (Senior Executive CGS): Haziq's GA Application chain minus the Research
 * Centre interview -- Admin -> CGS -> Supervisor -> Senior Director.
 * CGS may return to Admin; the Senior Director to CGS or the supervisor.
 */
class GaExtensionChainTest extends TestCase
{
    use FilesGaExtensions;
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected User $student;

    protected User $supervisor;

    protected User $admin;

    protected User $cgs;

    protected User $director;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();

        $this->supervisor = $this->supervisor();
        $this->student = $this->student(attributes: ['supervisor_id' => $this->supervisor->id]);
        $this->approveGaApplication($this->student);
        $this->admin = $this->admin();
        $this->cgs = $this->cgs();
        $this->director = $this->seniorDirector();
    }

    protected function submit(): Application
    {
        $this->actingAs($this->student)->post(route('ga-extension.store'), $this->gaExtensionFields() + [
            'identity_document_documents' => [UploadedFile::fake()->create('ic.pdf', 40, 'application/pdf')],
            'admission_letter_documents' => [UploadedFile::fake()->create('admission-letter.pdf', 50, 'application/pdf')],
            'supervisor_letter_documents' => [UploadedFile::fake()->create('supervisor-letter.pdf', 90, 'application/pdf')],
        ])->assertSessionHasNoErrors();

        return Application::where('module_type', 'ga_extension')->latest('id')->first();
    }

    protected function decide(User $as, Application $application, string $decision = 'approve', ?string $remarks = null): void
    {
        $this->actingAs($as)->post(route('ga-extension.decide', $application), array_filter(['decision' => $decision, 'remarks' => $remarks]));
    }

    protected function at(Application $application): string
    {
        $application->refresh();

        return $application->status === Application::STATUS_PENDING ? $application->current_stage : $application->status;
    }

    /* -----------------------------------------------------------------
     | The chain itself
     |------------------------------------------------------------------*/

    public function test_the_chain_is_haziqs_ga_application_chain_without_the_interview(): void
    {
        // Read from his public workflow, not copied: the roles in order,
        // skipping the Research Centre interview.
        $theirs = collect((new GaApplicationWorkflow)->stages())
            ->reject(fn (Stage $s) => $s->key === 'research_centre_interview')
            ->map(fn (Stage $s) => $s->role)->values()->all();

        $application = $this->submit();
        $ours = collect(app(WorkflowEngine::class)->stagesFor($application));

        $this->assertSame($theirs, $ours->map(fn (Stage $s) => $s->role)->all());
        $this->assertSame(['admin_verify', 'cgs_verify', 'supervisor', 'senior_director'], $ours->map(fn (Stage $s) => $s->key)->all());
        $this->assertSame(['verified', 'reviewed', 'endorsed', 'approved'], $ours->map(fn (Stage $s) => $s->decision)->all());
        $this->assertSame('ga_application', GaExtensionDetail::where('application_id', $application->id)->value('final_approver_snapshot'));
    }

    public function test_an_application_submitted_under_an_older_chain_keeps_it(): void
    {
        config(['nureen.ga_extension.final_approver' => 'cgs']);
        $old = $this->submit();
        config(['nureen.ga_extension.final_approver' => 'ga_application']);

        $this->assertSame(['admin_verify', 'supervisor', 'cgs_verify'], collect(app(WorkflowEngine::class)->stagesFor($old->fresh()))->map(fn (Stage $s) => $s->key)->all());

        $this->decide($this->admin, $old);
        $this->assertSame('supervisor', $this->at($old));
    }

    /* -----------------------------------------------------------------
     | Walking it, with the emails at every stage
     |------------------------------------------------------------------*/

    public function test_the_full_chain_emails_whoever_acts_next_and_ends_with_the_senior_director(): void
    {
        $application = $this->submit();
        $this->assertSame('admin_verify', $this->at($application));
        Notification::assertSentTo($this->admin, ApplicationAwaitingYou::class);
        Notification::assertNotSentTo($this->supervisor, ApplicationAwaitingYou::class);

        $this->decide($this->admin, $application);
        $this->assertSame('cgs_verify', $this->at($application));
        Notification::assertSentTo($this->cgs, ApplicationAwaitingYou::class);

        $this->decide($this->cgs, $application);
        $this->assertSame('supervisor', $this->at($application));
        Notification::assertSentTo($this->supervisor, ApplicationAwaitingYou::class);

        $this->decide($this->supervisor, $application);
        $this->assertSame('senior_director', $this->at($application));
        Notification::assertSentTo($this->director, ApplicationAwaitingYou::class);

        $this->decide($this->director, $application);
        $this->assertSame(Application::STATUS_APPROVED, $this->at($application));

        // A progress email to the student at every one of the four stages.
        Notification::assertSentToTimes($this->student, ApplicationDecided::class, 4);
        // The final outcome copies the endorsing supervisor; the Senior
        // Director decided it, so no "for information" copy to them.
        Notification::assertSentTo($this->supervisor, GaExtensionFinalDecisionCc::class);
        Notification::assertNotSentTo($this->director, GaExtensionFinalDecisionInformational::class);

        // An approval still moves the GA application's end date.
        $this->assertSame(
            GaExtensionDetail::where('application_id', $application->id)->value('requested_new_end_date')->toDateString(),
            GaApplicationDetail::query()->latest('id')->value('service_end_date')->toDateString(),
        );
    }

    public function test_cgs_is_no_longer_final_and_a_senior_director_rejection_closes_it(): void
    {
        $application = $this->submit();
        $this->decide($this->admin, $application);
        $this->decide($this->cgs, $application);
        $this->assertSame(Application::STATUS_PENDING, $application->fresh()->status);

        $this->decide($this->supervisor, $application);
        $this->decide($this->director, $application, 'reject', 'The GA maximum would be exceeded.');

        $this->assertSame(Application::STATUS_REJECTED, $this->at($application));
        Notification::assertSentTo($this->supervisor, GaExtensionFinalDecisionCc::class);
    }

    /* -----------------------------------------------------------------
     | Returns
     |------------------------------------------------------------------*/

    public function test_cgs_returns_to_admin_with_a_reason_and_nowhere_else(): void
    {
        $application = $this->submit();
        $this->decide($this->admin, $application);

        $this->actingAs($this->cgs)->post(route('ga-extension.return', [$application, 'to' => 'admin_verify']), ['remarks' => ''])
            ->assertSessionHasErrors('remarks');
        $this->actingAs($this->cgs)->post(route('ga-extension.return', [$application, 'to' => 'supervisor']), ['remarks' => 'Wrong target.'])
            ->assertSessionHasErrors('to');
        $this->assertSame('cgs_verify', $this->at($application));

        $this->actingAs($this->cgs)->post(route('ga-extension.return', [$application, 'to' => 'admin_verify']), ['remarks' => 'The admission letter is unsigned.'])
            ->assertSessionHas('status', "Application #{$application->id} sent back to Admin. They have been notified.");
        $this->assertSame('admin_verify', $this->at($application));
        $this->assertSame(Application::STATUS_PENDING, $application->status);
    }

    public function test_the_senior_director_returns_to_cgs_or_to_the_supervisor(): void
    {
        foreach (['cgs_verify' => 'CGS', 'supervisor' => 'the supervisor'] as $to => $who) {
            $application = $this->submit();
            $this->decide($this->admin, $application);
            $this->decide($this->cgs, $application);
            $this->decide($this->supervisor, $application);

            $this->actingAs($this->director)->post(route('ga-extension.return', [$application, 'to' => $to]), ['remarks' => 'Please check the period again.'])
                ->assertSessionHas('status', "Application #{$application->id} sent back to {$who}. They have been notified.");
            $this->assertSame($to, $this->at($application));
        }
    }

    public function test_admin_and_the_supervisor_have_no_return(): void
    {
        $application = $this->submit();
        $workflow = app(GaExtensionWorkflow::class);
        $this->assertSame([], $workflow->returnTargets($application->fresh()));

        $this->decide($this->admin, $application);
        $this->decide($this->cgs, $application);
        $this->assertSame([], $workflow->returnTargets($application->fresh()));

        $this->actingAs($this->supervisor)->post(route('ga-extension.return', $application), ['remarks' => 'Trying anyway.'])
            ->assertSessionHas('error', 'That application is not at a stage you can send back.');
        $this->assertSame('supervisor', $this->at($application));
    }

    /* -----------------------------------------------------------------
     | The CGS officer assignment
     |------------------------------------------------------------------*/

    public function test_the_cgs_assignment_applies_at_cgs_verify_and_not_at_admin(): void
    {
        $officer = $this->user(Role::NON_EXEC_CGS, ['name' => 'Zulkifly', 'email' => 'zulkifly.cgs@utp.edu.my']);
        $application = $this->submit();

        // Admin's stage: the map has no say.
        $this->assertSame(CgsVerdict::NOT_APPLICABLE, app(CgsAssignment::class)->verdict($this->cgs, $application->fresh())->reason);
        $this->decide($this->admin, $application);
        $this->assertSame('cgs_verify', $this->at($application));
        Notification::assertSentTo($officer, ApplicationAwaitingYou::class);
        Notification::assertNotSentTo($this->cgs, ApplicationAwaitingYou::class);

        // At cgs_verify only Zulkifly decides -- or returns.
        $this->actingAs($this->cgs)->post(route('ga-extension.decide', $application), ['decision' => 'approve'])->assertForbidden();
        $this->actingAs($this->cgs)->post(route('ga-extension.return', [$application, 'to' => 'admin_verify']), ['remarks' => 'Not mine.'])->assertForbidden();
        $this->assertSame('cgs_verify', $this->at($application));

        $this->decide($officer, $application);
        $this->assertSame('supervisor', $this->at($application));
    }

    /* -----------------------------------------------------------------
     | Client Charter and the generated form
     |------------------------------------------------------------------*/

    public function test_the_senior_director_stage_has_no_charter_figure_yet(): void
    {
        $this->assertNull(config('nureen.charter_days.ga_extension.senior_director'));
        $this->assertSame([2, 2, 3], [config('nureen.charter_days.ga_extension.admin_verify'), config('nureen.charter_days.ga_extension.cgs_verify'), config('nureen.charter_days.ga_extension.supervisor')]);

        $application = $this->submit();
        $charter = app(ClientCharter::class);

        // With the Senior Director still ahead, no expected decision date.
        $this->assertNull($charter->expectedDecision($application->fresh())['expected_date'] ?? null);

        foreach ([$this->admin, $this->cgs, $this->supervisor] as $who) {
            $this->decide($who, $application);
        }
        $clock = $charter->stageClock($application->fresh());
        $this->assertSame('senior_director', $clock['stage']->key);
        $this->assertFalse($clock['on_charter']);
    }

    public function test_the_generated_form_names_each_approver_in_its_own_column(): void
    {
        $application = $this->submit();
        foreach ([$this->admin, $this->cgs, $this->supervisor, $this->director] as $who) {
            $this->decide($who, $application);
        }

        $e = app(LetterRenderer::class)->gaExtensionFormData($application->fresh(), $this->director)['endorsements'];

        $this->assertSame($this->supervisor->name, $e['supervisor']['name']);
        $this->assertSame('Endorsed', $e['supervisor']['decision']);
        $this->assertSame($this->cgs->name, $e['cgs']['name']);
        $this->assertSame('Reviewed', $e['cgs']['decision']);
        $this->assertSame($this->director->name, $e['senior_director']['name']);
        $this->assertSame('Approved', $e['senior_director']['decision']);
    }
}
