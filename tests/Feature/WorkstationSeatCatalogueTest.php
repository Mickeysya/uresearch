<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\LockerKey;
use App\Modules\Chloe\Models\StudentGender;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationHistory;
use App\Modules\Chloe\Models\WorkstationLocation;
use App\Modules\Chloe\Models\WorkstationRequest;
use App\Modules\Chloe\Services\WorkstationSeatCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesEligibleWorkstationStudents;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Round 4, seat catalogue: the persistent seat counter, bulk add, retiring (soft delete) with its in-use rules, and the
 * room layout image. Runs on the test database only.
 */
class WorkstationSeatCatalogueTest extends TestCase
{
    use MakesEligibleWorkstationStudents;
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
    }

    protected function room(string $gender = 'male', string $code = 'N2-02-01'): WorkstationLocation
    {
        return WorkstationLocation::create(['block' => 'N2', 'room_code' => $code, 'gender' => $gender, 'name' => $code]);
    }

    protected function catalogue(): WorkstationSeatCatalogue
    {
        return app(WorkstationSeatCatalogue::class);
    }

    protected function setCounter(int $n): void
    {
        DB::table('workstation_seat_counter')->where('id', 1)->update(['last_number' => $n]);
    }

    public function test_the_counter_exists_and_starts_where_the_highest_seat_left_off(): void
    {
        $this->assertSame(1, DB::table('workstation_seat_counter')->count());
        $this->assertSame(1, $this->catalogue()->nextNumber()); // empty test database
    }

    public function test_add_location_with_n_creates_n_numbered_seats_in_one_action(): void
    {
        $this->setCounter(315);

        $this->actingAs($this->cgs())->post(route('workstation.cgs.catalog.locations.store'), [
            'block' => 'J2', 'room_code' => 'J2-01', 'gender' => 'female', 'quantity' => 4,
        ])->assertRedirect(route('workstation.cgs.catalog', ['block' => 'J2', 'room' => 'J2-01'])) // shows the new room, whatever filter was on
            ->assertSessionHas('status', 'Added 4 seats: Seat 316 to Seat 319.');

        $room = WorkstationLocation::where('room_code', 'J2-01')->first();
        $this->assertSame(['316', '317', '318', '319'], $room->workstations()->orderBy('id')->pluck('seat_code')->all());
        $this->assertSame('J2-01', $room->name); // label defaults to the room
        $this->assertSame(320, $this->catalogue()->nextNumber());
    }

    public function test_quantity_is_required_and_must_be_at_least_one(): void
    {
        $cgs = $this->cgs();
        foreach ([null, 0, -3, 'x'] as $bad) {
            $this->actingAs($cgs)->post(route('workstation.cgs.catalog.locations.store'), [
                'block' => 'J2', 'room_code' => 'J2-01', 'gender' => 'male', 'quantity' => $bad,
            ])->assertSessionHasErrors('quantity');
        }
        $this->assertSame(0, WorkstationLocation::count());
    }

    public function test_there_is_no_upper_limit_and_big_batches_are_chunked_in_one_transaction(): void
    {
        $room = $this->room();
        $n = WorkstationSeatCatalogue::CHUNK * 2 + 37;

        $range = $this->catalogue()->addSeats($room, $n);

        $this->assertSame(['first' => 1, 'last' => $n, 'count' => $n], $range);
        $this->assertSame($n, $room->workstations()->count());
    }

    // ---- batch numbering (CGS adds N seats at once) ----------------------------------------------------------------

    public function test_a_batch_of_ten_continues_the_highest_number_and_the_next_batch_of_five_continues_right_after(): void
    {
        $existing = $this->room('female', 'J2-01-03');
        $this->setCounter(315);
        foreach (range(1, 315, 105) as $n) { // a few existing seats so "highest number" is real data, not just the counter
            Workstation::create(['workstation_location_id' => $existing->id, 'seat_code' => (string) $n, 'status' => Workstation::STATUS_AVAILABLE]);
        }
        Workstation::create(['workstation_location_id' => $existing->id, 'seat_code' => '315', 'status' => Workstation::STATUS_AVAILABLE]);
        $cgs = $this->cgs();
        $highest = (int) Workstation::max(DB::raw('CAST(seat_code AS UNSIGNED)'));
        $this->assertSame(315, $highest);

        $this->actingAs($cgs)->post(route('workstation.cgs.catalog.seats.store'), ['workstation_location_id' => $existing->id, 'quantity' => 10])
            ->assertSessionHas('status', 'Added 10 seats: Seat 316 to Seat 325.');

        $first = Workstation::where('workstation_location_id', $existing->id)->where('seat_code', '>=', '316')->orderBy('id')->pluck('seat_code')->map(fn ($c) => (int) $c)->all();
        $this->assertSame(range($highest + 1, $highest + 10), $first, 'exactly next..next+9, in the order created, no gaps, no duplicates');
        $this->assertSame(326, $this->catalogue()->nextNumber());

        $this->actingAs($cgs)->post(route('workstation.cgs.catalog.seats.store'), ['workstation_location_id' => $existing->id, 'quantity' => 5])
            ->assertSessionHas('status', 'Added 5 seats: Seat 326 to Seat 330.');

        $all = Workstation::where('workstation_location_id', $existing->id)->orderBy('id')->pluck('seat_code')->map(fn ($c) => (int) $c)->all();
        $this->assertSame(range(316, 330), array_slice($all, -15), 'the second batch continues right after the first');
        $this->assertSame(count($all), count(array_unique($all)), 'no duplicate numbers anywhere in the room');
        $this->assertSame(331, $this->catalogue()->nextNumber());
        $this->assertSame(330, (int) DB::table('workstation_seat_counter')->value('last_number'));
    }

    public function test_a_batch_in_a_second_room_continues_the_same_global_counter(): void
    {
        $a = $this->room('male', 'N2-02-01');
        $b = $this->room('female', 'J2-01-03');
        $one = $this->catalogue()->addSeats($a, 3);
        $two = $this->catalogue()->addSeats($b, 4);
        $three = $this->catalogue()->addSeats($a, 2);

        $this->assertSame([1, 3], [$one['first'], $one['last']]);
        $this->assertSame([4, 7], [$two['first'], $two['last']]);
        $this->assertSame([8, 9], [$three['first'], $three['last']], 'one counter for every room, not one per room');
    }

    public function test_a_batch_never_reuses_a_number_even_if_the_counter_was_ever_behind_the_seats(): void
    {
        $room = $this->room();
        $this->catalogue()->addSeats($room, 5); // 1..5
        $this->setCounter(2);                    // a counter that fell behind (restore, import, seeder)

        $range = $this->catalogue()->addSeats($room, 3);

        $this->assertSame([6, 8], [$range['first'], $range['last']]);
        $this->assertSame(8, (int) DB::table('workstation_seat_counter')->value('last_number'));
        $this->assertSame(8, $room->workstations()->count());
    }

    public function test_a_retired_seat_number_is_still_counted_when_the_counter_is_behind(): void
    {
        $room = $this->room();
        $this->catalogue()->addSeats($room, 3); // 1..3
        $room->workstations()->where('seat_code', '3')->first()->delete(); // retired (soft delete), the number is gone for good
        $this->setCounter(1);

        $this->assertSame(4, $this->catalogue()->addSeats($room, 1)['first']);
    }

    public function test_the_add_forms_have_no_seat_number_field_and_a_typed_number_is_ignored(): void
    {
        $room = $this->room();
        $this->setCounter(315);
        $cgs = $this->cgs();

        $html = $this->actingAs($cgs)->get(route('workstation.cgs.catalog'))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/name="(seat_code|seat_number|number|first|start|from)"/', $html, 'the admin cannot type a seat number');
        $this->assertMatchesRegularExpression('/name="quantity"[^>]*min="1"/', $html, 'only a quantity, at least 1');

        // a crafted request that tries to pick numbers, an existing number or one below the counter
        $this->actingAs($cgs)->post(route('workstation.cgs.catalog.seats.store'), [
            'workstation_location_id' => $room->id, 'quantity' => 2, 'seat_code' => '5', 'seat_number' => '1', 'first' => '1', 'start' => '1',
        ])->assertSessionHas('status', 'Added 2 seats: Seat 316 to Seat 317.');

        $this->assertSame(['316', '317'], $room->workstations()->orderBy('id')->pluck('seat_code')->all());
    }

    public function test_deleting_seats_never_changes_the_next_number(): void
    {
        $room = $this->room();
        $this->catalogue()->addSeats($room, 5); // 1..5
        $this->assertNull($this->catalogue()->retire($room->workstations()->where('seat_code', '3')->first(), null));
        $this->assertNull($this->catalogue()->retire($room->workstations()->where('seat_code', '5')->first(), null)); // the newest

        $this->assertSame(6, $this->catalogue()->nextNumber());

        $range = $this->catalogue()->addSeats($room, 2);
        $this->assertSame(6, $range['first']); // not 3, not 5
        $this->assertSame(['1', '2', '4', '6', '7'], $room->workstations()->orderByRaw('CAST(seat_code AS UNSIGNED)')->pluck('seat_code')->all());
    }

    public function test_numbers_continue_past_999_and_sort_numerically(): void
    {
        $this->setCounter(998);
        $room = $this->room();
        $this->catalogue()->addSeats($room, 4); // 999, 1000, 1001, 1002

        $codes = $room->workstations()->orderByRaw('CAST(seat_code AS UNSIGNED) ASC')->pluck('seat_code')->all();
        $this->assertSame(['999', '1000', '1001', '1002'], $codes);

        // The CGS catalogue page orders the same way and shows "Seat 1001" in full.
        $this->actingAs($this->cgs())->get(route('workstation.cgs.catalog'))
            ->assertOk()->assertSeeInOrder(['Seat 999', 'Seat 1000', 'Seat 1001', 'Seat 1002']);
    }

    public function test_a_free_or_maintenance_seat_is_retired_and_vanishes_everywhere_but_history(): void
    {
        $room = $this->room();
        $this->catalogue()->addSeats($room, 3);
        [$a, $b] = [$room->workstations()->where('seat_code', '1')->first(), $room->workstations()->where('seat_code', '2')->first()];
        $b->update(['status' => Workstation::STATUS_DISABLED]);

        $cgs = $this->cgs();
        $this->actingAs($cgs)->delete(route('workstation.cgs.catalog.seats.destroy', $a))->assertSessionHas('status', 'Seat 1 deleted.');
        $this->actingAs($cgs)->delete(route('workstation.cgs.catalog.seats.destroy', $b))->assertSessionHas('status', 'Seat 2 deleted.');

        $this->assertSame(1, $room->workstations()->count());
        $this->assertSame(1, WorkstationLocation::withCount('workstations')->find($room->id)->workstations_count);
        $this->assertNotNull(Workstation::withTrashed()->find($a->id)); // soft deleted, row kept
        $this->assertSame(2, WorkstationHistory::where('action', WorkstationHistory::ACTION_SEAT_RETIRED)->count());
    }

    public function test_a_seat_in_use_cannot_be_deleted(): void
    {
        $room = $this->room();
        $student = $this->eligibleStudent();
        $cgs = $this->cgs();

        foreach ([Workstation::STATUS_OCCUPIED, Workstation::STATUS_AWAITING_KEY_RETURN, Workstation::STATUS_PENDING] as $i => $status) {
            $seat = Workstation::create(['workstation_location_id' => $room->id, 'seat_code' => (string) (10 + $i), 'status' => $status]);

            $this->actingAs($cgs)->delete(route('workstation.cgs.catalog.seats.destroy', $seat))
                ->assertSessionHas('error', 'This seat is in use. Ask CGS to contact the student and reassign the seat first.');
            $this->assertNull($seat->fresh()->deleted_at);
        }

        // An available seat that still has an active assignment is also in use.
        $held = Workstation::create(['workstation_location_id' => $room->id, 'seat_code' => '20']);
        WorkstationRequest::create(['workstation_id' => $held->id, 'student_id' => $student->id, 'status' => 'confirmed', 'requested_at' => now()]);
        $this->assertSame(WorkstationSeatCatalogue::IN_USE_MESSAGE, $this->catalogue()->blockReason($held));

        // A reserved seat is not free either, with its own message.
        $reserved = Workstation::create(['workstation_location_id' => $room->id, 'seat_code' => '21', 'status' => Workstation::STATUS_RESERVED]);
        $this->assertSame(WorkstationSeatCatalogue::NOT_FREE_MESSAGE, $this->catalogue()->blockReason($reserved));
    }

    public function test_a_retired_seat_is_gone_from_the_student_seat_list_and_counts(): void
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        $room = $this->room();
        $this->catalogue()->addSeats($room, 3);
        $this->catalogue()->retire($room->workstations()->where('seat_code', '2')->first(), null);

        $this->actingAs($student)->get(route('workstation.seats', $room))
            ->assertOk()->assertSee('Seat 1')->assertSee('Seat 3')->assertDontSee('Seat 2<');
    }

    public function test_the_cluster_field_is_gone_from_the_catalogue_page(): void
    {
        $room = $this->room();
        $this->catalogue()->addSeats($room, 1);

        $this->actingAs($this->cgs())->get(route('workstation.cgs.catalog'))
            ->assertOk()->assertDontSee('Cluster')->assertDontSee('cluster');
    }

    public function test_layout_upload_replace_remove_and_validation(): void
    {
        $room = $this->room();
        $cgs = $this->cgs();
        $png = UploadedFile::fake()->image('layout.png', 800, 600);

        $this->actingAs($cgs)->post(route('workstation.cgs.catalog.layout.store', $room), ['layout' => $png])->assertSessionHas('status');
        $first = $room->fresh()->layout_image_path;
        $this->assertNotNull($first);
        Storage::disk('local')->assertExists($first);
        $this->assertStringStartsWith('workstation/layouts/', $first);

        // Replace: the old file is removed.
        $this->actingAs($cgs)->post(route('workstation.cgs.catalog.layout.store', $room), ['layout' => UploadedFile::fake()->image('b.jpg', 400, 400)])->assertSessionHas('status');
        Storage::disk('local')->assertMissing($first);

        foreach ([
            UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
            UploadedFile::fake()->create('evil.svg', 10, 'image/svg+xml'),
            UploadedFile::fake()->image('huge.png')->size(5121),
        ] as $bad) {
            $before = $room->fresh()->layout_image_path;
            $this->actingAs($cgs)->post(route('workstation.cgs.catalog.layout.store', $room), ['layout' => $bad])->assertSessionHasErrors('layout');
            $this->assertSame($before, $room->fresh()->layout_image_path);
        }

        $this->actingAs($cgs)->delete(route('workstation.cgs.catalog.layout.destroy', $room))->assertSessionHas('status');
        $this->assertNull($room->fresh()->layout_image_path);
    }

    public function test_a_student_can_only_see_the_layout_of_a_room_of_their_own_gender_and_guests_and_others_cannot(): void
    {
        $male = $this->room('male', 'M-1');
        $female = $this->room('female', 'F-1');
        foreach ([$male, $female] as $r) {
            $path = 'workstation/layouts/room-'.$r->id.'-x.png';
            Storage::disk('local')->put($path, UploadedFile::fake()->image('x.png')->get());
            $r->update(['layout_image_path' => $path, 'layout_image_mime' => 'image/png']);
        }

        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);

        $this->actingAs($student)->get(route('workstation.room-layout', $male))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs($student)->get(route('workstation.room-layout', $female))->assertForbidden();
        // CGS has its own preview route; a student must not reach it.
        $this->actingAs($student)->get(route('workstation.cgs.catalog.layout', $male))->assertForbidden();
        $this->actingAs($this->cgs())->get(route('workstation.cgs.catalog.layout', $female))->assertOk();
        // Students cannot upload or remove.
        $this->actingAs($student)->post(route('workstation.cgs.catalog.layout.store', $male), ['layout' => UploadedFile::fake()->image('a.png')])->assertForbidden();
        $this->actingAs($student)->delete(route('workstation.cgs.catalog.layout.destroy', $male))->assertForbidden();

        auth()->logout();
        $this->get(route('workstation.room-layout', $male))->assertRedirect();
    }

    public function test_the_layout_link_shows_only_when_the_room_has_an_image(): void
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        $room = $this->room();
        $this->catalogue()->addSeats($room, 1);

        $this->actingAs($student)->get(route('workstation.seats', $room))->assertOk()->assertDontSee('View room layout');

        $path = 'workstation/layouts/room-'.$room->id.'-x.png';
        Storage::disk('local')->put($path, UploadedFile::fake()->image('x.png')->get());
        $room->update(['layout_image_path' => $path, 'layout_image_mime' => 'image/png']);

        $this->actingAs($student)->get(route('workstation.seats', $room))->assertOk()->assertSee('View room layout');
    }

    public function test_the_select_seat_page_uses_the_in_page_dialog_not_the_browser_confirm(): void
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        $room = $this->room();
        $this->catalogue()->addSeats($room, 1);

        $html = $this->actingAs($student)->get(route('workstation.seats', $room))->assertOk()->getContent();

        $this->assertStringContainsString('id="select-seat-modal"', $html);
        $this->assertStringContainsString('<dialog', $html);
        $this->assertStringNotContainsString('confirm(', $html);
    }

    public function test_the_unique_active_assignment_index_is_in_place(): void
    {
        $this->assertTrue(\Schema::hasColumn('workstation_requests', 'workstation_id_if_confirmed'));
        $this->assertTrue(\Schema::hasColumn('workstation_requests', 'student_id_if_confirmed'));
        $this->assertTrue(class_exists(LockerKey::class));
    }
}
