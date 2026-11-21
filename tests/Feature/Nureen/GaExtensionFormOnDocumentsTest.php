<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Models\GaExtensionDetail;
use App\Modules\Nureen\Support\GaExtensionFormAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Nureen\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The generated GA Extension Form is never stored, so the Documents page
 * lists it from GaExtensionWorkflow::generatedDocuments() -- only for users
 * the form's own routes let in (GaExtensionController::canViewForm(), now
 * Support\GaExtensionFormAccess) -- and an old uploaded "GA Extension Form"
 * is labelled as the old upload.
 */
class GaExtensionFormOnDocumentsTest extends TestCase
{
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected User $supervisor;

    protected User $student;

    protected Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->supervisor = $this->supervisor();
        $this->student = $this->student();
        $this->student->update(['supervisor_id' => $this->supervisor->id]);

        $this->application = Application::create([
            'student_id' => $this->student->id, 'submitted_by_id' => $this->student->id, 'module_type' => 'ga_extension',
            'status' => Application::STATUS_PENDING, 'current_stage' => 'cgs_verify', 'submitted_at' => now()->subDay(),
        ]);
        GaExtensionDetail::create([
            'application_id' => $this->application->id, 'supervisor_id' => $this->supervisor->id,
            'current_end_date' => '2026-12-31', 'requested_new_end_date' => '2027-06-30',
            'reason_for_extension' => 'Thesis writing.', 'final_approver_snapshot' => 'cgs',
        ]);
        ApplicationDocument::create([
            'application_id' => $this->application->id, 'doc_type' => "Supervisor's Supporting Letter",
            'original_name' => 'support.pdf', 'path' => 'x', 'size_bytes' => 1,
        ]);
    }

    public function test_the_generated_form_is_listed_only_for_users_can_view_form_allows(): void
    {
        $formView = route('ga-extension.form-pdf.view', $this->application);

        $listsTheForm = function (User $user) use ($formView) {
            $this->assertTrue(GaExtensionFormAccess::allows($user, $this->application), $user->role);
            $this->actingAs($user)->get(route('documents.index', ['range' => 'all']))
                ->assertOk()
                ->assertSee('GA Extension Form (generated)')
                ->assertSee('data-preview-url="'.e($formView).'"', false);
            $this->actingAs($user)->get($formView)->assertOk();
        };

        // Allowed: the owner and the CGS stage's role, while it waits at CGS.
        $listsTheForm($this->student);
        $listsTheForm($this->cgs());

        // The named supervisor: the entry sits beside the application's
        // files, so it shows once they can see those -- at their own stage.
        $this->application->forceFill(['current_stage' => 'supervisor'])->save();
        $listsTheForm($this->supervisor);

        // Not allowed: a role with no stage on this chain, the Dean of PGR.
        $dean = $this->dean();
        $this->assertFalse(GaExtensionFormAccess::allows($dean, $this->application));
        $this->actingAs($dean)->get(route('documents.index', ['range' => 'all']))
            ->assertOk()->assertDontSee('GA Extension Form (generated)');
        $this->actingAs($dean)->get($formView)->assertForbidden();
    }

    public function test_the_page_agrees_with_the_route_for_every_role(): void
    {
        $users = [$this->student, $this->supervisor, $this->cgs(), $this->dean(), $this->admin(),
            $this->seniorDirector(), $this->chair(), $this->student('22009999', 'other@test.my')];

        foreach ($users as $user) {
            $listed = str_contains(
                $this->actingAs($user)->get(route('documents.index', ['range' => 'all']))->assertOk()->getContent(),
                'GA Extension Form (generated)');
            $status = $this->actingAs($user)->get(route('ga-extension.form-pdf.view', $this->application))->status();

            // Listed => the route serves it. (Not listed can also mean the
            // user sees no stored file on this application at all.)
            if ($listed) {
                $this->assertSame(200, $status, "{$user->role} is shown the form but cannot open it");
            }
            if ($status === 403) {
                $this->assertFalse($listed, "{$user->role} is shown a form the route refuses");
            }
        }
    }

    public function test_an_old_uploaded_form_is_labelled_as_the_old_upload(): void
    {
        ApplicationDocument::create([
            'application_id' => $this->application->id, 'doc_type' => config('nureen.ga_extension.legacy_documents.form'),
            'original_name' => 'my-old-form.pdf', 'path' => 'x', 'size_bytes' => 1,
        ]);

        $this->actingAs($this->student)->get(route('documents.index', ['range' => 'all']))
            ->assertOk()
            ->assertSee('GA Extension Form (old upload)')
            ->assertSee('my-old-form.pdf')
            ->assertSee('GA Extension Form (generated)');
    }
}
