<?php

namespace Tests\Feature\Hani;

use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use App\Modules\Core\Models\Department;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerImportBatch;
use App\Modules\Hani\Models\ExaminerReviewItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Pool import (Support\Import\PoolImporter): CGS's own workbook and the
 * UResearch template, previewed then confirmed.
 *
 * The two real layouts are tested against the fictional demo workbooks in
 * app/Modules/Hani/Database/demo/, read in place and never modified; the
 * edge cases use small CSVs built here.
 */
class ExaminerImportTest extends TestCase
{
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected const DEMO = 'app/Modules/Hani/Database/demo/';

    protected function setUp(): void
    {
        parent::setUp();

        // Core's canonical departments, as DatabaseSeeder files them.
        foreach ([
            'Computing' => 'FSMC', 'Management' => 'FSMC', 'Applied Sciences' => 'FSMC', 'Geosciences' => 'FSMC',
            'Chemical Engineering' => 'FOE', 'Petroleum Engineering' => 'FOE', 'Civil & Environmental Engineering' => 'FOE',
            'Mechanical Engineering' => 'FOE', 'Electrical & Electronics Engineering' => 'FOE',
        ] as $name => $faculty) {
            Department::create(['name' => $name, 'faculty' => $faculty, 'is_active' => true]);
        }
    }

    protected function demoFile(string $name): UploadedFile
    {
        return new UploadedFile(base_path(self::DEMO.$name), $name, null, null, true);
    }

    protected function csv(string $content, string $name = 'pool.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    protected function upload(UploadedFile $file, $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->senior())->post(route('examiner-import.store'), ['file' => $file]);
    }

    protected ?\App\Modules\Core\Models\User $senior = null;

    protected function senior(): \App\Modules\Core\Models\User
    {
        return $this->senior ??= $this->seniorExec();
    }

    /** Upload, open the preview, confirm. Returns the preview response. */
    protected function import(UploadedFile $file): TestResponse
    {
        $this->upload($file)->assertSessionHasNoErrors()->assertRedirect();
        $batch = ExaminerImportBatch::latest('id')->first();

        $preview = $this->actingAs($this->senior())->get(route('examiner-import.show', $batch))->assertOk();

        $this->actingAs($this->senior())
            ->post(route('examiner-import.confirm', $batch))
            ->assertRedirect(route('examiner-admin.index'));

        return $preview;
    }

    public function test_the_cgs_layout_previews_before_anything_changes_then_imports(): void
    {
        $this->upload($this->demoFile('hani_examiner_pool_cgs_format_demo.xlsx'))->assertSessionHasNoErrors();
        $batch = ExaminerImportBatch::sole();

        $this->assertSame('cgs', $batch->layout);
        $this->assertSame(0, Examiner::count(), 'A preview writes nothing.');

        $this->actingAs($this->senior())->get(route('examiner-import.show', $batch))
            ->assertOk()
            ->assertSee('New: 40')
            ->assertSee('Possible duplicate: 1')
            // The original department name and what it was mapped to.
            ->assertSee('Computer &amp; Information Sciences', false)
            ->assertSee('→ Computing')
            ->assertSee('&quot;Hassan Omar, PhD&quot; looks like &quot;Prof Dr Hassan bin Omar&quot;', false);

        $this->actingAs($this->senior())->post(route('examiner-import.confirm', $batch))
            ->assertSessionHas('status');

        $this->assertSame(41, Examiner::count());

        $farah = Examiner::where('email', 'farah.rosli@example.com')->sole();
        $this->assertSame(Examiner::TYPE_INTERNAL, $farah->type);
        $this->assertSame('Computing', $farah->department);
        $this->assertSame('FSMC', $farah->faculty);

        // "Fundamental & Applied Sciences" is in no list but the alias.
        $this->assertSame('Applied Sciences', Examiner::where('email', 'ain.ismail@example.com')->value('department'));

        // External: "(UM)" stripped from the name; Details, dates and the numbered list parsed.
        $melissa = Examiner::where('email', 'melissa.teoh@example.org')->sole();
        $this->assertSame('Assoc Prof Dr Melissa Teoh Hui Min', $melissa->name);
        $this->assertSame('Universiti Malaya (UM)', $melissa->institution);
        $this->assertSame('5.2026', $melissa->faculty_approval);
        $this->assertSame('2014-09-01', $melissa->first_examination_date->format('Y-m-d'));
        $this->assertSame('2025-10-20', $melissa->last_examination_date->format('Y-m-d'));
        $this->assertSame(12, $melissa->years_experience);
        $this->assertSame(5, $melissa->phd_graduated);
        $this->assertSame('research', $melissa->sector);
        $this->assertContains('anomaly detection', $melissa->expertiseKeywords->pluck('keyword')->all());

        // "No" with a student: a review item, not an invented nomination.
        $rajesh = Examiner::where('email', 'rajesh.s@example.com')->sole();
        $this->assertTrue($rajesh->is_active);
        $this->assertDatabaseHas('examiner_review_items', [
            'examiner_id' => $rajesh->id,
            'kind' => ExaminerReviewItem::KIND_CURRENT_ASSIGNMENT,
            'detail' => 'Ong Wei Ming_22000731',
        ]);
        $this->assertDatabaseCount('examiner_nominations', 0);

        // "No" with a reason: Unavailable.
        $zulkifli = Examiner::where('email', 'zulkifli.hassan@example.com')->sole();
        $this->assertFalse($zulkifli->is_active);
        $this->assertSame('Retired (June 2026)', $zulkifli->unavailable_reason);
    }

    public function test_importing_the_same_file_twice_changes_nothing(): void
    {
        $this->import($this->demoFile('hani_examiner_pool_cgs_format_demo.xlsx'));
        $reviewItems = ExaminerReviewItem::count();

        $preview = $this->import($this->demoFile('hani_examiner_pool_cgs_format_demo.xlsx'));

        $preview->assertSee('Unchanged: 41')->assertDontSee('New:');
        $this->assertSame(41, Examiner::count());
        $this->assertSame($reviewItems, ExaminerReviewItem::count(), 'An open review item is not raised twice.');
    }

    public function test_the_template_layout_brings_publications_and_utp_links(): void
    {
        $preview = $this->import($this->demoFile('hani_examiner_pool_demo.xlsx'));

        $preview->assertSee('New: 40')->assertSee('Possible duplicate: 1')
            ->assertSee('is not a UTP department; kept as written', false);

        $this->assertSame(41, Examiner::count());
        $this->assertSame(2, Examiner::where('email', 'priya.ramasamy@example.org')->sole()->publications()->count());
        $this->assertStringStartsWith('Adjunct Professor', Examiner::where('email', 'azlina.mansor@example.org')->value('current_utp_appointment'));
        $this->assertNotNull(Examiner::where('email', 'iqbal.rashid@example.org')->sole()->former_utp_until);

        // Internal rows carry no external record, whatever the sheet says.
        $this->assertNull(Examiner::where('email', 'farah.rosli@example.com')->value('institution'));
        $this->assertSame('Machine learning and data analytics', Examiner::where('email', 'farah.rosli@example.com')->value('expertise'));
    }

    public function test_the_template_updates_examiners_the_cgs_file_created(): void
    {
        $this->import($this->demoFile('hani_examiner_pool_cgs_format_demo.xlsx'));

        $preview = $this->import($this->demoFile('hani_examiner_pool_demo.xlsx'));

        $preview->assertSee('Update: 41');
        $this->assertSame(41, Examiner::count(), 'Matched by email, so nobody is added twice.');
        // The CGS sheet has no expertise for internal examiners; the template does.
        $this->assertSame('Machine learning and data analytics', Examiner::where('email', 'farah.rosli@example.com')->value('expertise'));
    }

    public function test_bad_rows_are_rejected_with_a_reason_and_a_missing_email_is_flagged(): void
    {
        $csv = implode("\n", [
            'Type,Full Name,Email,Department,Institution,Status,Unavailable Reason',
            'internal,Dr Good Row,good@example.com,Computing,,Active,',
            'internal,Dr No Email,,Computer and Information Sciences,,Active,',
            'internal,Dr Nowhere,nowhere@example.com,Department of Nothing,,Active,',
            'external,Prof No Institution,noinst@example.org,Computing,,Active,',
            'internal,Dr Gone,gone@example.com,Computing,,Unavailable,',
            'internal,Dr Twice,good@example.com,Computing,,Active,',
            'lecturer,Dr Odd Type,odd@example.com,Computing,,Active,',
        ]);

        $preview = $this->import($this->csv($csv));

        $preview->assertSee('New: 2')
            ->assertSee('Rejected: 5')
            ->assertSee('Missing email: 1')
            ->assertSee('is not a UResearch department and no alias maps it', false)
            ->assertSee('An external examiner needs an institution.')
            ->assertSee('Marked unavailable without a reason.')
            ->assertSee('Same email as')
            ->assertSee('Type must be internal or external');

        $this->assertSame(['Dr Good Row', 'Dr No Email'], Examiner::orderBy('name')->pluck('name')->all());
        $this->assertTrue(Examiner::where('name', 'Dr No Email')->sole()->needsEmail());

        $this->actingAs($this->senior())->get(route('examiner-admin.index'))->assertSee('Email needed');
    }

    public function test_a_row_without_an_email_updates_the_same_person_rather_than_adding_them(): void
    {
        Examiner::create([
            'name' => 'Dr Kevin Tan Boon Huat', 'email' => null, 'department' => 'Computing',
            'type' => Examiner::TYPE_INTERNAL, 'is_active' => true,
        ]);

        $csv = "Type,Full Name,Email,Department,Keywords\n"
            ."internal,Dr. Kevin Tan Boon Huat,,Computer & Information Sciences,explainable ai; credit scoring\n";

        $this->import($this->csv($csv))->assertSee('Update: 1');

        $this->assertSame(1, Examiner::count());
        $this->assertSame(['credit scoring', 'explainable ai'], Examiner::sole()->expertiseKeywords->pluck('keyword')->sort()->values()->all());
    }

    public function test_more_than_one_student_in_remarks_keeps_the_first_and_asks_for_a_check(): void
    {
        $csv = "No,Approved Internal Examiner,Email,Department,Availability,Remarks\n"
            ."1,Dr Busy,busy@example.com,Computing,No,\"Aina_22000001; Badrul_22000002\"\n";

        $this->import($this->csv($csv))->assertSee('Check: more than one student listed');

        $examiner = Examiner::sole();
        $this->assertTrue($examiner->is_active);
        $this->assertSame('Aina_22000001', ExaminerReviewItem::where('kind', ExaminerReviewItem::KIND_CURRENT_ASSIGNMENT)->value('detail'));
        $this->assertSame('Also listed: Badrul_22000002', ExaminerReviewItem::where('kind', ExaminerReviewItem::KIND_MULTIPLE_STUDENTS)->value('detail'));

        $this->actingAs($this->senior())->get(route('examiner-import.review'))->assertOk()->assertSee('Aina_22000001');
    }

    public function test_an_unrecognised_file_is_refused(): void
    {
        $this->upload($this->csv("Colour,Size\nred,large\n"))->assertSessionHasErrors('file');
        $this->assertSame(0, ExaminerImportBatch::count());
    }

    public function test_unconfirmed_previews_are_purged_after_a_day(): void
    {
        $old = ExaminerImportBatch::create([
            'user_id' => $this->senior()->id, 'layout' => 'template', 'filename' => 'old.csv', 'rows' => [],
        ]);
        $old->forceFill(['created_at' => now()->subHours(25)])->save();

        $this->upload($this->csv("Type,Full Name,Email,Department\ninternal,Dr A,a@example.com,Computing\n"));

        $this->assertModelMissing($old);
        $this->assertSame(1, ExaminerImportBatch::count());
    }

    public function test_only_the_senior_executive_imports_and_only_their_own_preview(): void
    {
        $file = "Type,Full Name,Email,Department\ninternal,Dr A,a@example.com,Computing\n";

        $nonExec = $this->cgs();
        $this->upload($this->csv($file), $nonExec)->assertForbidden();
        $this->actingAs($nonExec)->get(route('examiner-import.template'))->assertForbidden();

        $this->upload($this->csv($file));
        $batch = ExaminerImportBatch::sole();

        $colleague = $this->user(\App\Modules\Core\Support\Role::SENIOR_EXEC_CGS, ['email' => 'other.senior@test.my']);
        $this->actingAs($colleague)->get(route('examiner-import.show', $batch))->assertNotFound();
        $this->actingAs($colleague)->post(route('examiner-import.confirm', $batch))->assertNotFound();
    }

    public function test_the_template_downloads_without_demo_rows(): void
    {
        $response = $this->actingAs($this->senior())->get(route('examiner-import.template'))->assertOk();

        $this->assertStringContainsString('uresearch-examiner-pool-template.xlsx', $response->headers->get('content-disposition'));

        $sheets = \Maatwebsite\Excel\Facades\Excel::toArray(
            new \App\Modules\Hani\Imports\PoolWorkbookImport(),
            $response->baseResponse->getFile()->getPathname(),
            null,
            \Maatwebsite\Excel\Excel::XLSX
        );

        $this->assertSame(array_keys(\App\Modules\Hani\Support\Import\TemplateLayoutReader::EXAMINER_HEADERS), $sheets[0][0]);
        $this->assertCount(1, $sheets[0], 'Headings only.');
    }

    public function test_an_examiner_can_be_edited_and_keeps_their_keywords_in_step(): void
    {
        $examiner = Examiner::create([
            'name' => 'Dr Edit Me', 'email' => 'edit@example.com', 'department' => 'Computing',
            'type' => Examiner::TYPE_INTERNAL, 'is_active' => true,
        ]);
        $examiner->expertiseKeywords()->create(['keyword' => 'old topic']);

        $this->actingAs($this->senior())->get(route('examiner-admin.edit', $examiner))
            ->assertOk()->assertSee('old topic');

        $this->actingAs($this->senior())->put(route('examiner-admin.update', $examiner), [
            'name' => 'Dr Edit Me',
            'email' => '',
            'department' => 'Computer and Information Sciences',
            'type' => Examiner::TYPE_INTERNAL,
            'keywords' => 'Time Series; anomaly detection',
        ])->assertSessionHasNoErrors()->assertRedirect(route('examiner-admin.index'));

        $examiner->refresh();
        $this->assertNull($examiner->email);
        $this->assertSame('Computing', $examiner->department);
        $this->assertSame(['anomaly detection', 'time series'], $examiner->expertiseKeywords->pluck('keyword')->sort()->values()->all());
    }

    public function test_the_demo_workbooks_are_left_untouched(): void
    {
        $before = md5_file(base_path(self::DEMO.'hani_examiner_pool_cgs_format_demo.xlsx'));

        $this->import($this->demoFile('hani_examiner_pool_cgs_format_demo.xlsx'));

        $this->assertSame($before, md5_file(base_path(self::DEMO.'hani_examiner_pool_cgs_format_demo.xlsx')));
    }
}
