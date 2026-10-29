<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\StudentGender;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationLocation;
use App\Modules\Chloe\Models\WorkstationPendingSelection;
use App\Modules\Chloe\Models\WorkstationRequest;
use App\Modules\Chloe\Notifications\WorkstationSelectionConfirmed;
use App\Modules\Chloe\Notifications\WorkstationSelectionPendingConfirmation;
use App\Modules\Chloe\Services\WorkstationAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\MakesEligibleWorkstationStudents;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The 5-minute hold between clicking Select and clicking the link in the
 * confirmation email. See WorkstationAllocator for the two steps this walks
 * end to end, and WorkstationController for the three routes involved
 * (register, confirm, and the listing pages' inline expiry check).
 */
class WorkstationSelectionConfirmationTest extends TestCase
{
    use MakesEligibleWorkstationStudents;
    use MakesUsers;
    use RefreshDatabase;

    /**
     * Every test here that reaches beginPendingSelection()/confirmPendingSelection()
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

    public function test_selecting_a_seat_holds_it_as_pending_and_emails_a_confirm_link(): void
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        $room = $this->room();
        $seat = $this->seat($room);

        $response = $this->actingAs($student)->post(route('workstation.register', $seat));

        $response->assertRedirect(route('workstation.seats', $room))
            ->assertSessionHas('status', 'A confirmation email has been sent to your email. Please confirm within 5 minutes to secure your seat.');

        $this->assertSame(Workstation::STATUS_PENDING, $seat->fresh()->status);

        $selection = WorkstationPendingSelection::where('workstation_id', $seat->id)->first();
        $this->assertNotNull($selection);
        $this->assertSame($student->id, $selection->student_id);
        $this->assertSame(WorkstationPendingSelection::STATUS_PENDING, $selection->status);
        $this->assertTrue($selection->expires_at->between(now()->addMinutes(4), now()->addMinutes(5)));

        Notification::assertSentTo($student, WorkstationSelectionPendingConfirmation::class);
    }

    public function test_a_seat_held_as_pending_does_not_appear_in_the_available_count(): void
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        $room = $this->room();
        $seat = $this->seat($room);

        app(WorkstationAllocator::class)->beginPendingSelection($student, $seat);

        $this->assertFalse($seat->fresh()->isAvailable());
    }

    public function test_clicking_the_confirm_link_within_the_window_grants_the_seat_and_emails_confirmation(): void
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        $room = $this->room();
        $seat = $this->seat($room);

        $result = app(WorkstationAllocator::class)->beginPendingSelection($student, $seat);
        $token = $result['selection']->token;

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

        Notification::assertSentTo($student, WorkstationSelectionConfirmed::class);
    }

    public function test_the_confirm_link_works_without_being_logged_in(): void
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        $room = $this->room();
        $seat = $this->seat($room);

        $result = app(WorkstationAllocator::class)->beginPendingSelection($student, $seat);

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

        $token = app(WorkstationAllocator::class)->beginPendingSelection($student, $seat)['selection']->token;
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

        $selection = app(WorkstationAllocator::class)->beginPendingSelection($student, $seat)['selection'];
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

        $selection = app(WorkstationAllocator::class)->beginPendingSelection($student, $seat)['selection'];
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

        $selection = app(WorkstationAllocator::class)->beginPendingSelection($student, $seat)['selection'];
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

        app(WorkstationAllocator::class)->beginPendingSelection($student, $firstSeat);

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

        $allocator = app(WorkstationAllocator::class);
        $token = $allocator->beginPendingSelection($student, $firstSeat)['selection']->token;
        $allocator->confirmPendingSelection(WorkstationPendingSelection::where('token', $token)->first());

        $response = $this->actingAs($student)->post(route('workstation.register', $secondSeat));

        $response->assertSessionHas('error');
        $this->assertSame(Workstation::STATUS_AVAILABLE, $secondSeat->fresh()->status);
    }

    public function test_two_students_cannot_both_get_the_same_seat(): void
    {
        $room = $this->room();
        $seat = $this->seat($room);
        $allocator = app(WorkstationAllocator::class);

        $first = $this->eligibleStudent('22001001', 'first@test.my');
        $second = $this->eligibleStudent('22001002', 'second@test.my');
        StudentGender::create(['student_id' => $first->id, 'gender' => 'male']);
        StudentGender::create(['student_id' => $second->id, 'gender' => 'male']);

        $allocator->beginPendingSelection($first, $seat);
        $result = $allocator->beginPendingSelection($second, $seat);

        $this->assertFalse($result['started']);
        $this->assertSame(
            1,
            WorkstationPendingSelection::where('workstation_id', $seat->id)
                ->where('status', WorkstationPendingSelection::STATUS_PENDING)
                ->count()
        );
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
