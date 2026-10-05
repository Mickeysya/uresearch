<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationLocation;
use App\Modules\Chloe\Models\WorkstationRequest;
use App\Modules\Chloe\Services\WorkstationAllocator;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class WorkstationAllocatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_student_holding_a_seat_cannot_book_a_second_one(): void
    {
        $student = User::create(['name' => 'S', 'email' => 's@test.my', 'password' => 'password', 'role' => Role::STUDENT]);
        $room = WorkstationLocation::create(['block' => '1', 'room_code' => 'R1', 'gender' => 'female', 'name' => 'Room 1']);
        $first = Workstation::create(['workstation_location_id' => $room->id, 'seat_code' => 'A1']);
        $second = Workstation::create(['workstation_location_id' => $room->id, 'seat_code' => 'A2']);

        $allocator = app(WorkstationAllocator::class);
        $this->assertTrue($allocator->request($student, $first)['allocated']);

        // The check lives inside the allocator's transaction now, under a
        // lock on the student, so a double submit cannot slip past it.
        try {
            $allocator->request($student, $second);
            $this->fail('A second seat was booked.');
        } catch (RuntimeException) {
        }

        $this->assertSame(Workstation::STATUS_AVAILABLE, $second->fresh()->status);
        $this->assertSame(1, WorkstationRequest::where('student_id', $student->id)
            ->where('status', WorkstationRequest::STATUS_CONFIRMED)->count());
    }
}
