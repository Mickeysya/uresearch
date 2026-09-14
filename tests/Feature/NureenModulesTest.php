<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Models\AttendanceRecord;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Notifications\AttendanceAtRisk;
use App\Modules\Nureen\Notifications\CertificationIssued;
use App\Modules\Nureen\Support\AttendanceSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Nureen's four modules, against docs/scope/nureen.md.
 *
 * Concentrated on the three things that were either missing or fragile: the
 * attendance import (which handles a file a human filled in by hand, so it is
 * the most likely thing in the portal to be fed something unexpected),
 * Supervision's required documentation, and the certificate actually being
 * dispatched rather than merely generated.
 */
class NureenModulesTest extends TestCase
{
    use RefreshDatabase;

    protected function student(string $matric = '22001001', string $email = 'student@test.my'): User
    {
        return User::create([
            'name' => 'Ahmad Danial',
            'email' => $email,
            'password' => 'password',
            'role' => Role::STUDENT,
            'matric_no' => $matric,
        ]);
    }

    protected function cgs(): User
    {
        return User::create([
            'name' => 'Puan Waheeda',
            'email' => 'cgs@test.my',
            'password' => 'password',
            'role' => Role::NON_EXEC_CGS,
        ]);
    }

    protected function sheet(array $rows, string $name = 'attendance.csv'): UploadedFile
    {
        $csv = implode(',', AttendanceSheet::COLUMNS)."\n";

        foreach ($rows as $row) {
            $csv .= implode(',', $row)."\n";
        }

        $path = tempnam(sys_get_temp_dir(), 'att').'.csv';
        file_put_contents($path, $csv);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    /* -----------------------------------------------------------------
     | Module 1 — Attendance: the template and the import
     |------------------------------------------------------------------*/

    public function test_cgs_can_download_the_template_in_both_formats(): void
    {
        $this->actingAs($this->cgs());

        $xlsx = $this->get(route('attendance.template'));
        $xlsx->assertOk();
        $this->assertStringContainsString('.xlsx', $xlsx->headers->get('content-disposition'));

        $csv = $this->get(route('attendance.template', ['format' => 'csv']));
        $csv->assertOk();
        $this->assertStringContainsString('.csv', $csv->headers->get('content-disposition'));

        // The header row must be exactly what upload() demands, or the
        // template is worse than useless.
        $this->assertStringContainsString(
            implode(',', AttendanceSheet::COLUMNS),
            $csv->streamedContent()
        );
    }

    public function test_a_student_cannot_download_the_template(): void
    {
        $this->actingAs($this->student());

        $this->get(route('attendance.template'))->assertForbidden();
    }

    /**
     * The round trip that matters: the file CGS is handed, filled in and sent
     * straight back, must import cleanly. This is what stops the template and
     * the importer drifting apart.
     */
    public function test_the_downloaded_template_imports_without_edits(): void
    {
        $this->student('22001001', 'one@test.my');
        $this->student('22001002', 'two@test.my');
        $this->actingAs($this->cgs());

        $this->post(route('attendance.upload'), [
            'csv_file' => $this->sheet(AttendanceSheet::sampleRows()),
        ])->assertRedirect();

        $this->assertSame(2, AttendanceRecord::count());
        // 18/20 — derived, never read from the sheet.
        $this->assertSame('90.00', AttendanceRecord::orderBy('id')->first()->percentage);
    }

    public function test_a_bad_row_is_skipped_with_a_reason_and_the_good_rows_still_save(): void
    {
        $this->student('22001001');
        $this->actingAs($this->cgs());

        $response = $this->post(route('attendance.upload'), [
            'csv_file' => $this->sheet([
                ['22001001', '2026-08-31', 18, 20],      // fine
                ['99999999', '2026-08-31', 18, 20],      // nobody
                ['22001001', 'not-a-date', 18, 20],      // unparseable
                ['22001001', '2026-07-31', 25, 20],      // attended > total
                ['22001001', '2026-06-30', 5, 0],        // zero sessions
            ]),
        ]);

        $response->assertRedirect();

        // The good row is saved rather than the whole upload failing.
        $this->assertSame(1, AttendanceRecord::count());

        $warning = session('warning');
        $this->assertNotNull($warning, 'Skipped rows must be reported, not silently dropped.');
        $this->assertStringContainsString('Row 3', $warning);
        $this->assertStringContainsString('99999999', $warning);
    }

    public function test_a_header_that_does_not_match_is_rejected_outright(): void
    {
        $this->actingAs($this->cgs());

        $path = tempnam(sys_get_temp_dir(), 'bad').'.csv';
        file_put_contents($path, "student,date,went,total\n22001001,2026-08-31,18,20\n");

        $this->post(route('attendance.upload'), [
            'csv_file' => new UploadedFile($path, 'bad.csv', 'text/csv', null, true),
        ])->assertRedirect();

        $this->assertNotNull(session('error'));
        $this->assertSame(0, AttendanceRecord::count());
    }

    /**
     * The field that actually breaks real uploads. A CSV opened and re-saved
     * through Excel comes back with the date rewritten into the local format,
     * which used to reach MySQL raw and fail the whole import under strict
     * mode.
     */
    public function test_dates_are_accepted_in_the_formats_excel_writes(): void
    {
        $this->assertSame('2026-08-31', AttendanceSheet::parseDate('2026-08-31'));
        $this->assertSame('2026-08-31', AttendanceSheet::parseDate('31/08/2026'));
        $this->assertSame('2026-08-31', AttendanceSheet::parseDate('31-08-2026'));
        $this->assertSame('2026-08-31', AttendanceSheet::parseDate(new \DateTimeImmutable('2026-08-31')));

        // Day-first, because every date the portal shows is day-first.
        $this->assertSame('2026-04-03', AttendanceSheet::parseDate('03/04/2026'));

        // And nonsense stays nonsense rather than rolling over into a date.
        $this->assertNull(AttendanceSheet::parseDate('31/13/2026'));
        $this->assertNull(AttendanceSheet::parseDate('not-a-date'));
        $this->assertNull(AttendanceSheet::parseDate(''));
    }

    public function test_reuploading_a_period_replaces_it_rather_than_duplicating(): void
    {
        $this->student('22001001');
        $this->actingAs($this->cgs());

        $this->post(route('attendance.upload'), ['csv_file' => $this->sheet([['22001001', '2026-08-31', 10, 20]])]);
        $this->post(route('attendance.upload'), ['csv_file' => $this->sheet([['22001001', '2026-08-31', 18, 20]])]);

        $this->assertSame(1, AttendanceRecord::count());
        $this->assertSame('90.00', AttendanceRecord::first()->percentage);
    }

    public function test_falling_below_the_threshold_alerts_the_student_and_supervisor(): void
    {
        Notification::fake();

        $supervisor = User::create([
            'name' => 'Dr Aisyah', 'email' => 'sup@test.my',
            'password' => 'password', 'role' => Role::SUPERVISOR,
        ]);

        $student = $this->student();
        $student->update(['supervisor_id' => $supervisor->id]);

        $this->actingAs($this->cgs());

        // 12/20 = 60%, well under the mandatory 80%.
        $this->post(route('attendance.upload'), ['csv_file' => $this->sheet([['22001001', '2026-08-31', 12, 20]])]);

        $this->assertTrue(AttendanceRecord::first()->at_risk);
        Notification::assertSentTo($student, AttendanceAtRisk::class);
        Notification::assertSentTo($supervisor, AttendanceAtRisk::class);
    }

    /* -----------------------------------------------------------------
     | Module 3 — Supervision: the documentation the scope asks for
     |------------------------------------------------------------------*/

    public function test_a_supervision_request_requires_a_document(): void
    {
        $supervisor = User::create([
            'name' => 'Dr Aisyah', 'email' => 'sup@test.my',
            'password' => 'password', 'role' => Role::SUPERVISOR,
        ]);

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

        $supervisor = User::create([
            'name' => 'Dr Aisyah', 'email' => 'sup@test.my',
            'password' => 'password', 'role' => Role::SUPERVISOR,
        ]);

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

    /* -----------------------------------------------------------------
     | Module 4 — Certification: generate, format, AND dispatch
     |------------------------------------------------------------------*/

    public function test_final_approval_generates_the_letter_and_dispatches_it(): void
    {
        Notification::fake();

        $student = $this->student();
        $director = User::create([
            'name' => 'En Zulkifly', 'email' => 'director@test.my',
            'password' => 'password', 'role' => Role::SENIOR_DIRECTOR_CGS,
        ]);

        $application = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'ga_certification',
            'status' => Application::STATUS_DRAFT,
        ]);

        GaCertificationDetail::create([
            'application_id' => $application->id,
            'appointment_type' => 'GA',
            'period_start' => now()->subYear(),
            'period_end' => now(),
            'purpose' => 'Scholarship application',
        ]);

        app(WorkflowEngine::class)->submit($application);

        // Clear the first stage so the Senior Director's decision is final.
        app(WorkflowEngine::class)->decide($application, $this->cgs(), 'approve');

        $this->actingAs($director)
            ->post(route('ga-certification.decide', $application), ['decision' => 'approve'])
            ->assertRedirect();

        $application->refresh();
        $this->assertSame(Application::STATUS_APPROVED, $application->status);

        // Generated, stored as a real document behind the authorised route...
        $certificate = $application->documents()->sole();
        $this->assertSame('GA/GRA Certification Letter', $certificate->doc_type);

        // ...and actually sent, which is the half the scope asked for and the
        // generic decision email did not do.
        Notification::assertSentTo($student, CertificationIssued::class);
    }
}
