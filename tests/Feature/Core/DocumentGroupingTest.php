<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Http\Controllers\DocumentLibraryController;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The Documents page, grouped by application.
 *
 * Grouping changes the page's shape only. The visibility tests here are the
 * ones that matter: every query behind the page still starts from
 * ApplicationDocument::scopeVisibleTo(), and these assert it, for a student
 * and for a CGS officer, rather than assume it.
 */
class DocumentGroupingTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    private function application(User $student, string $module = 'travel', array $attributes = []): Application
    {
        return Application::create($attributes + [
            'student_id' => $student->id,
            'module_type' => $module,
            'status' => Application::STATUS_DRAFT,
        ]);
    }

    private function file(Application $application, string $name, string $type = 'Supporting Document', $at = null): ApplicationDocument
    {
        $doc = ApplicationDocument::create([
            'application_id' => $application->id, 'doc_type' => $type,
            'original_name' => $name, 'path' => 'x', 'size_bytes' => 2048,
        ]);

        if ($at) {
            $doc->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        }

        return $doc;
    }

    public function test_files_from_one_application_appear_in_one_group(): void
    {
        $student = $this->student();
        $first = $this->application($student);
        $second = $this->application($student);
        $this->file($first, 'letter.pdf', "Supervisor's Supporting Letter");
        $this->file($first, 'form.pdf', 'GA Extension Form');
        $this->file($second, 'receipt.pdf');

        $response = $this->actingAs($student)->get(route('documents.index'))->assertOk();

        $groups = $response->viewData('groups');
        $this->assertSame(2, $groups->total());
        $byApp = $groups->getCollection()->keyBy(fn ($g) => $g->application->id);
        $this->assertEqualsCanonicalizing(['letter.pdf', 'form.pdf'], $byApp[$first->id]->documents->pluck('original_name')->all());
        $this->assertSame(['receipt.pdf'], $byApp[$second->id]->documents->pluck('original_name')->all());

        // One <details> per application, and the type is the file's title.
        $html = $response->getContent();
        $this->assertSame(2, substr_count($html, '<details class="doc-group"'));
        $response->assertSee('2 applications')->assertSee('3 files')
            ->assertSee($first->reference())
            ->assertSee("Supervisor's Supporting Letter");
    }

    public function test_a_student_still_sees_only_their_own_applications(): void
    {
        $mine = $this->student('22001001', 'a@test.my');
        $theirs = $this->student('22001002', 'b@test.my', ['name' => 'Siti Other']);
        $this->file($this->application($mine), 'mine.pdf');
        $other = $this->application($theirs);
        $this->file($other, 'theirs.pdf');

        $this->actingAs($mine)->get(route('documents.index'))
            ->assertOk()->assertSee('mine.pdf')
            ->assertDontSee('theirs.pdf')->assertDontSee($other->reference());

        // Searching for them by name or reference does not reach them either.
        $this->actingAs($mine)->get(route('documents.index', ['q' => 'Siti Other']))
            ->assertOk()->assertDontSee('theirs.pdf');
        $this->actingAs($mine)->get(route('documents.index', ['q' => $other->reference()]))
            ->assertOk()->assertDontSee('theirs.pdf');
    }

    public function test_a_cgs_officer_sees_only_applications_at_a_cgs_stage_or_that_they_decided(): void
    {
        $cgs = $this->cgs();
        $student = $this->student();
        $student->update(['supervisor_id' => $this->supervisor()->id]);

        // Somewhere CGS holds: the first CGS stage a bare application can sit on.
        $held = null;
        foreach (app(ModuleRegistry::class)->queuesForRole((string) $cgs->role) as $queue) {
            $candidate = $this->application($student, $queue['module']->key(), [
                'status' => Application::STATUS_PENDING, 'current_stage' => $queue['stage']->key,
            ]);
            if ($candidate->currentStage()) {
                $held = $candidate;
                break;
            }
            $candidate->delete();
        }
        $this->assertNotNull($held, 'No module routes to the CGS role');
        $this->file($held, 'at-cgs.pdf');

        // Another module, still with the supervisor: not CGS's yet.
        $elsewhere = $this->application($student, 'travel', [
            'status' => Application::STATUS_PENDING, 'current_stage' => 'supervisor',
        ]);
        $this->file($elsewhere, 'with-supervisor.pdf');

        // Finished in a module CGS never touched.
        $finished = $this->application($student, 'travel', ['status' => Application::STATUS_APPROVED]);
        $this->file($finished, 'finished.pdf');

        $response = $this->actingAs($cgs)->get(route('documents.index', ['range' => 'all']))->assertOk();
        $response->assertSee('at-cgs.pdf')->assertDontSee('with-supervisor.pdf')->assertDontSee('finished.pdf');
        $this->assertSame(1, $response->viewData('groups')->total());
        $this->assertSame(1, $response->viewData('fileTotal'));

        // And a search cannot widen it.
        $this->actingAs($cgs)->get(route('documents.index', ['q' => 'with-supervisor']))
            ->assertOk()->assertDontSee('with-supervisor.pdf');

        // Every file the page lists is one the download route would serve.
        $listed = $response->viewData('groups')->getCollection()->flatMap(fn ($g) => $g->documents);
        $this->assertTrue($listed->every(fn ($doc) => $doc->visibleTo($cgs)));
    }

    public function test_search_finds_by_student_name_reference_and_file_name(): void
    {
        $aisyah = $this->student('22001001', 'a@test.my', ['name' => 'Nur Aisyah Binti Kamal']);
        $haris = $this->student('22001002', 'b@test.my', ['name' => 'Haris Iskandar']);

        // Admins see everything, so the search is the only thing filtering.
        $admin = $this->admin();
        $one = $this->application($aisyah);
        $two = $this->application($haris);
        $this->file($one, 'aisyah-letter.pdf');
        $this->file($one, 'aisyah-transcript.pdf', 'Academic Transcript');
        $this->file($two, 'haris-receipt.pdf');

        // Student name: the whole application, open.
        $byName = $this->actingAs($admin)->get(route('documents.index', ['q' => 'aisyah binti']))->assertOk();
        $byName->assertSee('aisyah-letter.pdf')->assertSee('aisyah-transcript.pdf')->assertDontSee('haris-receipt.pdf');
        $this->assertStringContainsString('<details class="doc-group"  open', $byName->getContent());

        // Reference.
        $this->actingAs($admin)->get(route('documents.index', ['q' => $two->reference()]))
            ->assertOk()->assertSee('haris-receipt.pdf')->assertDontSee('aisyah-letter.pdf');

        // File name: the application opens, the matching file is highlighted,
        // and its sibling is still listed but not highlighted.
        $byFile = $this->actingAs($admin)->get(route('documents.index', ['q' => 'transcript']))->assertOk();
        $byFile->assertSee('aisyah-transcript.pdf')->assertSee('aisyah-letter.pdf')->assertDontSee('haris-receipt.pdf');
        $this->assertSame(1, substr_count($byFile->getContent(), 'is-match'));

        // Document type.
        $this->actingAs($admin)->get(route('documents.index', ['q' => 'Academic Transcript']))
            ->assertOk()->assertSee('aisyah-transcript.pdf')->assertDontSee('haris-receipt.pdf');

        $this->assertTrue(DocumentLibraryController::fileMatches(ApplicationDocument::where('original_name', 'aisyah-transcript.pdf')->first(), 'TRANSCRIPT'));
    }

    public function test_it_pages_twenty_applications_newest_upload_first(): void
    {
        $student = $this->student();

        // 25 applications, two files each, uploaded a day apart: 50 files.
        foreach (range(1, 25) as $i) {
            $application = $this->application($student);
            $this->file($application, sprintf('a%02d-one.pdf', $i), 'Letter', now()->subDays(30 - $i));
            $this->file($application, sprintf('a%02d-two.pdf', $i), 'Letter', now()->subDays(30 - $i));
        }

        $first = $this->actingAs($student)->get(route('documents.index'))->assertOk();
        $groups = $first->viewData('groups');
        $this->assertSame(20, $groups->count());
        $this->assertSame(25, $groups->total());
        $this->assertSame(50, $first->viewData('fileTotal'));
        $first->assertSee('25 applications')->assertSee('50 files')->assertSee('Showing 1–20 of 25 applications');

        // Newest upload first: application 25 leads, 6 is the last on page 1.
        $this->assertSame('a25-one.pdf', $groups->first()->documents->sortBy('original_name')->first()->original_name);
        $first->assertSee('a06-one.pdf')->assertDontSee('a05-one.pdf');
        // Only this page's files were loaded.
        $this->assertSame(40, $groups->getCollection()->sum(fn ($g) => $g->documents->count()));

        $second = $this->actingAs($student)->get(route('documents.index', ['page' => 2]))->assertOk();
        $this->assertSame(5, $second->viewData('groups')->count());
        $second->assertSee('a05-one.pdf')->assertSee('a01-two.pdf')->assertDontSee('a06-one.pdf');
    }

    public function test_module_tabs_count_applications_not_files(): void
    {
        $student = $this->student();
        $travel = $this->application($student, 'travel');
        $this->file($travel, 'one.pdf');
        $this->file($travel, 'two.pdf');
        $this->file($travel, 'three.pdf');

        $response = $this->actingAs($student)->get(route('documents.index'))->assertOk();
        $this->assertSame(1, (int) $response->viewData('byModule')['travel']);
        $response->assertSee('1 application')->assertSee('3 files');
    }
}
