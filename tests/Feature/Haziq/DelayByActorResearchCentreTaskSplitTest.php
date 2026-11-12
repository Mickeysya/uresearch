<?php

namespace Tests\Feature\Haziq;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Haziq\Models\GaApplicationDetail;
use App\Modules\Haziq\Models\GraGaStudentProfile;
use App\Modules\Haziq\Support\DelayByActorReport;
use App\Modules\Haziq\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Confirmed explicitly 2026-11-10: "ade 2 task die, dah set interview,
 * after interview date kene start counting second task pulak en mark
 * pass or fail, so machine learning delay by actor kene consider bende
 * ni." Research Centre's one stage is really two tasks -- schedule, then
 * mark the outcome once the interview has actually happened -- and the
 * calendar gap waiting for the interview date to arrive is not Research
 * Centre's own delay. See DelayByActorReport::researchCentreDelayHours().
 */
class DelayByActorResearchCentreTaskSplitTest extends TestCase
{
    use RefreshDatabase;

    protected function gaApplicationAtResearchCentreInterview(Carbon $submittedAt): Application
    {
        $student = User::create(['name' => 'Student', 'email' => 'student'.uniqid().'@test.com', 'password' => 'password', 'role' => Role::STUDENT]);
        $application = Application::create(['student_id' => $student->id, 'module_type' => 'ga_application', 'status' => Application::STATUS_APPROVED, 'current_stage' => null, 'submitted_at' => $submittedAt]);

        GaApplicationDetail::create([
            'application_id' => $application->id,
            'profile_id' => GraGaStudentProfile::create(['user_id' => $student->id, 'level_of_study' => 'phd', 'programme' => 'CS', 'admission_date' => now()])->id,
            'proposed_hours_per_week' => 10, 'proposed_allowance' => 2000,
            'service_start_date' => now(), 'service_end_date' => now()->addYears(2),
        ]);

        return $application;
    }

    protected function decidedByResearchCentre(Application $application, Carbon $decidedAt): void
    {
        $officer = User::create(['name' => 'RC Officer', 'email' => 'rc'.uniqid().'@test.com', 'password' => 'password', 'role' => Roles::RESEARCH_CENTRE, 'department' => 'Computing']);
        $history = ApprovalHistory::create(['application_id' => $application->id, 'approver_id' => $officer->id, 'stage_key' => 'research_centre_interview', 'stage_label' => 'Research Centre', 'decision' => 'approved']);
        $history->created_at = $decidedAt;
        $history->save();
    }

    public function test_the_wait_for_the_interview_date_itself_is_never_counted_as_delay(): void
    {
        $submitted = Carbon::parse('2026-01-01 00:00:00');
        $application = $this->gaApplicationAtResearchCentreInterview($submitted);

        // Scheduled right away, interview set two weeks out, decided the
        // same hour the interview happens -- zero delay on either task,
        // even though 14 days of wall-clock time passed.
        GaApplicationDetail::where('application_id', $application->id)->update([
            'interview_scheduled_at' => $submitted->copy(),
            'interview_date' => $submitted->copy()->addDays(14),
        ]);
        $this->decidedByResearchCentre($application, $submitted->copy()->addDays(14));

        $rows = DelayByActorReport::rows('ga_application');
        $row = $rows->firstWhere('stage_key', 'research_centre_interview');

        $this->assertSame(0.0, $row['delay_hours']);
    }

    public function test_slow_to_schedule_is_counted_even_with_a_prompt_outcome(): void
    {
        $submitted = Carbon::parse('2026-01-01 00:00:00');
        $application = $this->gaApplicationAtResearchCentreInterview($submitted);

        GaApplicationDetail::where('application_id', $application->id)->update([
            'interview_scheduled_at' => $submitted->copy()->addHours(50),
            'interview_date' => $submitted->copy()->addDays(14),
        ]);
        $this->decidedByResearchCentre($application, $submitted->copy()->addDays(14));

        $rows = DelayByActorReport::rows('ga_application');
        $row = $rows->firstWhere('stage_key', 'research_centre_interview');

        $this->assertSame(50.0, $row['delay_hours']);
    }

    public function test_slow_to_mark_the_outcome_after_the_interview_is_counted_even_with_prompt_scheduling(): void
    {
        $submitted = Carbon::parse('2026-01-01 00:00:00');
        $application = $this->gaApplicationAtResearchCentreInterview($submitted);

        GaApplicationDetail::where('application_id', $application->id)->update([
            'interview_scheduled_at' => $submitted->copy(),
            'interview_date' => $submitted->copy()->addDays(14),
        ]);
        $this->decidedByResearchCentre($application, $submitted->copy()->addDays(14)->addHours(20));

        $rows = DelayByActorReport::rows('ga_application');
        $row = $rows->firstWhere('stage_key', 'research_centre_interview');

        $this->assertSame(20.0, $row['delay_hours']);
    }

    /**
     * Confirmed explicitly 2026-11-10: "timing interview kena ada from
     * what time end at what time, habis je time tu start counting next
     * phase of task pass or fail." The marking task starts counting from
     * when the interview actually FINISHED, not when it started -- an
     * interview that runs 2pm-4pm gives Research Centre no reasonable
     * way to record an outcome at 2:05pm.
     */
    public function test_marking_delay_counts_from_the_interviews_end_time_not_its_start(): void
    {
        $submitted = Carbon::parse('2026-01-01 00:00:00');
        $application = $this->gaApplicationAtResearchCentreInterview($submitted);

        GaApplicationDetail::where('application_id', $application->id)->update([
            'interview_scheduled_at' => $submitted->copy(),
            'interview_date' => $submitted->copy()->addDays(14)->setTime(14, 0),
            'interview_ends_at' => $submitted->copy()->addDays(14)->setTime(16, 0),
        ]);
        // Decided 3 hours after the interview ENDED (4pm + 3h = 7pm), even
        // though that is 5 hours after it STARTED -- only the 3 counts.
        $this->decidedByResearchCentre($application, $submitted->copy()->addDays(14)->setTime(19, 0));

        $rows = DelayByActorReport::rows('ga_application');
        $row = $rows->firstWhere('stage_key', 'research_centre_interview');

        $this->assertSame(3.0, $row['delay_hours']);
    }

    public function test_falls_back_to_the_start_time_when_the_end_time_is_missing(): void
    {
        $submitted = Carbon::parse('2026-01-01 00:00:00');
        $application = $this->gaApplicationAtResearchCentreInterview($submitted);

        // Old-data shape: interview_ends_at never set.
        GaApplicationDetail::where('application_id', $application->id)->update([
            'interview_scheduled_at' => $submitted->copy(),
            'interview_date' => $submitted->copy()->addDays(14),
        ]);
        $this->decidedByResearchCentre($application, $submitted->copy()->addDays(14)->addHours(5));

        $rows = DelayByActorReport::rows('ga_application');
        $row = $rows->firstWhere('stage_key', 'research_centre_interview');

        $this->assertSame(5.0, $row['delay_hours']);
    }

    public function test_both_delays_sum_together(): void
    {
        $submitted = Carbon::parse('2026-01-01 00:00:00');
        $application = $this->gaApplicationAtResearchCentreInterview($submitted);

        GaApplicationDetail::where('application_id', $application->id)->update([
            'interview_scheduled_at' => $submitted->copy()->addHours(6),
            'interview_date' => $submitted->copy()->addDays(14),
        ]);
        $this->decidedByResearchCentre($application, $submitted->copy()->addDays(14)->addHours(4));

        $rows = DelayByActorReport::rows('ga_application');
        $row = $rows->firstWhere('stage_key', 'research_centre_interview');

        $this->assertSame(10.0, $row['delay_hours']);
    }

    public function test_deciding_before_the_interview_date_never_reads_as_a_negative_delay(): void
    {
        $submitted = Carbon::parse('2026-01-01 00:00:00');
        $application = $this->gaApplicationAtResearchCentreInterview($submitted);

        GaApplicationDetail::where('application_id', $application->id)->update([
            'interview_scheduled_at' => $submitted->copy(),
            'interview_date' => $submitted->copy()->addDays(14),
        ]);
        // Decided a day before the scheduled interview date -- an odd
        // data state, but one the figure should never read as "faster
        // than instant."
        $this->decidedByResearchCentre($application, $submitted->copy()->addDays(13));

        $rows = DelayByActorReport::rows('ga_application');
        $row = $rows->firstWhere('stage_key', 'research_centre_interview');

        $this->assertSame(0.0, $row['delay_hours']);
    }

    public function test_falls_back_to_the_plain_window_when_the_new_timestamp_is_missing(): void
    {
        $submitted = Carbon::parse('2026-01-01 00:00:00');
        $application = $this->gaApplicationAtResearchCentreInterview($submitted);

        // Old-data shape: interview_date set (old column), but
        // interview_scheduled_at never was.
        GaApplicationDetail::where('application_id', $application->id)->update([
            'interview_date' => $submitted->copy()->addDays(14),
        ]);
        $this->decidedByResearchCentre($application, $submitted->copy()->addDays(14)->addHours(3));

        $rows = DelayByActorReport::rows('ga_application');
        $row = $rows->firstWhere('stage_key', 'research_centre_interview');

        $this->assertEqualsWithDelta(14 * 24 + 3, $row['delay_hours'], 0.01);
    }
}
