<?php

namespace Tests\Feature\Hani;

use Tests\Feature\Hani\Concerns\FilesVivaNotices;
use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Notifications\ApplicationDecided;
use App\Modules\Hani\Models\VivaDetail;
use App\Modules\Hani\Models\VivaEligibility;
use App\Modules\Hani\Notifications\VivaNoticeSubmitted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Viva step 1: the eligibility checklist gates the Notice of Thesis
 * Submission. The buttons on the page are locked by script; these guard the
 * server-side rule that actually holds.
 */
class VivaEligibilityTest extends TestCase
{
    use FilesVivaNotices;
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected const MSC_CHECKS = [
        'research_methodology_passed' => 1, 'publication_accepted' => 1,
        'registered_current_semester' => 1, 'no_outstanding_fees' => 1, 'within_candidacy_period' => 1,
    ];

    protected function notice(array $overrides = []): array
    {
        return $this->noticeFields($overrides);
    }

    public function test_one_page_holds_the_checklist_and_the_notice_and_one_submit_files_it(): void
    {
        Storage::fake('local');
        Notification::fake();
        $student = $this->student(attributes: ['programme' => 'MSc Full-Time']);

        $this->actingAs($student)->get(route('viva.eligibility'))
            ->assertOk()
            ->assertSee('Research Methodology')
            ->assertDontSee('Research Completion Seminar')
            ->assertSee('Notice of Thesis Submission')
            ->assertSee('name="abstract"', false)
            ->assertSee('name="keywords"', false)
            ->assertSee('Publication Proof');

        // The old notice URL lands on the same page.
        $this->actingAs($student)->get(route('viva.notice'))
            ->assertRedirect(route('viva.eligibility').'#notice');

        // Four of five is not enough: the ticks are kept, nothing is filed.
        $this->actingAs($student)->post(route('viva.notice.store'),
            array_slice(self::MSC_CHECKS, 0, 4) + $this->notice())
            ->assertRedirect(route('viva.eligibility'))
            ->assertSessionHas('error');
        $this->assertSame(0, Application::count());
        $this->assertTrue(VivaEligibility::where('student_id', $student->id)->sole()->publication_accepted);

        // Every tick and every field, one submit.
        $this->actingAs($student)->post(route('viva.notice.store'), self::MSC_CHECKS + $this->notice())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('viva.eligibility'));

        $detail = VivaDetail::sole();
        $this->assertSame(['examiner matching', 'constraint satisfaction', 'expertise ranking'], $detail->keywords);
        $this->assertSame(150, VivaDetail::wordCount($detail->abstract));

        $this->actingAs($student)->get(route('viva.eligibility'))
            ->assertOk()
            ->assertSee('Your Viva Progress')
            ->assertSee('Locked, because your notice was filed on it.');
    }

    public function test_save_progress_keeps_the_ticks_without_any_notice_field(): void
    {
        $student = $this->student(attributes: ['programme' => 'MSc Full-Time']);

        $this->actingAs($student)->post(route('viva.eligibility.save'), array_slice(self::MSC_CHECKS, 0, 2))
            ->assertRedirect(route('viva.eligibility'))
            ->assertSessionHas('status', 'Checklist saved.');

        $this->assertTrue(VivaEligibility::sole()->research_methodology_passed);
        $this->assertFalse(VivaEligibility::sole()->no_outstanding_fees);
    }

    public function test_abstract_and_keywords_are_bounded(): void
    {
        Storage::fake('local');
        Notification::fake();
        $student = $this->student(attributes: ['programme' => 'MSc Full-Time']);

        $this->actingAs($student)->post(route('viva.notice.store'), self::MSC_CHECKS + $this->notice(['abstract' => $this->abstractOf(99)]))
            ->assertSessionHasErrors(['abstract' => 'The abstract must be between 100 and 500 words; it has 99.']);
        $this->actingAs($student)->post(route('viva.notice.store'), self::MSC_CHECKS + $this->notice(['abstract' => $this->abstractOf(501)]))
            ->assertSessionHasErrors('abstract');
        $this->actingAs($student)->post(route('viva.notice.store'), self::MSC_CHECKS + $this->notice(['keywords' => 'one; two']))
            ->assertSessionHasErrors('keywords');
        $this->actingAs($student)->post(route('viva.notice.store'), self::MSC_CHECKS + $this->notice(['keywords' => 'a, b, c, d, e, f, g, h, i']))
            ->assertSessionHasErrors('keywords');
        $this->assertSame(0, Application::count());

        $this->actingAs($student)->post(route('viva.notice.store'), self::MSC_CHECKS + $this->notice([
            'abstract' => $this->abstractOf(100), 'keywords' => "Deep learning, anomaly detection;\nTime series; anomaly detection",
        ]))->assertSessionHasNoErrors();

        $this->assertSame(['deep learning', 'anomaly detection', 'time series'], VivaDetail::sole()->keywords);
    }

    public function test_coursework_students_cannot_file_a_notice_through_the_merged_page(): void
    {
        Storage::fake('local');
        $student = $this->student(attributes: ['programme' => 'MSc by Coursework']);

        $this->actingAs($student)->get(route('viva.eligibility'))
            ->assertOk()
            ->assertSee('does not apply')
            ->assertDontSee('name="abstract"', false);

        $this->actingAs($student)->post(route('viva.notice.store'), self::MSC_CHECKS + $this->notice())
            ->assertRedirect(route('viva.eligibility'));
        $this->assertSame(0, Application::count());
    }

    public function test_the_abstract_and_keywords_drive_the_examiner_suggestions(): void
    {
        Storage::fake('local');
        Notification::fake();
        $student = $this->student(attributes: ['programme' => 'MSc Full-Time']);

        $this->actingAs($student)->post(route('viva.notice.store'), self::MSC_CHECKS + $this->notice([
            'abstract' => $this->abstractOf(120).' pipeline leak detection with sensors',
            'keywords' => 'anomaly detection; time series; sensor data',
        ]))->assertSessionHasNoErrors();

        $query = app(\App\Modules\Hani\Support\Matching\ExaminerSuggestions::class)->queryFor($student);

        $this->assertSame('the Notice of Thesis Submission', $query['source']);
        $this->assertSame(['anomaly detection', 'time series', 'sensor data'], $query['keywords']);
        $this->assertStringContainsString('pipeline leak detection', $query['text']);
    }

    public function test_phd_needs_rcs_and_msc_cannot_store_it(): void
    {
        $phd = $this->student('22001002', 'phd@test.my', ['programme' => 'PhD Part-Time']);

        Storage::fake('local');
        Notification::fake();

        $this->actingAs($phd)->post(route('viva.notice.store'), self::MSC_CHECKS + $this->notice())
            ->assertSessionHas('error');
        $this->assertSame(0, Application::count());

        $this->actingAs($phd)->post(route('viva.notice.store'), self::MSC_CHECKS + ['rcs_passed' => 1] + $this->notice())
            ->assertSessionHasNoErrors();
        $this->assertSame('PHD', VivaDetail::sole()->programme_level);

        $msc = $this->student(attributes: ['programme' => 'MSc Full-Time']);
        $this->actingAs($msc)->post(route('viva.eligibility.save'), self::MSC_CHECKS + ['rcs_passed' => 1]);

        $this->assertFalse(VivaEligibility::where('student_id', $msc->id)->sole()->rcs_passed);
    }

    public function test_eligible_student_files_one_notice_to_the_academic_executive(): void
    {
        Storage::fake('local');
        Notification::fake();
        $student = $this->student(attributes: ['programme' => 'MSc Full-Time']);
        VivaEligibility::create(['student_id' => $student->id] + self::MSC_CHECKS);

        // Proof must be a PDF, and the softbound date 3 months out.
        $this->actingAs($student)->post(route('viva.notice.store'), $this->notice([
            'publication_proof' => UploadedFile::fake()->create('proof.docx', 10),
        ]))->assertSessionHasErrors('publication_proof');

        $this->actingAs($student)->post(route('viva.notice.store'), $this->notice([
            'publication_proof' => UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf'),
        ]))->assertSessionHasErrors('publication_proof');

        $this->actingAs($student)->post(route('viva.notice.store'), $this->notice([
            'expected_softbound_date' => now()->addMonth()->toDateString(),
        ]))->assertSessionHasErrors('expected_softbound_date');

        $this->actingAs($student)->post(route('viva.notice.store'), $this->notice())
            ->assertSessionHasNoErrors();

        $application = Application::sole();
        $this->assertSame('viva_voce', $application->module_type);
        $this->assertSame('notice_received', $application->current_stage);
        $this->assertSame('MSC', VivaDetail::sole()->programme_level);
        $this->assertSame('Publication Proof', $application->documents()->sole()->doc_type);

        // A receipt on submit; the engine's own email follows on acknowledgement.
        Notification::assertSentTo($student, VivaNoticeSubmitted::class);
        Notification::assertNotSentTo($student, ApplicationDecided::class);

        // It lands in the Academic Executive's queue, for their department.
        $ae = $this->academicExec();
        $ae->update(['department' => 'Computing']);
        $student->update(['department' => 'Computing']);
        $this->actingAs($ae)->get(route('viva.queue'))
            ->assertOk()
            ->assertSee('Constraint-based examiner matching');

        $this->actingAs($ae)->post(route('viva.decide', $application), ['decision' => 'approve'])
            ->assertSessionHasNoErrors();
        Notification::assertSentTo($student, ApplicationDecided::class);

        // A second notice, and further checklist edits, are refused.
        $this->actingAs($student)->post(route('viva.notice.store'), $this->notice())
            ->assertRedirect(route('applications.show', $application));
        $this->assertSame(1, Application::count());
        $this->actingAs($student)->post(route('viva.eligibility.save'), [])
            ->assertSessionHas('error');
        $this->assertTrue(VivaEligibility::sole()->no_outstanding_fees);
    }

    public function test_coursework_students_get_no_checklist(): void
    {
        $student = $this->student(attributes: ['programme' => 'MSc by Coursework']);

        $this->actingAs($student)->post(route('viva.eligibility.save'), self::MSC_CHECKS)
            ->assertForbidden();
    }

    public function test_the_student_sidebar_has_one_viva_entry_for_every_viva_page(): void
    {
        $student = $this->student(attributes: ['programme' => 'MSc Full-Time']);

        $html = $this->actingAs($student)->get(route('viva.eligibility'))->assertOk()->getContent();

        // Core's sidebar reads the optional navLabel()/navPattern() only once
        // the change in app/Modules/Hani/SHARED_CHANGES.md (item 1) is applied.
        // Without it the one entry falls back to label(), highlighted here only.
        $coreReadsNavLabel = str_contains($html, '>Viva</a>');
        $entry = $coreReadsNavLabel ? 'Viva' : 'Notice of Thesis Submission';
        $active = '/class="nav-subitem\s+active\s*"\s*>'.preg_quote($entry, '/').'<\/a>/';

        // One viva entry under My Application, active here; no separate softbound entry.
        $this->assertSame(1, substr_count($html, 'title="'.$entry.'"'));
        $this->assertMatchesRegularExpression($active, $html);
        $this->assertStringNotContainsString('title="Softbound Thesis"', $html);
        if ($coreReadsNavLabel) {
            $this->assertStringNotContainsString('title="Notice of Thesis Submission"', $html);
        }

        // Teammates' entries are untouched.
        $this->assertStringContainsString('title="Travel"', $html);

        // The old URLs still answer, and the entry is still there on another
        // viva page (highlighted there too once Core reads navPattern()).
        $this->actingAs($student)->get(route('viva.softbound.create'))->assertRedirect(route('viva.eligibility'));
        $notice = $this->actingAs($student)->followingRedirects()->get(route('viva.notice'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($notice, 'title="'.$entry.'"'));
        if ($coreReadsNavLabel) {
            $this->assertMatchesRegularExpression($active, $notice);
        }
    }
}
