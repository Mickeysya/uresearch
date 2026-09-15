<?php

namespace Tests\Feature\Norhanis;

use App\Modules\Core\Models\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Travel — `docs/scope/norhanis.md` Module 1.
 *
 * Travel is the reference module and the only one whose chain branches, but it
 * had no test: Claims and Publication, both straight-line chains, did. These
 * cover the branch itself and the derived duration, which is the one number on
 * the form the student is not allowed to supply.
 */
class TravelTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    /** Memoised: MakesUsers::user() creates outright, so a second call collides on the unique email. */
    protected ?\App\Modules\Core\Models\User $sup = null;

    protected function sup(): \App\Modules\Core\Models\User
    {
        return $this->sup ??= $this->supervisor();
    }

    public function test_local_travel_ends_at_the_chair(): void
    {
        $application = $this->submit(['is_international' => 0]);

        $this->actingAs($this->sup())
            ->post(route('travel.decide', $application), ['decision' => 'approve']);

        $this->actingAs($this->chair())
            ->post(route('travel.decide', $application), ['decision' => 'approve']);

        $application->refresh();

        // The Chair holds the final say on local travel -- no CGS, no Dean.
        $this->assertSame(Application::STATUS_APPROVED, $application->status);
    }

    public function test_international_travel_continues_to_cgs_and_the_dean(): void
    {
        $application = $this->submit(['is_international' => 1]);

        $this->actingAs($this->sup())
            ->post(route('travel.decide', $application), ['decision' => 'approve']);

        $this->actingAs($this->chair())
            ->post(route('travel.decide', $application), ['decision' => 'approve']);

        $application->refresh();

        // Same two approvals, but the chain is not finished this time.
        $this->assertSame(Application::STATUS_PENDING, $application->status);
        $this->assertSame('cgs_review', $application->current_stage);

        $this->actingAs($this->cgs())
            ->post(route('travel.decide', $application), ['decision' => 'approve']);

        $this->assertSame('dean', $application->refresh()->current_stage);

        $this->actingAs($this->dean())
            ->post(route('travel.decide', $application), ['decision' => 'approve']);

        $this->assertSame(Application::STATUS_APPROVED, $application->refresh()->status);
    }

    public function test_the_chair_cannot_finish_an_international_application(): void
    {
        $application = $this->submit(['is_international' => 1]);

        $this->actingAs($this->sup())
            ->post(route('travel.decide', $application), ['decision' => 'approve']);

        $this->actingAs($this->chair())
            ->post(route('travel.decide', $application), ['decision' => 'approve']);

        // The Dean must not be skippable by whoever acts next.
        $this->actingAs($this->cgs())
            ->post(route('travel.decide', $application), ['decision' => 'approve']);

        $this->assertNotSame(Application::STATUS_APPROVED, $application->refresh()->status);
    }

    public function test_duration_is_derived_from_the_dates_not_posted(): void
    {
        $application = $this->submit([
            'travel_start_date' => now()->addDays(10)->toDateString(),
            'travel_end_date' => now()->addDays(14)->toDateString(),
            // The legacy form let the student type this. Ignored now.
            'duration_days' => 99,
        ]);

        $detail = \App\Modules\Norhanis\Models\TravelDetail::where('application_id', $application->id)->sole();

        $this->assertSame(5, $detail->duration_days, 'Duration must be computed from the dates, inclusive.');
    }

    public function test_an_end_date_before_the_start_is_rejected(): void
    {
        $this->actingAs($this->student());

        $this->post(route('travel.store'), $this->payload([
            'travel_start_date' => now()->addDays(10)->toDateString(),
            'travel_end_date' => now()->addDays(3)->toDateString(),
        ]))->assertSessionHasErrors('travel_end_date');

        $this->assertSame(0, Application::where('module_type', 'travel')->count());
    }

    public function test_the_form_renders_with_the_wizard_wired_up(): void
    {
        $this->actingAs($this->student());

        $this->get(route('travel.create'))
            ->assertOk()
            // The two halves of the stepper contract. If a refactor drops
            // either, the form silently reverts to one long scroll.
            ->assertSee('data-stepper', false)
            ->assertSee('class="fstep"', false);
    }

    /* ---------------- helpers ---------------- */

    protected function submit(array $overrides = []): Application
    {
        $student = $this->student();
        $student->update(['supervisor_id' => $this->sup()->id]);

        $this->actingAs($student)
            ->post(route('travel.store'), $this->payload($overrides))
            ->assertRedirect(route('applications.index'));

        return Application::where('module_type', 'travel')->sole();
    }

    protected function payload(array $overrides = []): array
    {
        return $overrides + [
            'type_of_request' => 'research_attachment',
            'travel_start_date' => now()->addDays(7)->toDateString(),
            'travel_end_date' => now()->addDays(9)->toDateString(),
            'reason_for_travel' => 'Reservoir simulation workshop',
            'destination_address' => 'Universiti Malaya, Kuala Lumpur',
            'is_international' => 0,
        ];
    }
}
