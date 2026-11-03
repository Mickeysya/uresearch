<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\StudentGender;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationLocation;
use App\Modules\Chloe\Models\WorkstationPendingSelection;
use App\Modules\Chloe\Models\WorkstationRequest;
use App\Modules\Chloe\Notifications\WorkstationSeatSelected;
use App\Modules\Chloe\Services\WorkstationAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\MakesEligibleWorkstationStudents;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Seat selection (round 4): Select + Confirm in the page's dialog gives the seat at once (bell only, no e-mail, never
 * double-booked). The old 5-minute e-mail hold is gone for new selections; the tests that follow the legacy-hold helper
 * keep the old confirm link and expiry cleanup working for holds made before the change.
 */
class WorkstationSelectionConfirmationTest extends TestCase
{
    use MakesEligibleWorkstationStudents;
    use MakesUsers;
    use RefreshDatabase;

    /**
     * Every test here that reaches selectSeat()/confirmPendingSelection()
     * queues a real notification unless this is faked -- and since the queue
     * connection is shared with the real queue:work container, an unfaked
     * one escapes into it and fails there once this test's in-memory SQLite
     * database is gone. Faked for every test, not just the two that assert
     * on it.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    protected function room(string $gender = 'male'): WorkstationLocation
    {
        return WorkstationLocation::create([
            'block' => 'N2', 'room_code' => 'N2-02-01', 'gender' => $gender, 'name' => 'N2-02-01',
        ]);
    }

    protected function seat(WorkstationLocation $room, string $code = '1'): Workstation
    {
        return Workstation::create(['workstation_location_id' => $room->id, 'seat_code' => $code, 'position' => 1]);
    }

    /** A seat hold exactly as the old Select button made it (before round 4); only legacy rows still look like this. */
    protected function legacyHold($student, Workstation $seat, int $minutes = 5): WorkstationPendingSelection
    {
        $seat->update(['status' => Workstation::STATUS_PENDING]);

        return WorkstationPendingSelection::create([
            'workstation_id' => $seat->id, 'student_id' => $student->id, 'token' => \Illuminate\Support\Str::random(64),
            'status' => WorkstationPendingSelection::STATUS_PENDING, 'expires_at' => now()->addMinutes($minutes),
        ]);
    }

    public function test_selecting_a_seat_gives_it_at_once_with_a_bell_and_no_email(): void
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        $room = $this->room();
        $seat = $this->seat($room);

        $this->actingAs($student)->post(route('workstation.register', $seat))
            ->assertRedirect(route('workstation.select'))
            ->assertSessionHas('status');

        $this->assertSame(Workstation::STATUS_OCCUPIED, $seat->fresh()->status);
        $this->assertTrue(WorkstationRequest::where('workstation_id', $seat->id)->where('student_id', $student->id)->where('status', 'confirmed')->exists());
        $this->assertSame(0, WorkstationPendingSelection::count());

        Notification::assertSentTo($student, WorkstationSeatSelected::class, fn ($n) => $n->via($student) === ['database']);
    }

    public function test_a_stale_page_selecting_a_taken_seat_changes_nothing_and_shows_the_banner(): void
    {
        $room = $this->room();
        $seat = $this->seat($room);
        $first = $this->eligibleStudent('22001001', 'first@test.my');
        $second = $this->eligibleStudent('22001002', 'second@test.my');
        StudentGender::create(['student_id' => $first->id, 'gender' => 'male']);
        StudentGender::create(['student_id' => $second->id, 'gender' => 'male']);

        $this->actingAs($first)->post(route('workstation.register', $seat))->assertSessionHas('status');
        // The second student's page still listed the seat as free.
        $this->actingAs($second)->post(route('workstation.register', $seat))
            ->assertRedirect(route('workstation.seats', $room))
            ->assertSessionHas('error', 'This seat is no longer available. No change was made.');

        $this->assertSame(1, WorkstationRequest::where('workstation_id', $seat->id)->count());
        $this->assertSame($first->id, WorkstationRequest::where('workstation_id', $seat->id)->value('student_id'));
        $this->assertFalse(WorkstationRequest::where('student_id', $second->id)->exists());
    }

    public function test_a_seat_retired_while_the_page_was_open_gives_the_same_banner(): void
    {
        $room = $this->room();
        $seat = $this->seat($room);
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);

        $seat->delete();

        $this->actingAs($student)->post(route('workstation.register', $seat->id))
            ->assertSessionHas('error', 'This seat is no longer available. No change was made.');
        $this->assertFalse(WorkstationRequest::exists());
    }

    public function test_the_database_refuses_a_second_active_assignment_of_the_same_seat(): void
    {
        $room = $this->room();
        $seat = $this->seat($room);
        $first = $this->eligibleStudent('22001001', 'first@test.my');
        $second = $this->eligibleStudent('22001002', 'second@test.my');

        WorkstationRequest::create(['workstation_id' => $seat->id, 'student_id' => $first->id, 'status' => 'confirmed', 'requested_at' => now()]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        WorkstationRequest::create(['workstation_id' => $seat->id, 'student_id' => $second->id, 'status' => 'confirmed', 'requested_at' => now()]);
    }

    public function test_released_requests_do_not_block_the_seat_being_taken_again(): void
    {
        $room = $this->room();
        $seat = $this->seat($room);
        $first = $this->eligibleStudent('22001001', 'first@test.my');
        $second = $this->eligibleStudent('22001002', 'second@test.my');
        StudentGender::create(['student_id' => $second->id, 'gender' => 'male']);

        WorkstationRequest::create(['workstation_id' => $seat->id, 'student_id' => $first->id, 'status' => 'released', 'requested_at' => now(), 'released_at' => now()]);

        $this->assertTrue(app(WorkstationAllocator::class)->selectSeat($second, $seat)['selected']);
    }

    public function test_a_seat_held_as_pending_does_not_appear_in_the_available_count(): void
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        $room = $this->room();
        $seat = $this->seat($room);

        $this->legacyHold($student, $seat);

        $this->assertFalse($seat->fresh()->isAvailable());
    }

    public function test_clicking_an_old_confirm_link_within_the_window_grants_the_seat_with_a_bell_only(): void
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        $room = $this->room();
        $seat = $this->seat($room);

        $token = $this->legacyHold($student, $seat)->token;

        $response = $this->get(route('workstation.confirm', $token));

        $response->assertOk()->assertViewHas('outcome', 'confirmed');
        $this->assertSame(Workstation::STATUS_OCCUPIED, $seat->fresh()->status);
        $this->assertSame(
            WorkstationPendingSelection::STATUS_CONFIRMED,
            WorkstationPendingSelection::where('token', $token)->value('status')
        );
        $this->assertTrue(
            WorkstationRequest::where('workstation_id', $seat->id)
                ->where('student_id', $student->id)
                ->where('status', WorkstationRequest::STATUS_CONFIRMED)
                ->exists()
        );

        Notification::assertSentTo($student, WorkstationSeatSelected::class);
    }

    public function test_the_confirm_link_works_without_being_logged_in(): void
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        $room = $this->room();
        $seat = $this->seat($room);

        $result = ['selection' => $this->legacyHold($student, $seat)];

        // No actingAs() -- the token itself is the authorisation, the same
        // shape as a password-reset link, not the session.
        $this->get(route('workstation.confirm', $result['selection']->token))
            ->assertOk()
            ->assertViewHas('outcome', 'confirmed');
    }

    public function test_clicking_the_same_confirm_link_twice_does_nothing_the_second_time(): void
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        $room = $this->room();
        $seat = $this->seat($room);

        $token = $this->legacyHold($student, $seat)->token;
        $this->get(route('workstation.confirm', $token));

        $response = $this->get(route('workstation.confirm', $token));

        $response->assertOk()->assertViewHas('outcome', 'already-confirmed');
        $this->assertSame(Workstation::STATUS_OCCUPIED, $seat->fresh()->status);
    }

    public function test_an_expired_link_shows_the_expiry_message_and_releases_the_seat(): void
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        $room = $this->room();
        $seat = $this->seat($room);

        $selection = $this->legacyHold($student, $seat);
        $selection->update(['expires_at' => now()->subMinute()]);

        $response = $this->get(route('workstation.confirm', $selection->token));

        $response->assertOk()->assertViewHas('outcome', 'expired')
            ->assertSee('This confirmation link has expired. Your selection has been cancelled. Please return to the dashboard and select a seat again.');
        $this->assertSame(Workstation::STATUS_AVAILABLE, $seat->fresh()->status);
        $this->assertSame(WorkstationPendingSelection::STATUS_EXPIRED, $selection->fresh()->status);
    }

    public function test_an_invalid_token_shows_a_not_valid_message_without_touching_any_seat(): void
    {
        $response = $this->get(route('workstation.confirm', 'this-token-does-not-exist'));

        $response->assertOk()->assertViewHas('outcome', 'invalid');
    }

    public function test_the_scheduled_cleanup_command_releases_expired_selections(): void
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        $room = $this->room();
        $seat = $this->seat($room);

        $selection = $this->legacyHold($student, $seat);
        $selection->update(['expires_at' => now()->subMinute()]);

        $this->artisan('workstation:release-expired-selections')
            ->expectsOutputToContain('Released 1 expired workstation selection(s).')
            ->assertSuccessful();

        $this->assertSame(Workstation::STATUS_AVAILABLE, $seat->fresh()->status);
    }

    public function test_visiting_the_room_list_inline_releases_an_expired_selection_even_without_the_scheduled_command(): void
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        $room = $this->room();
        $seat = $this->seat($room);

        $selection = $this->legacyHold($student, $seat);
        $selection->update(['expires_at' => now()->subMinute()]);

        $this->actingAs($student)->get(route('workstation.rooms', $room->block));

        $this->assertSame(Workstation::STATUS_AVAILABLE, $seat->fresh()->status);
    }

    public function test_a_student_cannot_select_a_second_seat_while_one_is_pending(): void
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        $room = $this->room();
        $firstSeat = $this->seat($room, '1');
        $secondSeat = $this->seat($room, '2');

        $this->legacyHold($student, $firstSeat);

        $response = $this->actingAs($student)->post(route('workstation.register', $secondSeat));

        $response->assertSessionHas('error');
        $this->assertSame(Workstation::STATUS_AVAILABLE, $secondSeat->fresh()->status);
    }

    public function test_a_student_cannot_select_a_second_seat_while_one_is_confirmed(): void
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        $room = $this->room();
        $firstSeat = $this->seat($room, '1');
        $secondSeat = $this->seat($room, '2');

        $this->assertTrue(app(WorkstationAllocator::class)->selectSeat($student, $firstSeat)['selected']);

        $response = $this->actingAs($student)->post(route('workstation.register', $secondSeat));

        $response->assertSessionHas('error');
        $this->assertSame(Workstation::STATUS_AVAILABLE, $secondSeat->fresh()->status);
    }

    public function test_the_gender_rule_is_enforced_when_selecting_not_just_when_browsing(): void
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'female']);
        $maleRoom = $this->room('male');
        $maleSeat = $this->seat($maleRoom);

        // A direct POST to a male seat's id, bypassing the (gender-filtered)
        // room list a real browse would have gone through.
        $this->actingAs($student)->post(route('workstation.register', $maleSeat))->assertForbidden();

        $this->assertSame(Workstation::STATUS_AVAILABLE, $maleSeat->fresh()->status);
    }
}
