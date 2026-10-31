<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Database\Seeders\NureenClientCharterDemoSeeder;
use App\Modules\Nureen\Database\Seeders\NureenRealignedWorkflowDemoSeeder;
use App\Modules\Nureen\Database\Seeders\Support\DemoDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Nureen\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Feature\Nureen\Concerns\ParsesPdf;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Every document Nureen's demo seeders store is a PDF a viewer can open:
 * the supporting-document placeholders (Database\Seeders\Support\
 * DemoDocuments) and the generated letters alike. Runs both seeders against
 * the in-memory test database and a fake disk -- nothing reaches the dev
 * database or storage.
 */
class SeededDocumentsTest extends TestCase
{
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use ParsesPdf;
    use RefreshDatabase;

    public function test_every_seeded_document_is_a_valid_pdf_with_a_meaningful_name(): void
    {
        Storage::fake('local');

        // The accounts NureenClientCharterDemoSeeder expects DatabaseSeeder to have made.
        $this->user(Role::SUPERVISOR, ['email' => 'supervisor@utp.edu.my', 'name' => 'Dr. Aisyah Rahman', 'department' => 'Computing']);
        $this->user(Role::CHAIR, ['email' => 'chair@utp.edu.my', 'name' => 'Dr. Lim Wei Chun', 'department' => 'Computing']);
        $this->user(Role::NON_EXEC_CGS, ['email' => 'cgs@utp.edu.my', 'name' => 'M Syahmi Ifwat M Jafri']);
        $this->user(Role::SENIOR_DIRECTOR_CGS, ['email' => 'director@utp.edu.my', 'name' => 'En Zulkifly']);

        $this->seed(NureenRealignedWorkflowDemoSeeder::class);
        $this->seed(NureenClientCharterDemoSeeder::class);

        $documents = ApplicationDocument::all();
        $this->assertGreaterThan(30, $documents->count());

        foreach ($documents as $document) {
            $label = "#{$document->application_id} {$document->original_name}";
            $contents = Storage::disk('local')->get($document->path);

            $this->assertGreaterThanOrEqual(1, $this->pdfPageCount($contents, $label));
            $this->assertSame(strlen($contents), $document->size_bytes, $label);
            $this->assertNotSame('doc.pdf', $document->original_name);
            $this->assertStringEndsWith('.pdf', $document->original_name);
        }

        // Every seeded application carries what its own form requires.
        $demoDocuments = app(DemoDocuments::class);

        foreach (Application::whereIn('module_type', ['ga_extension', 'supervision', 'ga_certification'])->get() as $application) {
            $types = $application->documents()->pluck('doc_type')->all();

            foreach ($demoDocuments->requiredTypes($application) as $required) {
                $this->assertContains($required, $types, "#{$application->id} is missing \"{$required}\"");
            }
        }

        $this->assertTrue($documents->contains('original_name', 'Evidence of Work Done - Chong Wei Xuan.pdf'));
        $this->assertTrue($documents->contains('original_name', 'IC or Passport-Visa Copy - Nurul Izzah.pdf'));
    }

    public function test_the_parser_rejects_the_old_45_byte_stand_in(): void
    {
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);

        $this->pdfPageCount("%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    }
}
