<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * documents.view -- the in-app viewer's source (components/file-preview):
 * the same rule as documents.show, inline for what a browser can render,
 * a plain download for everything else.
 */
class DocumentViewTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected function document(string $name, string $bytes): ApplicationDocument
    {
        Storage::fake('local');
        $student = $this->student();
        $application = Application::create([
            'student_id' => $student->id, 'submitted_by_id' => $student->id,
            'module_type' => 'travel', 'status' => 'pending', 'current_stage' => 'supervisor', 'submitted_at' => now(),
        ]);
        Storage::disk('local')->put("docs/$name", $bytes);

        return ApplicationDocument::create(['application_id' => $application->id, 'doc_type' => 'Evidence', 'original_name' => $name, 'path' => "docs/$name"]);
    }

    public function test_a_pdf_opens_inline_for_its_owner_and_nobody_else(): void
    {
        $doc = $this->document('evidence.pdf', '%PDF-1.4 test');
        $owner = $doc->application->student;

        $this->actingAs($owner)->get($doc->viewUrl())
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename=evidence.pdf');

        $this->actingAs($this->student('22009999', 'other@test.my'))->get($doc->viewUrl())->assertForbidden();
    }

    public function test_a_word_file_is_downloaded_not_shown(): void
    {
        $doc = $this->document('letter.docx', 'PK-fake');

        $disposition = $this->actingAs($doc->application->student)->get($doc->viewUrl())
            ->assertOk()->headers->get('Content-Disposition');

        $this->assertStringStartsWith('attachment', $disposition);
    }

    public function test_every_page_carries_the_viewer(): void
    {
        $this->actingAs($this->student())->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="file-preview"', false);
    }
}
