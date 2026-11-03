<?php

namespace Tests\Feature\Chloe;

use App\Modules\Chloe\Models\LockerKey;
use App\Modules\Chloe\Models\StudentGender;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationLocation;
use App\Modules\Chloe\Models\WorkstationRequest;
use App\Modules\Chloe\Notifications\LockerKeyReminder;
use App\Modules\Chloe\Notifications\WorkstationAllocated;
use App\Modules\Chloe\Notifications\WorkstationRequestRejected;
use App\Modules\Chloe\Services\WorkstationAllocator;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Workstation Management: booking, the one-seat rule, locker keys and CGS's
 * overrides. Not an approval chain, so every rule here lives in
 * WorkstationAllocator or the controllers, and these tests are what hold it.
 */
class WorkstationTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected WorkstationLocation $room;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->room = WorkstationLocation::create(['block' => '1', 'room_code' => 'R1', 'gender' => 'female', 'name' => 'Room 1']);
    }

    protected function seat(string $code, string $status = Workstation::STATUS_AVAILABLE): Workstation
    {
        return Workstation::create(['workstation_location_id' => $this->room->id, 'seat_code' => $code, 'status' => $status]);
    }

    protected function femaleStudent(string $matric = '22001001', string $email = 'student@test.my'): User
    {
        $student = $this->student($matric, $email);
        StudentGender::create(['student_id' => $student->id, 'gender' => 'female']);

        return $student;
    }

    protected function confirmed(User $student, Workstation $seat): WorkstationRequest
    {
        return app(WorkstationAllocator::class)->request($student, $seat)['request'];
    }

    public function test_a_student_books_a_free_seat_and_is_emailed(): void
    {
        $student = $this->femaleStudent();
        $seat = $this->seat('A1');

        $this->actingAs($student)
            ->post(route('workstation.register', $seat))
            ->assertRedirect(route('workstation.seats', $this->room))
            ->assertSessionHas('status');

        $this->assertSame(Workstation::STATUS_OCCUPIED, $seat->fresh()->status);
        $this->assertTrue(WorkstationRequest::where('student_id', $student->id)
            ->where('status', WorkstationRequest::STATUS_CONFIRMED)->exists());
        Notification::assertSentTo($student, WorkstationAllocated::class);
    }

    public function test_a_seat_someone_else_took_first_is_refused_and_recorded(): void
    {
        $first = $this->femaleStudent('22001001', 'first@test.my');
        $second = $this->femaleStudent('22001002', 'second@test.my');
        $seat = $this->seat('A1');
        $this->confirmed($first, $seat);

        $this->actingAs($second)
            ->post(route('workstation.register', $seat))
            ->assertSessionHas('error');

        $this->assertSame(WorkstationRequest::STATUS_REJECTED,
            WorkstationRequest::where('student_id', $second->id)->value('status'));
        Notification::assertSentTo($second, WorkstationRequestRejected::class);
    }

    public function test_a_student_holding_a_seat_cannot_book_a_second_one(): void
    {
        $student = $this->femaleStudent();
        $first = $this->seat('A1');
        $second = $this->seat('A2');
        $this->confirmed($student, $first);

        // Through the allocator: the check lives inside its transaction,
        // under a lock on the student, so a double submit cannot slip past.
        try {
            app(WorkstationAllocator::class)->request($student, $second);
            $this->fail('A second seat was booked.');
        } catch (RuntimeException) {
        }

        // And through the route, which turns that into a message.
        $this->actingAs($student)
            ->post(route('workstation.register', $second))
            ->assertSessionHas('error', 'You already have a workstation. Release it before selecting another.');

        $this->assertSame(Workstation::STATUS_AVAILABLE, $second->fresh()->status);
        $this->assertSame(1, WorkstationRequest::where('student_id', $student->id)
            ->where('status', WorkstationRequest::STATUS_CONFIRMED)->count());
    }

    public function test_a_room_of_the_other_designation_is_refused(): void
    {
        $student = $this->student();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);

        $this->actingAs($student)
            ->get(route('workstation.seats', $this->room))
            ->assertForbidden();
    }

    public function test_the_home_screen_lists_only_rooms_for_the_students_designation(): void
    {
        WorkstationLocation::create(['block' => '2', 'room_code' => 'M1', 'gender' => 'male', 'name' => 'Men Only Room']);
        $this->seat('A1');

        $this->actingAs($this->femaleStudent())
            ->get(route('workstation.select'))
            ->assertOk()
            ->assertSee('Room 1')
            ->assertDontSee('Men Only Room');
    }

    public function test_a_student_releases_their_own_seat_but_not_someone_elses(): void
    {
        $owner = $this->femaleStudent('22001001', 'owner@test.my');
        $other = $this->femaleStudent('22001002', 'other@test.my');
        $seat = $this->seat('A1');
        $request = $this->confirmed($owner, $seat);

        $this->actingAs($other)
            ->post(route('workstation.release', $request))
            ->assertForbidden();
        $this->assertSame(Workstation::STATUS_OCCUPIED, $seat->fresh()->status);

        $this->actingAs($owner)->post(route('workstation.release', $request));

        $this->assertSame(Workstation::STATUS_AVAILABLE, $seat->fresh()->status);
        $this->assertSame(WorkstationRequest::STATUS_RELEASED, $request->fresh()->status);
    }

    public function test_a_locker_key_needs_a_seat_and_runs_requested_collected_returned(): void
    {
        $student = $this->femaleStudent();
        $cgs = $this->cgs();

        // No seat yet: refused.
        $this->actingAs($student)
            ->post(route('workstation.locker-key.request'))
            ->assertSessionHas('error');
        $this->assertSame(0, LockerKey::count());

        $this->confirmed($student, $this->seat('A1'));

        $this->actingAs($student)->post(route('workstation.locker-key.request'))->assertSessionHas('status');
        // A second request while the first is open: refused.
        $this->actingAs($student)->post(route('workstation.locker-key.request'))->assertSessionHas('error');

        $key = LockerKey::sole();
        $this->assertSame(LockerKey::STATUS_REQUESTED, $key->status);

        $this->actingAs($cgs)->post(route('workstation.cgs.locker-key.collected', $key));
        $this->assertSame(LockerKey::STATUS_COLLECTED, $key->fresh()->status);

        $this->actingAs($cgs)->post(route('workstation.cgs.locker-key.returned', $key));
        $this->assertSame(LockerKey::STATUS_RETURNED, $key->fresh()->status);
    }

    public function test_an_uncollected_key_is_reminded_once_per_grace_period(): void
    {
        $student = $this->femaleStudent();
        $request = $this->confirmed($student, $this->seat('A1'));
        $key = LockerKey::create([
            'workstation_request_id' => $request->id,
            'student_id' => $student->id,
            'status' => LockerKey::STATUS_REQUESTED,
            'requested_at' => now()->subDays(LockerKey::GRACE_DAYS + 1),
        ]);

        $this->artisan('workstation:remind-locker-keys')->assertSuccessful();
        $this->artisan('workstation:remind-locker-keys')->assertSuccessful();

        Notification::assertSentToTimes($student, LockerKeyReminder::class, 1);
        $this->assertSame(1, $key->fresh()->reminder_count);
    }

    public function test_cgs_force_assign_needs_a_reason_and_a_free_seat(): void
    {
        $cgs = $this->cgs();
        $student = $this->femaleStudent();
        $seat = $this->seat('A1');
        $taken = $this->seat('A2', Workstation::STATUS_OCCUPIED);

        $this->actingAs($cgs)
            ->post(route('workstation.cgs.force-assign', $seat), ['student_id' => $student->id])
            ->assertSessionHasErrors('reason');

        $this->actingAs($cgs)
            ->post(route('workstation.cgs.force-assign', $taken), ['student_id' => $student->id, 'reason' => 'Exception'])
            ->assertSessionHas('error');

        $this->actingAs($cgs)
            ->post(route('workstation.cgs.force-assign', $seat), ['student_id' => $student->id, 'reason' => 'Medical case'])
            ->assertSessionHas('status');

        $request = WorkstationRequest::where('workstation_id', $seat->id)->sole();
        $this->assertTrue($request->is_override);
        $this->assertSame($cgs->id, $request->overridden_by_id);
        $this->assertSame('Medical case', $request->override_reason);
        $this->assertSame(Workstation::STATUS_OCCUPIED, $seat->fresh()->status);
    }

    public function test_cgs_force_release_frees_the_seat_and_marks_the_students_request(): void
    {
        $cgs = $this->cgs();
        $student = $this->femaleStudent();
        $seat = $this->seat('A1');
        $request = $this->confirmed($student, $seat);

        $this->actingAs($cgs)
            ->post(route('workstation.cgs.force-release', $seat), ['reason' => 'Graduated'])
            ->assertSessionHas('status');

        $this->assertSame(Workstation::STATUS_AVAILABLE, $seat->fresh()->status);
        $request->refresh();
        $this->assertSame(WorkstationRequest::STATUS_RELEASED, $request->status);
        $this->assertTrue($request->is_override);
        $this->assertSame('Graduated', $request->override_reason);
    }

    public function test_a_student_cannot_use_the_cgs_overrides(): void
    {
        $student = $this->femaleStudent();
        $seat = $this->seat('A1');

        $this->actingAs($student)
            ->post(route('workstation.cgs.force-assign', $seat), ['student_id' => $student->id, 'reason' => 'Me'])
            ->assertForbidden();
        $this->actingAs($student)
            ->get(route('workstation.cgs.operations'))
            ->assertForbidden();

        $this->assertSame(Workstation::STATUS_AVAILABLE, $seat->fresh()->status);
    }
}
