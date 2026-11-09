<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\StudentGender;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationLocation;
use App\Modules\Chloe\Support\SeatOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Support\MakesEligibleWorkstationStudents;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/** Every list of seats is in ascending seat NUMBER order (3, 20, 100, 1001), never text order ("100", "1001", "20", "3"). */
class WorkstationSeatOrderTest extends TestCase
{
    use MakesEligibleWorkstationStudents;
    use MakesUsers;
    use RefreshDatabase;

    /** Created in a scrambled order on purpose, so neither id order nor text order can pass by luck. */
    protected const CODES = ['100', '3', '1001', '20', '999', '12A', '12', '2'];
    protected const EXPECTED = ['2', '3', '12', '12A', '20', '100', '999', '1001'];

    protected function room(string $code, string $block = 'N2', string $gender = 'male'): WorkstationLocation
    {
        return WorkstationLocation::create(['block' => $block, 'room_code' => $code, 'gender' => $gender, 'name' => $code]);
    }

    protected function seats(WorkstationLocation $room, array $codes = self::CODES, string $status = Workstation::STATUS_AVAILABLE): void
    {
        foreach ($codes as $i => $code) {
            Workstation::create(['workstation_location_id' => $room->id, 'seat_code' => $code, 'position' => 100 - $i, 'status' => $status]);
        }
    }

    protected function shown(string $html): array
    {
        preg_match_all('/Seat\s+(\w+)\s*</', preg_replace('/<[^>]+>/', '<', $html), $m);

        return array_values(array_filter($m[1], fn ($c) => in_array($c, self::EXPECTED, true)));
    }

    public function test_the_helper_orders_by_number_not_text_and_breaks_ties_the_same_way_every_time(): void
    {
        $rows = collect(self::CODES)->map(fn ($c, $i) => ['block' => 'N2', 'room' => 'N2-02-01', 'code' => $c, 'id' => $i + 1]);
        $sorted = SeatOrder::sort($rows, fn ($r) => [$r['block'], $r['room'], $r['code'], $r['id']])->pluck('code')->all();

        $this->assertSame(self::EXPECTED, $sorted);
        $this->assertNotSame(self::EXPECTED, collect(self::CODES)->sort()->values()->all(), 'plain text sorting gives a different (wrong) order');
        $this->assertSame(1001, SeatOrder::number('1001'));
        $this->assertSame(100, SeatOrder::number('0100'));
    }

    public function test_the_helper_groups_by_block_then_room_then_seat_number(): void
    {
        $rows = collect([['N2', 'N2-02-05', '3'], ['J2', 'J2-01-06', '100'], ['N2', 'N2-02-01', '1001'], ['J2', 'J2-01-06', '20'], ['N2', 'N2-02-01', '3']])->map(fn ($r, $i) => $r + [3 => $i]);

        $this->assertSame(
            ['J2-01-06/20', 'J2-01-06/100', 'N2-02-01/3', 'N2-02-01/1001', 'N2-02-05/3'],
            SeatOrder::sort($rows, fn ($r) => [$r[0], $r[1], $r[2], $r[3]])->map(fn ($r) => $r[1].'/'.$r[2])->all()
        );
    }

    public function test_the_query_orders_in_the_database_by_number(): void
    {
        $room = $this->room('N2-02-01');
        $this->seats($room);

        $this->assertSame(self::EXPECTED, SeatOrder::query(Workstation::query()->where('workstation_location_id', $room->id))->pluck('seat_code')->all());
    }

    public function test_the_cgs_room_page_lists_seats_in_number_order(): void
    {
        $room = $this->room('N2-02-01');
        $this->seats($room);

        $this->assertSame(self::EXPECTED, $this->shown($this->actingAs($this->cgs())->get(route('workstation.cgs.room', $room))->assertOk()->getContent()));
    }

    public function test_the_cgs_search_results_are_one_continuous_seat_number_order_over_all_pages(): void
    {
        // two blocks and three rooms with their seat numbers interleaved, as in the real data after seats are added in batches
        $rooms = [$this->room('N2-02-05'), $this->room('J2-01-06', 'J2'), $this->room('N2-02-01')];
        $numbers = array_merge(range(1, 60), [100, 315, 1001, 1002]);
        foreach ($numbers as $i => $n) {
            Workstation::create(['workstation_location_id' => $rooms[$i % 3]->id, 'seat_code' => (string) $n, 'position' => 1, 'status' => Workstation::STATUS_AVAILABLE]);
        }
        $cgs = $this->cgs();

        $pages = [];
        for ($page = 1; $page <= 4; $page++) {
            $html = $this->actingAs($cgs)->get(route('workstation.cgs.operations', ['seat_status' => 'available', 'page' => $page]))->assertOk()->getContent();
            preg_match_all('/Seat\s+(\d+)\s+Block/u', html_entity_decode(strip_tags($html)), $m);
            $pages[$page] = array_map('intval', $m[1]);
        }

        $all = array_merge(...array_values($pages));
        $this->assertCount(64, $all, 'the result set is unchanged: every available seat, 24 per page (24 + 24 + 16)');
        $this->assertSame([24, 24, 16, 0], array_values(array_map('count', $pages)));
        $this->assertSame(1, $pages[1][0], 'page 1 starts at the lowest seat number');
        $this->assertSame(1002, end($pages[3]), 'the last page ends at the highest seat number');
        $this->assertSame($numbers, $all, 'strictly ascending over all pages, whatever the block or room');
        $this->assertSame($all, collect($all)->sort()->values()->all());
        $this->assertNotSame(
            collect($rooms)->flatMap(fn ($r) => $r->workstations()->orderBy('id')->pluck('seat_code')->map(fn ($c) => (int) $c))->all(),
            $all,
            'the old block, room, seat order would give a different list'
        );
    }

    public function test_the_results_helper_orders_by_seat_number_first_and_block_room_only_break_a_tie(): void
    {
        $rows = collect([['N2', 'N2-02-07', '54', 1], ['J2', 'J2-01-03', '252', 2], ['N2', 'N2-02-01', '3', 3], ['J2', 'J2-01-06', '3', 4], ['J2', 'J2-01-03', '1001', 5], ['N2', 'N2-02-05', '20', 6]]);

        $this->assertSame(
            ['J2-01-06/3', 'N2-02-01/3', 'N2-02-05/20', 'N2-02-07/54', 'J2-01-03/252', 'J2-01-03/1001'],
            SeatOrder::sortAcrossRooms($rows, fn ($r) => [$r[0], $r[1], $r[2], $r[3]])->map(fn ($r) => $r[1].'/'.$r[2])->all()
        );
    }

    public function test_the_student_seat_list_and_the_catalogue_use_the_same_order(): void
    {
        $room = $this->room('N2-02-01');
        $this->seats($room);
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);

        $this->assertSame(self::EXPECTED, $this->shown($this->actingAs($student)->get(route('workstation.seats', $room))->assertOk()->getContent()));
        $this->assertSame(self::EXPECTED, $this->shown($this->actingAs($this->cgs())->get(route('workstation.cgs.catalog'))->assertOk()->getContent()));
    }

    public function test_a_live_refresh_of_the_room_page_keeps_the_order(): void
    {
        $room = $this->room('N2-02-01');
        $this->seats($room);
        $cgs = $this->cgs();
        $first = $this->shown($this->actingAs($cgs)->get(route('workstation.cgs.room', $room))->getContent());

        Workstation::where('workstation_location_id', $room->id)->where('seat_code', '20')->update(['status' => Workstation::STATUS_DISABLED]);
        $second = $this->shown($this->actingAs($cgs)->get(route('workstation.cgs.room', $room))->getContent());

        $this->assertSame(self::EXPECTED, $first);
        $this->assertSame($first, $second, 'a status change never moves a seat');
    }
}
