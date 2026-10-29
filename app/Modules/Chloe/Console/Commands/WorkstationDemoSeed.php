<?php

namespace App\Modules\Chloe\Console\Commands;

use App\Modules\Chloe\Models\DemoStudentProfile;
use App\Modules\Chloe\Models\LockerKey;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationHistory;
use App\Modules\Chloe\Models\WorkstationRequest;
use App\Modules\Chloe\Models\WorkstationTerminationNotice;
use App\Modules\Chloe\Services\WorkstationAllocator;
use App\Modules\Chloe\Support\StudentDirectory;
use App\Modules\Chloe\Support\WorkingDays;
use App\Modules\Core\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Gives every seat the PDF seeded as Occupied-with-no-tracked-student a
 * realistic demo occupant, so the CGS screens show a name instead of "No
 * student record" everywhere, plus three Available seats with an
 * unreturned key for the Key Return list and stats bar to have something
 * to show. Never touches a seat that already belongs to a real login
 * (student@utp.edu.my, student2@utp.edu.my, Chloe Ching) -- those are
 * found by having a tracked WorkstationRequest at all, demo or not.
 *
 * Idempotent and deterministic: a fixed seed (self::SEED) plus per-seat
 * seeding (`mt_srand(self::SEED + $seat->id)`) means which seat gets which
 * key-status "bucket" is a pure function of the seat's own id, not of
 * iteration order or how many times this has already run. A demo profile,
 * once created, is found again by the demo_profile_id already on its
 * WorkstationRequest and is never recreated -- only its locker key's dates
 * get refreshed, relative to "now" at the moment this runs, so the data
 * keeps looking current no matter when someone re-runs it.
 */
class WorkstationDemoSeed extends Command
{
    protected $signature = 'workstation:demo-seed';

    protected $description = 'Give every untracked Occupied seat a realistic demo student, and seed three unreturned-key Available seats, for CGS screen testing';

    /** Fixed, not time-based -- see the class doc for why. */
    protected const SEED = 260126;

    /**
     * PART 5 #1: full-time research only -- Part-Time and Coursework were
     * removed entirely, not just deprioritised, since
     * WorkstationEligibility::isFullTimeResearch() excludes both and every
     * demo student must be eligible under PART 1.
     */
    protected const PROGRAMMES = [
        'MSc Computer Science (Full-Time)', 'MSc Information Technology (Full-Time)',
        'PhD Electrical & Electronic Engineering (Full-Time)', 'PhD Chemical Engineering (Full-Time)',
        'MSc Petroleum Engineering (Full-Time)', 'PhD Civil Engineering (Full-Time)',
        'MSc Mechanical Engineering (Full-Time)', 'PhD Computer & Information Sciences (Full-Time)',
    ];

    protected const MALAY_MALE_FIRST = ['Ahmad', 'Muhammad Haziq', 'Mohd Firdaus', 'Amir Hakim', 'Hafiz', 'Zulkifli', 'Faizal', 'Danial', 'Syafiq', 'Fakhrul', 'Azizul', 'Shahrul', 'Nabil', 'Afiq', 'Hakimi'];

    protected const MALAY_FEMALE_FIRST = ['Nur Aina', 'Siti Khadijah', 'Nurul Iman', 'Farah Adilah', 'Aisyah', 'Nadia', 'Fatimah', 'Zulaikha', 'Intan', 'Humaira', 'Aina Batrisyia', 'Sofea', 'Alya', 'Qistina', 'Izzati'];

    protected const MALAY_FATHER = ['Abdullah', 'Ismail', 'Yusof', 'Rahman', 'Hassan', 'Zainal', 'Mahmud', 'Kassim', 'Osman', 'Rashid'];

    protected const CHINESE_SURNAME = ['Tan', 'Lim', 'Lee', 'Wong', 'Ng', 'Ong', 'Chong', 'Chan', 'Goh', 'Yap', 'Teh', 'Loh', 'Yeoh', 'Chin', 'Koh'];

    protected const CHINESE_MALE_GIVEN = ['Wei Jian', 'Jun Hao', 'Kai Ming', 'Zhi Hao', 'Jia Hao', 'Chee Kiat', 'Kang Wei', 'Jun Wei', 'Yong Zhi', 'Wei Ming'];

    protected const CHINESE_FEMALE_GIVEN = ['Xin Yi', 'Mei Xuan', 'Hui Ling', 'Jia Ying', 'Wan Ting', 'Yi Xuan', 'Shu Hui', 'Li Wen', 'Pei Shan', 'Ern Yee'];

    protected const INDIAN_MALE_FIRST = ['Ravindran', 'Suresh', 'Vikram', 'Arun', 'Prakash', 'Dinesh', 'Senthil', 'Raja', 'Mohan', 'Kumaresan', 'Balamurugan', 'Nathan', 'Siva', 'Gopal', 'Mahesh'];

    protected const INDIAN_FEMALE_FIRST = ['Divya Sri', 'Priya', 'Kavitha', 'Shalini', 'Meera', 'Vani', 'Deepa', 'Nisha', 'Yashoda', 'Latha'];

    protected const INDIAN_FATHER = ['Subramaniam', 'Krishnan', 'Muniandy', 'Raju', 'Gunasegaran', 'Pillai', 'Naidu', 'Rajan', 'Veloo', 'Samy'];

    protected const INTERNATIONAL_MALE = ['James Anderson', 'Michael Carter', 'David Thompson', 'Daniel Robertson', 'Thomas Wright', 'Benjamin Clarke', 'Christopher Hale', 'Matthew Reed', 'Joseph Collins', 'Samuel Harper'];

    protected const INTERNATIONAL_FEMALE = ['Sarah Mitchell', 'Emily Clarke', 'Jessica Turner', 'Laura Bennett', 'Rachel Foster', 'Hannah Walsh', 'Victoria Grant', 'Natalie Shaw', 'Olivia Brooks', 'Megan Cross'];

    /** Set within one run, to avoid two seats in the same run landing on the same perturbed name. */
    protected array $namesUsedThisRun = [];

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('workstation:demo-seed refuses to run in production.');

            return self::FAILURE;
        }

        $created = 0;
        $refreshed = 0;
        $corrected = 0;

        try {
            DB::transaction(function () use (&$created, &$refreshed, &$corrected) {
                // PART 5 #1: fixes any demo profile seeded by an older run
                // with a Part-Time/Coursework programme, or a past/missing
                // expiry -- a re-run is how this corrects itself, per the
                // brief ("update the existing demo profiles in-place").
                $corrected = $this->correctExistingDemoProfilesForEligibility();

                // Every Occupied seat, not just the still-untracked ones --
                // a seat this command already gave a demo profile to on an
                // earlier run DOES have an activeRequest now (its own), and
                // still needs to be found here so its locker key's dates
                // get refreshed, not skipped forever.
                //
                // The lookup below is a plain query per seat, not the
                // eager-loaded activeRequest relation -- a seat carrying
                // an old, unrelated non-confirmed request with a LATER
                // requested_at than its real confirmed one (a leftover
                // from a force-release path that never attached a
                // request) makes Workstation::activeRequest()'s
                // latestOfMany() aggregate pick the wrong row's timestamp
                // and come back null, which this command found the hard
                // way live: it then "helpfully" seeded a second demo
                // profile onto an already-seeded seat. A direct,
                // status-scoped query per seat has no such ambiguity.
                Workstation::where('status', Workstation::STATUS_OCCUPIED)
                    ->with('location')
                    ->orderBy('id')
                    ->get()
                    ->each(function (Workstation $seat) use (&$created, &$refreshed) {
                        $request = WorkstationRequest::where('workstation_id', $seat->id)
                            ->where('status', WorkstationRequest::STATUS_CONFIRMED)
                            ->latest('requested_at')
                            ->first();

                        if ($request && $request->student_id) {
                            // A real login's seat -- never touched.
                            return;
                        }

                        if ($request && $request->demo_profile_id) {
                            $this->refreshLockerKey($seat, $request);
                            $refreshed++;

                            return;
                        }

                        $this->seedNewOccupant($seat);
                        $created++;
                    });

                $this->seedUnreturnedKeySeats();

                // PART 5 #3: 3 currently-Occupied demo seats, in a
                // termination notice period -- taken FROM the Occupied
                // pool above, so the Occupied total is unchanged.
                $this->seedTerminationNoticeSeats();

                // Inside the transaction -- a mismatch rolls everything
                // this run just did back out, rather than reporting it
                // after the bad rows are already committed.
                $this->verifyGenderMatches();
            });
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->verifySeatCounts();

        $this->info("Demo students created: {$created}. Already-seeded seats refreshed: {$refreshed}. Existing profiles corrected for eligibility: {$corrected}.");

        return self::SUCCESS;
    }

    /**
     * PART 5 #1/#2: every existing demo profile, forced back onto a
     * full-time research programme, Active status, and a future candidacy
     * expiry spread over roughly the next 24 months -- idempotent per
     * profile id, so a profile already correct is left untouched (and not
     * recounted) on the next run.
     */
    protected function correctExistingDemoProfilesForEligibility(): int
    {
        $corrected = 0;

        DemoStudentProfile::all()->each(function (DemoStudentProfile $profile) use (&$corrected) {
            $programme = $this->pickFrom(self::PROGRAMMES, $profile->id, 'programme-correction');
            $expiry = now()->addMonths($this->seededRand($profile->id, 'expiry-correction', 3, 24))->toDateString();

            $needsUpdate = $profile->programme !== $programme
                || $profile->status !== DemoStudentProfile::STATUS_ACTIVE
                || ! $profile->candidacy_expiry_date
                || $profile->candidacy_expiry_date->isPast();

            if (! $needsUpdate) {
                return;
            }

            $profile->update([
                'programme' => $programme,
                'status' => DemoStudentProfile::STATUS_ACTIVE,
                'candidacy_expiry_date' => $expiry,
            ]);

            $corrected++;
        });

        return $corrected;
    }

    /**
     * PART 5 #3: picks 3 distinct currently-Occupied demo seats (never a
     * real student's -- demo_profile_id is required) and starts a
     * termination notice directly, the same way seedUnreturnedKeySeats()
     * writes its scenario directly rather than through
     * WorkstationAllocator -- this is backdated demo state, not a live
     * action. Found again by its own reason on a re-run (the 3 reasons
     * below are unique among themselves), so the same seat keeps the same
     * notice and only its dates are refreshed -- the daily job skips demo
     * students entirely, so nothing else ever would.
     */
    protected function seedTerminationNoticeSeats(): void
    {
        $scenarios = [
            ['reason' => WorkstationAllocator::REASON_TERMINATION_DEFERMENT, 'working_days' => 1, 'seed' => 95000],
            ['reason' => WorkstationAllocator::REASON_TERMINATION_WITHDRAWN, 'working_days' => 3, 'seed' => 95001],
            ['reason' => WorkstationAllocator::REASON_TERMINATION_DISCIPLINARY, 'working_days' => 5, 'seed' => 95002],
        ];

        $today = now('Asia/Kuala_Lumpur')->startOfDay();

        foreach ($scenarios as $scenario) {
            $existingNotice = WorkstationTerminationNotice::where('reason', $scenario['reason'])
                ->where('status', WorkstationTerminationNotice::STATUS_ACTIVE)
                ->whereHas('workstationRequest', fn ($q) => $q->whereNotNull('demo_profile_id'))
                ->first();

            $vacateBy = WorkingDays::add($today, $scenario['working_days']);

            if ($existingNotice) {
                $existingNotice->update(['notice_date' => $today, 'vacate_by_date' => $vacateBy]);

                continue;
            }

            $takenRequestIds = WorkstationTerminationNotice::where('status', WorkstationTerminationNotice::STATUS_ACTIVE)
                ->pluck('workstation_request_id');

            $candidates = WorkstationRequest::where('status', WorkstationRequest::STATUS_CONFIRMED)
                ->whereNotNull('demo_profile_id')
                ->whereNotIn('id', $takenRequestIds)
                ->with('workstation')
                ->orderBy('id')
                ->get();

            if ($candidates->isEmpty()) {
                continue;
            }

            $index = $this->seededRand($scenario['seed'], 'termination-pick', 0, $candidates->count() - 1);
            $request = $candidates->get($index);

            WorkstationTerminationNotice::create([
                'workstation_request_id' => $request->id,
                'reason' => $scenario['reason'],
                'notice_date' => $today,
                'vacate_by_date' => $vacateBy,
                'status' => WorkstationTerminationNotice::STATUS_ACTIVE,
            ]);

            WorkstationHistory::record(
                seat: $request->workstation,
                actorType: WorkstationHistory::ACTOR_SYSTEM,
                actorName: 'System',
                action: WorkstationHistory::ACTION_TERMINATION_NOTICE_ISSUED,
                reason: $scenario['reason'],
                remark: 'Vacate by '.$vacateBy->format('j M Y'),
                student: $request->occupant()?->asUserLike(),
            );
        }
    }

    protected function seedNewOccupant(Workstation $seat): void
    {
        $gender = $seat->location->gender;
        $name = $this->generateName($gender, $seat->id);
        $matricNo = $this->nextStudentId();

        $profile = DemoStudentProfile::create([
            'matric_no' => $matricNo,
            'name' => $name,
            'email' => $this->emailFor($name, $matricNo),
            'gender' => $gender,
            'programme' => $this->pickFrom(self::PROGRAMMES, $seat->id, 'programme'),
            'programme_start_date' => now()->subMonths($this->seededRand($seat->id, 'start', 6, 18)),
            // PART 5 #2: spread over roughly the next 24 months, not a
            // single fixed date for every demo student.
            'candidacy_expiry_date' => now()->addMonths($this->seededRand($seat->id, 'expiry', 3, 24)),
            'status' => DemoStudentProfile::STATUS_ACTIVE,
        ]);

        $allocatedAt = now()->subMonths($this->seededRand($seat->id, 'alloc', 6, 18));

        $request = WorkstationRequest::create([
            'workstation_id' => $seat->id,
            'demo_profile_id' => $profile->id,
            'status' => WorkstationRequest::STATUS_CONFIRMED,
            'requested_at' => $allocatedAt,
        ]);

        WorkstationHistory::record(
            seat: $seat,
            actorType: WorkstationHistory::ACTOR_SYSTEM,
            actorName: 'System',
            action: WorkstationHistory::ACTION_SEAT_CONFIRMED,
            beforeState: Workstation::STATUS_AVAILABLE,
            afterState: Workstation::STATUS_OCCUPIED,
            student: StudentDirectory::fromDemoProfile($profile)->asUserLike(),
        );

        $this->refreshLockerKey($seat, $request);
    }

    /**
     * The bucket (none/collected/pending-collection) is a pure function of
     * the seat's own id, so it never changes between runs -- only the
     * dates inside it do, recomputed relative to "now" each time this is
     * called, which is the whole "refreshes... so it looks current" rule.
     */
    protected function refreshLockerKey(Workstation $seat, WorkstationRequest $request): void
    {
        $bucket = $this->seededRand($seat->id, 'key-bucket', 1, 100);

        // 1-60: Not Requested -- no row at all, same as a real student who
        // never asked for one.
        if ($bucket <= 60) {
            return;
        }

        $existing = LockerKey::where('workstation_request_id', $request->id)->latest('requested_at')->first();

        // 61-85 (25%): Collected.
        if ($bucket <= 85) {
            $requestedDaysAgo = $this->seededRand($seat->id, 'collected-requested', 7, 30);
            $collectedDaysAgo = $this->seededRand($seat->id, 'collected-collected', 1, $requestedDaysAgo - 1);

            $attributes = [
                'workstation_request_id' => $request->id,
                'demo_profile_id' => $request->demo_profile_id,
                'status' => LockerKey::STATUS_COLLECTED,
                'requested_at' => now()->subDays($requestedDaysAgo),
                'collected_at' => now()->subDays($collectedDaysAgo),
            ];

            $existing ? $existing->update($attributes) : LockerKey::create($attributes);

            return;
        }

        // 86-100 (15%): Pending Collection, requested within the last 6 days.
        $attributes = [
            'workstation_request_id' => $request->id,
            'demo_profile_id' => $request->demo_profile_id,
            'status' => LockerKey::STATUS_REQUESTED,
            'requested_at' => now()->subDays($this->seededRand($seat->id, 'pending-requested', 0, 6)),
            'collected_at' => null,
        ];

        $existing ? $existing->update($attributes) : LockerKey::create($attributes);
    }

    /**
     * Point 8: three currently-Available seats get a former demo occupant
     * whose locker key is Return Pending -- one not yet due, one due in
     * about 2 days, one already overdue. The seat itself is never changed
     * (status stays Available, so the PDF's Available count is untouched);
     * only a RELEASED request + a Return Pending key exist behind it.
     */
    protected function seedUnreturnedKeySeats(): void
    {
        $scenarios = [
            ['tag' => 'not-due', 'days' => 10],
            ['tag' => 'due-soon', 'days' => 2],
            ['tag' => 'overdue', 'days' => -5],
        ];

        // The candidate pool for picking one of these 3 scenarios is
        // genuinely-Available seats PLUS whichever seats a PRIOR run of
        // this same method already put into Awaiting Key Return -- found
        // by their own demo-linked request, never a real one -- so the
        // same seat is found again (and only refreshed) on every re-run,
        // rather than a fresh pick landing on a different seat each time.
        $seats = Workstation::where(function ($query) {
            $query->where('status', Workstation::STATUS_AVAILABLE)
                ->orWhere(function ($awaiting) {
                    $awaiting->where('status', Workstation::STATUS_AWAITING_KEY_RETURN)
                        ->whereHas('requests', fn ($r) => $r->whereNotNull('demo_profile_id'));
                });
        })
            ->with('location')
            ->orderBy('id')
            ->get();

        foreach ($scenarios as $i => $scenario) {
            $seat = $seats->get($this->seededRand(90000 + $i, 'unreturned-pick', 0, max($seats->count() - 1, 0)));

            if (! $seat) {
                continue;
            }

            $existingRequest = WorkstationRequest::where('workstation_id', $seat->id)
                ->where('status', WorkstationRequest::STATUS_RELEASED)
                ->whereNotNull('demo_profile_id')
                ->latest('requested_at')
                ->first();

            $returnDueAt = now()->addDays($scenario['days']);

            if ($existingRequest) {
                LockerKey::where('workstation_request_id', $existingRequest->id)
                    ->latest('requested_at')
                    ->first()
                    ?->update(['return_due_at' => $returnDueAt]);

                // Idempotent against the seat's own status too -- a prior
                // run (or the one-time data migration) should already have
                // set this, but a run that finds the request without it
                // still corrects it rather than leaving the seat offerable.
                if ($seat->status !== Workstation::STATUS_AWAITING_KEY_RETURN) {
                    $seat->update(['status' => Workstation::STATUS_AWAITING_KEY_RETURN]);
                }

                continue;
            }

            $gender = $seat->location->gender;
            $name = $this->generateName($gender, 80000 + $i);
            $matricNo = $this->nextStudentId();

            $profile = DemoStudentProfile::create([
                'matric_no' => $matricNo,
                'name' => $name,
                'email' => $this->emailFor($name, $matricNo),
                'gender' => $gender,
                'programme' => $this->pickFrom(self::PROGRAMMES, 80000 + $i, 'programme'),
                'programme_start_date' => now()->subMonths(14),
                'candidacy_expiry_date' => now()->addMonths(10),
                'status' => DemoStudentProfile::STATUS_ACTIVE,
            ]);

            $requestedAt = now()->subMonths(10);
            $releasedAt = now()->subDays(7);

            $request = WorkstationRequest::create([
                'workstation_id' => $seat->id,
                'demo_profile_id' => $profile->id,
                'status' => WorkstationRequest::STATUS_RELEASED,
                'requested_at' => $requestedAt,
                'released_at' => $releasedAt,
            ]);

            // PART 1 #8: created directly as Awaiting Key Return, not
            // Available -- a Collected key's seat was never offerable
            // under the current rule, this scenario exists specifically
            // to demonstrate that.
            $seat->update(['status' => Workstation::STATUS_AWAITING_KEY_RETURN]);

            WorkstationHistory::record(
                seat: $seat,
                actorType: WorkstationHistory::ACTOR_SYSTEM,
                actorName: 'System',
                action: WorkstationHistory::ACTION_SEAT_RELEASED,
                reason: WorkstationAllocator::REASON_TERMINATION_GRADUATED,
                beforeState: Workstation::STATUS_OCCUPIED,
                afterState: Workstation::STATUS_AWAITING_KEY_RETURN,
                student: StudentDirectory::fromDemoProfile($profile)->asUserLike(),
            );

            LockerKey::create([
                'workstation_request_id' => $request->id,
                'demo_profile_id' => $profile->id,
                'status' => LockerKey::STATUS_RETURN_PENDING,
                'requested_at' => $requestedAt,
                'collected_at' => $requestedAt->copy()->addDay(),
                'return_due_at' => $returnDueAt,
            ]);

            WorkstationHistory::record(
                seat: $seat,
                actorType: WorkstationHistory::ACTOR_SYSTEM,
                actorName: 'System',
                action: WorkstationHistory::ACTION_KEY_RETURN_PENDING,
                beforeState: LockerKey::STATUS_COLLECTED,
                afterState: LockerKey::STATUS_RETURN_PENDING,
                student: StudentDirectory::fromDemoProfile($profile)->asUserLike(),
            );
        }
    }

    protected function verifyGenderMatches(): void
    {
        $mismatches = WorkstationRequest::query()
            ->whereNotNull('demo_profile_id')
            ->whereIn('status', [WorkstationRequest::STATUS_CONFIRMED, WorkstationRequest::STATUS_RELEASED])
            ->with(['demoProfile', 'workstation.location'])
            ->get()
            ->filter(fn (WorkstationRequest $request) => $request->demoProfile->gender !== $request->workstation->location->gender);

        if ($mismatches->isNotEmpty()) {
            $details = $mismatches->map(fn (WorkstationRequest $r) => "{$r->demoProfile->name} ({$r->demoProfile->matric_no}, {$r->demoProfile->gender}) in {$r->workstation->location->room_code} ({$r->workstation->location->gender})")->implode('; ');

            throw new \RuntimeException("workstation:demo-seed aborted: gender mismatch between demo student and room: {$details}");
        }
    }

    /**
     * PART 1 #8: the PDF's own split was Available 155, but 3 of those are
     * now Awaiting Key Return by design (this method's own 3 scenarios) --
     * so the invariant that actually holds is Available + Awaiting = 155,
     * not Available alone = 155.
     */
    protected function verifySeatCounts(): void
    {
        $counts = [
            'total' => Workstation::count(),
            'available' => Workstation::where('status', Workstation::STATUS_AVAILABLE)->count(),
            'awaiting_key_return' => Workstation::where('status', Workstation::STATUS_AWAITING_KEY_RETURN)->count(),
            'occupied' => Workstation::where('status', Workstation::STATUS_OCCUPIED)->count(),
            'disabled' => Workstation::where('status', Workstation::STATUS_DISABLED)->count(),
        ];

        $expected = $counts['total'] === 315
            && $counts['available'] + $counts['awaiting_key_return'] === 155
            && $counts['occupied'] === 157
            && $counts['disabled'] === 3;

        if (! $expected) {
            $this->warn('Seat counts no longer match the PDF ('.json_encode($counts).') -- seeding did not change any seat status besides the 3 Awaiting Key Return scenarios, so this reflects real catalogue/CGS changes, not this command.');
        }
    }

    /**
     * Tries its preferred category (so which ethnicity a given seat leans
     * toward is still a function of the seat, not pure chance every call)
     * for a bounded number of attempts, then falls through to the other
     * three. International's pool is deliberately smaller than the other
     * three (real Malaysian postgrad cohorts skew that way too), so on a
     * catalogue this size it alone would run out well before 500 attempts
     * if nothing ever moved on from it -- the fallthrough is what keeps
     * this correct regardless of how any one pool's size compares to how
     * many seats happen to roll it.
     */
    protected function generateName(string $gender, int $seedKey): string
    {
        $preferred = $this->seededRand($seedKey, 'name-category', 1, 4);
        $categories = array_unique([$preferred, 1, 2, 3, 4]);

        foreach ($categories as $category) {
            for ($attempt = 0; $attempt < 100; $attempt++) {
                $name = match ($category) {
                    1 => $this->malayName($gender, $seedKey, $attempt),
                    2 => $this->chineseName($gender, $seedKey, $attempt),
                    3 => $this->indianName($gender, $seedKey, $attempt),
                    default => $this->internationalName($gender, $seedKey, $attempt),
                };

                if (! in_array($name, $this->namesUsedThisRun, true) && ! DemoStudentProfile::where('name', $name)->exists()) {
                    $this->namesUsedThisRun[] = $name;

                    return $name;
                }
            }
        }

        throw new \RuntimeException("Could not generate a unique demo name for seed key {$seedKey}.");
    }

    protected function malayName(string $gender, int $seedKey, int $attempt): string
    {
        $firstPool = $gender === 'female' ? self::MALAY_FEMALE_FIRST : self::MALAY_MALE_FIRST;
        $first = $this->pickFrom($firstPool, $seedKey, 'malay-first', $attempt);
        $father = $this->pickFrom(self::MALAY_FATHER, $seedKey, 'malay-father', $attempt);
        $particle = $gender === 'female' ? 'binti' : 'bin';

        return "{$first} {$particle} {$father}";
    }

    protected function chineseName(string $gender, int $seedKey, int $attempt): string
    {
        $surname = $this->pickFrom(self::CHINESE_SURNAME, $seedKey, 'chinese-surname', $attempt);
        $givenPool = $gender === 'female' ? self::CHINESE_FEMALE_GIVEN : self::CHINESE_MALE_GIVEN;
        $given = $this->pickFrom($givenPool, $seedKey, 'chinese-given', $attempt);

        return "{$surname} {$given}";
    }

    protected function indianName(string $gender, int $seedKey, int $attempt): string
    {
        $firstPool = $gender === 'female' ? self::INDIAN_FEMALE_FIRST : self::INDIAN_MALE_FIRST;
        $first = $this->pickFrom($firstPool, $seedKey, 'indian-first', $attempt);
        $father = $this->pickFrom(self::INDIAN_FATHER, $seedKey, 'indian-father', $attempt);
        $particle = $gender === 'female' ? 'a/p' : 'a/l';

        return "{$first} {$particle} {$father}";
    }

    protected function internationalName(string $gender, int $seedKey, int $attempt): string
    {
        $pool = $gender === 'female' ? self::INTERNATIONAL_FEMALE : self::INTERNATIONAL_MALE;

        return $this->pickFrom($pool, $seedKey, 'international', $attempt);
    }

    /** @param array<int, mixed> $pool */
    protected function pickFrom(array $pool, int $seedKey, string $salt, int $attempt = 0): mixed
    {
        $index = $this->seededRand($seedKey, $salt.$attempt, 0, count($pool) - 1);

        return $pool[$index];
    }

    /**
     * mt_rand(), not random_int() -- it is the one that actually respects
     * mt_srand(), which is the entire idempotency mechanism this command
     * relies on. Re-seeded on every call with a key built from the seat id
     * and a purpose-specific salt, so two different "rolls" for the same
     * seat (its name vs. its key bucket vs. its allocation date) never
     * accidentally share a value just because they happened to want the
     * same range.
     */
    protected function seededRand(int $seedKey, string $salt, int $min, int $max): int
    {
        if ($max < $min) {
            return $min;
        }

        mt_srand(self::SEED + $seedKey + crc32($salt));

        return mt_rand($min, $max);
    }

    protected function emailFor(string $name, string $matricNo): string
    {
        $firstWord = strtok($name, ' ');
        $local = preg_replace('/[^a-z]/', '', strtolower($firstWord));

        return "{$local}_{$matricNo}@utp.edu.my";
    }

    protected function nextStudentId(): string
    {
        foreach ([21, 22, 23, 24, 25] as $yy) {
            for ($n = 1; $n <= 9999; $n++) {
                $candidate = sprintf('%02d01%04d', $yy, $n);

                if (! $this->isStudentIdTaken($candidate)) {
                    return $candidate;
                }
            }
        }

        throw new \RuntimeException('No free demo student ID left in the YY01NNNN space.');
    }

    protected function isStudentIdTaken(string $id): bool
    {
        // Chloe Ching's own ID -- explicitly excluded per the brief, though
        // its YY00xxxx shape could never collide with YY01NNNN anyway.
        if ($id === '26005007') {
            return true;
        }

        return User::where('matric_no', $id)->exists()
            || DemoStudentProfile::where('matric_no', $id)->exists()
            || WorkstationHistory::where('student_matric_no', $id)->exists()
            || DB::table('hardbound_submission_details')->where('matric_no', $id)->exists();
    }
}
