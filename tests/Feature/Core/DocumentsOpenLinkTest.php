<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Models\GaExtensionDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Nureen\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The Documents page's "Open application" link goes to a page the viewer's
 * role may open -- or is not shown. It used to be applications.show for
 * everyone: students-only, so every member of staff got a 403.
 */
class DocumentsOpenLinkTest extends TestCase
{
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    private function file(Application $application, string $name): void
    {
        ApplicationDocument::create([
            'application_id' => $application->id, 'doc_type' => 'Supporting Document',
            'original_name' => $name, 'path' => 'x', 'size_bytes' => 1,
        ]);
    }

    /** @return list<string> every "Open application" href on the page */
    private function openLinks(TestResponse $response): array
    {
        preg_match_all('#<a href="([^"]+)" data-region-swap-ignore>Open application</a>#', $response->getContent(), $m);

        return array_map('html_entity_decode', $m[1]);
    }

    private function assertOpens(User $user, string $file): string
    {
        $response = $this->actingAs($user)->get(route('documents.index', ['q' => $file]))->assertOk()->assertSee($file);
        $links = $this->openLinks($response);
        $this->assertCount(1, $links, "{$user->role} should get one link");
        $this->actingAs($user)->get($links[0])->assertOk();

        return $links[0];
    }

    public function test_the_student_a_cgs_officer_and_a_supervisor_each_get_a_link_that_opens(): void
    {
        $supervisor = $this->supervisor();
        $student = $this->student();
        $student->update(['supervisor_id' => $supervisor->id]);

        // Nureen's GA Extension, waiting at Non-Executive CGS (syahmi.cgs's role).
        $gaex = Application::create([
            'student_id' => $student->id, 'submitted_by_id' => $student->id, 'module_type' => 'ga_extension',
            'status' => Application::STATUS_PENDING, 'current_stage' => 'cgs_verify', 'submitted_at' => now()->subDay(),
        ]);
        GaExtensionDetail::create([
            'application_id' => $gaex->id, 'supervisor_id' => $supervisor->id,
            'current_end_date' => '2026-12-31', 'requested_new_end_date' => '2027-06-30',
            'reason_for_extension' => 'Thesis writing.', 'final_approver_snapshot' => 'cgs',
        ]);
        $this->file($gaex, 'gaex-letter.pdf');

        // Norhanis's Travel, waiting at the supervisor: a module with no
        // staff page contract, so the link is its queue for that stage.
        $travel = Application::create([
            'student_id' => $student->id, 'module_type' => 'travel',
            'status' => Application::STATUS_PENDING, 'current_stage' => 'supervisor', 'submitted_at' => now()->subDay(),
        ]);
        $this->file($travel, 'travel-letter.pdf');

        $cgs = $this->cgs();
        $this->assertSame(Role::NON_EXEC_CGS, $cgs->role);
        $this->assertSame(route('nureen.application', $gaex), $this->assertOpens($cgs, 'gaex-letter.pdf'));

        $this->assertSame(route('travel.queue', ['stage' => 'supervisor']), $this->assertOpens($supervisor, 'travel-letter.pdf'));

        $this->assertSame(route('applications.show', $gaex), $this->assertOpens($student, 'gaex-letter.pdf'));
        $this->assertSame(route('applications.show', $travel), $this->assertOpens($student, 'travel-letter.pdf'));
    }

    public function test_someone_who_can_see_the_file_but_open_no_page_gets_no_link(): void
    {
        $supervisor = $this->supervisor();
        $student = $this->student();

        // The supervisor endorsed it and it has moved on to the Chair: the
        // file stays visible to them (they decided on it), but Travel has no
        // page for a supervisor once it has left their stage.
        $travel = Application::create([
            'student_id' => $student->id, 'module_type' => 'travel',
            'status' => Application::STATUS_PENDING, 'current_stage' => 'chair', 'submitted_at' => now()->subDay(),
        ]);
        ApprovalHistory::create([
            'application_id' => $travel->id, 'approver_id' => $supervisor->id,
            'stage_key' => 'supervisor', 'stage_label' => 'Supervisor', 'decision' => 'endorsed',
        ]);
        $this->file($travel, 'moved-on.pdf');

        $response = $this->actingAs($supervisor)->get(route('documents.index'))->assertOk()->assertSee('moved-on.pdf');
        $this->assertSame([], $this->openLinks($response));
        $response->assertDontSee('Open application');
    }
}
