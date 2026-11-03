<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Models\GaExtensionDetail;
use App\Modules\Nureen\Support\ProcessingTimeAnalytics;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Nureen\Concerns\FilesGaExtensions;
use Tests\Feature\Nureen\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Saved drafts of GA Extension and GA/GRA Certification requests: an
 * applications row in status 'draft' (current_stage NULL) plus its detail
 * row, created by "Save draft", continued later, then submitted through the
 * normal validation -- or deleted.
 */
class DraftsTest extends TestCase
{
    use FilesGaExtensions;
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected User $student;

    protected User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));

        $this->supervisor = $this->supervisor('sup@test.my');
        $this->student = $this->student('22001001', 'me@test.my', ['supervisor_id' => $this->supervisor->id]);
    }

    protected function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->create($name, 40, 'application/pdf');
    }

    /** The three required GA Extension document types, one file each. */
    protected function gaDocuments(): array
    {
        return [
            'identity_document_documents' => [$this->pdf('ic.pdf')],
            'admission_letter_documents' => [$this->pdf('admission.pdf')],
            'supervisor_letter_documents' => [$this->pdf('letter.pdf')],
        ];
    }

    /** Every field the generated form needs (Concerns\FilesGaExtensions). */
    protected function gaFields(): array
    {
        return $this->gaExtensionFields([
            'current_end_date' => '2026-12-31',
            'requested_new_end_date' => '2027-06-30',
            'reason_for_extension' => 'Fieldwork slipped two semesters and the analysis needs the assistantship to continue.',
        ]);
    }

    protected function draft(): Application
    {
        return Application::where('status', Application::STATUS_DRAFT)->latest('id')->firstOrFail();
    }

    /* -----------------------------------------------------------------
     | Saving
     |------------------------------------------------------------------*/

    public function test_an_empty_draft_saves_for_both_modules(): void
    {
        foreach (['ga-extension' => GaExtensionDetail::class, 'ga-certification' => GaCertificationDetail::class] as $prefix => $detail) {
            $this->actingAs($this->student)->post(route("{$prefix}.draft.save"), [])->assertSessionHasNoErrors();

            $draft = $this->draft();
            $this->assertNull($draft->current_stage);
            $this->assertNull($draft->submitted_at);
            $this->assertSame($this->student->id, $draft->student_id);
            $this->assertSame(1, $detail::where('application_id', $draft->id)->count());

            // Continue opens the same form, ready to fill.
            $this->actingAs($this->student)->get(route("{$prefix}.draft.edit", $draft))->assertOk()
                ->assertSee('Continuing your draft')
                ->assertSee('name="draft_id" value="'.$draft->id.'"', false);
        }
    }

    public function test_saving_again_updates_the_same_draft_and_keeps_its_files(): void
    {
        $this->actingAs($this->student)->post(route('ga-extension.draft.save'), [
            'reason_for_extension' => 'First thoughts.',
            'admission_letter_documents' => [$this->pdf('ga-form.pdf')],
            'draft_step' => 1,
        ])->assertRedirect();
        $draft = $this->draft();

        $this->actingAs($this->student)->post(route('ga-extension.draft.update', $draft), [
            'current_end_date' => '2026-12-01',
            'reason_for_extension' => 'Second, longer thoughts.',
            'identity_document_documents' => [$this->pdf('ic.pdf')],
            'draft_step' => 2,
        ])->assertRedirect(route('ga-extension.draft.edit', $draft));

        $this->assertSame(1, Application::count());
        $detail = GaExtensionDetail::where('application_id', $draft->id)->sole();
        $this->assertSame('Second, longer thoughts.', $detail->reason_for_extension);
        $this->assertSame('2026-12-01', $detail->current_end_date->toDateString());
        $this->assertSame(2, $detail->draft_step);

        // Both sessions' files are attached, and the form lists them.
        $this->assertSame(['ga-form.pdf', 'ic.pdf'], $draft->documents()->orderBy('id')->pluck('original_name')->all());
        $this->actingAs($this->student)->get(route('ga-extension.draft.edit', $draft))
            ->assertSee('ga-form.pdf')->assertSee('ic.pdf')
            ->assertSee('Second, longer thoughts.')
            ->assertSee('data-resume-step="2"', false)
            ->assertSee('aria-label="Remove ga-form.pdf"', false);
    }

    public function test_a_draft_save_only_checks_types_and_sizes(): void
    {
        $this->actingAs($this->student)->post(route('ga-extension.draft.save'), [
            'current_end_date' => 'not a date',
            'reason_for_extension' => str_repeat('x', 2001),
            'admission_letter_documents' => [UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')],
        ])->assertSessionHasErrors(['current_end_date', 'reason_for_extension', 'admission_letter_documents.0']);

        $this->assertSame(0, Application::count());
    }

    public function test_a_ticked_file_is_removed_from_the_draft_and_the_disk(): void
    {
        $this->actingAs($this->student)->post(route('ga-extension.draft.save'), ['admission_letter_documents' => [$this->pdf('old.pdf'), $this->pdf('keep.pdf')]]);
        $draft = $this->draft();
        $old = $draft->documents()->where('original_name', 'old.pdf')->sole();

        $this->actingAs($this->student)->post(route('ga-extension.draft.update', $draft), ['remove_documents' => [$old->id]])->assertRedirect();

        $this->assertSame(['keep.pdf'], $draft->documents()->pluck('original_name')->all());
        Storage::disk('local')->assertMissing($old->path);
    }

    public function test_the_five_file_limit_counts_files_already_attached(): void
    {
        $this->actingAs($this->student)->post(route('ga-extension.draft.save'), [
            'admission_letter_documents' => array_map(fn ($i) => $this->pdf("f{$i}.pdf"), range(1, 4)),
        ]);
        $draft = $this->draft();

        $this->actingAs($this->student)->post(route('ga-extension.draft.update', $draft), [
            'admission_letter_documents' => [$this->pdf('f5.pdf'), $this->pdf('f6.pdf')],
        ])->assertSessionHasErrors('admission_letter_documents');

        $this->assertSame(4, $draft->documents()->count());
    }

    /* -----------------------------------------------------------------
     | Submitting a draft
     |------------------------------------------------------------------*/

    public function test_submitting_an_incomplete_draft_fails_with_the_normal_messages(): void
    {
        $this->actingAs($this->student)->post(route('ga-extension.draft.save'), ['reason_for_extension' => 'Too short']);
        $draft = $this->draft();

        $this->actingAs($this->student)->post(route('ga-extension.store'), ['draft_id' => $draft->id, 'reason_for_extension' => 'Too short'])
            ->assertSessionHasErrors([
                'current_end_date' => 'The current end date field is required.',
                'requested_new_end_date' => 'The requested new end date field is required.',
                'reason_for_extension' => 'Please give CGS at least a sentence or two of justification.',
                'admission_letter_documents' => 'Attach at least one file for "Admission Letter & Offer Letter".',
            ]);

        $this->assertSame(Application::STATUS_DRAFT, $draft->fresh()->status);
        $this->assertNull($draft->fresh()->current_stage);
    }

    public function test_submitting_a_complete_draft_reuses_it_at_the_first_stage_with_its_files(): void
    {
        // Session 1: the dates and two of the three required documents.
        $this->actingAs($this->student)->post(route('ga-extension.draft.save'), [
            'current_end_date' => '2026-12-01',
            'admission_letter_documents' => [$this->pdf('admission.pdf')],
            'identity_document_documents' => [$this->pdf('ic.pdf')],
        ]);
        $draft = $this->draft();
        $saved = $draft->documents()->orderBy('id')->get();

        // Session 2: the rest and a stage gate proof, then Submit.
        $this->actingAs($this->student)->post(route('ga-extension.store'), [
            'draft_id' => $draft->id,
            'supervisor_letter_documents' => [$this->pdf('letter.pdf')],
            'published_article_documents' => [$this->pdf('paper.pdf')],
        ] + $this->gaFields())->assertSessionHasNoErrors()->assertRedirect(route('applications.index'));

        $this->assertSame(1, Application::count());
        $application = $draft->fresh();
        $this->assertSame(Application::STATUS_PENDING, $application->status);
        $this->assertSame('supervisor', $application->current_stage);
        $this->assertNotNull($application->submitted_at);

        $detail = GaExtensionDetail::where('application_id', $application->id)->sole();
        $this->assertSame($this->supervisor->id, $detail->supervisor_id);
        $this->assertSame(config('nureen.ga_extension.final_approver'), $detail->final_approver_snapshot);
        $this->assertNull($detail->draft_step);

        // The files saved while drafting are the same rows and files, plus the new two.
        $this->assertSame(4, $application->documents()->count());
        foreach ($saved as $document) {
            $this->assertTrue($application->documents()->whereKey($document->id)->exists());
            Storage::disk('local')->assertExists($document->path);
        }

        // It reaches the supervisor, and a submitted draft can't be continued or resubmitted.
        $this->actingAs($this->supervisor)->get(route('ga-extension.queue'))->assertSee('#'.$application->id);
        $this->actingAs($this->student)->get(route('ga-extension.draft.edit', $application))->assertNotFound();
        $this->actingAs($this->student)->post(route('ga-extension.store'), ['draft_id' => $application->id] + $this->gaFields() + $this->gaDocuments())->assertNotFound();
        $this->assertSame(1, Application::count());
    }

    public function test_a_certification_draft_submits_and_takes_the_final_types_labels(): void
    {
        // Drafted as GA with evidence attached, then switched to GRA.
        $this->actingAs($this->student)->post(route('ga-certification.draft.save'), [
            'appointment_type' => 'GA',
            'course_code' => 'CCB2012',
            'supporting_documents' => [$this->pdf('evidence.pdf')],
        ]);
        $draft = $this->draft();

        $this->actingAs($this->student)->post(route('ga-certification.store'), [
            'draft_id' => $draft->id,
            'appointment_type' => 'GRA',
            'period_start' => '2026-01-01',
            'period_end' => '2026-06-30',
            'project_title' => 'Reservoir Simulation under Uncertainty',
            'project_leader_name' => 'Dr. Lim',
            'semester' => 'Jan 2026',
            'study_level' => 'research',
            'level_of_study' => 'phd',
            'mode_of_study' => 'full_time',
            'identity_number' => '900101-08-1234',
            'personal_email' => 'student.personal@gmail.test',
            'declaration' => '1',
        ])->assertSessionHasNoErrors();

        $application = $draft->fresh();
        $this->assertSame(['pending', 'supervisor'], [$application->status, $application->current_stage]);
        $detail = GaCertificationDetail::where('application_id', $application->id)->sole();
        $this->assertSame('GRA', $detail->appointment_type);
        $this->assertNull($detail->course_code);   // the GA field is cleared
        $this->assertSame(
            [config('nureen.ga_certification.supporting_document_label.GRA')],
            $application->documents()->pluck('doc_type')->all(),
        );
    }

    /* -----------------------------------------------------------------
     | Only the owner
     |------------------------------------------------------------------*/

    public function test_another_student_cannot_open_change_submit_or_delete_my_draft(): void
    {
        $this->actingAs($this->student)->post(route('ga-extension.draft.save'), ['reason_for_extension' => 'Mine.', 'admission_letter_documents' => [$this->pdf('mine.pdf')]]);
        $draft = $this->draft();
        $other = $this->student('22001002', 'other@test.my', ['supervisor_id' => $this->supervisor->id]);

        $this->actingAs($other)->get(route('ga-extension.draft.edit', $draft))->assertForbidden();
        $this->actingAs($other)->post(route('ga-extension.draft.update', $draft), ['reason_for_extension' => 'Theirs.'])->assertForbidden();
        $this->actingAs($other)->delete(route('ga-extension.draft.destroy', $draft))->assertForbidden();
        $this->actingAs($other)->post(route('ga-extension.store'), ['draft_id' => $draft->id] + $this->gaFields() + $this->gaDocuments())->assertForbidden();
        $this->actingAs($other)->get(route('nureen.drafts'))->assertDontSee('mine.pdf')->assertDontSee('data-draft="'.$draft->id.'"', false);

        // Staff can't either: these are student-only routes.
        $this->actingAs($this->supervisor)->get(route('ga-extension.draft.edit', $draft))->assertForbidden();

        $this->assertSame('Mine.', GaExtensionDetail::where('application_id', $draft->id)->value('reason_for_extension'));
        $this->assertSame(Application::STATUS_DRAFT, $draft->fresh()->status);
        $this->assertSame(1, $draft->documents()->count());
    }

    public function test_deleting_a_draft_removes_its_detail_row_documents_and_files(): void
    {
        $this->actingAs($this->student)->post(route('ga-certification.draft.save'), ['appointment_type' => 'GA', 'supporting_documents' => [$this->pdf('evidence.pdf')]]);
        $draft = $this->draft();
        $path = $draft->documents()->sole()->path;

        $this->actingAs($this->student)->get(route('nureen.drafts'))->assertOk()
            ->assertSee('Certification Letter')
            ->assertSee(route('ga-certification.draft.edit', $draft))
            ->assertSee(route('ga-certification.draft.destroy', $draft));

        $this->actingAs($this->student)->delete(route('ga-certification.draft.destroy', $draft))->assertRedirect(route('nureen.drafts'));

        $this->assertNull(Application::find($draft->id));
        $this->assertSame(0, GaCertificationDetail::where('application_id', $draft->id)->count());
        $this->assertSame(0, ApplicationDocument::where('application_id', $draft->id)->count());
        Storage::disk('local')->assertMissing($path);
    }

    /* -----------------------------------------------------------------
     | Invisible to the workflow
     |------------------------------------------------------------------*/

    /** A complete-looking draft of each module, saved a month ago. */
    protected function oldDrafts(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00'));
        $this->actingAs($this->student)->post(route('ga-extension.draft.save'), $this->gaFields() + $this->gaDocuments());
        $ga = $this->draft();
        $this->actingAs($this->student)->post(route('ga-certification.draft.save'), ['appointment_type' => 'GA', 'period_start' => '2026-01-01', 'period_end' => '2026-06-30', 'supporting_documents' => [$this->pdf('e.pdf')]]);
        $cert = $this->draft();
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));

        return [$ga, $cert];
    }

    public function test_a_draft_is_in_no_approvers_queue(): void
    {
        [$ga, $cert] = $this->oldDrafts();
        $cgs = $this->cgs();
        $director = $this->user(Role::SENIOR_DIRECTOR_CGS);

        $views = [
            [$this->supervisor, route('ga-extension.queue')],
            [$cgs, route('ga-extension.queue', ['stage' => 'cgs_verify'])],
            [$director, route('ga-extension.queue', ['stage' => 'senior_director'])],
            [$this->supervisor, route('ga-certification.queue')],
            [$cgs, route('ga-certification.queue', ['stage' => 'cgs_verify'])],
            [$director, route('ga-certification.queue', ['stage' => 'senior_director'])],
        ];

        foreach ($views as [$user, $url]) {
            $this->actingAs($user)->get($url)->assertOk()
                ->assertDontSee('#'.$ga->id.' ', false)
                ->assertDontSee('#'.$cert->id.' ', false)
                ->assertDontSee($this->student->name);
        }
    }

    public function test_reminders_and_analytics_ignore_drafts(): void
    {
        [$ga, $cert] = $this->oldDrafts();

        // Even a draft carrying a stray history row is never reported.
        ApprovalHistory::create(['application_id' => $ga->id, 'approver_id' => $this->supervisor->id, 'stage_key' => 'supervisor', 'stage_label' => 'Lecturer/Supervisor', 'decision' => 'endorsed']);

        $this->artisan('ga-extension:remind-stalled')->assertSuccessful();
        $this->artisan('ga-certification:remind-stalled')->assertSuccessful();
        $this->artisan('supervision:remind-stalled')->assertSuccessful();
        Notification::assertNothingSent();
        $this->assertSame(0, DB::table('application_reminders')->count());

        $analytics = app(ProcessingTimeAnalytics::class);
        $this->assertTrue($analytics->complianceByStageAndModule()->isEmpty());
        $this->assertTrue(collect($analytics->overdueApplications())->isEmpty());
    }
}
