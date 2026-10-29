<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\LockerKey;
use App\Modules\Chloe\Models\StudentGender;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationHistory;
use App\Modules\Chloe\Models\WorkstationLocation;
use App\Modules\Chloe\Models\WorkstationRequest;
use App\Modules\Chloe\Services\WorkstationAllocator;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\MakesEligibleWorkstationStudents;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The new "Awaiting Key Return" seat status, the Release Hold action,
 * previous-occupant display, the readable History labels, the unreturned-
 * key student rule, and the Lost key status -- all from the same task.
 */
class WorkstationAwaitingKeyReturnTest extends TestCase
{
    use MakesEligibleWorkstationStudents;
    use MakesUsers;
    use RefreshDatabase;

    protected ?User $cgsUserInstance = null;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    /** Memoized -- $this->cgs() creates a fixed-email row, so a test calling it twice would collide. */
    protected function cgsUser(): User
    {
        return $this->cgsUserInstance ??= $this->cgs();
    }

    protected function room(string $gender = 'male', string $code = 'N2-02-01'): WorkstationLocation
    {
        return WorkstationLocation::firstOrCreate(
            ['room_code' => $code],
            ['block' => 'N2', 'gender' => $gender, 'name' => $code]
        );
    }

    protected function seat(WorkstationLocation $room, string $code, string $status = Workstation::STATUS_AVAILABLE): Workstation
    {
        return Workstation::create([
            'workstation_location_id' => $room->id, 'seat_code' => $code, 'position' => 1, 'status' => $status,
        ]);
    }

    protected function occupiedSeatWithKey(string $email, string $seatCode, ?string $keyStatus, string $gender = 'male', string $name = 'Ahmad Danial'): array
    {
        $student = $this->eligibleStudent('2200'.substr(md5($email), 0, 4), $email, ['name' => $name]);
        StudentGender::create(['student_id' => $student->id, 'gender' => $gender]);
        $room = $this->room($gender);
        $seat = $this->seat($room, $seatCode, Workstation::STATUS_OCCUPIED);
        $request = WorkstationRequest::create([
            'workstation_id' => $seat->id, 'student_id' => $student->id,
            'status' => WorkstationRequest::STATUS_CONFIRMED, 'requested_at' => now(),
        ]);

        $lockerKey = null;

        if ($keyStatus) {
            $lockerKey = LockerKey::create([
                'workstation_request_id' => $request->id, 'student_id' => $student->id,
                'status' => $keyStatus, 'requested_at' => now()->subDays(5),
                'collected_at' => $keyStatus === LockerKey::STATUS_COLLECTED ? now()->subDays(4) : null,
            ]);
        }

        return [$student, $request, $seat, $lockerKey];
    }

    // -------------------------------------------------------------
    // PART 1: the new status itself
    // -------------------------------------------------------------

    public function test_releasing_a_collected_key_seat_makes_it_awaiting_key_return_not_available(): void
    {
        [$student, $request, $seat] = $this->occupiedSeatWithKey('p1a@test.my', '401', LockerKey::STATUS_COLLECTED);

        app(WorkstationAllocator::class)->releaseSeat($request, WorkstationHistory::ACTOR_CGS, $this->cgsUser(), 'System error');

        $this->assertSame(Workstation::STATUS_AWAITING_KEY_RETURN, $seat->fresh()->status);
        $this->assertSame(LockerKey::STATUS_RETURN_PENDING, LockerKey::first()->status);
    }

    public function test_releasing_a_not_requested_or_pending_collection_key_seat_becomes_available(): void
    {
        [, $request1, $seat1] = $this->occupiedSeatWithKey('p1b@test.my', '402', null);
        app(WorkstationAllocator::class)->releaseSeat($request1, WorkstationHistory::ACTOR_CGS, $this->cgsUser(), 'System error');
        $this->assertSame(Workstation::STATUS_AVAILABLE, $seat1->fresh()->status);

        [, $request2, $seat2] = $this->occupiedSeatWithKey('p1c@test.my', '403', LockerKey::STATUS_REQUESTED);
        app(WorkstationAllocator::class)->releaseSeat($request2, WorkstationHistory::ACTOR_CGS, $this->cgsUser(), 'System error');
        $this->assertSame(Workstation::STATUS_AVAILABLE, $seat2->fresh()->status);
    }

    public function test_an_awaiting_key_return_seat_is_excluded_from_student_lists_counts_and_direct_select(): void
    {
        [$student, $request, $seat] = $this->occupiedSeatWithKey('p1d@test.my', '404', LockerKey::STATUS_COLLECTED, 'female', 'Nur Aina');
        app(WorkstationAllocator::class)->releaseSeat($request, WorkstationHistory::ACTOR_CGS, $this->cgsUser(), 'System error');
        $this->assertSame(Workstation::STATUS_AWAITING_KEY_RETURN, $seat->fresh()->status);

        $otherStudent = $this->eligibleStudent('22009999', 'browsing@test.my');
        StudentGender::create(['student_id' => $otherStudent->id, 'gender' => 'female']);

        $seatsResponse = $this->actingAs($otherStudent)->get(route('workstation.seats', $seat->workstation_location_id));
        $seatsResponse->assertDontSee('Seat 404');

        $roomsResponse = $this->actingAs($otherStudent)->get(route('workstation.rooms', 'N2'));
        $roomsResponse->assertOk();

        $csrf = csrf_token();
        $select = $this->actingAs($otherStudent)->post(route('workstation.register', $seat), ['_token' => $csrf]);
        $select->assertRedirect();
        $select->assertSessionHas('error', 'This seat is no longer available. Please select another seat.');
    }

    public function test_an_awaiting_key_return_seat_is_excluded_from_assign_and_reassign_lists(): void
    {
        [$student, $request, $akrSeat] = $this->occupiedSeatWithKey('p1e@test.my', '405', LockerKey::STATUS_COLLECTED, 'male');
        app(WorkstationAllocator::class)->releaseSeat($request, WorkstationHistory::ACTOR_CGS, $this->cgsUser(), 'System error');

        [$occupant2, $request2, $occupiedSeat2] = $this->occupiedSeatWithKey('p1f@test.my', '406', null, 'male');
        $cgs = $this->cgsUser();

        // Reassign target list must not offer the Awaiting Key Return seat.
        $response = $this->actingAs($cgs)->get(route('workstation.cgs.seat', $occupiedSeat2));
        $response->assertOk();
        $response->assertDontSee('Seat 405');

        // Assign Student is never offered on the Awaiting Key Return seat
        // itself -- its Actions section only has Release Hold. (The
        // Assign Student *modal*, like every other action's, is always
        // present in the markup regardless of seat status -- its heading
        // "Assign Student to Seat {n}" is what a plain substring check on
        // "Assign Student" would actually be matching, so this checks the
        // trigger button's own markup instead.)
        $akrPage = $this->actingAs($cgs)->get(route('workstation.cgs.seat', $akrSeat));
        $akrPage->assertDontSee('data-open-modal="assign-modal"', false);
        $akrPage->assertSee('data-open-modal="release-hold-modal"', false);
    }

    public function test_reassigning_a_student_whose_key_is_collected_leaves_the_old_seat_awaiting_key_return(): void
    {
        [$student, $request, $oldSeat] = $this->occupiedSeatWithKey('p1g@test.my', '407', LockerKey::STATUS_COLLECTED);
        $newSeat = $this->seat($this->room('male', 'N2-02-10'), '408');

        app(WorkstationAllocator::class)->reassignSeat($request, $newSeat, $this->cgsUser(), 'Allocation error');

        $this->assertSame(Workstation::STATUS_AWAITING_KEY_RETURN, $oldSeat->fresh()->status);
        $this->assertSame(Workstation::STATUS_OCCUPIED, $newSeat->fresh()->status);
        $this->assertSame(LockerKey::STATUS_RETURN_PENDING, LockerKey::first()->status);
    }

    public function test_mark_as_returned_automatically_frees_an_awaiting_key_return_seat_and_writes_history(): void
    {
        [$student, $request, $seat] = $this->occupiedSeatWithKey('p1h@test.my', '409', LockerKey::STATUS_COLLECTED);
        app(WorkstationAllocator::class)->releaseSeat($request, WorkstationHistory::ACTOR_CGS, $this->cgsUser(), 'System error');
        $this->assertSame(Workstation::STATUS_AWAITING_KEY_RETURN, $seat->fresh()->status);

        $lockerKey = LockerKey::first();
        $lockerKey->markReturned(WorkstationHistory::ACTOR_CGS, 'Puan Waheeda');

        $this->assertSame(Workstation::STATUS_AVAILABLE, $seat->fresh()->status);
        $this->assertDatabaseHas('workstation_histories', [
            'seat_number' => '409', 'action' => WorkstationHistory::ACTION_SEAT_AVAILABLE_AGAIN,
        ]);
    }

    public function test_release_hold_with_each_reason_frees_the_seat_marks_the_key_lost_and_allows_a_new_key_request(): void
    {
        foreach (WorkstationAllocator::REASONS_RELEASE_HOLD as $i => $reason) {
            [$student, $request, $seat] = $this->occupiedSeatWithKey("p1i{$i}@test.my", (string) (410 + $i), LockerKey::STATUS_COLLECTED);
            app(WorkstationAllocator::class)->releaseSeat($request, WorkstationHistory::ACTOR_CGS, $this->cgsUser(), 'System error');
            $this->assertSame(Workstation::STATUS_AWAITING_KEY_RETURN, $seat->fresh()->status);

            app(WorkstationAllocator::class)->releaseHold($seat->fresh(), $this->cgsUser(), $reason, 'Checked with student');

            $this->assertSame(Workstation::STATUS_AVAILABLE, $seat->fresh()->status);
            $this->assertSame(LockerKey::STATUS_LOST, LockerKey::first()->status);
            $this->assertDatabaseHas('workstation_histories', [
                'seat_number' => (string) (410 + $i), 'action' => WorkstationHistory::ACTION_KEY_MARKED_LOST, 'reason' => $reason,
            ]);
            $this->assertDatabaseHas('workstation_histories', [
                'seat_number' => (string) (410 + $i), 'action' => WorkstationHistory::ACTION_RELEASE_HOLD, 'reason' => $reason,
            ]);

            // The student (now with a Lost, not Return Pending, key) can
            // select a new seat and request a new key without being blocked.
            $this->assertFalse(LockerKey::where('student_id', $student->id)->where('status', LockerKey::STATUS_RETURN_PENDING)->exists());
        }
    }

    public function test_release_hold_rejects_an_invalid_reason_and_a_non_awaiting_seat(): void
    {
        [$student, $request, $seat] = $this->occupiedSeatWithKey('p1j@test.my', '420', LockerKey::STATUS_COLLECTED);
        app(WorkstationAllocator::class)->releaseSeat($request, WorkstationHistory::ACTOR_CGS, $this->cgsUser(), 'System error');

        $this->expectException(\InvalidArgumentException::class);
        app(WorkstationAllocator::class)->releaseHold($seat->fresh(), $this->cgsUser(), 'Not a real reason');
    }

    public function test_release_hold_rejects_a_seat_that_is_not_awaiting_key_return(): void
    {
        $seat = $this->seat($this->room('male', 'N2-02-11'), '421');

        $this->expectException(\RuntimeException::class);
        app(WorkstationAllocator::class)->releaseHold($seat, $this->cgsUser(), 'Key lost');
    }

    public function test_set_maintenance_on_an_awaiting_key_return_seat_is_blocked_with_the_exact_message(): void
    {
        [$student, $request, $seat] = $this->occupiedSeatWithKey('p1k@test.my', '422', LockerKey::STATUS_COLLECTED);
        app(WorkstationAllocator::class)->releaseSeat($request, WorkstationHistory::ACTOR_CGS, $this->cgsUser(), 'System error');

        $cgs = $this->cgsUser();
        $response = $this->actingAs($cgs)->post(route('workstation.cgs.seat.maintenance', $seat), ['reason' => 'Repair needed']);

        $response->assertSessionHas('error', 'Return the key or use Release Hold first.');
        $this->assertSame(Workstation::STATUS_AWAITING_KEY_RETURN, $seat->fresh()->status);
    }

    public function test_the_set_maintenance_button_is_replaced_by_the_disabled_note_and_release_hold_button(): void
    {
        [$student, $request, $seat] = $this->occupiedSeatWithKey('p1l@test.my', '423', LockerKey::STATUS_COLLECTED);
        app(WorkstationAllocator::class)->releaseSeat($request, WorkstationHistory::ACTOR_CGS, $this->cgsUser(), 'System error');

        $response = $this->actingAs($this->cgsUser())->get(route('workstation.cgs.seat', $seat));

        $response->assertOk();
        $response->assertSee('Return the key or use Release Hold first.');
        $response->assertSee('Release Hold');
        $response->assertDontSee('Set Maintenance');
    }

    public function test_overdue_is_derived_and_the_seat_never_becomes_maintenance_automatically(): void
    {
        [$student, $request, $seat] = $this->occupiedSeatWithKey('p1m@test.my', '424', LockerKey::STATUS_COLLECTED);
        app(WorkstationAllocator::class)->releaseSeat($request, WorkstationHistory::ACTOR_CGS, $this->cgsUser(), 'System error');

        LockerKey::first()->update(['return_due_at' => now()->subDays(20)]);

        $this->artisan('workstation:process-releases')->assertSuccessful();
        $this->artisan('workstation:remind-locker-keys')->assertSuccessful();

        $this->assertSame(Workstation::STATUS_AWAITING_KEY_RETURN, $seat->fresh()->status);
        $this->assertTrue(LockerKey::first()->isOverdueForReturn());
    }

    // -------------------------------------------------------------
    // PART 2: previous occupant
    // -------------------------------------------------------------

    public function test_seat_details_shows_a_previous_occupant_block_for_an_awaiting_key_return_seat(): void
    {
        [$student, $request, $seat] = $this->occupiedSeatWithKey('p2a@test.my', '430', LockerKey::STATUS_COLLECTED, 'male', 'Harun Previous');
        app(WorkstationAllocator::class)->releaseSeat($request, WorkstationHistory::ACTOR_CGS, $this->cgsUser(), 'System error');

        $response = $this->actingAs($this->cgsUser())->get(route('workstation.cgs.seat', $seat));

        $response->assertOk();
        $response->assertSee('Previous Occupant');
        $response->assertSee('Harun Previous');
        $response->assertSee($student->matric_no);
        $response->assertSee('mailto:'.$student->email, false);
        $response->assertSee('Locker key not returned');
        $response->assertDontSee('No student assigned');
    }

    public function test_seat_details_shows_an_unreturned_key_elsewhere_for_an_occupied_student(): void
    {
        [$student, $oldRequest, $oldSeat] = $this->occupiedSeatWithKey('p2b@test.my', '431', LockerKey::STATUS_COLLECTED, 'male', 'Zalina Mover');
        app(WorkstationAllocator::class)->releaseSeat($oldRequest, WorkstationHistory::ACTOR_CGS, $this->cgsUser(), 'System error');

        // Now seat them somewhere new (a different, real seat) directly.
        $newSeat = $this->seat($this->room('male', 'N2-02-12'), '432', Workstation::STATUS_OCCUPIED);
        $newRequest = WorkstationRequest::create([
            'workstation_id' => $newSeat->id, 'student_id' => $student->id,
            'status' => WorkstationRequest::STATUS_CONFIRMED, 'requested_at' => now(),
        ]);

        $response = $this->actingAs($this->cgsUser())->get(route('workstation.cgs.seat', $newSeat));

        $response->assertOk();
        $response->assertSee('Unreturned locker key: Seat 431');
    }

    public function test_search_and_suggestions_find_the_previous_occupant_of_an_awaiting_key_return_seat(): void
    {
        [$student, $request, $seat] = $this->occupiedSeatWithKey('p2c@test.my', '433', LockerKey::STATUS_COLLECTED, 'male', 'Qistina Vanished');
        app(WorkstationAllocator::class)->releaseSeat($request, WorkstationHistory::ACTOR_CGS, $this->cgsUser(), 'System error');

        $cgs = $this->cgsUser();

        $search = $this->actingAs($cgs)->get(route('workstation.cgs.operations', ['q' => 'Qistina Vanished']));
        $search->assertOk();
        $search->assertSee('Seat 433');

        $suggestions = $this->actingAs($cgs)->getJson(route('workstation.cgs.suggestions', ['q' => 'Qistina']));
        $suggestions->assertOk();
        $this->assertTrue(collect($suggestions->json())->pluck('text')->contains('Qistina Vanished'));
    }

    // -------------------------------------------------------------
    // PART 3: readable History
    // -------------------------------------------------------------

    public function test_history_snapshot_includes_email_and_programme(): void
    {
        [$student, $request, $seat] = $this->occupiedSeatWithKey('p3a@test.my', '440', null);
        $student->update(['programme' => 'MSc Full-Time']);

        app(WorkstationAllocator::class)->releaseSeat($request, WorkstationHistory::ACTOR_CGS, $this->cgsUser(), 'System error');

        $this->assertDatabaseHas('workstation_histories', [
            'seat_number' => '440', 'student_email' => $student->email, 'student_programme' => 'MSc Full-Time',
        ]);
    }

    public function test_actor_action_and_state_labels_are_readable(): void
    {
        [$student, $request, $seat] = $this->occupiedSeatWithKey('p3b@test.my', '441', LockerKey::STATUS_COLLECTED);
        app(WorkstationAllocator::class)->releaseSeat($request, WorkstationHistory::ACTOR_CGS, $this->cgsUser(), 'System error');

        $seatHistory = WorkstationHistory::where('seat_number', '441')
            ->where('action', WorkstationHistory::ACTION_SEAT_RELEASED)->first();
        $this->assertSame('CGS · '.$this->cgsUser()->name, $seatHistory->actorLabel());
        $this->assertSame('Awaiting Key Return', $seatHistory->afterLabel());
        $this->assertSame('Occupied', $seatHistory->beforeLabel());

        $keyHistory = WorkstationHistory::where('seat_number', '441')
            ->where('action', WorkstationHistory::ACTION_KEY_RETURN_PENDING)->first();
        $this->assertSame('Locker key set to Return Pending', $keyHistory->actionLabel());

        $systemHistory = new WorkstationHistory(['actor_type' => WorkstationHistory::ACTOR_SYSTEM, 'actor_name' => 'System']);
        $this->assertSame('System', $systemHistory->actorLabel());
    }

    public function test_demo_data_remark_is_hidden_in_display_but_not_removed_from_storage(): void
    {
        $entry = WorkstationHistory::create([
            'block' => 'N2', 'room_code' => 'N2-02-01', 'seat_number' => '1',
            'actor_type' => WorkstationHistory::ACTOR_SYSTEM, 'actor_name' => 'System',
            'action' => WorkstationHistory::ACTION_SEAT_CONFIRMED, 'remark' => 'Demo data',
        ]);

        $this->assertNull($entry->remarkForDisplay());
        $this->assertSame('Demo data', $entry->fresh()->remark);
    }

    public function test_export_history_has_the_new_columns_and_readable_labels(): void
    {
        [$student, $request, $seat] = $this->occupiedSeatWithKey('p3c@test.my', '442', null);
        app(WorkstationAllocator::class)->releaseSeat($request, WorkstationHistory::ACTOR_CGS, $this->cgsUser(), 'System error');

        $response = $this->actingAs($this->cgsUser())->get(route('workstation.cgs.export.history'));
        $response->assertOk();
        $this->assertStringContainsString('spreadsheet', $response->headers->get('Content-Type'));
    }

    // -------------------------------------------------------------
    // PART 4: unreturned key blocks a new request
    // -------------------------------------------------------------

    public function test_a_student_with_an_unreturned_key_cannot_request_a_new_one_even_on_a_new_seat(): void
    {
        [$student, $oldRequest, $oldSeat] = $this->occupiedSeatWithKey('p4a@test.my', '450', LockerKey::STATUS_COLLECTED, 'male', 'Faiz Owed');
        app(WorkstationAllocator::class)->releaseSeat($oldRequest, WorkstationHistory::ACTOR_STUDENT, $student, 'Changing to another workstation');

        $newSeat = $this->seat($this->room('male', 'N2-02-13'), '451', Workstation::STATUS_OCCUPIED);
        WorkstationRequest::create([
            'workstation_id' => $newSeat->id, 'student_id' => $student->id,
            'status' => WorkstationRequest::STATUS_CONFIRMED, 'requested_at' => now(),
        ]);

        $response = $this->actingAs($student)->post(route('workstation.locker-key.request'));

        $response->assertSessionHas('error', 'Please return your previous locker key first.');
        $this->assertDatabaseMissing('locker_keys', ['workstation_request_id' => WorkstationRequest::where('workstation_id', $newSeat->id)->first()->id]);

        $page = $this->actingAs($student)->get(route('workstation.select'));
        $page->assertSee('Please return your previous locker key first.');
    }

    public function test_a_student_with_an_unreturned_key_can_still_select_a_seat(): void
    {
        [$student, $oldRequest, $oldSeat] = $this->occupiedSeatWithKey('p4b@test.my', '452', LockerKey::STATUS_COLLECTED, 'male');
        app(WorkstationAllocator::class)->releaseSeat($oldRequest, WorkstationHistory::ACTOR_STUDENT, $student, 'Changing to another workstation');

        $newSeat = $this->seat($this->room('male', 'N2-02-14'), '453');

        $response = $this->actingAs($student)->post(route('workstation.register', $newSeat));

        $response->assertRedirect();
        $this->assertSame(Workstation::STATUS_PENDING, $newSeat->fresh()->status);
    }

    public function test_a_normal_student_without_an_unreturned_key_is_unaffected(): void
    {
        [$student, $request, $seat] = $this->occupiedSeatWithKey('p4c@test.my', '454', null);

        $response = $this->actingAs($student)->post(route('workstation.locker-key.request'));

        $response->assertSessionHas('status');
        $this->assertSame(LockerKey::STATUS_REQUESTED, LockerKey::first()->status);
    }

    // -------------------------------------------------------------
    // PART 5: Lost key status
    // -------------------------------------------------------------

    public function test_a_lost_key_is_excluded_from_the_return_pending_list(): void
    {
        [$student, $request, $seat] = $this->occupiedSeatWithKey('p5a@test.my', '460', LockerKey::STATUS_COLLECTED);
        app(WorkstationAllocator::class)->releaseSeat($request, WorkstationHistory::ACTOR_CGS, $this->cgsUser(), 'System error');
        app(WorkstationAllocator::class)->releaseHold($seat->fresh(), $this->cgsUser(), 'Key lost');

        $this->assertCount(0, LockerKey::returnPendingList());
    }
}
