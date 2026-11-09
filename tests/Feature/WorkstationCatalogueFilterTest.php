<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationLocation;
use App\Modules\Chloe\Services\WorkstationSeatCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/** Manage Seat Catalogue: the Block / Room filter (server side, kept in the URL) and the redirects that keep it. */
class WorkstationCatalogueFilterTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected WorkstationLocation $j1;

    protected WorkstationLocation $j2;

    protected WorkstationLocation $n1;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        // J2 has two rooms, N2 has one; the names are unique so a section can be found (or not) in the page
        $this->j1 = WorkstationLocation::create(['block' => 'J2', 'room_code' => 'J2-01-03', 'gender' => 'female', 'name' => 'ROOM ALPHA']);
        $this->j2 = WorkstationLocation::create(['block' => 'J2', 'room_code' => 'J2-01-05', 'gender' => 'female', 'name' => 'ROOM BRAVO']);
        $this->n1 = WorkstationLocation::create(['block' => 'N2', 'room_code' => 'N2-02-01', 'gender' => 'male', 'name' => 'ROOM CHARLIE']);
        $catalogue = app(WorkstationSeatCatalogue::class);
        $catalogue->addSeats($this->n1, 3);  // 1..3
        $catalogue->addSeats($this->j1, 4);  // 4..7
        $catalogue->addSeats($this->j2, 2);  // 8..9
    }

    protected ?\App\Modules\Core\Models\User $cgsUser = null;

    protected function staff(): \App\Modules\Core\Models\User
    {
        return $this->cgsUser ??= $this->cgs();
    }

    protected function page(array $query = [])
    {
        return $this->actingAs($this->staff())->get(route('workstation.cgs.catalog', $query));
    }

    /** The room codes whose section is shown (not carrying `hidden`) in the page. Every room is always in the page. */
    protected function shown(string $html): array
    {
        preg_match_all('/<div class="card-container-inline" data-cat-room data-block="[^"]*" data-room="([^"]*)" data-seats="\d+"( hidden)?>/', $html, $m, PREG_SET_ORDER);

        return array_values(array_map(fn ($x) => $x[1], array_filter($m, fn ($x) => ($x[2] ?? '') === '')));
    }

    protected function all(string $html): array
    {
        preg_match_all('/data-cat-room data-block="[^"]*" data-room="([^"]*)"/', $html, $m);

        return $m[1];
    }

    public function test_the_default_view_lists_every_room_like_before(): void
    {
        $html = $this->page()->assertOk()->assertSee('Showing 3 rooms (9 seats).')->getContent();

        $this->assertSame(['J2-01-03', 'J2-01-05', 'N2-02-01'], $this->shown($html));
    }

    public function test_filtering_by_block_shows_only_the_rooms_of_that_block(): void
    {
        $j2 = $this->page(['block' => 'J2'])->assertOk()->assertSee('Showing 2 rooms (6 seats).')->getContent();
        $this->assertSame(['J2-01-03', 'J2-01-05'], $this->shown($j2));
        $this->assertCount(3, $this->all($j2), 'every room is still in the page; the others are hidden');

        $n2 = $this->page(['block' => 'N2'])->assertOk()->assertSee('Showing 1 room (3 seats).')->getContent();
        $this->assertSame(['N2-02-01'], $this->shown($n2));
    }

    public function test_filtering_by_room_shows_only_that_rooms_section(): void
    {
        $html = $this->page(['block' => 'J2', 'room' => 'J2-01-03'])->assertOk()->assertSee('Showing 1 room (4 seats).')->assertSee('Add seats')->assertSee('Room layout image')->getContent();

        $this->assertSame(['J2-01-03'], $this->shown($html));
    }

    public function test_a_room_on_its_own_selects_its_block_too(): void
    {
        $html = $this->page(['room' => 'N2-02-01'])->assertOk()->getContent();

        $this->assertSame(['N2-02-01'], $this->shown($html));
        $this->assertMatchesRegularExpression('/<option value="N2" selected>/', $html);
    }

    public function test_an_unknown_block_or_room_falls_back_to_everything_without_an_error(): void
    {
        $this->page(['block' => 'ZZ'])->assertOk()->assertSee('Showing 3 rooms (9 seats).');
        $this->page(['room' => 'NOPE-1'])->assertOk()->assertSee('Showing 3 rooms (9 seats).');
        $this->assertSame(['J2-01-03', 'J2-01-05', 'N2-02-01'], $this->shown($this->page(['block' => 'ZZ', 'room' => 'NOPE-1'])->assertOk()->getContent()));
        $this->page(['block' => ['x'], 'room' => ['y']])->assertOk();
        // a real room that is not in the chosen block: the block stays, the room becomes "All rooms"
        $this->assertSame(['J2-01-03', 'J2-01-05'], $this->shown($this->page(['block' => 'J2', 'room' => 'N2-02-01'])->assertOk()->getContent()));
    }

    public function test_the_room_list_depends_on_the_block(): void
    {
        $all = $this->page()->getContent();
        $this->assertStringContainsString('<option value="J2-01-03"', $all);
        $this->assertStringContainsString('<option value="N2-02-01"', $all);

        $j2 = $this->page(['block' => 'J2'])->getContent();
        $this->assertStringContainsString('<option value="J2-01-05"', $j2);
        $this->assertStringNotContainsString('<option value="N2-02-01"', $j2);
        $this->assertStringContainsString('<option value="">All rooms</option>', $j2);
        $this->assertStringContainsString('<option value="">All blocks</option>', $j2);
    }

    public function test_the_filter_is_a_plain_get_form_that_names_the_url_parameters(): void
    {
        $html = $this->page(['block' => 'J2', 'room' => 'J2-01-05'])->getContent();

        $this->assertStringContainsString('method="GET"', $html);
        $this->assertMatchesRegularExpression('/<select name="block" id="cat-block">/', $html);
        $this->assertMatchesRegularExpression('/<select name="room" id="cat-room">/', $html);
        $this->assertMatchesRegularExpression('/<option value="J2-01-05" selected>/', $html);
    }

    public function test_the_seat_order_inside_a_room_is_still_ascending_by_number(): void
    {
        $room = WorkstationLocation::create(['block' => 'J2', 'room_code' => 'J2-01-09', 'gender' => 'female', 'name' => 'ROOM DELTA']);
        foreach (['100', '3', '1001', '20'] as $c) {
            Workstation::create(['workstation_location_id' => $room->id, 'seat_code' => $c, 'status' => 'available']);
        }

        $html = $this->page(['room' => 'J2-01-09'])->assertOk()->getContent();
        $section = substr($html, strpos($html, 'data-room="J2-01-09"'));
        $section = substr($section, 0, strpos($section, 'data-cat-room', 10) ?: null); // up to the next room
        preg_match_all('/<td>Seat (\d+)<\/td>/', $section, $m);

        $this->assertSame(['3', '20', '100', '1001'], $m[1]);
    }

    public function test_the_sections_are_in_order_header_add_location_filter_bar_then_the_rooms(): void
    {
        $html = $this->page()->assertOk()->getContent();

        $header = strpos($html, 'Workstation Catalogue');
        $add = strpos($html, '<h3>Add Location</h3>');
        $filter = strpos($html, 'id="cat-filter"');
        $result = strpos($html, 'id="cat-result"');
        $firstRoom = strpos($html, 'ROOM ALPHA');

        $this->assertNotFalse($header);
        $this->assertTrue($header < $add && $add < $filter && $filter < $result && $result < $firstRoom, 'header, Add Location, filter bar, result line, then the room sections');
    }

    public function test_add_location_lands_on_the_new_rooms_filtered_view_with_the_filter_bar_visible(): void
    {
        $response = $this->actingAs($this->staff())->post(route('workstation.cgs.catalog.locations.store'), [
            'block' => 'K9', 'room_code' => 'K9-01-01', 'gender' => 'male', 'quantity' => 2, 'name' => 'ROOM ECHO',
        ]);
        $response->assertRedirect(route('workstation.cgs.catalog', ['block' => 'K9', 'room' => 'K9-01-01']));

        $page = $this->actingAs($this->staff())->get($response->headers->get('Location'))->assertOk();
        $page->assertSee('id="cat-filter"', false)->assertSee('Showing 1 room (2 seats).');
        $this->assertSame(['K9-01-01'], $this->shown($page->getContent()));
        $this->assertMatchesRegularExpression('/<option value="K9-01-01" selected>/', $page->getContent());
    }

    public function test_the_layout_file_control_is_one_box_with_a_choose_file_button_and_a_name_over_the_real_input(): void
    {
        $html = $this->page(['room' => 'J2-01-03'])->assertOk()->getContent();
        $id = $this->j1->id;

        $this->assertStringContainsString('<div class="cat-file">', $html);
        $this->assertStringContainsString('<label class="cat-file-btn" for="layout-'.$id.'">Choose File</label>', $html);
        $this->assertStringContainsString('<span class="cat-file-name" data-file-name>No file chosen</span>', $html);
        $this->assertMatchesRegularExpression('/<input type="file" class="cat-file-input" name="layout" id="layout-'.$id.'" accept="[^"]+" required data-file-input>/', $html);
        $this->assertStringContainsString('--cat-control-h: 42px', $html);
    }

    // ---- the in-browser filter's contract with the server-rendered page ------------------------------------------------

    public function test_every_room_is_in_the_page_with_data_attributes_for_the_script(): void
    {
        $html = $this->page(['block' => 'N2'])->assertOk()->getContent();

        $this->assertSame(['J2-01-03', 'J2-01-05', 'N2-02-01'], $this->all($html));
        $this->assertStringContainsString('data-cat-room data-block="J2" data-room="J2-01-03" data-seats="4" hidden>', $html, 'outside the selection, so hidden');
        $this->assertStringContainsString('data-cat-room data-block="N2" data-room="N2-02-01" data-seats="3">', $html, 'inside the selection, so shown');
        $this->assertStringContainsString('data-cat-room data-block="J2" data-room="J2-01-05" data-seats="2" hidden>', $html);
    }

    public function test_the_script_gets_the_room_list_and_the_result_line_is_announced_politely(): void
    {
        $html = $this->page(['block' => 'J2'])->getContent();

        $this->assertMatchesRegularExpression('/id="cat-filter"[^>]*data-rooms="[^"]*J2-01-03[^"]*N2-02-01[^"]*"/', $html, 'all rooms are handed to the script, whatever the block');
        $this->assertStringContainsString('id="cat-result" aria-live="polite"', $html);
    }

    public function test_apply_is_a_noscript_fallback_only_and_clear_shows_only_while_filtered(): void
    {
        $html = $this->page()->getContent();
        $this->assertStringContainsString('<noscript><button type="submit">Apply</button></noscript>', $html);
        $this->assertSame(1, substr_count($html, '>Apply</button>'), 'the only Apply button is the one inside noscript');
        $this->assertMatchesRegularExpression('/<a class="cat-clear" href="[^"]+" hidden>/', $html, 'no selection: Clear is hidden');

        $this->assertDoesNotMatchRegularExpression('/<a class="cat-clear" href="[^"]+" hidden>/', $this->page(['block' => 'J2'])->getContent());
    }

    public function test_the_script_filters_without_a_reload_and_keeps_the_url_in_step(): void
    {
        $html = $this->page()->getContent();

        $this->assertStringContainsString('history.replaceState', $html);
        $this->assertStringContainsString("sec.hidden = !show", $html);
        $this->assertStringNotContainsString('filter.submit()', $html, 'the filter never submits itself when the script is on');
        $this->assertStringContainsString("input[name=\"filter_block\"]", $html, 'the hidden fields are updated live');
    }

    // ---- the selection survives every action ----------------------------------------------------------------------

    protected function sel(): array
    {
        return ['filter_block' => 'J2', 'filter_room' => 'J2-01-03'];
    }

    protected function back(): string
    {
        return route('workstation.cgs.catalog', ['block' => 'J2', 'room' => 'J2-01-03']);
    }

    public function test_add_seats_returns_to_the_same_filtered_view(): void
    {
        $this->actingAs($this->staff())->post(route('workstation.cgs.catalog.seats.store'), ['workstation_location_id' => $this->j1->id, 'quantity' => 2] + $this->sel())
            ->assertRedirect($this->back())->assertSessionHas('status', 'Added 2 seats: Seat 10 to Seat 11.');
    }

    public function test_save_status_delete_upload_and_remove_layout_all_return_to_the_same_filtered_view(): void
    {
        $cgs = $this->staff();
        $seat = $this->j1->workstations()->first();

        $this->actingAs($cgs)->put(route('workstation.cgs.catalog.seats.update', $seat), ['status' => 'disabled', 'notes' => 'x'] + $this->sel())->assertRedirect($this->back());
        $this->assertSame('disabled', $seat->fresh()->status);
        $this->actingAs($cgs)->put(route('workstation.cgs.catalog.seats.update', $seat), ['status' => 'available'] + $this->sel())->assertRedirect($this->back()); // restore

        $this->actingAs($cgs)->post(route('workstation.cgs.catalog.layout.store', $this->j1), ['layout' => UploadedFile::fake()->image('l.png', 400, 300)] + $this->sel())->assertRedirect($this->back());
        $this->actingAs($cgs)->delete(route('workstation.cgs.catalog.layout.destroy', $this->j1), $this->sel())->assertRedirect($this->back());

        $this->actingAs($cgs)->delete(route('workstation.cgs.catalog.seats.destroy', $seat), $this->sel())->assertRedirect($this->back())->assertSessionHas('status');
        $this->assertSoftDeleted('workstations', ['id' => $seat->id]);
    }

    public function test_only_a_block_kept_in_the_selection_and_an_unfiltered_action_goes_to_the_full_list(): void
    {
        $cgs = $this->staff();

        $this->actingAs($cgs)->post(route('workstation.cgs.catalog.seats.store'), ['workstation_location_id' => $this->j1->id, 'quantity' => 1, 'filter_block' => 'N2'])
            ->assertRedirect(route('workstation.cgs.catalog', ['block' => 'N2']));
        $this->actingAs($cgs)->post(route('workstation.cgs.catalog.seats.store'), ['workstation_location_id' => $this->j1->id, 'quantity' => 1])
            ->assertRedirect(route('workstation.cgs.catalog'));
    }

    public function test_a_tampered_selection_is_dropped_not_followed(): void
    {
        $this->actingAs($this->staff())->post(route('workstation.cgs.catalog.seats.store'), [
            'workstation_location_id' => $this->j1->id, 'quantity' => 1, 'filter_block' => 'http://evil.example', 'filter_room' => '../../x',
        ])->assertRedirect(route('workstation.cgs.catalog'));
    }

    public function test_every_form_on_the_page_carries_the_selection(): void
    {
        $html = $this->page(['block' => 'J2', 'room' => 'J2-01-03'])->getContent();

        // every room is in the page: per room add seats + upload, per seat a save form (4 + 2 + 3 = 9), and one delete dialog = 6 + 9 + 1 forms
        $this->assertSame(16, substr_count($html, 'name="filter_block" value="J2"'));
        $this->assertSame(16, substr_count($html, 'name="filter_room" value="J2-01-03"'));
        $this->assertSame(substr_count($html, 'name="filter_block"'), substr_count($html, 'name="filter_room"'));
    }

    public function test_the_page_is_for_cgs_only(): void
    {
        $this->actingAs($this->student())->get(route('workstation.cgs.catalog', ['block' => 'J2']))->assertForbidden();
    }
}
