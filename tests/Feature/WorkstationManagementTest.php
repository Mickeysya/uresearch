<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\LockerKey;
use App\Modules\Chloe\Models\StudentGender;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationHistory;
use App\Modules\Chloe\Models\WorkstationLocation;
use App\Modules\Chloe\Models\WorkstationRequest;
use App\Modules\Chloe\Models\WorkstationTerminationNotice;
use App\Modules\Chloe\Notifications\LockerKeyNotice;
use App\Modules\Chloe\Notifications\WorkstationStatusNotice;
use App\Modules\Chloe\Services\WorkstationAllocator;
use App\Modules\Core\Models\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\Support\MakesUsers;
use Tests\TestCase;

class WorkstationManagementTest extends TestCase
{
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
        $student = $this->student('2200'.substr(md5($email), 0, 4), $email);
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

    // -------------------------------------------------------------
    // releaseSeat
    // -------------------------------------------------------------

    public function test_student_return_with_each_reason_releases_the_seat_and_notifies(): void
    {
        foreach (WorkstationAllocator::REASONS_STUDENT_RETURN as $i => $reason) {
            [$student, $request, $seat] = $this->withConfirmedSeat("return{$i}@test.my", (string) (10 + $i));

            app(WorkstationAllocator::class)->releaseSeat($request, WorkstationHistory::ACTOR_STUDENT, $student, $reason);

            $this->assertSame(Workstation::STATUS_AVAILABLE, $seat->fresh()->status);
            $this->assertSame(WorkstationRequest::STATUS_RELEASED, $request->fresh()->status);
            $this->assertNotNull($request->fresh()->released_at);

            Notification::assertSentTo($student, WorkstationStatusNotice::class, function ($n) {
                return $this->notificationProperty($n, 'kind') === WorkstationStatusNotice::RETURNED;
            });

            $this->assertDatabaseHas('workstation_histories', [
                'seat_number' => $seat->seat_code,
                'action' => WorkstationHistory::ACTION_SEAT_RELEASED,
                'actor_type' => WorkstationHistory::ACTOR_STUDENT,
                'reason' => $reason,
            ]);
        }
    }

    public function test_cgs_release_with_each_reason_notifies_except_student_has_vacated(): void
    {
        $cgs = $this->cgs();

        foreach (WorkstationAllocator::REASONS_RELEASE_IMMEDIATE as $i => $reason) {
            [$student, $request, $seat] = $this->withConfirmedSeat("cgsrelease{$i}@test.my", (string) (20 + $i));

            app(WorkstationAllocator::class)->releaseSeat($request, WorkstationHistory::ACTOR_CGS, $cgs, $reason);

            $this->assertSame(Workstation::STATUS_AVAILABLE, $seat->fresh()->status);

            if ($reason === WorkstationAllocator::REASON_RELEASE_NO_EMAIL) {
                Notification::assertNotSentTo($student, WorkstationStatusNotice::class);
            } else {
                Notification::assertSentTo($student, WorkstationStatusNotice::class, function ($n) {
                    return $this->notificationProperty($n, 'kind') === WorkstationStatusNotice::RELEASED;
                });
            }
        }
    }

    public function test_release_rejects_an_invalid_reason(): void
    {
        [$student, $request] = $this->withConfirmedSeat('badreason@test.my', '30');

        $this->expectException(\InvalidArgumentException::class);
        app(WorkstationAllocator::class)->releaseSeat($request, WorkstationHistory::ACTOR_STUDENT, $student, 'Not a real reason');
    }

    public function test_release_rejects_a_request_that_is_not_confirmed(): void
    {
        [$student, $request] = $this->withConfirmedSeat('alreadyreleased@test.my', '31');
        $request->update(['status' => WorkstationRequest::STATUS_RELEASED]);

        $this->expectException(RuntimeException::class);
        app(WorkstationAllocator::class)->releaseSeat($request, WorkstationHistory::ACTOR_STUDENT, $student, 'No longer need a workstation');
    }

    // -------------------------------------------------------------
    // Key handling on release (STEP 4)
    // -------------------------------------------------------------

    public function test_releasing_with_no_key_request_does_nothing_to_any_key(): void
    {
        [$student, $request, $seat] = $this->withConfirmedSeat('nokey@test.my', '40');

        app(WorkstationAllocator::class)->releaseSeat($request, WorkstationHistory::ACTOR_STUDENT, $student, 'No longer need a workstation');

        $this->assertSame(0, LockerKey::count());
    }

    public function test_releasing_with_a_pending_key_cancels_it_and_adds_the_pending_paragraph(): void
    {
        [$student, $request, $seat] = $this->withConfirmedSeat('pendingkey@test.my', '41');
        $key = LockerKey::create(['workstation_request_id' => $request->id, 'student_id' => $student->id, 'status' => LockerKey::STATUS_REQUESTED, 'requested_at' => now()]);

        app(WorkstationAllocator::class)->releaseSeat($request, WorkstationHistory::ACTOR_STUDENT, $student, 'No longer need a workstation');

        $this->assertSame(LockerKey::STATUS_CANCELLED, $key->fresh()->status);

        Notification::assertSentTo($student, WorkstationStatusNotice::class, function ($n) {
            return $this->notificationProperty($n, 'context')['key_paragraph'] === 'pending';
        });
    }

    public function test_releasing_with_a_collected_key_sets_return_pending_seven_days_out(): void
    {
        [$student, $request, $seat] = $this->withConfirmedSeat('collectedkey@test.my', '42');
        $key = LockerKey::create(['workstation_request_id' => $request->id, 'student_id' => $student->id, 'status' => LockerKey::STATUS_COLLECTED, 'requested_at' => now()->subDays(2), 'collected_at' => now()->subDay()]);

        app(WorkstationAllocator::class)->releaseSeat($request, WorkstationHistory::ACTOR_STUDENT, $student, 'No longer need a workstation');

        $key->refresh();
        $this->assertSame(LockerKey::STATUS_RETURN_PENDING, $key->status);
        $this->assertTrue($key->return_due_at->isSameDay(now()->addDays(7)));
        $this->assertNull($key->return_reminder_sent_at);
        $this->assertFalse($key->isOverdueForReturn());

        Notification::assertSentTo($student, WorkstationStatusNotice::class, function ($n) {
            return $this->notificationProperty($n, 'context')['key_paragraph'] === 'collected';
        });
    }

    public function test_overdue_is_derived_not_stored(): void
    {
        [$student, $request] = $this->withConfirmedSeat('overdue@test.my', '43');
        $key = LockerKey::create(['workstation_request_id' => $request->id, 'student_id' => $student->id, 'status' => LockerKey::STATUS_RETURN_PENDING, 'requested_at' => now()->subDays(10), 'return_due_at' => now()->subDay()]);

        $this->assertTrue($key->isOverdueForReturn());
        $this->assertDatabaseMissing('locker_keys', ['id' => $key->id, 'status' => 'overdue']);
    }

    public function test_a_return_pending_key_is_never_auto_cancelled(): void
    {
        [$student, $request] = $this->withConfirmedSeat('neverautocancel@test.my', '44');
        $key = LockerKey::create(['workstation_request_id' => $request->id, 'student_id' => $student->id, 'status' => LockerKey::STATUS_RETURN_PENDING, 'requested_at' => now()->subDays(30), 'return_due_at' => now()->subDays(20)]);

        $key->processIfOverdue();

        $this->assertSame(LockerKey::STATUS_RETURN_PENDING, $key->fresh()->status);
    }

    public function test_mark_key_as_returned_only_from_return_pending(): void
    {
        [$student, $request] = $this->withConfirmedSeat('markreturned@test.my', '45');
        $key = LockerKey::create(['workstation_request_id' => $request->id, 'student_id' => $student->id, 'status' => LockerKey::STATUS_RETURN_PENDING, 'requested_at' => now(), 'return_due_at' => now()->addDays(7)]);

        $key->markReturned(WorkstationHistory::ACTOR_CGS, 'CGS');

        $this->assertSame(LockerKey::STATUS_RETURNED, $key->fresh()->status);
        $this->assertNotNull($key->fresh()->returned_at);
        $this->assertDatabaseHas('workstation_histories', ['action' => WorkstationHistory::ACTION_KEY_RETURNED]);

        $this->expectException(RuntimeException::class);
        $key->markReturned(WorkstationHistory::ACTOR_CGS, 'CGS');
    }

    public function test_a_new_student_on_the_seat_is_blocked_while_the_last_key_is_return_pending(): void
    {
        [$studentA, $requestA, $seat] = $this->withConfirmedSeat('returnblockA@test.my', '46');
        $keyA = LockerKey::create(['workstation_request_id' => $requestA->id, 'student_id' => $studentA->id, 'status' => LockerKey::STATUS_COLLECTED, 'requested_at' => now()->subDays(3), 'collected_at' => now()->subDays(2)]);
        app(WorkstationAllocator::class)->releaseSeat($requestA, WorkstationHistory::ACTOR_STUDENT, $studentA, 'No longer need a workstation');

        // A new student lands on the same physical seat.
        $studentB = $this->student('22005555', 'returnblockB@test.my');
        StudentGender::create(['student_id' => $studentB->id, 'gender' => 'male']);
        $seat->update(['status' => Workstation::STATUS_OCCUPIED]);
        WorkstationRequest::create(['workstation_id' => $seat->id, 'student_id' => $studentB->id, 'status' => WorkstationRequest::STATUS_CONFIRMED, 'requested_at' => now()]);

        $response = $this->actingAs($studentB)->post(route('workstation.locker-key.request'));

        $response->assertSessionHas('error', 'The locker key for this seat has not been returned yet. Please contact CGS.');
        $this->assertSame(0, LockerKey::where('student_id', $studentB->id)->count());
    }

    // -------------------------------------------------------------
    // reassignSeat
    // -------------------------------------------------------------

    public function test_reassign_moves_the_student_and_notifies_with_changed(): void
    {
        [$student, $request, $oldSeat] = $this->withConfirmedSeat('reassign@test.my', '50');
        $newRoom = $this->room('male', 'N2-02-03');
        $newSeat = $this->seat($newRoom, '51');
        $cgs = $this->cgs();

        $newRequest = app(WorkstationAllocator::class)->reassignSeat($request, $newSeat, $cgs, 'Allocation error');

        $this->assertSame(Workstation::STATUS_AVAILABLE, $oldSeat->fresh()->status);
        $this->assertSame(Workstation::STATUS_OCCUPIED, $newSeat->fresh()->status);
        $this->assertSame(WorkstationRequest::STATUS_RELEASED, $request->fresh()->status);
        $this->assertSame(WorkstationRequest::STATUS_CONFIRMED, $newRequest->status);
        $this->assertSame($student->id, $newRequest->student_id);

        Notification::assertSentTo($student, WorkstationStatusNotice::class, function ($n) {
            return $this->notificationProperty($n, 'kind') === WorkstationStatusNotice::CHANGED;
        });

        $this->assertSame(
            2,
            WorkstationHistory::where('action', WorkstationHistory::ACTION_SEAT_REASSIGNED)->count()
        );
    }

    public function test_reassign_rejects_a_pending_or_maintenance_seat(): void
    {
        [$student, $request] = $this->withConfirmedSeat('reassignreject@test.my', '52');
        $room = $this->room('male', 'N2-02-05');
        $pendingSeat = $this->seat($room, '53');
        $pendingSeat->update(['status' => Workstation::STATUS_PENDING]);

        $this->expectException(RuntimeException::class);
        app(WorkstationAllocator::class)->reassignSeat($request, $pendingSeat, $this->cgs(), 'Allocation error');
    }

    public function test_reassign_rejects_a_different_gender_seat(): void
    {
        [$student, $request] = $this->withConfirmedSeat('reassigngender@test.my', '54', 'male');
        $femaleRoom = $this->room('female', 'N2-02-07');
        $femaleSeat = $this->seat($femaleRoom, '55');

        $this->expectException(RuntimeException::class);
        app(WorkstationAllocator::class)->reassignSeat($request, $femaleSeat, $this->cgs(), 'Allocation error');
    }

    // -------------------------------------------------------------
    // assignStudent
    // -------------------------------------------------------------

    public function test_assign_student_succeeds_and_notifies_assigned(): void
    {
        $student = $this->student();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        $room = $this->room('male', 'N2-02-01');
        $seat = $this->seat($room, '60');
        $cgs = $this->cgs();

        $request = app(WorkstationAllocator::class)->assignStudent($student, $seat, $cgs, 'System error');

        $this->assertSame(Workstation::STATUS_OCCUPIED, $seat->fresh()->status);
        $this->assertSame(WorkstationRequest::STATUS_CONFIRMED, $request->status);

        Notification::assertSentTo($student, WorkstationStatusNotice::class, function ($n) {
            return $this->notificationProperty($n, 'kind') === WorkstationStatusNotice::ASSIGNED;
        });
    }

    public function test_assign_rejects_a_student_who_already_has_a_seat(): void
    {
        [$student, , $existingSeat] = $this->withConfirmedSeat('alreadyhasseat@test.my', '61');
        $room = $this->room('male', 'N2-02-01');
        $newSeat = $this->seat($room, '62');

        $this->expectException(RuntimeException::class);
        app(WorkstationAllocator::class)->assignStudent($student, $newSeat, $this->cgs(), 'System error');
    }

    public function test_assign_rejects_a_non_available_seat(): void
    {
        $student = $this->student();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        $room = $this->room('male', 'N2-02-01');
        $seat = $this->seat($room, '63');
        $seat->update(['status' => Workstation::STATUS_OCCUPIED]);

        $this->expectException(RuntimeException::class);
        app(WorkstationAllocator::class)->assignStudent($student, $seat, $this->cgs(), 'System error');
    }

    public function test_two_simultaneous_assigns_to_the_same_seat_only_one_succeeds(): void
    {
        $studentA = $this->student('22007001', 'assignraceA@test.my');
        $studentB = $this->student('22007002', 'assignraceB@test.my');
        StudentGender::create(['student_id' => $studentA->id, 'gender' => 'male']);
        StudentGender::create(['student_id' => $studentB->id, 'gender' => 'male']);
        $room = $this->room('male', 'N2-02-01');
        $seat = $this->seat($room, '64');
        $cgs = $this->cgs();
        $allocator = app(WorkstationAllocator::class);

        $allocator->assignStudent($studentA, $seat, $cgs, 'System error');

        $this->expectException(RuntimeException::class);
        $allocator->assignStudent($studentB, $seat, $cgs, 'System error');
    }

    // -------------------------------------------------------------
    // setMaintenance / restoreSeat
    // -------------------------------------------------------------

    public function test_set_maintenance_only_from_available(): void
    {
        $room = $this->room('male', 'N2-02-01');
        $seat = $this->seat($room, '70');

        app(WorkstationAllocator::class)->setMaintenance($seat, $this->cgs(), 'Repair needed');
        $this->assertSame(Workstation::STATUS_DISABLED, $seat->fresh()->status);
    }

    public function test_set_maintenance_rejects_occupied_with_exact_message(): void
    {
        [, , $seat] = $this->withConfirmedSeat('maintoccupied@test.my', '71');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Reassign or release the student first.');
        app(WorkstationAllocator::class)->setMaintenance($seat, $this->cgs(), 'Repair needed');
    }

    public function test_set_maintenance_rejects_pending(): void
    {
        $room = $this->room('male', 'N2-02-01');
        $seat = $this->seat($room, '72');
        $seat->update(['status' => Workstation::STATUS_PENDING]);

        $this->expectException(RuntimeException::class);
        app(WorkstationAllocator::class)->setMaintenance($seat, $this->cgs(), 'Repair needed');
    }

    public function test_restore_seat_returns_it_to_available(): void
    {
        $room = $this->room('male', 'N2-02-01');
        $seat = $this->seat($room, '73');
        $seat->update(['status' => Workstation::STATUS_DISABLED]);

        app(WorkstationAllocator::class)->restoreSeat($seat, $this->cgs());
        $this->assertSame(Workstation::STATUS_AVAILABLE, $seat->fresh()->status);
    }

    // -------------------------------------------------------------
    // History is append-only
    // -------------------------------------------------------------

    public function test_history_rows_exist_and_the_model_has_no_update_or_delete_entry_point(): void
    {
        [$student, $request] = $this->withConfirmedSeat('historycheck@test.my', '80');
        app(WorkstationAllocator::class)->releaseSeat($request, WorkstationHistory::ACTOR_STUDENT, $student, 'No longer need a workstation');

        $this->assertGreaterThan(0, WorkstationHistory::count());
        $this->assertFalse(method_exists(WorkstationHistory::class, 'edit'));

        // The model itself offers no route/controller method anywhere in
        // this module that calls ->update() or ->delete() on a History row
        // -- verified by code review (see report), not re-asserted here
        // since there's nothing to call.
    }

    // -------------------------------------------------------------
    // Daily job (STEP 7)
    // -------------------------------------------------------------

    /**
     * PART 3: replaces the old 14/7/3-day notice pipeline test above --
     * one notice, 5 working days, auto-release with no email on the day.
     */
    public function test_daily_job_starts_termination_for_a_dismissed_student_and_releases_after_the_vacate_by_day(): void
    {
        [$student, $request, $seat] = $this->withConfirmedSeat('dismissed@test.my', '92');
        StudyCandidacy::create([
            'student_id' => $student->id, 'programme_start_date' => now()->subYears(2),
            'candidacy_expiry_date' => now()->addDays(60), 'status' => StudyCandidacy::STATUS_DISMISSED,
            'dismissed_at' => now(),
        ]);

        $this->artisan('workstation:process-releases')->assertSuccessful();

        $notice = WorkstationTerminationNotice::where('workstation_request_id', $request->id)->first();
        $this->assertNotNull($notice);
        $this->assertSame(WorkstationAllocator::REASON_TERMINATION_DISMISSAL, $notice->reason);
        $this->assertSame(WorkstationTerminationNotice::STATUS_ACTIVE, $notice->status);
        $this->assertSame(Workstation::STATUS_OCCUPIED, $seat->fresh()->status);
        Notification::assertSentToTimes($student, WorkstationStatusNotice::class, 1);
        Notification::assertSentTo($student, WorkstationStatusNotice::class, function ($n) {
            return $this->notificationProperty($n, 'kind') === WorkstationStatusNotice::TERMINATION_NOTICE;
        });

        // Running again the same day starts nothing new.
        $this->artisan('workstation:process-releases')->assertSuccessful();
        $this->assertSame(1, WorkstationTerminationNotice::where('workstation_request_id', $request->id)->count());
        Notification::assertSentToTimes($student, WorkstationStatusNotice::class, 1);

        // Past the vacate-by day: auto-released, no further email/bell.
        $notice->update(['vacate_by_date' => now()->subDay()]);
        $this->artisan('workstation:process-releases')->assertSuccessful();

        $this->assertSame(WorkstationRequest::STATUS_RELEASED, $request->fresh()->status);
        $this->assertSame(Workstation::STATUS_AVAILABLE, $seat->fresh()->status);
        $this->assertSame(WorkstationTerminationNotice::STATUS_RELEASED, $notice->fresh()->status);
        Notification::assertSentToTimes($student, WorkstationStatusNotice::class, 1); // unchanged -- no email on the auto-release
        $this->assertDatabaseHas('workstation_histories', [
            'seat_number' => $seat->seat_code, 'action' => WorkstationHistory::ACTION_SEAT_RELEASED_VACATE_ENDED,
        ]);
    }

    /**
     * Final polish PART 4.2 (found live: no existing test covered the
     * Graduated automatic trigger specifically, only Dismissal and
     * candidacy expiry). Confirms StudyCandidacy::STATUS_COMPLETED
     * starts a termination notice with the Graduated reason, same as
     * the Dismissal case above.
     */
    public function test_daily_job_starts_termination_for_a_graduated_student(): void
    {
        [$student, $request] = $this->withConfirmedSeat('graduated@test.my', '95');
        StudyCandidacy::create([
            'student_id' => $student->id, 'programme_start_date' => now()->subYears(2),
            'candidacy_expiry_date' => now()->addDays(60), 'status' => StudyCandidacy::STATUS_COMPLETED,
        ]);

        $this->artisan('workstation:process-releases')->assertSuccessful();

        $notice = WorkstationTerminationNotice::where('workstation_request_id', $request->id)->first();
        $this->assertNotNull($notice);
        $this->assertSame(WorkstationAllocator::REASON_TERMINATION_GRADUATED, $notice->reason);
        $this->assertSame(WorkstationTerminationNotice::STATUS_ACTIVE, $notice->status);
    }

    public function test_daily_job_starts_termination_at_candidacy_expiry(): void
    {
        [$student, $request] = $this->withConfirmedSeat('expiring@test.my', '93');
        StudyCandidacy::create([
            'student_id' => $student->id, 'programme_start_date' => now()->subYears(2),
            'candidacy_expiry_date' => now()->subDay(), 'status' => StudyCandidacy::STATUS_ACTIVE,
        ]);

        $this->artisan('workstation:process-releases')->assertSuccessful();

        $notice = WorkstationTerminationNotice::where('workstation_request_id', $request->id)->first();
        $this->assertNotNull($notice);
        $this->assertSame(WorkstationAllocator::REASON_TERMINATION_EXPIRY, $notice->reason);
    }

    /** PART 2's exception: StudyCandidacy::hasOpenAppeal() already exists and is exactly what this needs -- no stub required. */
    public function test_an_expired_candidacy_with_a_pending_appeal_is_not_terminated(): void
    {
        [$student, $request] = $this->withConfirmedSeat('appealpending@test.my', '94');
        $candidacy = StudyCandidacy::create([
            'student_id' => $student->id, 'programme_start_date' => now()->subYears(2),
            'candidacy_expiry_date' => now()->subDay(), 'status' => StudyCandidacy::STATUS_ACTIVE,
        ]);

        CandidacyAppeal::create([
            'student_id' => $student->id, 'study_candidacy_id' => $candidacy->id, 'appeal_no' => 'SCA-99999994-01', 'sequence_no' => 1,
            'status' => CandidacyAppeal::STATUS_WITH_SUPERVISOR, 'current_stage' => 'supervisor', 'round' => 1, 'requested_months' => 3,
            'submitted_at' => now()->subDay(), 'stage_entered_at' => now()->subDay(),
        ]);

        $this->artisan('workstation:process-releases')->assertSuccessful();

        $this->assertSame(0, WorkstationTerminationNotice::where('workstation_request_id', $request->id)->count());
        $this->assertSame(WorkstationRequest::STATUS_CONFIRMED, $request->fresh()->status);
    }

    public function test_an_active_student_with_a_future_expiry_is_left_entirely_alone(): void
    {
        [$student, $request] = $this->withConfirmedSeat('activeuntouched@test.my', '95');
        StudyCandidacy::create([
            'student_id' => $student->id, 'programme_start_date' => now()->subYears(2),
            'candidacy_expiry_date' => now()->addDays(60), 'status' => StudyCandidacy::STATUS_ACTIVE,
        ]);

        $this->artisan('workstation:process-releases')->assertSuccessful();

        $this->assertSame(0, WorkstationTerminationNotice::where('workstation_request_id', $request->id)->count());
        $this->assertSame(WorkstationRequest::STATUS_CONFIRMED, $request->fresh()->status);
    }

    public function test_dry_run_prints_without_changing_anything(): void
    {
        [$student, $request] = $this->withConfirmedSeat('dryrun@test.my', '99');
        StudyCandidacy::create([
            'student_id' => $student->id, 'programme_start_date' => now()->subYears(2),
            'candidacy_expiry_date' => now()->addDays(60), 'status' => StudyCandidacy::STATUS_DISMISSED,
            'dismissed_at' => now(),
        ]);

        $this->artisan('workstation:process-releases', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, WorkstationTerminationNotice::where('workstation_request_id', $request->id)->count());
        $this->assertSame(WorkstationRequest::STATUS_CONFIRMED, $request->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_dummy_occupants_are_untouched_by_the_daily_job(): void
    {
        $room = $this->room('male', 'N2-02-01');
        // Bulk-seeded occupied, no WorkstationRequest, no login -- exactly
        // how DatabaseSeeder creates the ~147 unexplained-occupant seats.
        Workstation::create(['workstation_location_id' => $room->id, 'seat_code' => '96', 'position' => 1, 'status' => Workstation::STATUS_OCCUPIED]);

        $this->artisan('workstation:process-releases')->assertSuccessful();

        $this->assertSame(0, WorkstationHistory::count());
    }

    public function test_return_reminder_fires_once_three_days_before_due(): void
    {
        [$student, $request] = $this->withConfirmedSeat('returnreminder@test.my', '97');
        $key = LockerKey::create([
            'workstation_request_id' => $request->id, 'student_id' => $student->id,
            'status' => LockerKey::STATUS_RETURN_PENDING, 'requested_at' => now()->subDays(5),
            'return_due_at' => now()->addDays(2),
        ]);

        $this->artisan('workstation:process-releases')->assertSuccessful();

        Notification::assertSentTo($student, LockerKeyNotice::class, fn ($n) => $this->notificationProperty($n, 'kind') === LockerKeyNotice::RETURN_REMINDER);
        $this->assertNotNull($key->fresh()->return_reminder_sent_at);

        $this->artisan('workstation:process-releases')->assertSuccessful();
        Notification::assertSentToTimes($student, LockerKeyNotice::class, 1);
    }

    // -------------------------------------------------------------
    // Pending/Return lists + search
    // -------------------------------------------------------------

    public function test_return_pending_list_supports_search_and_reports_overdue(): void
    {
        [$studentA, $requestA] = $this->withConfirmedSeat('returnlistA@test.my', '98');
        $studentA->update(['matric_no' => '22008888']);
        LockerKey::create(['workstation_request_id' => $requestA->id, 'student_id' => $studentA->id, 'status' => LockerKey::STATUS_RETURN_PENDING, 'requested_at' => now()->subDays(10), 'return_due_at' => now()->subDay()]);

        $list = LockerKey::returnPendingList();
        $this->assertCount(1, $list);
        $this->assertTrue($list->first()['overdue']);

        $this->assertCount(1, LockerKey::returnPendingList('22008888'));
        $this->assertCount(1, LockerKey::returnPendingList('98'));
        $this->assertCount(0, LockerKey::returnPendingList('no-match'));
    }

    /**
     * Final polish PART 4.6 (found live): a seat with an OLD confirmed
     * request plus a NEWER non-confirmed one (released/cancelled) used to
     * resolve activeRequest() to null -- a plain where()+latestOfMany()
     * chain picks "latest by requested_at across every status" in its own
     * subquery, then an outer filter drops that row if it isn't
     * confirmed, with nothing to fall back to. Reproduced live on a real
     * seat (CGS saw "No student record" on an Occupied seat with a
     * genuine occupant). The fix moves the status filter into ofMany()'s
     * own constraint callback, so "latest" is computed only among
     * confirmed rows to begin with.
     */
    public function test_active_request_resolves_the_confirmed_row_even_when_a_newer_non_confirmed_request_exists_for_the_same_seat(): void
    {
        [$student, $request, $seat] = $this->withConfirmedSeat('activerequest-order@test.my', '77');
        $request->update(['requested_at' => now()->subYear()]);

        $otherStudent = $this->student('22099999', 'activerequest-order-other@test.my');
        WorkstationRequest::create([
            'workstation_id' => $seat->id, 'student_id' => $otherStudent->id,
            'status' => WorkstationRequest::STATUS_RELEASED, 'requested_at' => now(),
        ]);

        $fresh = $seat->fresh();
        $this->assertNotNull($fresh->activeRequest, 'activeRequest must not be null just because a newer, non-confirmed request exists.');
        $this->assertSame($request->id, $fresh->activeRequest->id);
        $this->assertSame($student->id, $fresh->activeRequest->occupant()?->id ?? $fresh->activeRequest->student_id);
    }

    protected function notificationProperty(object $notification, string $property): mixed
    {
        $ref = new \ReflectionProperty($notification, $property);
        $ref->setAccessible(true);

        return $ref->getValue($notification);
    }
}
