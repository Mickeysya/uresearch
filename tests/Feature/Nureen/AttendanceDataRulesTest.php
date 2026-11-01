<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Database\Seeders\NureenAttendanceDemoSeeder;
use App\Modules\Nureen\Models\AttendanceAppealDetail;
use App\Modules\Nureen\Models\AttendanceRecord;
use App\Modules\Nureen\Models\AttendanceRiskState;
use App\Modules\Nureen\Support\AttendanceEarlyWarning;
use App\Modules\Nureen\Support\AttendanceIngestor;
use App\Modules\Nureen\Support\AttendanceProjector;
use App\Modules\Nureen\Support\AttendanceRiskEvaluator;
use App\Modules\Nureen\Support\AttendanceSources\SimulatedSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Nureen\Concerns\FilesAttendanceAppeals;
use Tests\Feature\Nureen\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The rules that keep attendance data sound: no period recorded before it
 * has ended, no projection across mixed period lengths, a clean weekly demo
 * semester, appeals that carry CGS's required documents and record what was
 * requested and what was approved, and the configurable rule for counting
 * excused sessions.
 */
class AttendanceDataRulesTest extends TestCase
{
    use FilesAttendanceAppeals;
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    /* -----------------------------------------------------------------
     | 1. Never a period that ends after today
     |------------------------------------------------------------------*/

    public function test_the_simulated_source_never_writes_a_period_ending_after_today(): void
    {
        $this->student();

        // Sunday 1 Nov: the last completed week ended Friday 30 Oct, not 6 Nov.
        $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00'));
        $this->assertSame(['2026-10-30'], (new SimulatedSource())->pull()->pluck('period_end')->unique()->values()->all());

        // On a Friday, that day's week has ended.
        $this->travelTo(CarbonImmutable::parse('2026-10-30 18:00'));
        $this->assertSame(['2026-10-30'], (new SimulatedSource())->pull()->pluck('period_end')->unique()->values()->all());

        // Wednesday: the previous Friday.
        $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00'));
        $this->assertSame(['2026-10-30'], (new SimulatedSource())->pull()->pluck('period_end')->unique()->values()->all());
    }

    public function test_no_source_can_record_a_future_period(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00'));
        $this->student();

        $summary = (new AttendanceIngestor())->ingest(collect([
            ['matric_no' => '22001001', 'period_end' => '2026-11-06', 'sessions_attended' => 9, 'sessions_total' => 10, 'line' => 2],
            ['matric_no' => '22001001', 'period_end' => '2026-10-30', 'sessions_attended' => 9, 'sessions_total' => 10, 'line' => 3],
        ]));

        $this->assertSame(1, $summary->rejected);
        $this->assertStringContainsString('2026-11-06 is in the future', $summary->reasons[0]);
        $this->assertSame(['2026-10-30'], AttendanceRecord::pluck('period_end')->map->toDateString()->all());
    }

    /* -----------------------------------------------------------------
     | 2. Mixed period lengths: rule-based flag only
     |------------------------------------------------------------------*/

    public function test_mixed_period_lengths_skip_the_projection_but_keep_the_rule(): void
    {
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00'));
        config(['nureen.attendance.semester_start' => '2026-08-01']);

        $student = $this->student();
        foreach ([['2026-08-31', 38, 40], ['2026-09-30', 36, 40], ['2026-10-23', 8, 10], ['2026-10-30', 7, 10]] as [$end, $a, $t]) {
            $record = AttendanceRecord::recordPeriod($student->id, $end, $a, $t);
            $record->update(['at_risk' => AttendanceRiskEvaluator::isAtRisk($record)]);
        }

        app(AttendanceEarlyWarning::class)->run();

        $state = AttendanceRiskState::where('student_id', $student->id)->sole();
        $this->assertTrue($state->at_risk);
        $this->assertSame('current', $state->reason);   // the latest week is 70%
        $this->assertNull($state->projected_percentage);
        $this->assertNull($state->trend_slope);
        $this->assertSame(AttendanceProjector::INCONSISTENT_PERIODS, $state->projection_status);

        $this->actingAs($this->cgs())
            ->get(route('attendance.at-risk'))
            ->assertSee('Projection unavailable: inconsistent periods');
    }

    /* -----------------------------------------------------------------
     | 3. The demo semester
     |------------------------------------------------------------------*/

    public function test_the_demo_seeder_gives_each_demo_student_one_clean_weekly_semester(): void
    {
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00'));
        config(['nureen.attendance.semester_start' => '2026-10-05']);

        $cases = [];
        foreach ([NureenAttendanceDemoSeeder::PROJECTION_ONLY, NureenAttendanceDemoSeeder::CURRENT_RULE, NureenAttendanceDemoSeeder::STEADY, '22001013'] as $i => $matric) {
            $cases[$matric] = $this->student($matric, "demo{$i}@test.my");
        }
        $real = $this->student('23999999', 'real@test.my');

        // Old mixed history that must go: a monthly row and a future weekly one.
        AttendanceRecord::recordPeriod($cases['22001013']->id, '2026-09-30', 30, 40);
        AttendanceRecord::recordPeriod($cases['22001013']->id, '2026-11-06', 5, 5);
        AttendanceRecord::recordPeriod($real->id, '2026-09-30', 30, 40);

        $this->seed(NureenAttendanceDemoSeeder::class);

        foreach ($cases as $student) {
            $ends = AttendanceRecord::where('student_id', $student->id)->orderBy('period_end')->pluck('period_end')->map->toDateString()->all();
            $this->assertSame(NureenAttendanceDemoSeeder::WEEKS, $ends);
            $this->assertSame('weekly', AttendanceProjector::periodLength($ends));
        }

        // A student not on the demo list is never touched.
        $this->assertSame(1, AttendanceRecord::where('student_id', $real->id)->count());

        app(AttendanceEarlyWarning::class)->run();

        $reason = fn (string $matric) => AttendanceRiskState::where('student_id', $cases[$matric]->id)->value('reason');
        $this->assertSame('projected', $reason(NureenAttendanceDemoSeeder::PROJECTION_ONLY));
        $this->assertSame('both', $reason(NureenAttendanceDemoSeeder::CURRENT_RULE));
        $this->assertNull($reason(NureenAttendanceDemoSeeder::STEADY));
        $this->assertNull($reason('22001013'));

        $projectionOnly = AttendanceRiskState::where('student_id', $cases[NureenAttendanceDemoSeeder::PROJECTION_ONLY]->id)->sole();
        $this->assertSame(95.0, $projectionOnly->current_percentage);
        $this->assertSame(75.0, $projectionOnly->projected_percentage);
    }

    /* -----------------------------------------------------------------
     | 4. Appeals: required documents; approve fewer, never more
     |------------------------------------------------------------------*/

    protected function appealFor(User $student, int $requested): Application
    {
        $record = AttendanceRecord::where('student_id', $student->id)->latest('period_end')->first();

        $this->actingAs($student)->post(route('attendance-appeal.store'), $this->appealDocuments() + [
            'attendance_record_id' => $record->id,
            'sessions_requested' => $requested,
            'reason' => 'I was on approved medical leave for the sessions marked absent that week.',
        ])->assertSessionHasNoErrors();

        return Application::where('module_type', 'attendance_appeal')->latest('id')->first();
    }

    public function test_both_required_documents_are_enforced(): void
    {
        $student = $this->student();
        $base = ['reason' => 'The UTrace export for this semester does not match my own timetable at all.'];

        foreach (config('nureen.attendance_appeal.required_documents') as $field => $label) {
            $documents = $this->appealDocuments();
            unset($documents[$field]);

            $this->actingAs($student)
                ->post(route('attendance-appeal.store'), $documents + $base)
                ->assertSessionHasErrors([$field => "Attach your {$label}."]);
        }

        $this->assertSame(0, Application::where('module_type', 'attendance_appeal')->count());

        $this->actingAs($student)->post(route('attendance-appeal.store'), $this->appealDocuments() + $base)->assertSessionHasNoErrors();
        $types = Application::where('module_type', 'attendance_appeal')->sole()->documents()->pluck('doc_type')->sort()->values()->all();
        $this->assertSame(['Justification Letter', 'Monthly Activity Breakdown'], $types);

        $this->actingAs($student)->get(route('attendance-appeal.create'))
            ->assertSee('Justification Letter')
            ->assertSee('Monthly Activity Breakdown');
    }

    /**
     * Regression: the form listed periods through a method that had been
     * renamed, which only failed once a student HAD a period with missed
     * sessions -- an empty list never ran that line.
     */
    public function test_the_appeal_form_lists_each_period_with_missed_sessions(): void
    {
        $student = $this->student();
        AttendanceRecord::recordPeriod($student->id, '2026-10-23', 10, 10);
        AttendanceRecord::recordPeriod($student->id, '2026-10-30', 7, 10)->update(['sessions_excused' => 1]);

        $this->actingAs($student)->get(route('attendance-appeal.create'))
            ->assertOk()
            ->assertSee('Period ending 30 Oct 2026: attended 7 of 10, 1 already excused, 2 missed')
            ->assertDontSee('Period ending 23 Oct 2026')   // nothing missed, nothing to appeal
            ->assertSee('data-missed="2"', false);
    }

    public function test_cgs_can_approve_fewer_sessions_than_requested_and_both_are_recorded(): void
    {
        Notification::fake();
        $student = $this->student();
        AttendanceRecord::recordPeriod($student->id, '2026-10-30', 4, 10);
        $appeal = $this->appealFor($student, 6);
        $cgs = $this->cgs();

        $this->actingAs($cgs)->get(route('attendance-appeal.queue'))
            ->assertSee('Sessions to approve')
            ->assertSee('max="6"', false);

        // More than requested is refused, and nothing is decided.
        $this->actingAs($cgs)
            ->post(route('attendance-appeal.decide', $appeal), ['decision' => 'approve', 'sessions_approved' => 7])
            ->assertSessionHasErrors('sessions_approved');
        $this->assertSame(Application::STATUS_PENDING, $appeal->fresh()->status);

        $this->actingAs($cgs)
            ->post(route('attendance-appeal.decide', $appeal), ['decision' => 'approve', 'sessions_approved' => 3])
            ->assertSessionHas('status', fn ($s) => str_contains($s, '3 of 6 requested sessions excused'));

        $detail = AttendanceAppealDetail::where('application_id', $appeal->id)->sole();
        $this->assertSame(6, $detail->sessions_requested);
        $this->assertSame(3, $detail->sessions_approved);

        $record = AttendanceRecord::where('student_id', $student->id)->sole();
        $this->assertSame(3, $record->sessions_excused);
        $this->assertSame('70.00', (string) $record->percentage);  // (4 + 3) / 10
    }

    public function test_approved_sessions_default_to_the_number_requested(): void
    {
        Notification::fake();
        $student = $this->student();
        AttendanceRecord::recordPeriod($student->id, '2026-10-30', 4, 10);
        $appeal = $this->appealFor($student, 6);

        $this->actingAs($this->cgs())->post(route('attendance-appeal.decide', $appeal), ['decision' => 'approve']);

        $this->assertSame(6, AttendanceAppealDetail::where('application_id', $appeal->id)->value('sessions_approved'));
        $this->assertSame(6, AttendanceRecord::where('student_id', $student->id)->value('sessions_excused'));
    }

    public function test_a_rejected_appeal_approves_nothing(): void
    {
        Notification::fake();
        $student = $this->student();
        AttendanceRecord::recordPeriod($student->id, '2026-10-30', 4, 10);
        $appeal = $this->appealFor($student, 6);

        $this->actingAs($this->cgs())->post(route('attendance-appeal.decide', $appeal), [
            'decision' => 'reject', 'remarks' => 'No supporting evidence for those dates.',
        ]);

        $this->assertNull(AttendanceAppealDetail::where('application_id', $appeal->id)->value('sessions_approved'));
        $this->assertSame(0, AttendanceRecord::where('student_id', $student->id)->value('sessions_excused'));
    }

    /* -----------------------------------------------------------------
     | 5. How excused sessions count -- both settings
     |------------------------------------------------------------------*/

    protected function excusedRecord(): AttendanceRecord
    {
        $student = $this->student();
        $record = AttendanceRecord::recordPeriod($student->id, '2026-10-30', 12, 20);
        $record->update(['sessions_excused' => 4]);

        return $record->fresh();
    }

    public function test_count_as_attended_adds_excused_sessions_to_attended(): void
    {
        config(['nureen.attendance.excused_sessions' => 'count_as_attended', 'nureen.attendance.semester_start' => null]);
        $record = $this->excusedRecord();

        $this->assertSame(16, $record->countedAttended());
        $this->assertSame(20, $record->countedTotal());
        $this->assertSame('80.00', (string) $record->percentage);
        $this->assertSame(80.0, AttendanceRiskEvaluator::assess($record->student_id)['current']);
    }

    public function test_exclude_from_total_takes_excused_sessions_out_of_the_period(): void
    {
        config(['nureen.attendance.excused_sessions' => 'exclude_from_total', 'nureen.attendance.semester_start' => null]);
        $record = $this->excusedRecord();

        $this->assertSame(12, $record->countedAttended());
        $this->assertSame(16, $record->countedTotal());
        $this->assertSame('75.00', (string) $record->percentage);
        $this->assertSame(75.0, AttendanceRiskEvaluator::assess($record->student_id)['current']);

        // Every missed session excused: nothing countable was missed.
        $record->update(['sessions_excused' => 8]);
        $this->assertSame('100.00', (string) $record->fresh()->percentage);
    }

    public function test_changing_the_setting_recalculates_stored_figures_at_the_next_check(): void
    {
        Notification::fake();
        config(['nureen.attendance.excused_sessions' => 'count_as_attended', 'nureen.attendance.semester_start' => null]);
        $record = $this->excusedRecord();
        $this->assertSame('80.00', (string) $record->percentage);

        config(['nureen.attendance.excused_sessions' => 'exclude_from_total']);
        app(AttendanceEarlyWarning::class)->run();

        $this->assertSame('75.00', (string) $record->fresh()->percentage);
    }
}
