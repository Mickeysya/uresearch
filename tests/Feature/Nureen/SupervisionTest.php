<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Nureen\Models\SupervisionDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Supervisor appointment requests — `docs/scope/nureen.md` Module 3.
 *
 * The scope asks for the request to carry "required documentation", which the
 * first cut did not enforce. Two of these cover that it is mandatory and that
 * the file lands on the private disk rather than anywhere a URL could reach.
 *
 * The third covers who may see a request at all. This queue is the one place
 * the engine's role-scoping is not enough: every supervisor-role account is
 * handed every request at the "supervisor" stage, because the student has no
 * supervisor_id yet for the engine to filter on -- this request is what sets
 * it. So the module narrows its own queue, and that narrowing is what stops a
 * supervisor reading a request addressed to a colleague.
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
}
