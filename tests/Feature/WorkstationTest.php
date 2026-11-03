<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\StudentGender;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationBlock;
use App\Modules\Chloe\Models\WorkstationLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\MakesEligibleWorkstationStudents;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Gender is a student record, read-only everywhere in this module — there
 * is no route anywhere, student or CGS, that writes to student_genders,
 * and a student with none set cannot reach any room or seat. See
 * WorkstationController's class doc.
 */
class WorkstationTest extends TestCase
{
    use MakesEligibleWorkstationStudents;
    use MakesUsers;
    use RefreshDatabase;

    protected function room(string $gender, string $roomCode = 'N2-03-01-02', string $block = 'N2'): WorkstationLocation
    {
        return WorkstationLocation::create([
            'block' => $block,
            'room_code' => $roomCode,
            'gender' => $gender,
            'name' => $roomCode,
        ]);
    }

    public function test_a_student_with_no_gender_recorded_cannot_browse_rooms(): void
    {
        $student = $this->eligibleStudent();
        $this->room('male');

        $this->actingAs($student)
            ->get(route('workstation.rooms', 'N2'))
            ->assertRedirect(route('workstation.select'));

        $this->actingAs($student)
            ->get(route('workstation.select'))
            ->assertOk()
            ->assertSee('Your gender information is missing. Please contact the Centre for Graduate Studies (CGS).');
    }

    public function test_there_is_no_student_facing_route_to_set_gender(): void
    {
        $this->assertFalse(Route::has('workstation.gender.set'));
    }

    /**
     * Gender is read-only everywhere in this module now -- there is no
     * longer any route, on either side, that writes to student_genders.
     * It is set only by DatabaseSeeder's demo data (or directly in the
     * database by whoever owns that process outside this module).
     */
    public function test_there_is_no_cgs_route_to_set_gender_either(): void
    {
        $this->assertFalse(Route::has('workstation.cgs.gender.set'));

        $student = $this->eligibleStudent();
        $cgs = $this->cgs();

        $this->actingAs($cgs)
            ->post('/cgs/workstation/students/'.$student->id.'/gender', ['gender' => 'female'])
            ->assertStatus(404);

        $this->assertNull(StudentGender::where('student_id', $student->id)->value('gender'));
    }

    public function test_a_student_only_sees_rooms_matching_their_recorded_gender(): void
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'female']);

        $this->room('male', 'N2-03-01-02');
        $femaleRoom = $this->room('female', 'N2-03-05-06');

        $response = $this->actingAs($student)->get(route('workstation.rooms', 'N2'));

        $response->assertOk()->assertSee($femaleRoom->name)->assertDontSee('N2-03-01-02');
    }

    public function test_a_direct_link_to_a_room_of_the_other_gender_is_refused(): void
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);

        $femaleRoom = $this->room('female', 'N2-03-05-06');

        $this->actingAs($student)
            ->get(route('workstation.seats', $femaleRoom))
            ->assertForbidden();
    }

    public function test_a_room_with_no_photo_falls_back_to_the_placeholder_illustration(): void
    {
        $room = $this->room('male');

        $this->assertStringContainsString('room-placeholder.svg', $room->photoUrl());
    }

    public function test_a_room_with_a_photo_uses_it_instead_of_the_placeholder(): void
    {
        $room = $this->room('male');
        $room->update(['photo_path' => 'images/workstation/n2-03-01-02.jpg']);

        $this->assertStringContainsString('n2-03-01-02.jpg', $room->photoUrl());
        $this->assertStringNotContainsString('placeholder', $room->photoUrl());
    }

    public function test_the_room_list_page_shows_the_room_photo(): void
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);

        $room = $this->room('male', 'N2-03-01-02');
        Workstation::create(['workstation_location_id' => $room->id, 'seat_code' => '1', 'position' => 1]);

        $this->actingAs($student)
            ->get(route('workstation.rooms', 'N2'))
            ->assertOk()
            ->assertSee('room-placeholder.svg', false);
    }

    public function test_the_seats_page_shows_a_seat_icon_but_not_the_room_banner_photo(): void
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);

        $room = $this->room('male', 'N2-03-01-02');
        Workstation::create(['workstation_location_id' => $room->id, 'seat_code' => '1', 'position' => 1]);

        $response = $this->actingAs($student)->get(route('workstation.seats', $room));

        $response->assertOk()->assertSee($room->seatIconUrl(), false);
        $response->assertDontSee($room->photoUrl(), false);
    }

    public function test_the_block_page_has_a_back_link_and_the_home_button(): void
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        $this->room('male');

        $response = $this->actingAs($student)->get(route('workstation.rooms', 'N2'));

        $response->assertOk();
        $response->assertSee('page-header-back', false);
        $response->assertSee(route('workstation.select'), false);
        $response->assertSee('>Home<', false);
    }

    public function test_the_seat_list_page_has_a_back_link_to_the_block_not_only_home(): void
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        $room = $this->room('male', 'N2-03-01-02');
        Workstation::create(['workstation_location_id' => $room->id, 'seat_code' => '1', 'position' => 1]);

        $response = $this->actingAs($student)->get(route('workstation.seats', $room));

        $response->assertOk();
        $response->assertSee('page-header-back', false);
        $response->assertSee(route('workstation.rooms', 'N2'), false);
        $response->assertSee('>Home<', false);
        // The old in-panel "← Block N2" breadcrumb is gone -- exactly one
        // back link now, not a duplicate.
        $this->assertSame(1, substr_count($response->getContent(), 'page-header-back'));
    }

    public function test_the_landing_page_has_no_back_link(): void
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);

        $response = $this->actingAs($student)->get(route('workstation.select'));

        $response->assertOk();
        $response->assertDontSee('page-header-back', false);
    }

    public function test_seats_are_listed_in_ascending_numeric_order_not_position_order(): void
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);

        $room = $this->room('male', 'N2-03-01-02');

        // seat_code is a string column, so a naive sort would put "10"
        // before "9"; `position` is set deliberately out of numeric order
        // (the room's old physical reading order) so this fails if the
        // query ever goes back to ordering by position instead of the cast
        // seat number.
        Workstation::create(['workstation_location_id' => $room->id, 'seat_code' => '10', 'position' => 1]);
        Workstation::create(['workstation_location_id' => $room->id, 'seat_code' => '2', 'position' => 2]);
        Workstation::create(['workstation_location_id' => $room->id, 'seat_code' => '9', 'position' => 3]);

        $response = $this->actingAs($student)->get(route('workstation.seats', $room));

        $html = $response->getContent();
        $posTwo = strpos($html, 'Seat 2<');
        $posNine = strpos($html, 'Seat 9<');
        $posTen = strpos($html, 'Seat 10<');

        $this->assertNotFalse($posTwo);
        $this->assertNotFalse($posNine);
        $this->assertNotFalse($posTen);
        $this->assertTrue($posTwo < $posNine && $posNine < $posTen, 'Seats are not in ascending numeric order.');
    }

    public function test_only_available_seats_are_listed_occupied_and_maintenance_are_skipped(): void
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);

        $room = $this->room('male', 'N2-03-01-02');
        Workstation::create(['workstation_location_id' => $room->id, 'seat_code' => '1', 'position' => 1, 'status' => Workstation::STATUS_AVAILABLE]);
        Workstation::create(['workstation_location_id' => $room->id, 'seat_code' => '2', 'position' => 2, 'status' => Workstation::STATUS_OCCUPIED]);
        Workstation::create(['workstation_location_id' => $room->id, 'seat_code' => '3', 'position' => 3, 'status' => Workstation::STATUS_DISABLED]);

        $response = $this->actingAs($student)->get(route('workstation.seats', $room));

        $response->assertSee('Seat 1<', false);
        $response->assertDontSee('Seat 2<', false);
        $response->assertDontSee('Seat 3<', false);
    }

    public function test_a_blocks_seat_icon_falls_back_through_photo_then_illustration_then_placeholder(): void
    {
        $room = $this->room('male', 'Z9-01-01', 'Z9');

        // No seat-z9.jpg or seat-z9.svg exists, so it falls all the way
        // back to the generic room illustration.
        $this->assertStringContainsString('room-placeholder.svg', $room->seatIconUrl());
    }

    public function test_a_block_with_no_photo_falls_back_to_the_placeholder_illustration(): void
    {
        $block = WorkstationBlock::create(['code' => 'J2', 'name' => 'Block J2']);

        $this->assertStringContainsString('block-placeholder.svg', $block->photoUrl());
    }

    public function test_a_block_with_a_photo_uses_it_instead_of_the_placeholder(): void
    {
        $block = WorkstationBlock::create(['code' => 'J2', 'name' => 'Block J2', 'photo_path' => 'images/workstation/j2.jpg']);

        $this->assertStringContainsString('j2.jpg', $block->photoUrl());
        $this->assertStringNotContainsString('placeholder', $block->photoUrl());
    }

    public function test_a_block_with_rooms_but_no_workstation_block_row_still_gets_a_card(): void
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        $this->room('male', 'X9-01-01-01', 'X9');

        $this->actingAs($student)
            ->get(route('workstation.select'))
            ->assertOk()
            ->assertSee('Block X9')
            ->assertSee('block-placeholder.svg', false);
    }

    public function test_the_home_page_shows_a_photo_card_for_each_block_with_rooms(): void
    {
        WorkstationBlock::create(['code' => 'J2', 'name' => 'Block J2']);
        WorkstationBlock::create(['code' => 'N2', 'name' => 'Block N2']);

        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        $this->room('male', 'N2-03-01-02', 'N2');

        $response = $this->actingAs($student)->get(route('workstation.select'));

        // J2 has a WorkstationBlock row but no rooms yet, so it is left off
        // Home entirely -- the block list is driven by WorkstationLocation,
        // not by which WorkstationBlock rows happen to exist.
        $response->assertOk()
            ->assertSee('Block N2')
            ->assertDontSee('Block J2');
    }
}
