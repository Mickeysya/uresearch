<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Nureen\Models\AttendanceRecord;
use App\Modules\Nureen\Models\AttendanceRiskState;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Models\GaExtensionDetail;
use App\Modules\Nureen\Notifications\AttendanceAtRisk;
use App\Modules\Nureen\Support\AttendanceEarlyWarning;
use App\Modules\Nureen\Support\AttendanceIngestor;
use App\Modules\Nureen\Support\AttendanceLeave;
use App\Modules\Nureen\Support\AttendanceProvider;
use App\Modules\Nureen\Support\AttendanceRiskEvaluator;
use App\Modules\Nureen\Support\AttendanceScope;
use App\Modules\Nureen\Support\AttendanceSyncRunner;
use App\Modules\Nureen\Support\StudyProfile;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Nureen\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Attendance against the Postgraduate Handbook 2025:
 *   - UTrace is compulsory only for full-time research students
 *     (config('nureen.attendance.scope'), Support\AttendanceScope, with
 *     what is known about a student from Support\StudyProfile);
 *   - approved annual leave is not absence, and is capped at 14 days a
 *     year split by semester (config('nureen.attendance.leave'));
 *   - the alert states the real consequences.
 *
 * Periods are weekly from Friday 4 September 2026 (the September semester).
 */
class AttendanceHandbookTest extends TestCase
{
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00'));
        config(['nureen.attendance.semester_periods' => 14, 'nureen.attendance.semester_start' => null]);
        Notification::fake();
    }

    protected function studentWith(array $attended, string $matric = '22001001', array $attributes = [], int $total = 20): User
    {
        $supervisor = $this->supervisor("sup-{$matric}@test.my");
        $student = $this->student($matric, "s{$matric}@test.my", $attributes + ['supervisor_id' => $supervisor->id]);
        $friday = CarbonImmutable::parse('2026-09-04');

        foreach (array_values($attended) as $i => $a) {
            AttendanceRecord::recordPeriod($student->id, $friday->addWeeks($i)->toDateString(), $a, $total);
        }

        return $student;
    }

    /** A submitted GA/GRA Certification declaring the student's study profile. */
    protected function declareOnCertification(User $student, string $mode, string $type, string $level = 'phd', ?string $submittedAt = null): void
    {
        $application = Application::create([
            'student_id' => $student->id, 'submitted_by_id' => $student->id, 'module_type' => 'ga_certification',
            'status' => Application::STATUS_PENDING, 'current_stage' => 'cgs_verify', 'submitted_at' => $submittedAt ?? now(),
        ]);
        GaCertificationDetail::create([
            'application_id' => $application->id, 'appointment_type' => 'GA',
            'study_level' => $type, 'mode_of_study' => $mode, 'level_of_study' => $level,
        ]);
    }

    protected function check(): void
    {
        app(AttendanceEarlyWarning::class)->run();
    }

    /* -----------------------------------------------------------------
     | Study profile
     |------------------------------------------------------------------*/

    public function test_the_study_profile_reads_the_programme_text_for_mode_and_level_only(): void
    {
        $this->assertSame(['mode' => 'part_time', 'type' => null, 'level' => 'phd'], StudyProfile::fromProgramme('PhD Part-Time'));
        $this->assertSame(['mode' => 'full_time', 'type' => null, 'level' => 'msc'], StudyProfile::fromProgramme('MSc Full-Time'));
        $this->assertSame(['mode' => null, 'type' => null, 'level' => 'msc'], StudyProfile::fromProgramme('Master of Science (Computer and Information Sciences)'));
        $this->assertSame(['mode' => null, 'type' => null, 'level' => null], StudyProfile::fromProgramme(null));
    }

    public function test_the_latest_declaration_wins_over_the_programme_and_drafts_never_count(): void
    {
        $student = $this->student(attributes: ['programme' => 'PhD Part-Time']);
        $this->assertFalse(StudyProfile::for($student)->isFullTimeResearch());

        // A draft is not a declaration.
        $draft = Application::create(['student_id' => $student->id, 'submitted_by_id' => $student->id, 'module_type' => 'ga_certification', 'status' => Application::STATUS_DRAFT]);
        GaCertificationDetail::create(['application_id' => $draft->id, 'study_level' => 'research', 'mode_of_study' => 'full_time']);
        $this->assertSame('part_time', StudyProfile::for($student)->mode);

        $this->declareOnCertification($student, 'full_time', 'research', submittedAt: '2026-10-01');
        $profile = StudyProfile::for($student);
        $this->assertTrue($profile->isFullTimeResearch());
        $this->assertSame('GA/GRA Certification', $profile->source);

        // A later GA Extension saying part-time is the newest declaration.
        $ext = Application::create(['student_id' => $student->id, 'submitted_by_id' => $student->id, 'module_type' => 'ga_extension', 'status' => Application::STATUS_PENDING, 'current_stage' => 'supervisor', 'submitted_at' => '2026-10-20']);
        GaExtensionDetail::create(['application_id' => $ext->id, 'mode_of_study' => 'part_time', 'study_type' => 'research']);
        $this->assertFalse(StudyProfile::for($student)->isFullTimeResearch());
        $this->assertSame('GA Extension', StudyProfile::for($student)->source);
    }

    public function test_coursework_or_odl_is_never_full_time_research_and_unknown_stays_unknown(): void
    {
        $coursework = $this->student('22000001', 'c@test.my');
        $this->declareOnCertification($coursework, 'full_time', 'coursework');
        $this->assertFalse(StudyProfile::for($coursework)->isFullTimeResearch());

        $odl = $this->student('22000002', 'o@test.my');
        $this->declareOnCertification($odl, 'full_time_odl', 'research');
        $this->assertFalse(StudyProfile::for($odl)->isFullTimeResearch());

        // Full-time on the profile, type never declared.
        $unknown = $this->student('22000003', 'u@test.my', ['programme' => 'MSc Full-Time']);
        $this->assertNull(StudyProfile::for($unknown)->isFullTimeResearch());
    }

    /* -----------------------------------------------------------------
     | Scope switch
     |------------------------------------------------------------------*/

    public function test_scope_all_flags_and_alerts_a_part_time_student_as_before(): void
    {
        config(['nureen.attendance.scope' => 'all']);
        $student = $this->studentWith([10, 10, 10], attributes: ['programme' => 'PhD Part-Time']);

        $this->check();

        $this->assertTrue(AttendanceRiskState::where('student_id', $student->id)->sole()->at_risk);
        $this->assertTrue(AttendanceScope::monitors($student));
        Notification::assertSentTo($student, AttendanceAtRisk::class);
    }

    public function test_scope_full_time_research_leaves_a_part_time_student_unflagged_and_unalerted(): void
    {
        config(['nureen.attendance.scope' => 'full_time_research']);
        $student = $this->studentWith([10, 10, 10], attributes: ['programme' => 'PhD Part-Time']);

        $this->check();

        $this->assertFalse(AttendanceScope::monitors($student));
        $this->assertFalse(AttendanceRiskEvaluator::assess($student->id)['at_risk']);
        $this->assertFalse(AttendanceRecord::where('student_id', $student->id)->where('at_risk', true)->exists());
        $this->assertFalse((bool) AttendanceRiskState::where('student_id', $student->id)->value('at_risk'));
        Notification::assertNotSentTo($student, AttendanceAtRisk::class);

        // Core's dashboards hear the same; the student's card says why.
        $this->assertFalse(app(AttendanceProvider::class)->latestFor($student)->at_risk);
        $this->assertSame('Not monitored', app(AttendanceProvider::class)->actionFor($student)['value']);
        $this->assertStringContainsString('Part-time', AttendanceScope::exclusionReason($student));
    }

    public function test_scope_full_time_research_still_flags_a_full_time_research_student(): void
    {
        config(['nureen.attendance.scope' => 'full_time_research']);
        $student = $this->studentWith([10, 10, 10]);
        $this->declareOnCertification($student, 'full_time', 'research');

        $this->check();

        $this->assertTrue(AttendanceRiskState::where('student_id', $student->id)->sole()->at_risk);
        Notification::assertSentTo($student, AttendanceAtRisk::class);
    }

    public function test_unknown_students_are_included_by_default_and_excluded_on_request(): void
    {
        config(['nureen.attendance.scope' => 'full_time_research']);
        $student = $this->studentWith([10, 10, 10]);

        $this->assertTrue(AttendanceScope::monitors($student));

        config(['nureen.attendance.scope_unknown' => 'exclude']);
        $this->assertFalse(AttendanceScope::monitors($student));
        $this->check();
        Notification::assertNotSentTo($student, AttendanceAtRisk::class);
    }

    public function test_switching_scope_on_clears_a_flag_from_before(): void
    {
        config(['nureen.attendance.scope' => 'all']);
        $student = $this->studentWith([10, 10, 10], attributes: ['programme' => 'MSc Part-Time']);
        $this->check();
        $this->assertTrue(AttendanceRiskState::where('student_id', $student->id)->sole()->at_risk);

        config(['nureen.attendance.scope' => 'full_time_research']);
        $this->check();

        $this->assertFalse(AttendanceRiskState::where('student_id', $student->id)->sole()->at_risk);
        $this->assertFalse(AttendanceRecord::where('student_id', $student->id)->where('at_risk', true)->exists());
        $this->actingAs($this->cgs())->get(route('attendance.at-risk'))->assertOk()->assertDontSee('22001001');
    }

    /* -----------------------------------------------------------------
     | Approved leave counts like excused sessions
     |------------------------------------------------------------------*/

    public function test_approved_leave_counts_as_attended_by_default(): void
    {
        config(['nureen.attendance.excused_sessions' => 'count_as_attended']);
        $student = $this->studentWith([14]);

        $record = AttendanceRecord::recordLeave($student->id, '2026-09-04', 4);

        $this->assertSame(18, $record->countedAttended());
        $this->assertSame(20, $record->countedTotal());
        $this->assertSame('90.00', (string) $record->fresh()->percentage);
        $this->assertSame(2, $record->sessionsMissed());
    }

    public function test_approved_leave_leaves_the_total_under_exclude_from_total(): void
    {
        config(['nureen.attendance.excused_sessions' => 'exclude_from_total']);
        $student = $this->studentWith([14]);

        $record = AttendanceRecord::recordLeave($student->id, '2026-09-04', 4);

        $this->assertSame(14, $record->countedAttended());
        $this->assertSame(16, $record->countedTotal());
        $this->assertSame('87.50', (string) $record->fresh()->percentage);
    }

    public function test_leave_takes_a_student_out_of_risk_like_an_excusal_would(): void
    {
        $student = $this->studentWith([15]);
        $this->assertTrue(AttendanceRiskEvaluator::isAtRisk(AttendanceRecord::sole()));

        AttendanceRecord::recordLeave($student->id, '2026-09-04', 3);

        $this->assertFalse(AttendanceRecord::sole()->at_risk);
    }

    /* -----------------------------------------------------------------
     | Leave through every way attendance arrives
     |------------------------------------------------------------------*/

    public function test_the_ingestor_reads_leave_days_and_a_blank_keeps_what_is_recorded(): void
    {
        $student = $this->student();

        $summary = app(AttendanceIngestor::class)->ingest(collect([
            ['matric_no' => '22001001', 'period_end' => '2026-09-04', 'sessions_attended' => 15, 'sessions_total' => 20, 'leave_days' => '3', 'line' => 2],
        ]));
        $this->assertSame(0, $summary->rejected);
        $this->assertSame(3, AttendanceRecord::sole()->leave_days);

        // Re-sent without the column, or blank: leave stays.
        app(AttendanceIngestor::class)->ingest(collect([['matric_no' => '22001001', 'period_end' => '2026-09-04', 'sessions_attended' => 16, 'sessions_total' => 20]]));
        app(AttendanceIngestor::class)->ingest(collect([['matric_no' => '22001001', 'period_end' => '2026-09-04', 'sessions_attended' => 16, 'sessions_total' => 20, 'leave_days' => '']]));
        $this->assertSame(3, AttendanceRecord::sole()->leave_days);
        $this->assertSame(16, AttendanceRecord::sole()->sessions_attended);
        $this->assertSame($student->id, AttendanceRecord::sole()->student_id);
    }

    public function test_bad_leave_days_are_rejected_with_a_reason(): void
    {
        $this->student();

        $summary = app(AttendanceIngestor::class)->ingest(collect([
            ['matric_no' => '22001001', 'period_end' => '2026-09-04', 'sessions_attended' => 15, 'sessions_total' => 20, 'leave_days' => 'two', 'line' => 2],
            ['matric_no' => '22001001', 'period_end' => '2026-09-11', 'sessions_attended' => 15, 'sessions_total' => 20, 'leave_days' => '6', 'line' => 3],
            ['matric_no' => '22001001', 'period_end' => '2026-09-18', 'sessions_attended' => 15, 'sessions_total' => 20, 'leave_days' => '-1', 'line' => 4],
        ]));

        $this->assertSame(3, $summary->rejected);
        $this->assertStringContainsString('Row 2: leave_days must be a whole number', $summary->reasons[0]);
        $this->assertStringContainsString('Row 3: 6 leave days plus 15 attended is more than the 20 sessions', $summary->reasons[1]);
        $this->assertSame(0, AttendanceRecord::count());
    }

    public function test_the_manual_upload_accepts_the_optional_leave_column(): void
    {
        $this->student();
        $csv = "matric_no,period_end,sessions_attended,sessions_total,leave_days\n22001001,2026-09-04,15,20,2\n";

        $this->actingAs($this->cgs())
            ->post(route('attendance.upload'), ['csv_file' => UploadedFile::fake()->createWithContent('export.csv', $csv)])
            ->assertSessionHas('status');

        $this->assertSame(2, AttendanceRecord::sole()->leave_days);
    }

    public function test_the_four_column_sheet_still_works_and_sets_no_leave(): void
    {
        $this->student();
        $csv = "matric_no,period_end,sessions_attended,sessions_total\n22001001,2026-09-04,15,20\n";

        $this->actingAs($this->cgs())->post(route('attendance.upload'), ['csv_file' => UploadedFile::fake()->createWithContent('export.csv', $csv)]);

        $this->assertSame(0, AttendanceRecord::sole()->leave_days);
    }

    public function test_the_push_api_and_sync_carry_leave_the_same_way(): void
    {
        $this->student();
        config(['nureen.attendance.ingest_key' => 'secret']);

        $this->postJson(route('attendance.ingest'), ['records' => [
            ['matric_no' => '22001001', 'period_end' => '2026-09-04', 'sessions_attended' => 15, 'sessions_total' => 20, 'leave_days' => 2],
        ]], ['X-Api-Key' => 'secret'])->assertStatus(202);

        app(AttendanceSyncRunner::class)->run('api');

        $this->assertSame(2, AttendanceRecord::sole()->leave_days);
    }

    /* -----------------------------------------------------------------
     | CGS records leave
     |------------------------------------------------------------------*/

    public function test_cgs_records_leave_for_a_period_on_file(): void
    {
        $student = $this->studentWith([15]);

        $cgs = $this->cgs();
        $this->actingAs($cgs)
            ->post(route('attendance.leave.store'), ['matric_no' => '22001001', 'period_end' => '2026-09-04', 'leave_days' => 3])
            ->assertRedirect(route('attendance.leave', ['year' => 2026]))
            ->assertSessionHas('status');

        $this->assertSame(3, AttendanceRecord::sole()->leave_days);
        $this->actingAs($cgs)->get(route('attendance.leave'))->assertOk()->assertSee($student->name)->assertSee('3 / 5');
    }

    public function test_recording_leave_is_refused_without_the_period_or_beyond_the_missed_sessions(): void
    {
        $this->studentWith([15]);
        $cgs = $this->cgs();

        $this->actingAs($cgs)->post(route('attendance.leave.store'), ['matric_no' => '22001001', 'period_end' => '2026-10-30', 'leave_days' => 1])
            ->assertSessionHasErrors('period_end');
        $this->actingAs($cgs)->post(route('attendance.leave.store'), ['matric_no' => '22001001', 'period_end' => '2026-09-04', 'leave_days' => 6])
            ->assertSessionHasErrors('leave_days');
        $this->actingAs($cgs)->post(route('attendance.leave.store'), ['matric_no' => '99999999', 'period_end' => '2026-09-04', 'leave_days' => 1])
            ->assertSessionHasErrors('matric_no');

        $this->assertSame(0, AttendanceRecord::sole()->leave_days);
    }

    public function test_only_cgs_records_leave(): void
    {
        $student = $this->studentWith([15]);

        $this->actingAs($student)->post(route('attendance.leave.store'), ['matric_no' => '22001001', 'period_end' => '2026-09-04', 'leave_days' => 3])->assertForbidden();
        $this->actingAs($this->supervisor('another@test.my'))->get(route('attendance.leave'))->assertForbidden();
    }

    /* -----------------------------------------------------------------
     | The allowance
     |------------------------------------------------------------------*/

    public function test_leave_within_the_allowance_raises_no_warning(): void
    {
        $student = $this->studentWith([10, 10]);
        AttendanceRecord::recordLeave($student->id, '2026-09-04', 3);
        AttendanceRecord::recordLeave($student->id, '2026-09-11', 2);

        $usage = app(AttendanceLeave::class)->usage($student->id, 2026);

        $this->assertSame(5, $usage['semesters']['sep']['days']);
        $this->assertFalse($usage['semesters']['sep']['over']);
        $this->assertSame([], app(AttendanceLeave::class)->problems($usage));
    }

    public function test_leave_over_a_semesters_allowance_warns_cgs(): void
    {
        $this->studentWith([10, 10]);
        $cgs = $this->cgs();

        $this->actingAs($cgs)->post(route('attendance.leave.store'), ['matric_no' => '22001001', 'period_end' => '2026-09-04', 'leave_days' => 4]);
        $response = $this->actingAs($cgs)->post(route('attendance.leave.store'), ['matric_no' => '22001001', 'period_end' => '2026-09-11', 'leave_days' => 2]);

        $response->assertSessionHas('leave_warnings', fn ($warnings) => str_contains($warnings[0], 'September semester 2026: 6 days recorded, allowance 5'));
        // Still counted.
        $this->assertSame(2, AttendanceRecord::whereDate('period_end', '2026-09-11')->value('leave_days'));

        $this->actingAs($cgs)->get(route('attendance.at-risk'))
            ->assertSee('over the approved-leave allowance this year')
            ->assertSee('September semester 2026: 6 days recorded, allowance 5', false);
    }

    public function test_leave_over_the_annual_allowance_warns_even_when_each_semester_is_fine_by_config(): void
    {
        // A looser per-semester split, so only the 14-day year is exceeded.
        config(['nureen.attendance.leave.semesters.jan.days' => 10, 'nureen.attendance.leave.semesters.may.days' => 10, 'nureen.attendance.leave.semesters.sep.days' => 10]);
        $student = $this->student();
        foreach (['2026-02-06' => 8, '2026-09-04' => 7] as $date => $leave) {
            AttendanceRecord::recordPeriod($student->id, $date, 10, 20, $leave);
        }

        $usage = app(AttendanceLeave::class)->usage($student->id, 2026);
        $this->assertSame(15, $usage['total']);
        $this->assertTrue($usage['over_year']);
        $this->assertSame(['2026: 15 days recorded, over the 14-day annual allowance.'], app(AttendanceLeave::class)->problems($usage));
    }

    public function test_the_upload_warns_when_the_sheet_puts_someone_over(): void
    {
        $this->student();
        $csv = "matric_no,period_end,sessions_attended,sessions_total,leave_days\n22001001,2026-05-08,10,20,5\n";

        $this->actingAs($this->cgs())
            ->post(route('attendance.upload'), ['csv_file' => UploadedFile::fake()->createWithContent('export.csv', $csv)])
            ->assertSessionHas('leave_warnings', fn ($w) => str_contains($w[0], 'May semester 2026: 5 days recorded, allowance 4'));
    }

    /* -----------------------------------------------------------------
     | The alert's wording
     |------------------------------------------------------------------*/

    public function test_the_alert_states_the_handbook_consequences(): void
    {
        $student = $this->studentWith([10, 10, 10]);
        $this->check();

        Notification::assertSentTo($student, AttendanceAtRisk::class, function (AttendanceAtRisk $n) use ($student) {
            $text = (string) $n->toMail($student)->render();
            $this->assertStringContainsString('GA or GRA allowance may be suspended', $text);
            $this->assertStringContainsString('barred from registering for the next semester', $text);
            $this->assertStringContainsString('international student, CGS may not be able to support your student visa renewal', $text);

            $copy = (string) $n->toMail($student->supervisor)->render();
            $this->assertStringContainsString('GA/GRA allowance to be suspended', $copy);
            $this->assertStringContainsString('student visa renewal', $copy);

            return true;
        });
    }
}
