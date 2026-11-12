<?php

namespace Tests\Feature\Hani;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\VivaEligibility;
use App\Modules\Hani\Models\VivaNoticeCheck;
use App\Modules\Hani\Support\Demo\FictionalPdfs;
use App\Modules\Hani\Support\Indexing\IndexingSource;
use App\Modules\Hani\Support\Indexing\ManualCheckSource;
use App\Modules\Hani\Support\NoticeChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Hani\Concerns\FilesVivaNotices;
use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The notice's automatic document checks (UAT 11 Nov 2026), on FICTIONAL
 * fixture PDFs: a readable paper with a DOI, one that does not name the
 * student, and one that is not a PDF at all. Indexing is always "Needs
 * manual check" and nothing ever rejects the notice.
 */
class VivaNoticeChecksTest extends TestCase
{
    use FilesVivaNotices;
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected User $student;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
        $this->student = $this->student(attributes: ['name' => 'Nur Aisyah binti Kamal', 'programme' => 'MSc Full-Time', 'department' => 'Computing']);
        VivaEligibility::create(['student_id' => $this->student->id, 'research_methodology_passed' => 1, 'publication_accepted' => 1,
            'registered_current_semester' => 1, 'no_outstanding_fees' => 1, 'within_candidacy_period' => 1]);
    }

    protected function paper(string $name, array $lines): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'paper');
        file_put_contents($path, FictionalPdfs::document('Journal of Example Studies', $lines));

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    protected function file(array $papers): VivaNoticeCheck
    {
        $this->actingAs($this->student)->post(route('viva.notice.store'), $this->noticeFields(['publication_papers' => $papers]))->assertSessionHasNoErrors();

        return VivaNoticeCheck::where('application_id', Application::sole()->id)->sole();
    }

    protected function line(VivaNoticeCheck $check, string $key): array
    {
        return collect($check->results)->firstWhere('key', $key);
    }

    public function test_a_readable_paper_shows_its_doi_journal_issn_year_and_author(): void
    {
        $check = $this->file([$this->paper('paper.pdf', [
            'Constraint-based examiner matching',
            'Authors: Nur Aisyah Kamal, Hassan Omar',
            'Journal of Example Studies, Vol. 12, 2026. ISSN 1234-5679',
            'DOI: 10.5555/example.2026.042',
            'This manuscript has been accepted for publication.',
        ])]);

        $this->assertSame(VivaNoticeCheck::DONE, $check->status);
        $this->assertSame('pass', $this->line($check, 'paper1_file')['status']);
        $details = $this->line($check, 'paper1_details');
        $this->assertSame('pass', $details['status']);
        foreach (['10.5555/example.2026.042', '1234-5679', '2026', 'accepted for publication', 'Journal of Example Studies'] as $text) {
            $this->assertStringContainsString($text, $details['detail']);
        }
        $this->assertSame('pass', $this->line($check, 'paper1_author')['status']);
        $this->assertSame('not_checked', $this->line($check, 'paper1_indexing')['status']);
        $this->assertStringContainsString('Needs manual check — verify Scopus/WoS indexing', $this->line($check, 'paper1_indexing')['detail']);
        $this->assertStringContainsString('Needs manual check (indexing)', $this->line($check, 'requirement')['detail']);
        $this->assertSame(Application::STATUS_PENDING, Application::sole()->status, 'never auto-rejected');
    }

    public function test_a_paper_without_the_students_name_warns(): void
    {
        $check = $this->file([$this->paper('other.pdf', ['A different paper', 'Authors: Lim Wei Jie, Tan Mei Ling', 'DOI: 10.5555/other.1'])]);

        $this->assertSame('warning', $this->line($check, 'paper1_author')['status']);
        $this->assertSame('warning', $check->overall());
    }

    public function test_an_unreadable_paper_is_flagged_and_the_requirement_is_not_met(): void
    {
        $check = $this->file([UploadedFile::fake()->createWithContent('scan.pdf', 'this is not really a pdf')]);

        $this->assertSame('warning', $this->line($check, 'paper1_file')['status']);
        $this->assertSame('not_met', $this->line($check, 'requirement')['status']);
        $this->assertSame('not_met', $check->overall());
        $this->assertSame(Application::STATUS_PENDING, Application::sole()->status);
    }

    public function test_an_encrypted_pdf_is_flagged(): void
    {
        $check = $this->file([UploadedFile::fake()->createWithContent('locked.pdf', "%PDF-1.4\n1 0 obj << /Encrypt 5 0 R >> endobj\n%%EOF")]);

        $this->assertStringContainsString('encrypted', $this->line($check, 'paper1_file')['detail']);
    }

    public function test_indexing_is_always_a_manual_check_behind_the_interface(): void
    {
        $this->assertInstanceOf(ManualCheckSource::class, app(IndexingSource::class));
        $this->assertSame('not_checked', (new ManualCheckSource)->lookup('Journal of X', '1234-5679')['status']);
        $this->assertSame('not_checked', (new ManualCheckSource)->lookup(null, null)['status']);
    }

    public function test_the_ae_card_shows_the_document_checks_panel(): void
    {
        $this->file([$this->paper('paper.pdf', ['Authors: Nur Aisyah Kamal', 'DOI: 10.5555/x.1'])]);
        $ae = $this->academicExec();
        $ae->update(['department' => 'Computing']);

        $this->actingAs($ae)->get(route('viva.queue'))->assertOk()
            ->assertSee('Document checks')
            ->assertSee('Needs manual check — verify Scopus/WoS indexing')
            ->assertSee('Nothing here rejects the notice');
    }

    public function test_details_reader_on_plain_text(): void
    {
        $details = app(NoticeChecks::class)->details("Journal: Applied Example Letters\nISSN 2345678X (2025)\nhttps://doi.org/10.1234/abc.def.");
        $this->assertSame('10.1234/abc.def', $details['doi']);
        $this->assertSame('2345-678X', $details['issn']);
        $this->assertSame(2025, $details['year']);
        $this->assertSame('Applied Example Letters', $details['journal']);
        $this->assertFalse($details['accepted']);
    }
}
