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
use App\Modules\Chloe\Support\CandidacyDepartmentResolver;
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

        // Final polish (demo-seed idempotency fix): the 3 unreturned-key
        // scenario profiles carry a fixed, reserved matric number
        // (WorkstationDemoSeed::UNRETURNED_KEY_SCENARIOS) instead of one
        // from nextStudentId()'s sequential YY01NNNN space -- that fixed
        // identity is the whole idempotency mechanism (see that
        // constant's own doc), so it deliberately does not match the
        // regular pattern.
        $reservedMatricNos = array_column(\App\Modules\Chloe\Console\Commands\WorkstationDemoSeed::UNRETURNED_KEY_SCENARIOS, 'matric_no');

        foreach ($profiles as $profile) {
            if (in_array($profile->matric_no, $reservedMatricNos, true)) {
                $this->assertMatchesRegularExpression('/^\d{8}$/', $profile->matric_no);
            } else {
                $this->assertMatchesRegularExpression('/^(2[1-5])01\d{4}$/', $profile->matric_no);
            }
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

    /**
     * Found live: a re-run used to pick its 3 unreturned-key scenario
     * seats by computing an INDEX into a freshly-recomputed
     * Available-or-Awaiting pool -- stable only if nothing else has
     * touched any seat's status since the last run. Any real CGS action
     * between two runs (here: a plain CGS seat release, the same kind of
     * action a normal testing session performs constantly) changes that
     * pool's size, so the same index landed on a DIFFERENT seat -- the
     * old scenario seat was abandoned (left sitting in Awaiting Key
     * Return forever) and a brand new one created, which is exactly why
     * the Awaiting Key Return count grew by 3 on every re-run instead of
     * staying at 3. This test reproduces that exact failure condition and
     * proves the fixed (fixed-matric-number-anchored) version survives it:
     * same seats, same counts, both before and after the churn.
     */
    public function test_running_it_twice_with_unrelated_seat_churn_in_between_reuses_the_same_scenario_seats(): void
    {
        $this->layCatalogue();
        $this->artisan('workstation:demo-seed')->assertSuccessful();

        $scenarioSeatIdsBefore = Workstation::where('status', Workstation::STATUS_AWAITING_KEY_RETURN)->pluck('id')->sort()->values();
        $this->assertCount(3, $scenarioSeatIdsBefore);

        // Unrelated churn: new Available seats appear between the two runs
        // (the same kind of thing that happens constantly in real use --
        // new catalogue rows, or other seats cycling through Available) --
        // enough on its own to change the OLD code's pool size/order and
        // reproduce the bug, with no side effect on any existing seat's
        // own status.
        $extraRoom = WorkstationLocation::create(['block' => 'N2', 'room_code' => 'N2-T-03', 'gender' => 'male', 'name' => 'N2-T-03']);
        for ($i = 1; $i <= 5; $i++) {
            Workstation::create(['workstation_location_id' => $extraRoom->id, 'seat_code' => (string) (400 + $i), 'position' => 400 + $i, 'status' => Workstation::STATUS_AVAILABLE]);
        }

        $this->artisan('workstation:demo-seed')->assertSuccessful();

        $scenarioSeatIdsAfter = Workstation::where('status', Workstation::STATUS_AWAITING_KEY_RETURN)->pluck('id')->sort()->values();
        $this->assertSame($scenarioSeatIdsBefore->all(), $scenarioSeatIdsAfter->all(), 'The same 3 seats must hold the unreturned-key scenario after unrelated seat churn, not 3 new ones alongside the old.');
        $this->assertCount(3, $scenarioSeatIdsAfter);
    }

    /**
     * The literal requirement: run twice (with the same kind of unrelated
     * churn in between), and every demo table's row count plus the
     * identity of every scenario seat (both unreturned-key and
     * termination-notice) must be exactly identical.
     */
    public function test_running_it_twice_with_churn_leaves_every_demo_table_row_count_and_every_scenario_seat_identical(): void
    {
        $this->layCatalogue();
        $this->artisan('workstation:demo-seed')->assertSuccessful();

        $before = [
            'totals' => [
                'total' => Workstation::count(),
                'available' => Workstation::where('status', Workstation::STATUS_AVAILABLE)->count(),
                'occupied' => Workstation::where('status', Workstation::STATUS_OCCUPIED)->count(),
                'awaiting_key_return' => Workstation::where('status', Workstation::STATUS_AWAITING_KEY_RETURN)->count(),
                'disabled' => Workstation::where('status', Workstation::STATUS_DISABLED)->count(),
            ],
            'profiles' => DemoStudentProfile::count(),
            'requests' => WorkstationRequest::whereNotNull('demo_profile_id')->count(),
            'keys' => LockerKey::whereNotNull('demo_profile_id')->count(),
            'notices' => WorkstationTerminationNotice::where('status', WorkstationTerminationNotice::STATUS_ACTIVE)->count(),
            'history' => WorkstationHistory::count(),
            'unreturnedKeySeats' => Workstation::where('status', Workstation::STATUS_AWAITING_KEY_RETURN)->pluck('id')->sort()->values()->all(),
            'terminationSeats' => WorkstationTerminationNotice::where('status', WorkstationTerminationNotice::STATUS_ACTIVE)->with('workstationRequest')->get()->pluck('workstationRequest.workstation_id')->sort()->values()->all(),
        ];

        // Unrelated churn between the two runs.
        $extraRoom = WorkstationLocation::create(['block' => 'N2', 'room_code' => 'N2-T-04', 'gender' => 'female', 'name' => 'N2-T-04']);
        Workstation::create(['workstation_location_id' => $extraRoom->id, 'seat_code' => '500', 'position' => 500, 'status' => Workstation::STATUS_AVAILABLE]);
        $this->travel(2)->days();

        $this->artisan('workstation:demo-seed')->assertSuccessful();

        $after = [
            'totals' => [
                'total' => Workstation::count(),
                'available' => Workstation::where('status', Workstation::STATUS_AVAILABLE)->count(),
                'occupied' => Workstation::where('status', Workstation::STATUS_OCCUPIED)->count(),
                'awaiting_key_return' => Workstation::where('status', Workstation::STATUS_AWAITING_KEY_RETURN)->count(),
                'disabled' => Workstation::where('status', Workstation::STATUS_DISABLED)->count(),
            ],
            'profiles' => DemoStudentProfile::count(),
            'requests' => WorkstationRequest::whereNotNull('demo_profile_id')->count(),
            'keys' => LockerKey::whereNotNull('demo_profile_id')->count(),
            'notices' => WorkstationTerminationNotice::where('status', WorkstationTerminationNotice::STATUS_ACTIVE)->count(),
            'history' => WorkstationHistory::count(),
            'unreturnedKeySeats' => Workstation::where('status', Workstation::STATUS_AWAITING_KEY_RETURN)->pluck('id')->sort()->values()->all(),
            'terminationSeats' => WorkstationTerminationNotice::where('status', WorkstationTerminationNotice::STATUS_ACTIVE)->with('workstationRequest')->get()->pluck('workstationRequest.workstation_id')->sort()->values()->all(),
        ];

        // totals.total and totals.available differ by exactly the 1 new
        // seat the churn itself added -- everything else, including every
        // scenario seat identity, must be byte-for-byte identical.
        $after['totals']['total'] -= 1;
        $after['totals']['available'] -= 1;
        $this->assertSame($before, $after);
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

    /** Programme realignment: demo text is a catalogue name + "(Full-Time)"; the parsed study category (and so eligibility) of every demo student stays full-time research. */
    public function test_every_demo_profile_has_a_catalogue_programme_and_stays_full_time_research(): void
    {
        $this->layCatalogue();
        $this->artisan('workstation:demo-seed')->assertSuccessful();

        $levels = ['master_research', 'phd'];
        $departments = [];

        DemoStudentProfile::all()->each(function (DemoStudentProfile $profile) use ($levels, &$departments) {
            $this->assertMatchesRegularExpression('/ \(Full-Time\)$/', $profile->programme);
            $entry = CandidacyDepartmentResolver::catalogueEntry($profile->programme);
            $this->assertNotNull($entry, "Not in the catalogue: {$profile->programme}");
            $this->assertContains($entry['level'], $levels, 'No demo student is coursework');
            $this->assertSame(
                $entry['level'] === 'phd',
                str_starts_with($profile->programme, 'PhD') || str_starts_with($profile->programme, 'Doctor'),
            );

            $text = mb_strtolower($profile->programme);
            $this->assertFalse(str_contains($text, 'part-time') || str_contains($text, 'coursework'));
            $this->assertTrue(WorkstationEligibility::isEligible(StudentDirectory::fromDemoProfile($profile)));
            $departments[$entry['department']] = true;
        });

        $this->assertGreaterThan(1, count($departments));
    }

    /** Re-seeding never moves a student to another programme: the text is a pure function of the profile id. */
    public function test_programme_text_is_stable_across_reruns_and_expiry_is_untouched_by_a_relabel(): void
    {
        $this->layCatalogue();
        $this->artisan('workstation:demo-seed')->assertSuccessful();
        $before = DemoStudentProfile::orderBy('id')->get(['id', 'programme', 'candidacy_expiry_date'])->map(fn ($p) => [$p->id, $p->programme, (string) $p->candidacy_expiry_date])->all();

        $first = DemoStudentProfile::orderBy('id')->first();
        $first->update(['programme' => 'MSc Computer Science (Full-Time)']);
        $this->artisan('workstation:demo-seed')->assertSuccessful();

        $after = DemoStudentProfile::orderBy('id')->get(['id', 'programme', 'candidacy_expiry_date'])->map(fn ($p) => [$p->id, $p->programme, (string) $p->candidacy_expiry_date])->all();
        $this->assertSame($before, $after);
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
        // A Monday: two days later is a Wednesday, so the two working-day dates differ (on a Friday both roll to the same Monday).
        \Illuminate\Support\Carbon::setTestNow('2026-10-12 10:00:00');
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
