<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\LockerKey;
use App\Modules\Chloe\Models\StudentGender;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationHistory;
use App\Modules\Chloe\Models\WorkstationLocation;
use App\Modules\Chloe\Models\WorkstationRequest;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WorkstationDemoLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        app()['env'] = 'production';

        try {
            $this->artisan('workstation:demo-login')->assertFailed();
        } finally {
            app()['env'] = 'testing';
        }

        $this->assertDatabaseMissing('users', ['name' => 'Chloe Ching']);
    }

    public function test_it_creates_the_account_with_a_unique_stable_student_id_and_email(): void
    {
        $this->artisan('workstation:demo-login')->assertSuccessful();

        $user = User::where('name', 'Chloe Ching')->first();

        $this->assertNotNull($user);
        $this->assertSame(Role::STUDENT, $user->role);
        $this->assertMatchesRegularExpression('/^\d{2}00\d{4}$/', $user->matric_no);
        $this->assertSame('chloe_'.$user->matric_no.'@utp.edu.my', $user->email);
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertSame('MSc Full-Time', $user->programme);

        $matricNo = $user->matric_no;
        $email = $user->email;
        $userId = $user->id;

        // Running it again must not create a second account or change the
        // stable identifiers.
        $this->artisan('workstation:demo-login')->assertSuccessful();

        $this->assertSame(1, User::where('name', 'Chloe Ching')->count());
        $refreshed = User::find($userId);
        $this->assertSame($matricNo, $refreshed->matric_no);
        $this->assertSame($email, $refreshed->email);
    }

    public function test_the_student_id_never_collides_with_an_existing_one(): void
    {
        // Occupy a huge slice of this year's YY00xxxx space so the retry
        // loop in generateUniqueMatricNo() is actually exercised, not just
        // trivially passing on the first try. Bulk-inserted, bypassing
        // model events, so filling all 9999 slots stays fast.
        $year = now()->format('y');
        $now = now();

        $rows = [];
        for ($i = 1; $i <= 9999; $i++) {
            $rows[] = [
                'name' => "Filler {$i}", 'email' => "filler{$i}@test.my", 'password' => 'x',
                'role' => Role::STUDENT, 'matric_no' => $year.'00'.sprintf('%04d', $i),
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('users')->insert($chunk);
        }

        $this->artisan('workstation:demo-login')->assertFailed();
        // No collision is even possible to hand out -- every ID in this
        // year's space is taken, so the command fails loudly (an infinite
        // loop would time the test out) rather than silently reusing one.
    }

    public function test_default_gender_is_female_and_the_option_changes_it(): void
    {
        $this->artisan('workstation:demo-login')->assertSuccessful();
        $user = User::where('name', 'Chloe Ching')->first();

        $this->assertSame('female', StudentGender::where('student_id', $user->id)->value('gender'));

        $this->artisan('workstation:demo-login', ['--gender' => 'Male'])->assertSuccessful();
        $this->assertSame('male', StudentGender::where('student_id', $user->id)->value('gender'));

        // The student ID/email did not change just because gender did.
        $this->assertSame(1, User::where('name', 'Chloe Ching')->count());
    }

    public function test_an_invalid_gender_option_fails_without_changing_anything(): void
    {
        $this->artisan('workstation:demo-login', ['--gender' => 'Other'])->assertFailed();
        $this->assertDatabaseMissing('users', ['name' => 'Chloe Ching']);
    }

    public function test_study_candidacy_is_active_with_a_roughly_ten_month_default_expiry(): void
    {
        $this->artisan('workstation:demo-login')->assertSuccessful();
        $user = User::where('name', 'Chloe Ching')->first();

        $candidacy = StudyCandidacy::where('student_id', $user->id)->first();

        $this->assertNotNull($candidacy);
        $this->assertSame(StudyCandidacy::STATUS_ACTIVE, $candidacy->status);
        $this->assertTrue($candidacy->candidacy_expiry_date->isBetween(now()->addMonths(9), now()->addMonths(11)));
    }

    public function test_expiry_date_option_sets_it_on_create_and_changes_it_on_reset(): void
    {
        $this->artisan('workstation:demo-login', ['--expiry-date' => '2027-03-15'])->assertSuccessful();
        $user = User::where('name', 'Chloe Ching')->first();
        $this->assertSame('2027-03-15', StudyCandidacy::where('student_id', $user->id)->value('candidacy_expiry_date')->toDateString());

        // Resetting WITHOUT the option leaves it untouched.
        $this->artisan('workstation:demo-login')->assertSuccessful();
        $this->assertSame('2027-03-15', StudyCandidacy::where('student_id', $user->id)->value('candidacy_expiry_date')->toDateString());

        // Passing it again on a reset changes it.
        $this->artisan('workstation:demo-login', ['--expiry-date' => '2026-01-01'])->assertSuccessful();
        $this->assertSame('2026-01-01', StudyCandidacy::where('student_id', $user->id)->value('candidacy_expiry_date')->toDateString());
    }

    public function test_an_invalid_expiry_date_fails(): void
    {
        $this->artisan('workstation:demo-login', ['--expiry-date' => 'not-a-date'])->assertFailed();
        $this->assertDatabaseMissing('users', ['name' => 'Chloe Ching']);
    }

    public function test_reset_releases_a_confirmed_seat_and_cancels_a_requested_key_keeping_history(): void
    {
        $this->artisan('workstation:demo-login')->assertSuccessful();
        $user = User::where('name', 'Chloe Ching')->first();

        $room = WorkstationLocation::create(['block' => 'N2', 'room_code' => 'N2-TEST-01', 'gender' => 'female', 'name' => 'N2-TEST-01']);
        $seat = Workstation::create(['workstation_location_id' => $room->id, 'seat_code' => '1', 'position' => 1, 'status' => Workstation::STATUS_OCCUPIED]);
        $request = WorkstationRequest::create([
            'workstation_id' => $seat->id, 'student_id' => $user->id,
            'status' => WorkstationRequest::STATUS_CONFIRMED, 'requested_at' => now(),
        ]);
        LockerKey::create([
            'workstation_request_id' => $request->id, 'student_id' => $user->id,
            'status' => LockerKey::STATUS_REQUESTED, 'requested_at' => now(),
        ]);

        $this->artisan('workstation:demo-login')->assertSuccessful();

        $this->assertSame(Workstation::STATUS_AVAILABLE, $seat->fresh()->status);
        $this->assertSame(WorkstationRequest::STATUS_RELEASED, $request->fresh()->status);
        $this->assertSame(LockerKey::STATUS_CANCELLED, LockerKey::first()->status);
        $this->assertDatabaseHas('workstation_histories', [
            'seat_number' => '1', 'action' => WorkstationHistory::ACTION_SEAT_RELEASED,
            'actor_type' => WorkstationHistory::ACTOR_SYSTEM,
        ]);
        // Never deleted.
        $this->assertGreaterThan(0, WorkstationHistory::count());
    }

    public function test_reset_returns_a_collected_key_so_none_is_left_outstanding(): void
    {
        $this->artisan('workstation:demo-login')->assertSuccessful();
        $user = User::where('name', 'Chloe Ching')->first();

        $room = WorkstationLocation::create(['block' => 'N2', 'room_code' => 'N2-TEST-02', 'gender' => 'female', 'name' => 'N2-TEST-02']);
        $seat = Workstation::create(['workstation_location_id' => $room->id, 'seat_code' => '2', 'position' => 1, 'status' => Workstation::STATUS_OCCUPIED]);
        $request = WorkstationRequest::create([
            'workstation_id' => $seat->id, 'student_id' => $user->id,
            'status' => WorkstationRequest::STATUS_CONFIRMED, 'requested_at' => now(),
        ]);
        LockerKey::create([
            'workstation_request_id' => $request->id, 'student_id' => $user->id,
            'status' => LockerKey::STATUS_COLLECTED, 'requested_at' => now()->subDays(2), 'collected_at' => now()->subDay(),
        ]);

        $this->artisan('workstation:demo-login')->assertSuccessful();

        $this->assertSame(LockerKey::STATUS_RETURNED, LockerKey::first()->status);
        $this->assertFalse(LockerKey::where('student_id', $user->id)->whereIn('status', [
            LockerKey::STATUS_REQUESTED, LockerKey::STATUS_COLLECTED, LockerKey::STATUS_RETURN_PENDING,
        ])->exists());
    }

    public function test_a_fresh_account_has_no_seat_and_no_key(): void
    {
        $this->artisan('workstation:demo-login')->assertSuccessful();
        $user = User::where('name', 'Chloe Ching')->first();

        $this->assertFalse(WorkstationRequest::where('student_id', $user->id)->where('status', WorkstationRequest::STATUS_CONFIRMED)->exists());
        $this->assertFalse(LockerKey::where('student_id', $user->id)->exists());
    }
}
