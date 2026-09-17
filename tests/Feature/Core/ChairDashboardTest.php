<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Jason\Models\HardboundSignature;
use App\Modules\Norhanis\Models\TravelDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The Chair's own dashboard.
 *
 * What it replaced: the generic approver screen, which renders one stat card
 * per queue. A Chair owns five stages, so that was six cards with five zeros
 * over a five-category bar chart with one bar in it, and nowhere at all for
 * the figure a Chair is actually measured on.
 */
class ChairDashboardTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected function travelAwaitingChair(User $student, int $daysAgo): Application
    {
        $application = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'travel',
            'status' => Application::STATUS_PENDING,
            'current_stage' => 'chair',
            'submitted_at' => now()->subDays($daysAgo),
        ]);

        TravelDetail::create([
            'application_id' => $application->id,
            'type_of_request' => 'Field Visit',
            'travel_start_date' => now()->addMonth(),
            'travel_end_date' => now()->addMonth()->addDays(3),
            'duration_days' => 4,
            'reason_for_travel' => 'Conference',
            'destination_address' => 'Kuala Lumpur',
            'is_international' => false,
        ]);

        return $application;
    }

    public function test_a_chair_gets_the_chair_dashboard_and_not_the_generic_one(): void
    {
        $this->actingAs($this->chair())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Your queues')
            ->assertSee('Waiting longest')
            ->assertSee('Panels you filed')
            // The generic approver screen's chart canvas must not be here.
            ->assertDontSee('dashboardChart', false);
    }

    /**
     * The headline the old screen had nowhere to put. A count alone reads the
     * same at three days and at forty.
     */
    public function test_the_longest_wait_is_shown_and_toned(): void
    {
        $student = $this->student();
        $this->travelAwaitingChair($student, 3);
        $this->travelAwaitingChair($student, 41);

        $html = $this->actingAs($this->chair())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Longest wait')
            ->assertSee('well past the 30-day mark')
            ->getContent();

        // Both rows are counted, and the oldest is what the figure reports.
        $this->assertStringContainsString('41 days', $html);
        $this->assertStringContainsString('tone-critical', $html);
    }

    public function test_an_empty_desk_says_so_rather_than_showing_zeros(): void
    {
        $this->actingAs($this->chair())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('your queues are clear')
            ->assertSee('Nothing is waiting on you.');
    }

    /**
     * The alert is a module's rule, reaching Core through
     * ProvidesDashboardAlerts. Core never names HardboundSignature.
     */
    public function test_a_missing_signature_is_raised_only_when_there_is_something_to_approve(): void
    {
        $chair = $this->chair();
        $student = $this->student();

        // Nothing on the hardbound stage yet: no signature, but nothing is
        // blocked either, so the Chair is not chased about it.
        $this->actingAs($chair)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('No signature on file');

        Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'hardbound_submission',
            'status' => Application::STATUS_PENDING,
            'current_stage' => 'chairman',
            'submitted_at' => now()->subDay(),
        ]);

        $stage = collect(app(\App\Modules\Core\Services\ModuleRegistry::class)
            ->get('hardbound_submission')->stages())
            ->firstWhere('role', Role::CHAIR);

        Application::where('module_type', 'hardbound_submission')
            ->update(['current_stage' => $stage->key]);

        $this->actingAs($chair)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('No signature on file')
            ->assertSee('Upload my signature');

        // Once a signature exists, the alert goes.
        HardboundSignature::create([
            'user_id' => $chair->id,
            'path' => 'signatures/test.png',
            'original_name' => 'test.png',
            'mime_type' => 'image/png',
        ]);

        $this->actingAs($chair)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('No signature on file');
    }

    /**
     * A Chair files an examiner panel and it leaves their hands entirely.
     * Until this panel it was invisible to the person who filed it.
     */
    public function test_panels_the_chair_filed_are_visible_to_them(): void
    {
        $chair = $this->chair();
        $student = $this->student();

        Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $chair->id,
            'module_type' => 'appointment_letter',
            'status' => Application::STATUS_PENDING,
            'current_stage' => 'academic_exec',
            'submitted_at' => now()->subDays(2),
        ]);

        $this->actingAs($chair)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Panels you filed')
            ->assertSee($student->name)
            ->assertSee('Academic Executive');
    }

    /** One dead panel must cost that panel, not the page. */
    public function test_a_dashboard_panel_that_throws_does_not_take_the_page_down(): void
    {
        $chair = $this->chair();

        // approval_history is what decidedRecently() reads.
        \Illuminate\Support\Facades\Schema::drop('approval_history');

        $this->actingAs($chair)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Your queues');
    }
}
