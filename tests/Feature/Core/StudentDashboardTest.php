<?php

namespace Tests\Feature\Core;

use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Core\Contracts\SuppliesAllowanceStatus;
use App\Modules\Core\Contracts\SuppliesVivaStatus;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\StudentDashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The student's dashboard panels, where the figure is not always a figure
 * somebody uploaded -- see StudentDashboard::STARTING_PERCENTAGE.
 */
class StudentDashboardTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    /**
     * A first-week student has missed nothing, so the dial is full. Opening
     * at 0% put every new account in the Critical band on day one.
     */
    public function test_the_attendance_gauge_starts_full_before_anything_is_uploaded(): void
    {
        $this->actingAs($this->student())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-count-to="100"', false)
            ->assertSee('Nothing has been uploaded yet, so you start at 100%.');
    }

    /**
     * Viva and GA allowance have no supplying module yet (Hani's viva
     * scheduling and Haziq's allowance module are both unbuilt) -- the
     * cards must say so honestly rather than inventing a figure.
     */
    public function test_viva_and_allowance_cards_degrade_when_nothing_supplies_them(): void
    {
        $this->assertFalse(app()->bound(SuppliesVivaStatus::class));
        $this->assertFalse(app()->bound(SuppliesAllowanceStatus::class));

        $this->actingAs($this->student())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Not scheduled yet. It will appear here once CGS confirms a date.')
            ->assertSee('Not on a GA/GRA allowance, or payment tracking is not available yet.');
    }

    /** No candidacy on record yet -- CGS hasn't registered one -- is not an error. */
    public function test_the_candidacy_card_degrades_when_no_candidacy_is_on_record(): void
    {
        $this->actingAs($this->student())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('No candidacy is on record for you yet. CGS registers this once your programme starts.');
    }

    /**
     * With a real candidacy row, Core reads it through
     * SuppliesCandidacyStatus (Chloe's CandidacyStatusProvider), never
     * App\Modules\Chloe\Models\StudyCandidacy directly.
     */
    public function test_the_candidacy_card_shows_real_data_once_a_candidacy_exists(): void
    {
        $student = $this->student();

        StudyCandidacy::create([
            'student_id' => $student->id,
            'programme_start_date' => now()->subMonths(24)->startOfMonth(),
            'candidacy_expiry_date' => now()->addDays(219),
            'status' => StudyCandidacy::STATUS_ACTIVE,
            'cumulative_extension_months' => 6,
        ]);

        // 219 and its "days remaining" unit are separate elements (matching
        // the existing value/unit pattern elsewhere on this dashboard), so
        // they are asserted apart rather than as one contiguous string.
        $this->actingAs($student)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['219', 'days remaining'])
            ->assertSee('Extension used:')
            ->assertSee('6 of 12 months')
            ->assertDontSee('No candidacy is on record for you yet');

        // And the same figure is available straight off the service, the
        // same way the view reads it -- not re-derived in the test.
        $card = (new StudentDashboard($student))->candidacyCard();
        $this->assertSame(219, $card['daysRemaining']);
        $this->assertSame(6, $card['extensionUsedMonths']);
    }

    /**
     * "Start a new application" is derived from ModuleRegistry::submittable(),
     * not a hardcoded list -- every registered, self-submittable module
     * appears as a quick-link, by its own label and route.
     */
    public function test_the_quick_links_grid_lists_every_submittable_module(): void
    {
        $this->actingAs($this->student())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Start a new application')
            ->assertSee('GA Extension')
            ->assertSee('Travel')
            ->assertSee(route('ga-extension.create'), false);
    }

    /** A saved draft is counted and linked to Nureen's My Drafts page. */
    public function test_draft_count_shows_on_the_quick_links_panel(): void
    {
        $student = $this->student();

        Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'ga_extension',
            'status' => Application::STATUS_DRAFT,
        ]);

        $this->actingAs($student)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('My Drafts (1)');
    }
}
