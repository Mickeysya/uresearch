<?php

namespace Tests\Feature\Norhanis;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Norhanis\Models\ClaimsDetail;
use App\Modules\Norhanis\Models\TravelDetail;
use App\Modules\Norhanis\Support\StageDurations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The predicted decision date: working-day arithmetic, the client charter
 * standing in until a stage has history of its own, and the "past charter"
 * flag an approver sees.
 *
 * The clock is fixed at Friday 6 Nov 2026, noon, because every figure here is
 * in working days.
 */
class StageDurationsTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-06 12:00'));
    }

    public function test_working_days_skip_the_weekend_to_the_fraction(): void
    {
        $at = fn (string $when) => Carbon::parse($when);

        // Thursday noon to Friday noon, and Friday noon over the weekend to
        // Monday noon: both one working day.
        $this->assertEqualsWithDelta(1.0, StageDurations::workingDaysBetween($at('2026-11-05 12:00'), $at('2026-11-06 12:00')), 0.0001);
        $this->assertEqualsWithDelta(1.0, StageDurations::workingDaysBetween($at('2026-11-06 12:00'), $at('2026-11-09 12:00')), 0.0001);
        $this->assertSame(0.0, StageDurations::workingDaysBetween($at('2026-11-07 09:00'), $at('2026-11-08 17:00')));

        // And forwards: a day and a half from Friday noon is Tuesday midnight.
        $this->assertSame('2026-11-09 12:00', StageDurations::addWorkingDays($at('2026-11-06 12:00'), 1)->format('Y-m-d H:i'));
        $this->assertSame('2026-11-10 00:00', StageDurations::addWorkingDays($at('2026-11-06 12:00'), 1.5)->format('Y-m-d H:i'));
        // Started on a weekend, the count begins on Monday.
        $this->assertSame('2026-11-09 12:00', StageDurations::addWorkingDays($at('2026-11-07 15:00'), 0.5)->format('Y-m-d H:i'));
    }

    public function test_the_median_is_not_dragged_by_one_forgotten_application(): void
    {
        $this->assertSame(2.0, StageDurations::median([1, 2, 40]));
        $this->assertSame(1.5, StageDurations::median([2, 1, 40, 1]));
    }

    public function test_a_new_claim_is_estimated_from_the_client_charter(): void
    {
        $estimate = app(StageDurations::class)->estimate($this->claim($this->student()));

        // Supervisor 2 + Chair 2 + Non-Exec CGS 3 + Manager 2.
        $this->assertSame(9.0, $estimate['remaining']);
        $this->assertSame(['charter'], array_unique(array_column($estimate['steps'], 'basis')));
        // Nine working days from Friday noon: Thursday 19 Nov, noon.
        $this->assertSame('2026-11-19', $estimate['expected']->toDateString());

        $current = $estimate['steps'][0];
        $this->assertSame('current', $current['state']);
        $this->assertSame(0.0, $current['waited']);
        $this->assertFalse($current['overdue']);
    }

    public function test_a_stage_with_enough_history_uses_its_own_median(): void
    {
        $supervisor = $this->supervisor();
        $engine = app(WorkflowEngine::class);

        // Five claims, each endorsed one working day after it arrived.
        foreach (range(1, StageDurations::MIN_SAMPLES) as $i) {
            $claim = $this->claim($this->student('2200200'.$i, "s{$i}@test.my"));
            $claim->forceFill(['submitted_at' => now()->subDays(8)])->save(); // Thu 29 Oct, noon
            $engine->decide($claim, $supervisor, 'approve');
            $claim->history()->update(['created_at' => now()->subDays(7)]); // Fri 30 Oct, noon
        }

        $durations = new StageDurations();
        $this->assertSame(['days' => 1.0, 'basis' => 'history', 'samples' => 5], $durations->typical('claims_student', 'supervisor'));
        // The Chair has no decisions yet, so the charter still stands.
        $this->assertSame(['days' => 2.0, 'basis' => 'charter', 'samples' => 0], $durations->typical('claims_student', 'chair'));

        $estimate = $durations->estimate($this->claim($this->student('22002999', 'new@test.my')));
        $this->assertSame(8.0, $estimate['remaining']);
    }

    public function test_a_stage_past_its_charter_is_flagged_and_counts_as_due_now(): void
    {
        // A local trip submitted Tuesday noon, still with the supervisor on
        // Friday noon: three working days against a charter of one.
        $travel = $this->localTravel($this->student());
        $travel->forceFill(['submitted_at' => now()->subDays(3)])->save();

        $estimate = app(StageDurations::class)->estimate($travel);
        $current = $estimate['steps'][0];

        $this->assertSame(3.0, $current['waited']);
        $this->assertTrue($current['overdue']);
        // Nothing left for the supervisor; Chair, Non-Exec CGS and the
        // Senior Director at one day each.
        $this->assertSame(3.0, $estimate['remaining']);
        $this->assertSame('senior_director', end($estimate['steps'])['stage']->key);
    }

    public function test_a_closed_application_has_no_estimate(): void
    {
        $claim = $this->claim($this->student());
        app(WorkflowEngine::class)->decide($claim, $this->supervisor(), 'reject');

        $this->assertNull(app(StageDurations::class)->estimate($claim->refresh()));
    }

    public function test_the_travel_page_and_the_approvers_queue_show_the_estimate(): void
    {
        $student = $this->student();
        $supervisor = $this->supervisor();
        $student->forceFill(['supervisor_id' => $supervisor->id])->save();
        $travel = $this->localTravel($student);

        $this->actingAs($student)->get(route('travel.show', $travel))
            ->assertOk()
            ->assertSee('Expected final decision')
            ->assertSee('Thu, 12 Nov 2026')
            // Just the date: the per-stage breakdown is not shown.
            ->assertDontSee('working days from now')
            ->assertDontSee('usually takes');

        $this->actingAs($supervisor)->get(route('travel.queue'))
            ->assertOk()
            ->assertSee('Expected final decision');
    }

    protected function claim(User $student): Application
    {
        $application = Application::create([
            'student_id' => $student->id,
            'module_type' => 'claims_student',
            'status' => Application::STATUS_DRAFT,
        ]);

        ClaimsDetail::create([
            'application_id' => $application->id,
            'bank_name' => 'Maybank',
            'bank_account_no' => '1234567890',
            'total_claims' => 500,
        ]);

        return app(WorkflowEngine::class)->submit($application);
    }

    protected function localTravel(User $student): Application
    {
        $application = Application::create([
            'student_id' => $student->id,
            'module_type' => 'travel',
            'status' => Application::STATUS_DRAFT,
        ]);

        TravelDetail::create([
            'application_id' => $application->id,
            'type_of_request' => 'data_collection',
            'travel_start_date' => now()->addDays(10),
            'travel_end_date' => now()->addDays(12),
            'duration_days' => 3,
            'reason_for_travel' => 'Fieldwork',
            'destination_address' => 'Kuala Lumpur',
            'is_international' => false,
            'contact_person_email' => 'contact@test.my',
            'is_ga_gra' => false,
        ]);

        return app(WorkflowEngine::class)->submit($application);
    }
}
