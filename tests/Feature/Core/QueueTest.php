<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Norhanis\Models\TravelDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The approval queue at a size nobody demos at.
 *
 * Travel is the subject only because it is the reference module; every one
 * of the thirteen queues is the same two Core files, so what holds here
 * holds for all of them.
 */
class QueueTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    /** @return array<int, Application> */
    protected function pending(int $count, ?User $student = null): array
    {
        $student ??= $this->student();
        $made = [];

        for ($i = 0; $i < $count; $i++) {
            $application = Application::create([
                'student_id' => $student->id,
                'submitted_by_id' => $student->id,
                'module_type' => 'travel',
                'status' => Application::STATUS_PENDING,
                'current_stage' => 'supervisor',
                'submitted_at' => now()->subDays($count - $i),
            ]);

            TravelDetail::create([
                'application_id' => $application->id,
                'type_of_request' => 'Field Visit',
                'travel_start_date' => now()->addMonth(),
                'travel_end_date' => now()->addMonth()->addDays(3),
                'duration_days' => 4,
                'reason_for_travel' => 'Conference '.$i,
                'destination_address' => 'Kuala Lumpur',
                'is_international' => false,
            ]);

            $made[] = $application;
        }

        return $made;
    }

    public function test_the_queue_is_paged_rather_than_loaded_whole(): void
    {
        $this->pending(25);

        $html = $this->actingAs($this->supervisor())
            ->get(route('travel.queue'))
            ->assertOk()
            ->assertSee('25 applications awaiting your decision.')
            ->getContent();

        // 20 a page, so the 25th row is on page two and there are page links.
        $this->assertSame(20, substr_count($html, 'name="ids[]"'), 'Expected one page of rows.');
        $this->assertStringContainsString('class="pager"', $html);

        // How long each row has waited, as whole days. Carbon 3's diffInDays
        // returns a float, so without a cast this reads "24.000000002049d".
        $this->assertMatchesRegularExpression('/queue-row-age[^>]*>\s*(today|\d+d)\s*</', $html);
        $this->assertDoesNotMatchRegularExpression('/\d+\.\d+d</', $html);
    }

    public function test_an_approver_can_find_one_row_among_many(): void
    {
        $this->pending(22);
        $needle = $this->student('22009999', 'needle@test.my', ['name' => 'Zulaikha Binti Osman']);
        $this->pending(1, $needle);

        $supervisor = $this->supervisor();

        // By name, and by matric number. Both reach a row that pagination
        // would otherwise have buried.
        foreach (['Zulaikha', '22009999'] as $term) {
            $this->actingAs($supervisor)
                ->get(route('travel.queue', ['q' => $term]))
                ->assertOk()
                ->assertSee('Zulaikha Binti Osman')
                ->assertSee('1 application awaiting');
        }
    }

    public function test_a_search_that_matches_nothing_says_so_and_offers_a_way_back(): void
    {
        $this->pending(3);

        $this->actingAs($this->supervisor())
            ->get(route('travel.queue', ['q' => 'nobody at all']))
            ->assertOk()
            ->assertSee('No application matches')
            ->assertSee('Clear the search');
    }

    public function test_the_default_order_is_longest_waiting_first(): void
    {
        $applications = $this->pending(3);
        $oldest = $applications[0];
        $newest = $applications[2];

        $supervisor = $this->supervisor();

        $html = $this->actingAs($supervisor)->get(route('travel.queue'))->getContent();
        $this->assertLessThan(
            strpos($html, 'value="'.$newest->id.'"'),
            strpos($html, 'value="'.$oldest->id.'"'),
            'A queue is first in, first out: the longest wait comes first.'
        );

        $html = $this->actingAs($supervisor)->get(route('travel.queue', ['sort' => 'newest']))->getContent();
        $this->assertLessThan(
            strpos($html, 'value="'.$oldest->id.'"'),
            strpos($html, 'value="'.$newest->id.'"'),
            '?sort=newest has to actually reverse it.'
        );
    }

    public function test_a_page_of_applications_can_be_decided_in_one_submission(): void
    {
        $applications = $this->pending(3);
        $ids = collect($applications)->pluck('id')->all();

        $this->actingAs($this->supervisor())
            ->post(route('queue.decide-bulk', 'travel'), [
                'ids' => $ids,
                'decision' => 'approve',
                'remarks' => 'Cleared at the weekly review.',
            ])
            ->assertSessionHas('status', '3 applications approved.');

        // Approved out of the supervisor stage and onto the Chair's, through
        // the engine, with the remarks on each one's trail.
        foreach ($applications as $application) {
            $fresh = $application->fresh();
            $this->assertSame(Application::STATUS_PENDING, $fresh->status);
            $this->assertSame('chair', $fresh->current_stage);
            $this->assertSame('Cleared at the weekly review.', $fresh->history()->latest('id')->first()->remarks);
        }
    }

    /**
     * The bulk route is generic and open to any signed-in account, because
     * the engine is what authorises. This is the test that says so.
     */
    public function test_bulk_deciding_rows_that_are_not_yours_decides_nothing(): void
    {
        $applications = $this->pending(2);
        $ids = collect($applications)->pluck('id')->all();

        // The Chair owns a stage in this chain, but not the one these rows
        // are sitting on.
        $this->actingAs($this->chair())
            ->post(route('queue.decide-bulk', 'travel'), ['ids' => $ids, 'decision' => 'approve'])
            ->assertSessionHas('error');

        foreach ($applications as $application) {
            $this->assertSame('supervisor', $application->fresh()->current_stage);
        }

        // And a student cannot decide their own.
        $this->actingAs(User::find($applications[0]->student_id))
            ->post(route('queue.decide-bulk', 'travel'), ['ids' => $ids, 'decision' => 'approve'])
            ->assertSessionHas('error');

        $this->assertSame(Application::STATUS_PENDING, $applications[0]->fresh()->status);
    }

    /**
     * Render EVERY queue, for every role that owns a stage on it.
     *
     * This is the test that should have existed before queues were
     * paginated. Only Travel's queue was ever rendered by a test, so when
     * Supervision's controller filtered the returned rows -- which forwards
     * to the paginator's collection and hands back a plain Collection -- the
     * partial asking that Collection for ->total() was a 500 that nothing
     * caught. Thirteen of the fourteen queues had no render test at all.
     *
     * Deliberately dumb: it asserts 200 and the page header. A queue that
     * throws, or one whose controller quietly breaks the paginator contract,
     * fails here whoever owns it.
     */
    public function test_every_module_queue_renders_for_every_role_that_owns_a_stage(): void
    {
        $registry = app(\App\Modules\Core\Services\ModuleRegistry::class);
        $checked = 0;

        foreach ($registry->all() as $module) {
            foreach ($module->stages(null) as $stage) {
                $user = User::create([
                    'name' => 'Queue Smoke '.$checked,
                    'email' => 'smoke'.$checked.'@test.my',
                    'password' => 'password',
                    'role' => $stage->role,
                ]);

                $this->actingAs($user)
                    ->get(route($module->queueRoute(), ['stage' => $stage->key]))
                    ->assertOk()
                    // Escaped needle, not raw: "GA Extension" reaches
                    // the page as "GA Extension &amp; VISA".
                    ->assertSee($module->label());

                $checked++;
            }
        }

        $this->assertGreaterThan(20, $checked, 'Expected every module stage to be covered.');
    }

    public function test_bulk_deciding_cannot_reach_into_another_module(): void
    {
        $travel = $this->pending(1)[0];

        // Same ids, wrong module in the URL: scoped out by module_type.
        $this->actingAs($this->supervisor())
            ->post(route('queue.decide-bulk', 'publication'), ['ids' => [$travel->id], 'decision' => 'approve'])
            ->assertSessionHas('error');

        $this->assertSame('supervisor', $travel->fresh()->current_stage);
    }
}
