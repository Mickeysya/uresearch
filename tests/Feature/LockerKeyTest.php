<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\LockerKey;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationLocation;
use App\Modules\Chloe\Models\WorkstationRequest;
use App\Modules\Chloe\Notifications\LockerKeyNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\MakesUsers;
use Tests\TestCase;

class LockerKeyTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    /** A student with a confirmed seat, ready to request a key. */
    protected function withConfirmedSeat(string $email = 'student@test.my', string $seatCode = '5'): array
    {
        $student = $this->student('2200'.substr(md5($email), 0, 4), $email);
        $room = WorkstationLocation::firstOrCreate(
            ['room_code' => 'N2-02-01'],
            ['block' => 'N2', 'gender' => 'male', 'name' => 'N2-02-01']
        );
        $seat = Workstation::create(['workstation_location_id' => $room->id, 'seat_code' => $seatCode, 'position' => 1, 'status' => Workstation::STATUS_OCCUPIED]);
        $activeRequest = WorkstationRequest::create([
            'workstation_id' => $seat->id, 'student_id' => $student->id,
            'status' => WorkstationRequest::STATUS_CONFIRMED, 'requested_at' => now(),
        ]);

        return [$student, $activeRequest];
    }

    public function test_a_student_without_a_confirmed_seat_sees_the_prerequisite_message_and_cannot_request(): void
    {
        $student = $this->student();

        $response = $this->actingAs($student)->get(route('workstation.select'));

        $response->assertSee('Select a workstation first to request your locker key.');

        $this->actingAs($student)->post(route('workstation.locker-key.request'))
            ->assertSessionHas('error');
    }

    public function test_requesting_a_key_sets_pending_collection_and_sends_email_a(): void
    {
        [$student, $activeRequest] = $this->withConfirmedSeat();

        $response = $this->actingAs($student)->post(route('workstation.locker-key.request'));

        $response->assertRedirect(route('workstation.select'));

        $lockerKey = LockerKey::where('workstation_request_id', $activeRequest->id)->first();
        $this->assertNotNull($lockerKey);
        $this->assertSame(LockerKey::STATUS_REQUESTED, $lockerKey->status);
        $this->assertNotNull($lockerKey->requested_at);

        Notification::assertSentTo(
            $student,
            LockerKeyNotice::class,
            fn ($notification) => $this->notificationKind($notification) === LockerKeyNotice::REQUESTED
        );

        $page = $this->actingAs($student)->get(route('workstation.select'));
        $page->assertSee('Pending Collection');
        $page->assertSee('Please collect by '.$lockerKey->collectByDate()->format('j M Y'));
    }

    public function test_a_student_cannot_request_a_second_key_while_one_is_pending(): void
    {
        [$student, $activeRequest] = $this->withConfirmedSeat();
        $this->actingAs($student)->post(route('workstation.locker-key.request'));

        $response = $this->actingAs($student)->post(route('workstation.locker-key.request'));

        $response->assertSessionHas('error');
        $this->assertSame(1, LockerKey::where('workstation_request_id', $activeRequest->id)->count());
    }

    public function test_cancelling_returns_to_the_request_key_state_and_allows_requesting_again(): void
    {
        [$student, $activeRequest] = $this->withConfirmedSeat();
        $this->actingAs($student)->post(route('workstation.locker-key.request'));

        $cancel = $this->actingAs($student)->post(route('workstation.locker-key.cancel'));
        $cancel->assertRedirect(route('workstation.select'));

        $first = LockerKey::where('workstation_request_id', $activeRequest->id)->first();
        $this->assertSame(LockerKey::STATUS_CANCELLED, $first->status);

        $page = $this->actingAs($student)->get(route('workstation.select'));
        $page->assertSee('>Request Key<', false);
        $page->assertDontSee('Pending Collection');

        // And can request again -- the cancelled row doesn't block a new one.
        $this->actingAs($student)->post(route('workstation.locker-key.request'))
            ->assertSessionHas('status');

        $this->assertSame(
            2,
            LockerKey::where('workstation_request_id', $activeRequest->id)->count()
        );
    }

    public function test_a_collected_key_cannot_be_requested_again_or_cancelled(): void
    {
        [$student] = $this->withConfirmedSeat();
        $this->actingAs($student)->post(route('workstation.locker-key.request'));
        LockerKey::markAsCollected($student);

        $this->actingAs($student)->post(route('workstation.locker-key.request'))
            ->assertSessionHas('error');
        $this->actingAs($student)->post(route('workstation.locker-key.cancel'))
            ->assertSessionHas('error');

        $page = $this->actingAs($student)->get(route('workstation.select'));
        $page->assertSee('Collected');
        $page->assertDontSee('Cancel Request');
        $page->assertDontSee('Request Key');
    }

    public function test_back_dating_by_three_days_sends_exactly_one_reminder_and_never_a_second(): void
    {
        [$student] = $this->withConfirmedSeat();
        $this->actingAs($student)->post(route('workstation.locker-key.request'));
        LockerKey::query()->update(['requested_at' => now()->subDays(3)->subMinute()]);

        $this->artisan('workstation:remind-locker-keys')->assertSuccessful();

        Notification::assertSentToTimes(
            $student,
            LockerKeyNotice::class,
            2 // Email A from the request above, plus this one reminder.
        );
        $lockerKey = LockerKey::first();
        $this->assertNotNull($lockerKey->reminder_sent_at);
        $this->assertSame(LockerKey::STATUS_REQUESTED, $lockerKey->status);

        // Running it again must not send a second reminder.
        $this->artisan('workstation:remind-locker-keys')->assertSuccessful();
        Notification::assertSentToTimes($student, LockerKeyNotice::class, 2);
    }

    public function test_back_dating_by_seven_days_auto_cancels_and_sends_email_c(): void
    {
        [$student] = $this->withConfirmedSeat();
        $this->actingAs($student)->post(route('workstation.locker-key.request'));
        LockerKey::query()->update(['requested_at' => now()->subDays(7)->subMinute()]);

        $this->artisan('workstation:remind-locker-keys')->assertSuccessful();

        $lockerKey = LockerKey::first();
        $this->assertSame(LockerKey::STATUS_CANCELLED, $lockerKey->status);

        Notification::assertSentTo(
            $student,
            LockerKeyNotice::class,
            fn ($notification) => $this->notificationKind($notification) === LockerKeyNotice::CANCELLED
        );

        // Back to the Request Key state.
        $this->actingAs($student)->get(route('workstation.select'))->assertSee('>Request Key<', false);
    }

    public function test_the_inline_check_catches_an_overdue_key_without_the_scheduler_running(): void
    {
        [$student] = $this->withConfirmedSeat();
        $this->actingAs($student)->post(route('workstation.locker-key.request'));
        LockerKey::query()->update(['requested_at' => now()->subDays(7)->subMinute()]);

        // No artisan command run here at all -- just loading the page.
        $this->actingAs($student)->get(route('workstation.select'))->assertSee('>Request Key<', false);

        $this->assertSame(LockerKey::STATUS_CANCELLED, LockerKey::first()->status);
    }

    public function test_mark_as_collected_sets_collected_fields_and_sends_a_bell_only_notification(): void
    {
        [$student] = $this->withConfirmedSeat();
        $this->actingAs($student)->post(route('workstation.locker-key.request'));

        $lockerKey = LockerKey::markAsCollected($student);

        $this->assertSame(LockerKey::STATUS_COLLECTED, $lockerKey->status);
        $this->assertNotNull($lockerKey->collected_at);

        Notification::assertSentTo($student, LockerKeyNotice::class, function ($notification, $channels) {
            return $this->notificationKind($notification) === LockerKeyNotice::COLLECTED
                && $channels === ['database'];
        });
    }

    public function test_mark_as_collected_rejects_a_student_who_is_not_pending(): void
    {
        $student = $this->student();

        $this->expectException(\RuntimeException::class);
        LockerKey::markAsCollected($student);
    }

    public function test_the_pending_collection_list_supports_search_by_matric_and_seat_code(): void
    {
        [$studentA] = $this->withConfirmedSeat('a@test.my', '5');
        [$studentB, $requestB] = $this->withConfirmedSeat('b@test.my', '6');
        $studentB->update(['matric_no' => '22009999']);
        $this->actingAs($studentA)->post(route('workstation.locker-key.request'));
        $this->actingAs($studentB)->post(route('workstation.locker-key.request'));

        $this->assertCount(2, LockerKey::pendingCollectionList());
        $this->assertCount(1, LockerKey::pendingCollectionList('22009999'));
        $this->assertCount(
            1,
            LockerKey::pendingCollectionList($requestB->workstation->seat_code)
        );
        $this->assertCount(0, LockerKey::pendingCollectionList('no-such-match'));
    }

    public function test_a_dummy_occupant_seat_with_no_workstation_request_is_untouched_by_the_job(): void
    {
        $room = WorkstationLocation::create(['block' => 'N2', 'room_code' => 'N2-02-01', 'gender' => 'male', 'name' => 'N2-02-01']);
        // Seeded the way DatabaseSeeder does for the ~147 occupied seats with
        // no real occupant: status set directly, no WorkstationRequest at all.
        Workstation::create(['workstation_location_id' => $room->id, 'seat_code' => '9', 'position' => 1, 'status' => Workstation::STATUS_OCCUPIED]);

        $this->assertSame(0, LockerKey::count());
        $this->artisan('workstation:remind-locker-keys')->assertSuccessful();
        $this->assertSame(0, LockerKey::count());
    }

    public function test_a_student_cannot_see_or_cancel_another_students_key(): void
    {
        [$studentA] = $this->withConfirmedSeat('a@test.my', '5');
        [$studentB] = $this->withConfirmedSeat('b@test.my', '6');
        $this->actingAs($studentA)->post(route('workstation.locker-key.request'));

        $keyA = LockerKey::first();

        // studentB has no key of their own, so cancelling resolves to "no
        // request to cancel" rather than touching studentA's.
        $this->actingAs($studentB)->post(route('workstation.locker-key.cancel'))
            ->assertSessionHas('error');

        $this->assertSame(LockerKey::STATUS_REQUESTED, $keyA->fresh()->status);
    }

    protected function notificationKind(LockerKeyNotice $notification): string
    {
        $property = new \ReflectionProperty($notification, 'kind');
        $property->setAccessible(true);

        return $property->getValue($notification);
    }
}
