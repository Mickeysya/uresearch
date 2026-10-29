<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\DemoStudentProfile;
use App\Modules\Chloe\Models\LockerKey;
use App\Modules\Chloe\Models\StudentGender;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationHistory;
use App\Modules\Chloe\Models\WorkstationLocation;
use App\Modules\Chloe\Models\WorkstationRequest;
use App\Modules\Chloe\Models\WorkstationTerminationNotice;
use App\Modules\Chloe\Services\WorkstationAllocator;
use App\Modules\Chloe\Support\StudentDirectory;
use App\Modules\Chloe\Support\WorkstationEligibility;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesUsers;
use Tests\TestCase;

class WorkstationDemoSeedTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    /** Lays out a small catalogue shaped like the real one: some Occupied-untracked, one real tracked, some Available, some Maintenance. */
    protected function layCatalogue(): void
    {
        $maleRoom = WorkstationLocation::create(['block' => 'N2', 'room_code' => 'N2-T-01', 'gender' => 'male', 'name' => 'N2-T-01']);
        $femaleRoom = WorkstationLocation::create(['block' => 'N2', 'room_code' => 'N2-T-02', 'gender' => 'female', 'name' => 'N2-T-02']);

        // 10 occupied-untracked male seats, 6 occupied-untracked female seats.
        for ($i = 1; $i <= 10; $i++) {
            Workstation::create(['workstation_location_id' => $maleRoom->id, 'seat_code' => (string) $i, 'position' => $i, 'status' => Workstation::STATUS_OCCUPIED]);
        }
        for ($i = 1; $i <= 6; $i++) {
            Workstation::create(['workstation_location_id' => $femaleRoom->id, 'seat_code' => (string) (100 + $i), 'position' => $i, 'status' => Workstation::STATUS_OCCUPIED]);
        }

        // One real, tracked seat -- must never be touched.
        $realStudent = $this->student('22001001', 'student@test.my');
        StudentGender::create(['student_id' => $realStudent->id, 'gender' => 'male']);
        $realSeat = Workstation::create(['workstation_location_id' => $maleRoom->id, 'seat_code' => '999', 'position' => 99, 'status' => Workstation::STATUS_OCCUPIED]);
        WorkstationRequest::create([
            'workstation_id' => $realSeat->id, 'student_id' => $realStudent->id,
            'status' => WorkstationRequest::STATUS_CONFIRMED, 'requested_at' => now(),
        ]);

        // A handful of Available seats for the unreturned-key scenarios to pick from.
        for ($i = 1; $i <= 8; $i++) {
            Workstation::create(['workstation_location_id' => $maleRoom->id, 'seat_code' => (string) (200 + $i), 'position' => 200 + $i, 'status' => Workstation::STATUS_AVAILABLE]);
        }

        // A maintenance seat.
        Workstation::create(['workstation_location_id' => $maleRoom->id, 'seat_code' => '300', 'position' => 300, 'status' => Workstation::STATUS_DISABLED]);
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->layCatalogue();
        app()['env'] = 'production';

        try {
            $this->artisan('workstation:demo-seed')->assertFailed();
        } finally {
            app()['env'] = 'testing';
        }

        $this->assertSame(0, DemoStudentProfile::count());
    }

    public function test_every_untracked_occupied_seat_gets_a_demo_student_matching_room_gender(): void
    {
        $this->layCatalogue();

        $this->artisan('workstation:demo-seed')->assertSuccessful();

        $untrackedSeats = Workstation::where('status', Workstation::STATUS_OCCUPIED)
            ->where('seat_code', '!=', '999')
            ->get();

        foreach ($untrackedSeats as $seat) {
            $request = WorkstationRequest::where('workstation_id', $seat->id)
                ->where('status', WorkstationRequest::STATUS_CONFIRMED)
                ->first();

            $this->assertNotNull($request, "seat {$seat->seat_code} has no request");
            $this->assertNotNull($request->demo_profile_id);
            $this->assertNull($request->student_id);

            $profile = DemoStudentProfile::find($request->demo_profile_id);
            $this->assertSame($seat->location->gender, $profile->gender);
            $this->assertSame(DemoStudentProfile::STATUS_ACTIVE, $profile->status);
        }

        // The real tracked seat is completely untouched.
        $this->assertDatabaseHas('workstation_requests', [
            'workstation_id' => Workstation::where('seat_code', '999')->first()->id,
            'student_id' => User::where('email', 'student@test.my')->first()->id,
            'demo_profile_id' => null,
        ]);
    }

    public function test_no_duplicate_names_ids_or_emails(): void
    {
        $this->layCatalogue();
        $this->artisan('workstation:demo-seed')->assertSuccessful();

        $profiles = DemoStudentProfile::all();

        $this->assertSame($profiles->count(), $profiles->pluck('name')->unique()->count());
        $this->assertSame($profiles->count(), $profiles->pluck('matric_no')->unique()->count());
        $this->assertSame($profiles->count(), $profiles->pluck('email')->unique()->count());

        foreach ($profiles as $profile) {
            $this->assertMatchesRegularExpression('/^(2[1-5])01\d{4}$/', $profile->matric_no);
            $firstWord = strtolower(strtok($profile->name, ' '));
            $firstWord = preg_replace('/[^a-z]/', '', $firstWord);
            $this->assertSame("{$firstWord}_{$profile->matric_no}@utp.edu.my", $profile->email);
        }
    }

    public function test_locker_key_distribution_and_dates_are_consistent(): void
    {
        $this->layCatalogue();
        $this->artisan('workstation:demo-seed')->assertSuccessful();

        $keys = LockerKey::whereNotNull('demo_profile_id')->get();

        foreach ($keys as $key) {
            if ($key->status === LockerKey::STATUS_COLLECTED) {
                $this->assertNotNull($key->collected_at);
                $this->assertTrue($key->collected_at->gte($key->requested_at));
                $this->assertTrue($key->collected_at->lte(now()));
                $this->assertTrue($key->requested_at->lte(now()));
            }

            if ($key->status === LockerKey::STATUS_REQUESTED) {
                $this->assertTrue($key->requested_at->gte(now()->subDays(6)->startOfDay()));
                $this->assertTrue($key->requested_at->lte(now()));
            }
        }
    }

    public function test_three_available_seats_get_an_unreturned_key_one_overdue(): void
    {
        $this->layCatalogue();
        $this->artisan('workstation:demo-seed')->assertSuccessful();

        $returnPendingKeys = LockerKey::where('status', LockerKey::STATUS_RETURN_PENDING)->get();
        $this->assertCount(3, $returnPendingKeys);
        $this->assertCount(1, $returnPendingKeys->filter(fn ($k) => $k->isOverdueForReturn()));

        foreach ($returnPendingKeys as $key) {
            $request = $key->workstationRequest;
            $this->assertSame(WorkstationRequest::STATUS_RELEASED, $request->status);
            // PART 1 #8: created directly as Awaiting Key Return, not left
            // Available with an outstanding key -- that combination is no
            // longer a valid state once the status exists.
            $this->assertSame(Workstation::STATUS_AWAITING_KEY_RETURN, $request->workstation->status);
        }
    }

    public function test_seat_status_counts_are_unchanged_except_the_three_awaiting_key_return_scenarios(): void
    {
        $this->layCatalogue();

        $before = [
            'total' => Workstation::count(),
            'offerable' => Workstation::where('status', Workstation::STATUS_AVAILABLE)->count(),
            'occupied' => Workstation::where('status', Workstation::STATUS_OCCUPIED)->count(),
            'disabled' => Workstation::where('status', Workstation::STATUS_DISABLED)->count(),
        ];

        $this->artisan('workstation:demo-seed')->assertSuccessful();

        $after = [
            'total' => Workstation::count(),
            // Available + Awaiting Key Return together, since 3 Available
            // seats are deliberately converted by this very run.
            'offerable' => Workstation::where('status', Workstation::STATUS_AVAILABLE)->count()
                + Workstation::where('status', Workstation::STATUS_AWAITING_KEY_RETURN)->count(),
            'occupied' => Workstation::where('status', Workstation::STATUS_OCCUPIED)->count(),
            'disabled' => Workstation::where('status', Workstation::STATUS_DISABLED)->count(),
        ];

        $this->assertSame($before, $after);
        $this->assertSame(3, Workstation::where('status', Workstation::STATUS_AWAITING_KEY_RETURN)->count());
    }

    public function test_running_it_twice_creates_no_duplicates_and_refreshes_dates(): void
    {
        $this->layCatalogue();

        $this->artisan('workstation:demo-seed')->assertSuccessful();
        $profileCount = DemoStudentProfile::count();
        $requestCount = WorkstationRequest::count();
        $historyCount = WorkstationHistory::count();

        // Travel forward so a refreshed date would visibly differ from a stale one.
        $this->travel(3)->days();

        $this->artisan('workstation:demo-seed')->assertSuccessful();

        $this->assertSame($profileCount, DemoStudentProfile::count());
        $this->assertSame($requestCount, WorkstationRequest::count());
        $this->assertSame($historyCount, WorkstationHistory::count());

        // Every Pending Collection key is still "within the last 6 days" of
        // the NEW now(), i.e. it was recomputed, not left stale.
        $pending = LockerKey::where('status', LockerKey::STATUS_REQUESTED)->whereNotNull('demo_profile_id')->get();
        foreach ($pending as $key) {
            $this->assertTrue($key->requested_at->gte(now()->subDays(6)->startOfDay()));
        }
    }

    /** PART 5 #1: no Part-Time or Coursework programme anywhere in the list, or on any seeded profile. */
    public function test_every_demo_profile_gets_a_full_time_research_programme(): void
    {
        $this->layCatalogue();
        $this->artisan('workstation:demo-seed')->assertSuccessful();

        $this->assertGreaterThan(0, DemoStudentProfile::count());

        DemoStudentProfile::all()->each(function (DemoStudentProfile $profile) {
            $programme = mb_strtolower((string) $profile->programme);
            $this->assertStringNotContainsString('part-time', $programme, "{$profile->name}'s programme is Part-Time: {$profile->programme}");
            $this->assertStringNotContainsString('coursework', $programme, "{$profile->name}'s programme is Coursework: {$profile->programme}");
        });
    }

    /** PART 5 #1: an old Part-Time profile from before this change is corrected in place on the next run. */
    public function test_an_existing_part_time_demo_profile_is_corrected_on_rerun(): void
    {
        $this->layCatalogue();
        $this->artisan('workstation:demo-seed')->assertSuccessful();

        $profile = DemoStudentProfile::first();
        $profile->update(['programme' => 'MSc Information Technology (Part-Time)', 'candidacy_expiry_date' => now()->addYear()]);

        $this->artisan('workstation:demo-seed')->assertSuccessful();

        $corrected = mb_strtolower((string) $profile->fresh()->programme);
        $this->assertStringNotContainsString('part-time', $corrected);
    }

    /** PART 5 #2: every seeded demo profile passes WorkstationEligibility. */
    public function test_every_demo_profile_is_eligible(): void
    {
        $this->layCatalogue();
        $this->artisan('workstation:demo-seed')->assertSuccessful();

        DemoStudentProfile::all()->each(function (DemoStudentProfile $profile) {
            $identity = StudentDirectory::fromDemoProfile($profile);
            $this->assertTrue(
                WorkstationEligibility::isEligible($identity),
                "{$profile->name} is not eligible (status={$profile->status}, programme={$profile->programme}, expiry={$profile->candidacy_expiry_date})."
            );
        });
    }

    /** PART 5 #3: 3 currently-Occupied demo seats get a termination notice, with the Occupied total unchanged. */
    public function test_three_occupied_demo_seats_get_a_termination_notice(): void
    {
        $this->layCatalogue();
        $occupiedBefore = Workstation::where('status', Workstation::STATUS_OCCUPIED)->count();

        $this->artisan('workstation:demo-seed')->assertSuccessful();

        $notices = WorkstationTerminationNotice::where('status', WorkstationTerminationNotice::STATUS_ACTIVE)->get();
        $this->assertCount(3, $notices);

        $expectedReasons = [
            WorkstationAllocator::REASON_TERMINATION_DEFERMENT,
            WorkstationAllocator::REASON_TERMINATION_WITHDRAWN,
            WorkstationAllocator::REASON_TERMINATION_DISCIPLINARY,
        ];
        $this->assertSame(collect($expectedReasons)->sort()->values()->all(), $notices->pluck('reason')->sort()->values()->all());

        foreach ($notices as $notice) {
            $this->assertSame(Workstation::STATUS_OCCUPIED, $notice->workstationRequest->workstation->status);
            $this->assertNotNull($notice->workstationRequest->demo_profile_id);
        }

        $this->assertSame($occupiedBefore, Workstation::where('status', Workstation::STATUS_OCCUPIED)->count());
    }

    /** PART 5 #3: the daily job skips demo students, so demo-seed itself refreshes the dates on rerun. */
    public function test_termination_notice_dates_are_refreshed_on_rerun_not_duplicated(): void
    {
        $this->layCatalogue();
        $this->artisan('workstation:demo-seed')->assertSuccessful();

        $noticeCountBefore = WorkstationTerminationNotice::count();
        $firstVacateBy = WorkstationTerminationNotice::where(
            'reason', WorkstationAllocator::REASON_TERMINATION_DEFERMENT
        )->first()->vacate_by_date;

        $this->travel(2)->days();
        $this->artisan('workstation:demo-seed')->assertSuccessful();

        $this->assertSame($noticeCountBefore, WorkstationTerminationNotice::count());
        $refreshedVacateBy = WorkstationTerminationNotice::where(
            'reason', WorkstationAllocator::REASON_TERMINATION_DEFERMENT
        )->first()->vacate_by_date;
        $this->assertFalse($firstVacateBy->isSameDay($refreshedVacateBy));
    }

    public function test_demo_clear_removes_only_demo_data_and_restores_seats(): void
    {
        $this->layCatalogue();
        $this->artisan('workstation:demo-seed')->assertSuccessful();

        $realStudentId = User::where('email', 'student@test.my')->first()->id;
        $historyBeforeClear = WorkstationHistory::count();
        $this->assertGreaterThan(0, $historyBeforeClear);

        $this->artisan('workstation:demo-clear')->assertSuccessful();

        $this->assertSame(0, DemoStudentProfile::count());
        $this->assertSame(0, WorkstationRequest::whereNotNull('demo_profile_id')->count());
        $this->assertSame(0, LockerKey::whereNotNull('demo_profile_id')->count());

        // Every seat that had a demo occupant is Available again.
        $stillOccupied = Workstation::where('status', Workstation::STATUS_OCCUPIED)->get();
        $this->assertCount(1, $stillOccupied);
        $this->assertSame('999', $stillOccupied->first()->seat_code);

        // The real account's request, History and gender are untouched.
        $this->assertDatabaseHas('workstation_requests', ['student_id' => $realStudentId, 'status' => WorkstationRequest::STATUS_CONFIRMED]);
        $this->assertSame('male', StudentGender::where('student_id', $realStudentId)->value('gender'));
    }

    public function test_demo_clear_refuses_to_run_in_production(): void
    {
        $this->layCatalogue();
        $this->artisan('workstation:demo-seed')->assertSuccessful();

        app()['env'] = 'production';
        try {
            $this->artisan('workstation:demo-clear')->assertFailed();
        } finally {
            app()['env'] = 'testing';
        }

        $this->assertGreaterThan(0, DemoStudentProfile::count());
    }

    public function test_demo_clear_with_nothing_to_clear_succeeds_quietly(): void
    {
        $this->artisan('workstation:demo-clear')->assertSuccessful();
    }

    public function test_the_daily_release_job_skips_every_demo_student(): void
    {
        $this->layCatalogue();
        $this->artisan('workstation:demo-seed')->assertSuccessful();

        $before = WorkstationRequest::whereNotNull('demo_profile_id')->where('status', WorkstationRequest::STATUS_CONFIRMED)->count();

        $this->artisan('workstation:process-releases')->assertSuccessful();

        // Nothing was scheduled, noticed or released -- demo students have
        // no StudyCandidacy row at all, so the job's own `! $request->student`
        // guard (student_id is null for a demo row) returns immediately.
        $this->assertSame($before, WorkstationRequest::whereNotNull('demo_profile_id')->where('status', WorkstationRequest::STATUS_CONFIRMED)->count());
    }

    public function test_the_locker_key_reminder_job_skips_every_demo_student(): void
    {
        $this->layCatalogue();
        $this->artisan('workstation:demo-seed')->assertSuccessful();

        $pendingBefore = LockerKey::where('status', LockerKey::STATUS_REQUESTED)->whereNotNull('demo_profile_id')->pluck('id');
        $this->assertNotEmpty($pendingBefore);

        $this->artisan('workstation:remind-locker-keys')->assertSuccessful();

        // Still exactly the same rows, same status -- processIfOverdue()'s
        // own `! $this->student` guard means a demo key is never reminded
        // or auto-cancelled.
        $this->assertSame(
            $pendingBefore->sort()->values()->all(),
            LockerKey::where('status', LockerKey::STATUS_REQUESTED)->whereNotNull('demo_profile_id')->pluck('id')->sort()->values()->all(),
        );
    }
}
