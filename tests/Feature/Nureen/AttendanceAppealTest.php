<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Nureen\Models\AttendanceAppealDetail;
use App\Modules\Nureen\Models\AttendanceRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Attendance appeals — `docs/scope/nureen.md` Module 1, the dispute half.
 *
 * One stage, straight to CGS: no supervisor sits in between, so the single
 * decision is final and there is no second approver to catch a mistake. The
 * ownership check on `attendance_record_id` is the other half — the field is
 * a student-supplied id, and nothing else stops one student attaching another
 * student's flagged period to their own appeal.
 */
class AttendanceAppealTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected function record(int $studentId, string $periodEnd = '2026-08-31'): AttendanceRecord
    {
        return AttendanceRecord::recordPeriod($studentId, $periodEnd, 12, 20);
    }

    public function test_one_cgs_decision_closes_the_appeal(): void
    {
        $student = $this->student();
        $record = $this->record($student->id);

        $this->actingAs($student)
            ->post(route('attendance-appeal.store'), [
                'attendance_record_id' => $record->id,
                'reason' => 'I was on approved medical leave for four of the sessions marked absent.',
            ])
            ->assertRedirect(route('applications.index'));

        $application = Application::where('module_type', 'attendance_appeal')->sole();
        $this->assertSame(Application::STATUS_PENDING, $application->status);
        $this->assertSame('cgs_review', $application->current_stage);

        $this->actingAs($this->cgs())
            ->post(route('attendance-appeal.decide', $application), ['decision' => 'approve']);

        // Single stage, so the first approval is the last one.
        $this->assertSame(Application::STATUS_APPROVED, $application->fresh()->status);
    }

    public function test_a_student_cannot_appeal_against_another_students_record(): void
    {
        $mine = $this->student();
        $theirs = $this->student('22001099', 'other@test.my');
        $record = $this->record($theirs->id);

        $this->actingAs($mine)
            ->post(route('attendance-appeal.store'), [
                'attendance_record_id' => $record->id,
                'reason' => 'This period was recorded against me in error and should be reviewed.',
            ])
            ->assertForbidden();

        $this->assertSame(0, Application::where('module_type', 'attendance_appeal')->count());
        $this->assertSame(0, AttendanceAppealDetail::count());
    }

    /** Unlike GA Extension and Supervision, the evidence here is welcome but not demanded. */
    public function test_the_supporting_document_is_optional_but_stored_privately_when_given(): void
    {
        Storage::fake('local');

        $student = $this->student();

        $this->actingAs($student)
            ->post(route('attendance-appeal.store'), [
                'reason' => 'The uploaded sheet double-counted the week I was away on fieldwork.',
            ])
            ->assertRedirect(route('applications.index'));

        $this->assertSame(0, Application::sole()->documents()->count());

        $this->actingAs($student)
            ->post(route('attendance-appeal.store'), [
                'reason' => 'Attaching the medical certificate for the sessions in question.',
                'supporting_document' => UploadedFile::fake()->create('mc.pdf', 40, 'application/pdf'),
            ]);

        $withDocument = Application::where('module_type', 'attendance_appeal')->latest('id')->first();
        $document = $withDocument->documents()->sole();

        $this->assertSame('mc.pdf', $document->original_name);
        $this->assertStringStartsWith("applications/{$withDocument->id}/", $document->path);
        $this->assertStringNotContainsString('mc.pdf', $document->path);
    }

    public function test_an_appeal_needs_a_stated_reason(): void
    {
        $this->actingAs($this->student())
            ->post(route('attendance-appeal.store'), ['reason' => 'Wrong.'])
            ->assertSessionHasErrors('reason');

        $this->assertSame(0, Application::where('module_type', 'attendance_appeal')->count());
    }

    /** A general dispute, filed with no particular period named, is still valid. */
    public function test_an_appeal_can_be_filed_without_naming_a_record(): void
    {
        $this->actingAs($this->student())
            ->post(route('attendance-appeal.store'), [
                'reason' => 'The UTrace export for this semester does not match my own timetable at all.',
            ])
            ->assertRedirect(route('applications.index'));

        $detail = AttendanceAppealDetail::sole();
        $this->assertNull($detail->attendance_record_id);
    }
}
