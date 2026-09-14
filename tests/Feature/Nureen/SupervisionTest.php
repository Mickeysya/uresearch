<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Supervisor appointment requests — `docs/scope/nureen.md` Module 3.
 *
 * The scope asks for the request to carry "required documentation", which the
 * first cut did not enforce. These two cover that it is mandatory and that the
 * file lands on the private disk rather than anywhere a URL could reach.
 */
class SupervisionTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    public function test_a_supervision_request_requires_a_document(): void
    {
        $supervisor = $this->supervisor();

        $this->actingAs($this->student());

        $this->post(route('supervision.store'), [
            'requested_supervisor_id' => $supervisor->id,
            'justification' => 'We share a research interest in reservoir simulation and modelling.',
        ])->assertSessionHasErrors('supporting_document');

        $this->assertSame(0, Application::where('module_type', 'supervision')->count());
    }

    public function test_a_supervision_request_with_a_document_is_submitted_and_the_file_attached(): void
    {
        Storage::fake('local');

        $supervisor = $this->supervisor();

        $this->actingAs($this->student());

        $this->post(route('supervision.store'), [
            'requested_supervisor_id' => $supervisor->id,
            'justification' => 'We share a research interest in reservoir simulation and modelling.',
            'supporting_document' => UploadedFile::fake()->create('proposal.pdf', 120, 'application/pdf'),
        ])->assertRedirect(route('applications.index'));

        $application = Application::where('module_type', 'supervision')->sole();

        $this->assertSame(Application::STATUS_PENDING, $application->status);
        $this->assertSame('supervisor', $application->current_stage);

        $document = $application->documents()->sole();
        $this->assertSame('proposal.pdf', $document->original_name);
        // Private disk, random name -- never under public/.
        $this->assertStringStartsWith("applications/{$application->id}/", $document->path);
        $this->assertStringNotContainsString('proposal', $document->path);
    }
}
