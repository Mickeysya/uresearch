<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\StudentGender;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationHistory;
use App\Modules\Chloe\Models\WorkstationLocation;
use App\Modules\Chloe\Models\WorkstationPendingSelection;
use App\Modules\Chloe\Models\WorkstationRequest;
use App\Modules\Chloe\Models\WorkstationTerminationNotice;
use App\Modules\Chloe\Notifications\WorkstationStatusNotice;
use App\Modules\Chloe\Services\WorkstationAllocator;
use App\Modules\Chloe\Support\WorkstationEligibility;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Support\MakesEligibleWorkstationStudents;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The "Postgraduate Workstation - Term of Use" alignment task: PART 1
 * eligibility, PART 2 loan period, PART 3 termination flow (CGS side),
 * and PART 4 Term of Use acceptance. The allocator/daily-job layer for
 * PART 3 is covered by WorkstationManagementTest; this file covers the
 * controller/routes/views layer plus PART 1/2/4 end to end.
 */
class WorkstationTermOfUseTest extends TestCase
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

    protected function seat(WorkstationLocation $room, string $code): Workstation
    {
        return Workstation::create(['workstation_location_id' => $room->id, 'seat_code' => $code, 'position' => 1]);
    }

    protected function withConfirmedSeat(string $email, string $seatCode, string $gender = 'male'): array
    {
        $student = $this->eligibleStudent('2200'.substr(md5($email), 0, 4), $email);
        StudentGender::create(['student_id' => $student->id, 'gender' => $gender]);
        $room = $this->room($gender);
        $seat = $this->seat($room, $seatCode);
        $seat->update(['status' => Workstation::STATUS_OCCUPIED]);
        $request = WorkstationRequest::create([
            'workstation_id' => $seat->id, 'student_id' => $student->id,
            'status' => WorkstationRequest::STATUS_CONFIRMED, 'requested_at' => now(),
        ]);

        return [$student, $request, $seat];
    }

    protected function notificationProperty(object $notification, string $property): mixed
    {
        $ref = new \ReflectionProperty($notification, $property);
        $ref->setAccessible(true);

        return $ref->getValue($notification);
    }

    /** Part-Time programme, non-Active status, expired candidacy -- fails all three WorkstationEligibility conditions at once. */
    protected function ineligibleStudent(string $matric, string $email, string $name): User
    {
        $student = $this->student($matric, $email, [
            'name' => $name,
            'programme' => 'MSc Information Technology (Part-Time)',
        ]);

        StudyCandidacy::create([
            'student_id' => $student->id,
            'programme_start_date' => now()->subYears(3),
            'candidacy_expiry_date' => now()->subDay(),
            'status' => StudyCandidacy::STATUS_DISMISSED,
        ]);

        return $student;
    }

    // -------------------------------------------------------------
    // PART 1: eligibility
    // -------------------------------------------------------------

    public function test_a_student_with_no_candidacy_record_cannot_browse_or_select(): void
    {
        $student = $this->student();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        $room = $this->room('male');
        $seat = $this->seat($room, '1');

        $this->actingAs($student)
            ->get(route('workstation.rooms', 'N2'))
            ->assertRedirect(route('workstation.select'));

        $this->actingAs($student)->get(route('workstation.select'))
            ->assertSee(WorkstationEligibility::INELIGIBLE_MESSAGE);

        $response = $this->actingAs($student)->post(route('workstation.register', $seat));
        $response->assertSessionHas('error', WorkstationEligibility::INELIGIBLE_MESSAGE);
        $this->assertSame(Workstation::STATUS_AVAILABLE, $seat->fresh()->status);
    }

    public function test_a_part_time_student_is_ineligible(): void
    {
        $student = $this->student('22005001', 'parttime@test.my', ['programme' => 'MSc Information Technology (Part-Time)']);
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        StudyCandidacy::create([
            'student_id' => $student->id, 'programme_start_date' => now()->subYear(),
            'candidacy_expiry_date' => now()->addYear(), 'status' => StudyCandidacy::STATUS_ACTIVE,
        ]);
        $this->room('male');

        $this->actingAs($student)
            ->get(route('workstation.rooms', 'N2'))
            ->assertRedirect(route('workstation.select'))
            ->assertSessionHas('error', WorkstationEligibility::INELIGIBLE_MESSAGE);
    }

    public function test_a_dismissed_student_is_ineligible(): void
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        StudyCandidacy::where('student_id', $student->id)->update(['status' => StudyCandidacy::STATUS_DISMISSED]);
        $this->room('male');

        $this->actingAs($student)
            ->get(route('workstation.rooms', 'N2'))
            ->assertRedirect(route('workstation.select'))
            ->assertSessionHas('error', WorkstationEligibility::INELIGIBLE_MESSAGE);
    }

    public function test_an_eligible_student_can_browse_and_select(): void
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        $room = $this->room('male');
        $seat = $this->seat($room, '1');

        $this->actingAs($student)->get(route('workstation.rooms', 'N2'))->assertOk();

        $response = $this->actingAs($student)->post(route('workstation.register', $seat));
        $response->assertSessionHas('status');
        $this->assertSame(Workstation::STATUS_PENDING, $seat->fresh()->status);
    }

    /**
     * Server-side re-check, not just a filtered dropdown -- a direct POST
     * (a stale page, a replayed request, a crafted one) for a student who
     * fails all three eligibility conditions at once must still be
     * rejected by the student select handler itself.
     */
    public function test_student_select_post_rejects_a_direct_post_for_an_ineligible_student(): void
    {
        $student = $this->ineligibleStudent('22005101', 'directpost1@test.my', 'Direct Post One');
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        $seat = $this->seat($this->room('male'), '30');

        $response = $this->actingAs($student)->post(route('workstation.register', $seat));

        $response->assertSessionHas('error', WorkstationEligibility::INELIGIBLE_MESSAGE);
        $this->assertSame(Workstation::STATUS_AVAILABLE, $seat->fresh()->status);
    }

    /** Same re-check on the CGS Assign POST handler, not only in the search endpoint's candidate list. */
    public function test_cgs_assign_post_rejects_a_direct_post_for_an_ineligible_student(): void
    {
        $student = $this->ineligibleStudent('22005102', 'directpost2@test.my', 'Direct Post Two');
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        $seat = $this->seat($this->room('male'), '31');
        $cgs = $this->cgs();

        $response = $this->actingAs($cgs)->post(route('workstation.cgs.seat.assign', $seat), [
            'student_id' => $student->id, 'reason' => 'System error',
        ]);

        $response->assertSessionHas('error', WorkstationEligibility::INELIGIBLE_MESSAGE);
        $this->assertSame(Workstation::STATUS_AVAILABLE, $seat->fresh()->status);
    }

    /** Same re-check on the CGS Reassign POST handler, for the seat's existing (now-ineligible) occupant. */
    public function test_cgs_reassign_post_rejects_a_direct_post_when_the_occupant_is_ineligible(): void
    {
        $room = $this->room('male');
        $oldSeat = $this->seat($room, '32');
        $newSeat = $this->seat($room, '33');

        $student = $this->ineligibleStudent('22005103', 'directpost3@test.my', 'Direct Post Three');
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        $oldSeat->update(['status' => Workstation::STATUS_OCCUPIED]);
        WorkstationRequest::create([
            'workstation_id' => $oldSeat->id, 'student_id' => $student->id,
            'status' => WorkstationRequest::STATUS_CONFIRMED, 'requested_at' => now(),
        ]);
        $cgs = $this->cgs();

        $response = $this->actingAs($cgs)->post(route('workstation.cgs.seat.reassign', $oldSeat), [
            'new_workstation_id' => $newSeat->id, 'reason' => 'Allocation error',
        ]);

        $response->assertSessionHas('error', WorkstationEligibility::INELIGIBLE_MESSAGE);
        $this->assertSame(Workstation::STATUS_OCCUPIED, $oldSeat->fresh()->status);
        $this->assertSame(Workstation::STATUS_AVAILABLE, $newSeat->fresh()->status);
    }

    // -------------------------------------------------------------
    // PART 2: loan period
    // -------------------------------------------------------------

    public function test_loan_valid_until_is_shown_on_the_student_page(): void
    {
        [$student, , $seat] = $this->withConfirmedSeat('loan@test.my', '10');
        $expiry = StudyCandidacy::where('student_id', $student->id)->value('candidacy_expiry_date');

        $this->actingAs($student)->get(route('workstation.select'))
            ->assertSee('Loan valid until '.Carbon::parse($expiry)->format('j M Y'));
    }

    // -------------------------------------------------------------
    // PART 3: CGS two-group Release, Release now, Withdraw Termination
    // -------------------------------------------------------------

    public function test_cgs_release_with_a_termination_reason_starts_a_notice_not_an_immediate_release(): void
    {
        [$student, , $seat] = $this->withConfirmedSeat('terminate@test.my', '20');
        $cgs = $this->cgs();

        $response = $this->actingAs($cgs)->post(route('workstation.cgs.seat.release', $seat), [
            'reason' => WorkstationAllocator::REASON_TERMINATION_WITHDRAWN,
            'seat_confirmation' => $seat->seat_code,
        ]);

        $response->assertRedirect(route('workstation.cgs.seat', $seat));
        $this->assertSame(Workstation::STATUS_OCCUPIED, $seat->fresh()->status);

        $notice = WorkstationTerminationNotice::first();
        $this->assertNotNull($notice);
        $this->assertSame(WorkstationAllocator::REASON_TERMINATION_WITHDRAWN, $notice->reason);
        $this->assertSame(WorkstationTerminationNotice::STATUS_ACTIVE, $notice->status);

        Notification::assertSentTo($student, WorkstationStatusNotice::class, function ($n) {
            return $this->notificationProperty($n, 'kind') === WorkstationStatusNotice::TERMINATION_NOTICE;
        });

        $this->assertDatabaseHas('workstation_histories', [
            'seat_number' => $seat->seat_code, 'action' => WorkstationHistory::ACTION_TERMINATION_NOTICE_ISSUED,
        ]);
    }

    public function test_release_now_releases_a_seat_already_mid_notice(): void
    {
        [$student, $request, $seat] = $this->withConfirmedSeat('releasenow@test.my', '21');
        $cgs = $this->cgs();

        app(WorkstationAllocator::class)->startTermination($request, WorkstationHistory::ACTOR_CGS, $cgs, WorkstationAllocator::REASON_TERMINATION_DEFERMENT);

        $response = $this->actingAs($cgs)->post(route('workstation.cgs.seat.release-now', $seat), [
            'reason' => 'Student has vacated',
            'seat_confirmation' => $seat->seat_code,
        ]);

        $response->assertRedirect(route('workstation.cgs.seat', $seat));
        $this->assertSame(Workstation::STATUS_AVAILABLE, $seat->fresh()->status);
        $this->assertSame(WorkstationRequest::STATUS_RELEASED, $request->fresh()->status);
        $this->assertSame(WorkstationTerminationNotice::STATUS_RELEASED, WorkstationTerminationNotice::first()->status);
        Notification::assertNotSentTo($student, WorkstationStatusNotice::class, function ($n) {
            return $this->notificationProperty($n, 'kind') === WorkstationStatusNotice::RELEASED;
        });
    }

    public function test_withdraw_termination_cancels_the_notice_and_the_seat_stays_occupied(): void
    {
        [$student, $request, $seat] = $this->withConfirmedSeat('withdraw@test.my', '22');
        $cgs = $this->cgs();

        app(WorkstationAllocator::class)->startTermination($request, WorkstationHistory::ACTOR_CGS, $cgs, WorkstationAllocator::REASON_TERMINATION_DEFERMENT);
        Notification::fake();

        $response = $this->actingAs($cgs)->post(route('workstation.cgs.seat.withdraw-termination', $seat), [
            'reason' => 'Issued by mistake',
        ]);

        $response->assertRedirect(route('workstation.cgs.seat', $seat));
        $this->assertSame(Workstation::STATUS_OCCUPIED, $seat->fresh()->status);
        $this->assertSame(WorkstationRequest::STATUS_CONFIRMED, $request->fresh()->status);
        $this->assertSame(WorkstationTerminationNotice::STATUS_WITHDRAWN, WorkstationTerminationNotice::first()->status);

        Notification::assertSentTo($student, WorkstationStatusNotice::class, function ($n) {
            return $this->notificationProperty($n, 'kind') === WorkstationStatusNotice::TERMINATION_WITHDRAWN;
        });
        $this->assertDatabaseHas('workstation_histories', [
            'seat_number' => $seat->seat_code, 'action' => WorkstationHistory::ACTION_TERMINATION_NOTICE_WITHDRAWN,
        ]);
    }

    public function test_seat_status_filter_finds_only_seats_with_an_active_notice(): void
    {
        [, $noticedRequest, $noticedSeat] = $this->withConfirmedSeat('noticedseat@test.my', '23');
        [, , $plainSeat] = $this->withConfirmedSeat('plainseat@test.my', '24');
        $cgs = $this->cgs();

        app(WorkstationAllocator::class)->startTermination($noticedRequest, WorkstationHistory::ACTOR_CGS, $cgs, WorkstationAllocator::REASON_TERMINATION_DEFERMENT);

        $response = $this->actingAs($cgs)->get(route('workstation.cgs.operations', ['seat_status' => 'termination_notice']));

        $response->assertOk()->assertSee('Seat '.$noticedSeat->seat_code)->assertDontSee('Seat '.$plainSeat->seat_code);
    }

    public function test_search_finds_a_seat_by_its_termination_reason(): void
    {
        [, $request, $seat] = $this->withConfirmedSeat('searchnotice@test.my', '25');
        $cgs = $this->cgs();

        app(WorkstationAllocator::class)->startTermination($request, WorkstationHistory::ACTOR_CGS, $cgs, WorkstationAllocator::REASON_TERMINATION_DISCIPLINARY);

        $response = $this->actingAs($cgs)->get(route('workstation.cgs.operations', ['q' => 'disciplinary']));

        $response->assertOk()->assertSee('Seat '.$seat->seat_code);
    }

    // -------------------------------------------------------------
    // PART 4: Term of Use acceptance
    // -------------------------------------------------------------

    public function test_the_term_of_use_page_is_reachable_without_logging_in(): void
    {
        $this->get(route('workstation.terms'))->assertOk()->assertSee('Postgraduate Workstation');
        $this->get(route('workstation.terms.pdf'))->assertOk();
    }

    public function test_confirming_a_selection_records_terms_acceptance(): void
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        $room = $this->room('male');
        $seat = $this->seat($room, '1');

        $this->actingAs($student)->post(route('workstation.register', $seat));

        $selection = WorkstationPendingSelection::first();
        $this->get(route('workstation.confirm', $selection->token))->assertOk();

        $request = WorkstationRequest::where('workstation_id', $seat->id)->first();
        $this->assertNotNull($request->terms_accepted_at);
        $this->assertSame('First Edition', $request->terms_edition);
        $this->assertDatabaseHas('workstation_histories', [
            'seat_number' => $seat->seat_code, 'action' => WorkstationHistory::ACTION_SEAT_CONFIRMED_TERMS,
        ]);
    }
}
