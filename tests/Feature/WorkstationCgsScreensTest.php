<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\DemoStudentProfile;
use App\Modules\Chloe\Models\LockerKey;
use App\Modules\Chloe\Models\StudentGender;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationHistory;
use App\Modules\Chloe\Models\WorkstationLocation;
use App\Modules\Chloe\Models\WorkstationRequest;
use App\Modules\Chloe\Notifications\LockerKeyNotice;
use App\Modules\Chloe\Notifications\WorkstationStatusNotice;
use App\Modules\Chloe\Services\WorkstationAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use Tests\Support\MakesEligibleWorkstationStudents;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The CGS staff screens built on top of WorkstationAllocator's STEP 2
 * methods: the landing page (search/filters/stats/export), Block -> Room ->
 * Seat drill-down, and every per-seat action. WorkstationManagementTest
 * covers the allocator/model layer these screens call into; this file
 * covers the controller/routes/views layer on top of it.
 */
class WorkstationCgsScreensTest extends TestCase
{
    use MakesEligibleWorkstationStudents;
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
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

    protected function occupiedSeat(string $email, string $seatCode, string $gender = 'male', string $name = 'Ahmad Danial'): array
    {
        $student = $this->eligibleStudent('2200'.substr(md5($email), 0, 4), $email, ['name' => $name]);
        StudentGender::create(['student_id' => $student->id, 'gender' => $gender]);
        $room = $this->room($gender);
        $seat = $this->seat($room, $seatCode, Workstation::STATUS_OCCUPIED);
        $request = WorkstationRequest::create([
            'workstation_id' => $seat->id, 'student_id' => $student->id,
            'status' => WorkstationRequest::STATUS_CONFIRMED, 'requested_at' => now(),
        ]);

        return [$student, $request, $seat];
    }

    /** @return array{0: DemoStudentProfile, 1: WorkstationRequest, 2: Workstation} */
    protected function demoOccupiedSeat(string $seatCode, string $gender = 'male', string $name = 'Lim Wei Jian'): array
    {
        $profile = DemoStudentProfile::create([
            'matric_no' => '2201'.substr(md5($seatCode), 0, 4),
            'name' => $name,
            'email' => 'demo_'.substr(md5($seatCode), 0, 4).'@utp.edu.my',
            'gender' => $gender,
            'programme' => 'MSc Full-Time',
            'status' => DemoStudentProfile::STATUS_ACTIVE,
            'candidacy_expiry_date' => now()->addYear(),
        ]);
        $room = $this->room($gender);
        $seat = $this->seat($room, $seatCode, Workstation::STATUS_OCCUPIED);
        $request = WorkstationRequest::create([
            'workstation_id' => $seat->id, 'demo_profile_id' => $profile->id,
            'status' => WorkstationRequest::STATUS_CONFIRMED, 'requested_at' => now(),
        ]);

        return [$profile, $request, $seat];
    }

    // -------------------------------------------------------------
    // Access control
    // -------------------------------------------------------------

    public function test_a_student_cannot_open_any_cgs_workstation_page_or_action(): void
    {
        [$student, $request, $seat] = $this->occupiedSeat('student1@test.my', '101');

        $this->actingAs($student)->get(route('workstation.cgs.operations'))->assertForbidden();
        $this->actingAs($student)->get(route('workstation.cgs.block', 'N2'))->assertForbidden();
        $this->actingAs($student)->get(route('workstation.cgs.room', $seat->workstation_location_id))->assertForbidden();
        $this->actingAs($student)->get(route('workstation.cgs.seat', $seat))->assertForbidden();
        $this->actingAs($student)->post(route('workstation.cgs.seat.release', $seat), [
            'reason' => 'System error', 'seat_confirmation' => $seat->seat_code,
        ])->assertForbidden();
    }

    // -------------------------------------------------------------
    // Landing page
    // -------------------------------------------------------------

    public function test_the_landing_page_shows_blocks_when_no_filter_is_active(): void
    {
        $this->room('male', 'N2-02-01');
        $cgs = $this->cgs();

        $response = $this->actingAs($cgs)->get(route('workstation.cgs.operations'));

        $response->assertOk();
        $response->assertSee('Block N2');
        $response->assertSee('Workstation Management');
        $response->assertDontSee('page-header-back', false);
    }

    // -------------------------------------------------------------
    // Back / Home header
    // -------------------------------------------------------------

    public function test_results_mode_has_a_back_link_that_browse_mode_does_not(): void
    {
        $this->seat($this->room('male', 'N2-02-14'), '920');
        $cgs = $this->cgs();

        $browse = $this->actingAs($cgs)->get(route('workstation.cgs.operations'));
        $browse->assertOk();
        $browse->assertDontSee('page-header-back', false);

        $results = $this->actingAs($cgs)->get(route('workstation.cgs.operations', ['q' => '920']));
        $results->assertOk();
        $results->assertSee('page-header-back', false);
        $results->assertSee(route('workstation.cgs.operations'), false);
    }

    public function test_cgs_block_room_and_seat_details_pages_all_have_back_and_home(): void
    {
        $room = $this->room('male', 'N2-02-15');
        $seat = $this->seat($room, '921');
        $cgs = $this->cgs();

        $blockResponse = $this->actingAs($cgs)->get(route('workstation.cgs.block', 'N2'));
        $blockResponse->assertOk();
        $blockResponse->assertSee('page-header-back', false);
        $blockResponse->assertSee(route('workstation.cgs.operations'), false);

        $roomResponse = $this->actingAs($cgs)->get(route('workstation.cgs.room', $room));
        $roomResponse->assertOk();
        $roomResponse->assertSee('page-header-back', false);
        $roomResponse->assertSee(route('workstation.cgs.block', 'N2'), false);
        // The old in-panel "← Block N2" breadcrumb is gone; the info line
        // (room code / block / gender badge) is kept.
        $this->assertSame(1, substr_count($roomResponse->getContent(), 'page-header-back'));

        $seatResponse = $this->actingAs($cgs)->get(route('workstation.cgs.seat', $seat));
        $seatResponse->assertOk();
        $seatResponse->assertSee('page-header-back', false);
        $seatResponse->assertSee(route('workstation.cgs.room', $room), false);

        // Home is the blue solid button, unchanged, on all three.
        foreach ([$blockResponse, $roomResponse, $seatResponse] as $response) {
            $response->assertSee('>Home<', false);
        }
    }

    public function test_search_and_filters_switch_to_the_results_grid_and_match_seats(): void
    {
        [$student, $request, $seat] = $this->occupiedSeat('findme@test.my', '205', 'male', 'Zarina Binti Ismail');
        $this->seat($this->room('male', 'N2-02-02'), '999');
        $cgs = $this->cgs();

        $response = $this->actingAs($cgs)->get(route('workstation.cgs.operations', ['q' => 'Zarina']));

        $response->assertOk();
        $response->assertSee('Seat 205');
        $response->assertDontSee('Seat 999');
    }

    public function test_search_matches_a_former_student_whose_key_is_unreturned(): void
    {
        [$student, $request, $seat] = $this->occupiedSeat('former@test.my', '301', 'male', 'Farah Aina');
        LockerKey::create([
            'workstation_request_id' => $request->id, 'student_id' => $student->id,
            'status' => LockerKey::STATUS_COLLECTED, 'requested_at' => now()->subDays(10), 'collected_at' => now()->subDays(9),
        ]);

        $cgs = $this->cgs();
        app(WorkstationAllocator::class)->releaseSeat($request, WorkstationHistory::ACTOR_CGS, $cgs, 'System error');

        $response = $this->actingAs($cgs)->get(route('workstation.cgs.operations', ['q' => 'Farah']));

        $response->assertOk();
        $response->assertSee('Seat 301');
        // PART 1: a Collected key's seat is now Awaiting Key Return, not
        // Available-with-a-"Key Return Pending"-badge.
        $this->assertSame(Workstation::STATUS_AWAITING_KEY_RETURN, $seat->fresh()->status);
        $response->assertSee('Awaiting Key Return');
    }

    public function test_stat_bar_filter_for_maintenance_shows_only_disabled_seats(): void
    {
        $room = $this->room('male', 'N2-02-03');
        $this->seat($room, '401', Workstation::STATUS_DISABLED);
        $this->seat($room, '402', Workstation::STATUS_AVAILABLE);
        $cgs = $this->cgs();

        $response = $this->actingAs($cgs)->get(route('workstation.cgs.operations', ['seat_status' => 'disabled']));

        $response->assertOk();
        $response->assertSee('Seat 401');
        $response->assertDontSee('Seat 402');
    }

    // -------------------------------------------------------------
    // Block / Room pages
    // -------------------------------------------------------------

    public function test_the_block_page_shows_room_cards_with_counts(): void
    {
        $room = $this->room('male', 'N2-02-04');
        $this->seat($room, '501', Workstation::STATUS_AVAILABLE);
        $this->seat($room, '502', Workstation::STATUS_OCCUPIED);
        $this->seat($room, '503', Workstation::STATUS_DISABLED);
        $cgs = $this->cgs();

        $response = $this->actingAs($cgs)->get(route('workstation.cgs.block', 'N2'));

        $response->assertOk();
        $response->assertSee('Available: 1', false);
        $response->assertSee('Occupied: 1', false);
        $response->assertSee('Maintenance: 1', false);
    }

    public function test_the_room_page_lists_seats_in_ascending_numeric_order(): void
    {
        $room = $this->room('male', 'N2-02-05');
        $this->seat($room, '20');
        $this->seat($room, '3');
        $this->seat($room, '100');
        $cgs = $this->cgs();

        $response = $this->actingAs($cgs)->get(route('workstation.cgs.room', $room));

        $response->assertOk();
        $body = $response->getContent();
        $this->assertTrue(strpos($body, 'Seat 3') < strpos($body, 'Seat 20'));
        $this->assertTrue(strpos($body, 'Seat 20') < strpos($body, 'Seat 100'));
    }

    // -------------------------------------------------------------
    // Seat details
    // -------------------------------------------------------------

    public function test_seat_details_shows_no_student_assigned_for_an_available_seat(): void
    {
        $seat = $this->seat($this->room('male', 'N2-02-06'), '601');
        $cgs = $this->cgs();

        $response = $this->actingAs($cgs)->get(route('workstation.cgs.seat', $seat));

        $response->assertOk();
        $response->assertSee('No student assigned.');
    }

    public function test_seat_details_shows_student_info_for_an_occupied_seat(): void
    {
        [$student, $request, $seat] = $this->occupiedSeat('details@test.my', '701', 'female', 'Nur Hidayah');

        $cgs = $this->cgs();
        $response = $this->actingAs($cgs)->get(route('workstation.cgs.seat', $seat));

        $response->assertOk();
        $response->assertSee('Nur Hidayah');
        $response->assertSee($student->matric_no);
        $response->assertSee($student->email);
    }

    // -------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------

    public function test_reassign_action_moves_the_student_and_writes_history(): void
    {
        [$student, $request, $oldSeat] = $this->occupiedSeat('reassign@test.my', '801');
        $newSeat = $this->seat($this->room('male', 'N2-02-07'), '802');
        $cgs = $this->cgs();

        $response = $this->actingAs($cgs)->post(route('workstation.cgs.seat.reassign', $oldSeat), [
            'new_workstation_id' => $newSeat->id,
            'reason' => 'Allocation error',
        ]);

        $response->assertRedirect(route('workstation.cgs.seat', $newSeat));
        $this->assertSame(Workstation::STATUS_AVAILABLE, $oldSeat->fresh()->status);
        $this->assertSame(Workstation::STATUS_OCCUPIED, $newSeat->fresh()->status);
        $this->assertDatabaseHas('workstation_histories', [
            'seat_number' => $oldSeat->seat_code, 'action' => WorkstationHistory::ACTION_SEAT_REASSIGNED,
        ]);
        $this->assertDatabaseHas('workstation_histories', [
            'seat_number' => $newSeat->seat_code, 'action' => WorkstationHistory::ACTION_SEAT_REASSIGNED,
        ]);
    }

    public function test_release_action_requires_the_seat_number_typed_correctly(): void
    {
        [$student, $request, $seat] = $this->occupiedSeat('release1@test.my', '901');
        $cgs = $this->cgs();

        $response = $this->actingAs($cgs)->post(route('workstation.cgs.seat.release', $seat), [
            'reason' => 'System error', 'seat_confirmation' => 'wrong-number',
        ]);

        $response->assertRedirect(route('workstation.cgs.seat', $seat));
        $response->assertSessionHas('error');
        $this->assertSame(Workstation::STATUS_OCCUPIED, $seat->fresh()->status);
    }

    public function test_release_action_releases_and_notifies(): void
    {
        [$student, $request, $seat] = $this->occupiedSeat('release2@test.my', '902');
        $cgs = $this->cgs();

        $response = $this->actingAs($cgs)->post(route('workstation.cgs.seat.release', $seat), [
            'reason' => 'System error', 'seat_confirmation' => $seat->seat_code,
        ]);

        $response->assertRedirect(route('workstation.cgs.seat', $seat));
        $this->assertSame(Workstation::STATUS_AVAILABLE, $seat->fresh()->status);
        $this->assertSame(WorkstationRequest::STATUS_RELEASED, $request->fresh()->status);
    }

    public function test_set_maintenance_from_available_and_restore(): void
    {
        $seat = $this->seat($this->room('male', 'N2-02-08'), '903');
        $cgs = $this->cgs();

        $this->actingAs($cgs)->post(route('workstation.cgs.seat.maintenance', $seat), ['reason' => 'Repair needed'])
            ->assertRedirect(route('workstation.cgs.seat', $seat));
        $this->assertSame(Workstation::STATUS_DISABLED, $seat->fresh()->status);

        $this->actingAs($cgs)->post(route('workstation.cgs.seat.restore', $seat))
            ->assertRedirect(route('workstation.cgs.seat', $seat));
        $this->assertSame(Workstation::STATUS_AVAILABLE, $seat->fresh()->status);
    }

    public function test_set_maintenance_rejects_an_occupied_seat_with_a_banner(): void
    {
        [$student, $request, $seat] = $this->occupiedSeat('maint@test.my', '904');
        $cgs = $this->cgs();

        $response = $this->actingAs($cgs)->post(route('workstation.cgs.seat.maintenance', $seat), ['reason' => 'Repair needed']);

        $response->assertRedirect(route('workstation.cgs.seat', $seat));
        $response->assertSessionHas('error', 'Reassign or release the student first.');
    }

    public function test_assign_student_action(): void
    {
        $seat = $this->seat($this->room('male', 'N2-02-09'), '905');
        $student = $this->eligibleStudent('2200ass1', 'assign1@test.my');
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        $cgs = $this->cgs();

        $response = $this->actingAs($cgs)->post(route('workstation.cgs.seat.assign', $seat), [
            'student_id' => $student->id, 'reason' => 'System error',
        ]);

        $response->assertRedirect(route('workstation.cgs.seat', $seat));
        $this->assertSame(Workstation::STATUS_OCCUPIED, $seat->fresh()->status);
        $this->assertDatabaseHas('workstation_requests', [
            'workstation_id' => $seat->id, 'student_id' => $student->id, 'status' => WorkstationRequest::STATUS_CONFIRMED,
        ]);
    }

    public function test_search_students_endpoint_returns_only_gender_matched_seatless_students(): void
    {
        $seat = $this->seat($this->room('female', 'N2-02-10'), '906');

        $matching = $this->eligibleStudent('2200sea1', 'seatless-f@test.my', ['name' => 'Aina Zafirah']);
        StudentGender::create(['student_id' => $matching->id, 'gender' => 'female']);

        $wrongGender = $this->student('2200sea2', 'seatless-m@test.my', ['name' => 'Amir Hakim']);
        StudentGender::create(['student_id' => $wrongGender->id, 'gender' => 'male']);

        [$alreadySeated] = $this->occupiedSeat('already-seated@test.my', '907', 'female', 'Already Seated');

        $cgs = $this->cgs();
        $response = $this->actingAs($cgs)->getJson(route('workstation.cgs.seat.students.search', $seat));

        $response->assertOk();
        $names = collect($response->json())->pluck('name');
        $this->assertTrue($names->contains('Aina Zafirah'));
        $this->assertFalse($names->contains('Amir Hakim'));
        $this->assertFalse($names->contains('Already Seated'));
    }

    public function test_mark_key_collected_and_returned_via_seat_details_routes(): void
    {
        [$student, $request, $seat] = $this->occupiedSeat('key1@test.my', '908');
        LockerKey::create([
            'workstation_request_id' => $request->id, 'student_id' => $student->id,
            'status' => LockerKey::STATUS_REQUESTED, 'requested_at' => now(),
        ]);
        $cgs = $this->cgs();

        $this->actingAs($cgs)->post(route('workstation.cgs.seat.key-collected', $seat))
            ->assertRedirect(route('workstation.cgs.seat', $seat));
        $this->assertSame(LockerKey::STATUS_COLLECTED, LockerKey::first()->status);

        app(WorkstationAllocator::class)->releaseSeat($request->fresh(), WorkstationHistory::ACTOR_CGS, $cgs, 'System error');
        $this->assertSame(LockerKey::STATUS_RETURN_PENDING, LockerKey::first()->status);

        $this->actingAs($cgs)->post(route('workstation.cgs.seat.key-returned', $seat))
            ->assertRedirect(route('workstation.cgs.seat', $seat));
        $this->assertSame(LockerKey::STATUS_RETURNED, LockerKey::first()->status);
    }

    // -------------------------------------------------------------
    // Export
    // -------------------------------------------------------------

    public function test_export_results_and_export_history_download_an_xlsx_file(): void
    {
        $this->seat($this->room('male', 'N2-02-11'), '909');
        $cgs = $this->cgs();

        $seatsResponse = $this->actingAs($cgs)->get(route('workstation.cgs.export.seats'));
        $seatsResponse->assertOk();
        $this->assertStringContainsString('spreadsheet', $seatsResponse->headers->get('Content-Type'));

        $historyResponse = $this->actingAs($cgs)->get(route('workstation.cgs.export.history'));
        $historyResponse->assertOk();
        $this->assertStringContainsString('spreadsheet', $historyResponse->headers->get('Content-Type'));
    }

    // -------------------------------------------------------------
    // Old actions, now delegating
    // -------------------------------------------------------------

    public function test_old_force_assign_route_requires_a_listed_reason(): void
    {
        $seat = $this->seat($this->room('male', 'N2-02-12'), '910');
        $student = $this->student('2200old1', 'old-assign@test.my');
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        $cgs = $this->cgs();

        $this->actingAs($cgs)->post(route('workstation.cgs.force-assign', $seat), [
            'student_id' => $student->id, 'reason' => 'not a real reason',
        ])->assertSessionHasErrors('reason');

        $this->actingAs($cgs)->post(route('workstation.cgs.force-assign', $seat), [
            'student_id' => $student->id, 'reason' => 'System error',
        ])->assertRedirect(route('workstation.cgs.operations'));

        $this->assertSame(Workstation::STATUS_OCCUPIED, $seat->fresh()->status);
        $this->assertDatabaseHas('workstation_histories', [
            'seat_number' => $seat->seat_code, 'action' => WorkstationHistory::ACTION_STUDENT_ASSIGNED,
        ]);
    }

    public function test_old_force_release_route_delegates_to_release_seat_when_a_request_exists(): void
    {
        [$student, $request, $seat] = $this->occupiedSeat('old-release@test.my', '911');
        $cgs = $this->cgs();

        $this->actingAs($cgs)->post(route('workstation.cgs.force-release', $seat), ['reason' => 'System error'])
            ->assertRedirect(route('workstation.cgs.operations'));

        $this->assertSame(Workstation::STATUS_AVAILABLE, $seat->fresh()->status);
        $this->assertSame(WorkstationRequest::STATUS_RELEASED, $request->fresh()->status);
    }

    public function test_old_force_release_route_falls_back_for_a_seat_with_no_tracked_request(): void
    {
        $seat = $this->seat($this->room('male', 'N2-02-13'), '912', Workstation::STATUS_OCCUPIED);
        $cgs = $this->cgs();

        $this->actingAs($cgs)->post(route('workstation.cgs.force-release', $seat), ['reason' => 'System error'])
            ->assertRedirect(route('workstation.cgs.operations'));

        $this->assertSame(Workstation::STATUS_AVAILABLE, $seat->fresh()->status);
        $this->assertDatabaseHas('workstation_histories', [
            'seat_number' => $seat->seat_code, 'action' => WorkstationHistory::ACTION_SEAT_RELEASED,
        ]);
    }

    public function test_old_mark_locker_collected_route_still_works(): void
    {
        [$student, $request, $seat] = $this->occupiedSeat('old-key1@test.my', '913');
        $lockerKey = LockerKey::create([
            'workstation_request_id' => $request->id, 'student_id' => $student->id,
            'status' => LockerKey::STATUS_REQUESTED, 'requested_at' => now(),
        ]);
        $cgs = $this->cgs();

        $this->actingAs($cgs)->post(route('workstation.cgs.locker-key.collected', $lockerKey))
            ->assertRedirect(route('workstation.cgs.operations'));

        $this->assertSame(LockerKey::STATUS_COLLECTED, $lockerKey->fresh()->status);
    }

    public function test_old_mark_locker_returned_route_only_works_from_return_pending(): void
    {
        [$student, $request, $seat] = $this->occupiedSeat('old-key2@test.my', '914');
        $lockerKey = LockerKey::create([
            'workstation_request_id' => $request->id, 'student_id' => $student->id,
            'status' => LockerKey::STATUS_COLLECTED, 'requested_at' => now(), 'collected_at' => now(),
        ]);
        $cgs = $this->cgs();

        $this->actingAs($cgs)->post(route('workstation.cgs.locker-key.returned', $lockerKey))
            ->assertSessionHas('error');
        $this->assertSame(LockerKey::STATUS_COLLECTED, $lockerKey->fresh()->status);

        app(WorkstationAllocator::class)->releaseSeat($request->fresh(), WorkstationHistory::ACTOR_CGS, $cgs, 'System error');
        $this->assertSame(LockerKey::STATUS_RETURN_PENDING, $lockerKey->fresh()->status);

        $this->actingAs($cgs)->post(route('workstation.cgs.locker-key.returned', $lockerKey))
            ->assertRedirect(route('workstation.cgs.operations'));
        $this->assertSame(LockerKey::STATUS_RETURNED, $lockerKey->fresh()->status);
    }

    // -------------------------------------------------------------
    // Demo students: every CGS action works on one exactly like a real
    // account, per Part 2's explicit test list.
    // -------------------------------------------------------------

    public function test_seat_details_shows_a_demo_students_full_identity(): void
    {
        [$profile, $request, $seat] = $this->demoOccupiedSeat('950', 'female', 'Tan Mei Xuan');
        $cgs = $this->cgs();

        $response = $this->actingAs($cgs)->get(route('workstation.cgs.seat', $seat));

        $response->assertOk();
        $response->assertSee('Tan Mei Xuan');
        $response->assertSee($profile->matric_no);
        $response->assertSee($profile->email);
        $response->assertDontSee('No student record');
    }

    public function test_reassign_works_on_a_demo_students_seat(): void
    {
        [$profile, $request, $oldSeat] = $this->demoOccupiedSeat('951');
        $newSeat = $this->seat($this->room('male', 'N2-02-16'), '952');
        $cgs = $this->cgs();

        $response = $this->actingAs($cgs)->post(route('workstation.cgs.seat.reassign', $oldSeat), [
            'new_workstation_id' => $newSeat->id,
            'reason' => 'Allocation error',
        ]);

        $response->assertRedirect(route('workstation.cgs.seat', $newSeat));
        $this->assertSame(Workstation::STATUS_AVAILABLE, $oldSeat->fresh()->status);
        $this->assertSame(Workstation::STATUS_OCCUPIED, $newSeat->fresh()->status);
        $newRequest = WorkstationRequest::where('workstation_id', $newSeat->id)->where('status', 'confirmed')->first();
        $this->assertSame($profile->id, $newRequest->demo_profile_id);
        $this->assertNull($newRequest->student_id);
    }

    public function test_release_works_on_a_demo_students_seat(): void
    {
        [$profile, $request, $seat] = $this->demoOccupiedSeat('953');
        $cgs = $this->cgs();

        $response = $this->actingAs($cgs)->post(route('workstation.cgs.seat.release', $seat), [
            'reason' => 'System error', 'seat_confirmation' => $seat->seat_code,
        ]);

        $response->assertRedirect(route('workstation.cgs.seat', $seat));
        $this->assertSame(Workstation::STATUS_AVAILABLE, $seat->fresh()->status);
        $this->assertSame(WorkstationRequest::STATUS_RELEASED, $request->fresh()->status);
        $this->assertDatabaseHas('workstation_histories', [
            'seat_number' => '953', 'action' => WorkstationHistory::ACTION_SEAT_RELEASED,
            'student_name' => $profile->name, 'student_matric_no' => $profile->matric_no,
        ]);
    }

    public function test_mark_as_collected_and_returned_work_on_a_demo_students_seat(): void
    {
        [$profile, $request, $seat] = $this->demoOccupiedSeat('954');
        LockerKey::create([
            'workstation_request_id' => $request->id, 'demo_profile_id' => $profile->id,
            'status' => LockerKey::STATUS_REQUESTED, 'requested_at' => now(),
        ]);
        $cgs = $this->cgs();

        $this->actingAs($cgs)->post(route('workstation.cgs.seat.key-collected', $seat))
            ->assertRedirect(route('workstation.cgs.seat', $seat));
        $this->assertSame(LockerKey::STATUS_COLLECTED, LockerKey::first()->status);

        app(WorkstationAllocator::class)->releaseSeat($request->fresh(), WorkstationHistory::ACTOR_CGS, $cgs, 'System error');
        $this->assertSame(LockerKey::STATUS_RETURN_PENDING, LockerKey::first()->status);

        $this->actingAs($cgs)->post(route('workstation.cgs.seat.key-returned', $seat))
            ->assertRedirect(route('workstation.cgs.seat', $seat));
        $this->assertSame(LockerKey::STATUS_RETURNED, LockerKey::first()->status);
    }

    // -------------------------------------------------------------
    // Smart search suggestions
    // -------------------------------------------------------------

    public function test_a_student_gets_403_on_the_suggestions_endpoint(): void
    {
        [$student] = $this->occupiedSeat('sugstudent@test.my', '960');

        $this->actingAs($student)->getJson(route('workstation.cgs.suggestions', ['q' => 'ah']))->assertForbidden();
    }

    public function test_fewer_than_two_characters_returns_no_suggestions(): void
    {
        [$student] = $this->occupiedSeat('sug1@test.my', '961', 'male', 'Ahmad Danial');
        $cgs = $this->cgs();

        $this->actingAs($cgs)->getJson(route('workstation.cgs.suggestions', ['q' => 'a']))
            ->assertOk()
            ->assertJson([]);
    }

    public function test_prefix_match_ranks_above_contains_match(): void
    {
        [$ahmad] = $this->occupiedSeat('sug2@test.my', '962', 'male', 'Ahmad Danial');
        [$siraj] = $this->occupiedSeat('sug3@test.my', '963', 'male', 'Siraj Ahmad');
        $cgs = $this->cgs();

        $response = $this->actingAs($cgs)->getJson(route('workstation.cgs.suggestions', ['q' => 'ahm']));
        $response->assertOk();

        $names = collect($response->json())->pluck('text');
        $this->assertSame('Ahmad Danial', $names->first());
    }

    public function test_a_typo_still_finds_the_right_student(): void
    {
        [$student] = $this->occupiedSeat('sug4@test.my', '964', 'male', 'Ahmad Danial');
        $cgs = $this->cgs();

        $response = $this->actingAs($cgs)->getJson(route('workstation.cgs.suggestions', ['q' => 'ahmed']));

        $response->assertOk();
        $this->assertTrue(collect($response->json())->pluck('text')->contains('Ahmad Danial'));
    }

    public function test_suggestions_include_a_student_id_a_seat_and_a_room_and_a_demo_student(): void
    {
        [$student, $request, $seat] = $this->occupiedSeat('sug5@test.my', '9650001', 'male', 'Zahra Testperson');
        $demoRoom = $this->room('male', 'N2-SUG-01');
        $this->seat($demoRoom, '9651111');
        [$demoProfile] = $this->demoOccupiedSeat('9652222', 'male', 'Zahra Demo Person');
        $cgs = $this->cgs();

        // Student ID prefix.
        $byId = $this->actingAs($cgs)->getJson(route('workstation.cgs.suggestions', ['q' => $student->matric_no]));
        $byId->assertOk();
        $this->assertTrue(collect($byId->json())->pluck('text')->contains('Zahra Testperson'));

        // Seat number.
        $bySeat = $this->actingAs($cgs)->getJson(route('workstation.cgs.suggestions', ['q' => '9651111']));
        $bySeat->assertOk();
        $this->assertTrue(collect($bySeat->json())->pluck('type')->contains('seat'));

        // Room code.
        $byRoom = $this->actingAs($cgs)->getJson(route('workstation.cgs.suggestions', ['q' => 'N2-SUG-01']));
        $byRoom->assertOk();
        $this->assertTrue(collect($byRoom->json())->pluck('type')->contains('room'));

        // Demo student by name.
        $byDemo = $this->actingAs($cgs)->getJson(route('workstation.cgs.suggestions', ['q' => 'Zahra Demo']));
        $byDemo->assertOk();
        $this->assertTrue(collect($byDemo->json())->pluck('text')->contains('Zahra Demo Person'));
    }

    public function test_suggestions_are_capped_at_six_and_include_label_and_highlight(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->occupiedSeat("many{$i}@test.my", (string) (970 + $i), 'male', "Capname Person{$i}");
        }
        $cgs = $this->cgs();

        $response = $this->actingAs($cgs)->getJson(route('workstation.cgs.suggestions', ['q' => 'Capname']));
        $response->assertOk();

        $results = $response->json();
        $this->assertLessThanOrEqual(6, count($results));
        $this->assertArrayHasKey('label', $results[0]);
        $this->assertArrayHasKey('highlight', $results[0]);
        $this->assertArrayHasKey('fill', $results[0]);
    }

    public function test_a_student_suggestion_shows_their_seat_or_no_seat(): void
    {
        [$student] = $this->occupiedSeat('sug6@test.my', '980', 'male', 'Harun Seated');
        $cgs = $this->cgs();

        $response = $this->actingAs($cgs)->getJson(route('workstation.cgs.suggestions', ['q' => 'Harun Seated']));
        $response->assertOk();

        $row = collect($response->json())->firstWhere('text', 'Harun Seated');
        $this->assertStringContainsString('Seat 980', $row['label']);
    }

    /**
     * Notification::fake() (this class's own setUp()) intercepts before
     * via()/toMail() ever run, which is exactly how the real bug here --
     * both notification classes read $notifiable->name, undefined on the
     * AnonymousNotifiable a demo student's StudentIdentity::notify()
     * actually hands them -- passed the whole automated suite and only
     * surfaced live, as a failed queue job. This calls toMail() for real,
     * against a real AnonymousNotifiable, so that regression can't recur
     * silently again.
     */
    public function test_workstation_status_notice_renders_for_a_demo_recipient(): void
    {
        $profile = DemoStudentProfile::create([
            'matric_no' => '21019001', 'name' => 'Demo Render Test', 'email' => 'demo_render@utp.edu.my',
            'gender' => 'male', 'status' => DemoStudentProfile::STATUS_ACTIVE,
        ]);
        $seat = $this->seat($this->room('male', 'N2-RENDER-01'), '991', Workstation::STATUS_AVAILABLE);

        $notifiable = new AnonymousNotifiable;
        $notifiable->route('mail', $profile->email);

        $notice = new WorkstationStatusNotice(
            $seat,
            WorkstationStatusNotice::RELEASED,
            ['reason' => 'System error', 'key_paragraph' => null, 'return_due_date' => null],
            $profile->name,
        );

        $mail = $notice->toMail($notifiable);

        $this->assertInstanceOf(MailMessage::class, $mail);
        $this->assertStringContainsString($profile->name, $mail->greeting);
        $this->assertSame(['mail'], $notice->via($notifiable));
    }

    public function test_locker_key_notice_renders_for_a_demo_recipient(): void
    {
        [$profile, $request, $seat] = $this->demoOccupiedSeat('992');
        $lockerKey = LockerKey::create([
            'workstation_request_id' => $request->id, 'demo_profile_id' => $profile->id,
            'status' => LockerKey::STATUS_REQUESTED, 'requested_at' => now(),
        ]);

        $notifiable = new AnonymousNotifiable;
        $notifiable->route('mail', $profile->email);

        $notice = new LockerKeyNotice($lockerKey, LockerKeyNotice::REQUESTED);
        $mail = $notice->toMail($notifiable);

        $this->assertInstanceOf(MailMessage::class, $mail);
        $this->assertStringContainsString($profile->name, $mail->greeting);
        $this->assertSame(['mail'], $notice->via($notifiable));
    }

    /**
     * Final polish PART 4.5 (found live at 390px): a single long badge
     * ("Awaiting Key Return") inside a seat card pushed the whole room
     * page 61px past the viewport -- .cgs-seat-text had no max-width, so
     * the wrapped card's text/badge column could still grow past the
     * card's own available width to fit the badge's un-wrapped text.
     */
    public function test_the_room_page_constrains_seat_card_text_and_badges_to_their_own_width(): void
    {
        $room = $this->room();
        $this->seat($room, '921');
        $cgs = $this->cgs();

        $response = $this->actingAs($cgs)->get(route('workstation.cgs.room', $room));
        $response->assertOk();
        $this->assertStringContainsString('.cgs-seat-text { flex: 1; min-width: 0; max-width: 100%; }', $response->getContent());
        $this->assertStringContainsString('.cgs-seat-badges .status-badge { white-space: normal; max-width: 100%; }', $response->getContent());
    }
}
