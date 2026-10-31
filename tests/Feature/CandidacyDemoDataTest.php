<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyDepartment;
use App\Modules\Chloe\Models\CandidacyStaffAssignment;
use App\Modules\Chloe\Models\CandidacyStaffProfile;
use App\Modules\Chloe\Models\DemoStudentProfile;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Stage 3: candidacy:demo-seed / demo-clear -- idempotent, rule-abiding, and removing only what they made. */
class CandidacyDemoDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2026-11-01 10:00:00');

        $mk = fn ($role, $email, $name, $x = []) => User::create($x + ['name' => $name, 'email' => $email, 'password' => 'password', 'role' => $role]);
        $cis = CandidacyDepartment::create(['code' => 'CIS', 'name' => 'Computer & Information Sciences']);
        foreach ([[$mk(Role::SUPERVISOR, 'supervisor@utp.edu.my', 'Dr. Aisyah Rahman'), 'Lecturer', 'lecturer'], [$mk(Role::CHAIR, 'chair@utp.edu.my', 'Dr. Lim Wei Chun'), 'Chair', 'chair']] as [$u, $t, $r]) {
            $p = CandidacyStaffProfile::create(['user_id' => $u->id, 'full_name' => $u->name, 'designation' => $t, 'department_id' => $cis->id]);
            CandidacyStaffAssignment::create(['department_id' => $cis->id, 'staff_profile_id' => $p->id, 'role' => $r]);
        }
        $dean = $mk(Role::DEAN_PGR, 'dean@utp.edu.my', 'Prof. Dr. Hafiz Osman');
        CandidacyStaffProfile::create(['user_id' => $dean->id, 'full_name' => $dean->name, 'designation' => 'Dean, Postgraduate and Research']);
        $mk(Role::NON_EXEC_CGS, 'cgs@utp.edu.my', 'Puan Waheeda');

        $real = $mk(Role::STUDENT, 'student2@utp.edu.my', 'Nur Farah Adilah', ['matric_no' => '22001002']);
        StudyCandidacy::create(['student_id' => $real->id, 'programme_start_date' => '2022-10-11', 'candidacy_expiry_date' => '2026-10-01', 'status' => 'dismissed']);

        $programmes = ['MSc Computer Science (Full-Time)', 'MSc Information Technology (Full-Time)', 'MSc Mechanical Engineering (Full-Time)', 'MSc Petroleum Engineering (Full-Time)',
            'PhD Chemical Engineering (Full-Time)', 'PhD Civil Engineering (Full-Time)', 'PhD Computer & Information Sciences (Full-Time)', 'PhD Electrical & Electronic Engineering (Full-Time)'];
        $n = 0;
        foreach ($programmes as $programme) {
            foreach (range(1, 16) as $i) {
                DemoStudentProfile::create(['matric_no' => (string) (21010000 + ++$n), 'name' => "Demo Student {$n}", 'email' => "demo{$n}@utp.edu.my", 'gender' => 'male', 'programme' => $programme,
                    'programme_start_date' => '2025-01-10', 'candidacy_expiry_date' => Carbon::parse('2027-03-01')->addDays($n * 5)->toDateString(), 'status' => 'active']);
            }
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string,int> */
    protected function counts(): array
    {
        return collect(['candidacy_appeals', 'candidacy_appeal_events', 'candidacy_appeal_approvals', 'candidacy_appeal_documents', 'candidacy_appeal_publications', 'candidacy_staff_profiles', 'candidacy_staff_assignments', 'candidacy_departments', 'demo_student_profiles', 'users', 'study_candidacies'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }

    public function test_seeding_twice_gives_identical_counts(): void
    {
        $this->artisan('candidacy:demo-seed')->assertSuccessful();
        $first = $this->counts();
        $this->artisan('candidacy:demo-seed')->expectsOutputToContain('created this run: 0')->assertSuccessful();

        $this->assertSame($first, $this->counts());
        $this->assertGreaterThanOrEqual(38, $first['candidacy_appeals']);
        $this->assertLessThanOrEqual(42, $first['candidacy_appeals']);
    }

    public function test_six_departments_each_with_lecturers_a_chair_and_a_dean(): void
    {
        $this->artisan('candidacy:demo-seed')->assertSuccessful();

        $this->assertSame(6, CandidacyDepartment::count());
        foreach (CandidacyDepartment::all() as $dept) {
            $roles = CandidacyStaffAssignment::where('department_id', $dept->id)->get()->groupBy('role');
            $this->assertGreaterThanOrEqual(3, $roles->get('lecturer', collect())->count(), $dept->code);
            $this->assertSame(1, $roles->get('chair', collect())->count(), $dept->code);
            $this->assertSame(1, $roles->get('dean', collect())->count(), $dept->code);
        }

        // the real accounts are mapped to CIS and get no signature file from the seed
        $cis = CandidacyDepartment::where('code', 'CIS')->first();
        $real = CandidacyStaffProfile::whereIn('user_id', User::whereIn('email', ['supervisor@utp.edu.my', 'chair@utp.edu.my', 'dean@utp.edu.my'])->pluck('id'))->get();
        $this->assertCount(3, $real);
        $this->assertTrue($real->every(fn ($p) => ! $p->hasSignature() && ! $p->is_demo));
        $this->assertSame(3, CandidacyStaffAssignment::where('department_id', $cis->id)->whereIn('staff_profile_id', $real->pluck('id'))->count());
    }

    public function test_every_state_is_covered_with_waiting_days_from_0_to_14(): void
    {
        $this->artisan('candidacy:demo-seed')->assertSuccessful();
        $a = CandidacyAppeal::where('is_demo', true)->get();

        foreach (['draft', 'with_supervisor', 'with_chair', 'with_cgs', 'with_dean', 'returned', 'approved', 'rejected', 'closed_expired'] as $status) {
            $this->assertTrue($a->contains('status', $status), $status);
        }
        foreach (['supervisor', 'chair', 'cgs', 'dean'] as $by) {
            $this->assertTrue($a->where('status', 'returned')->contains('returned_by_stage', $by), "returned by {$by}");
        }
        $this->assertTrue($a->contains(fn ($x) => $x->needs_cgs_recheck && $x->status === 'with_cgs'));
        $days = $a->filter(fn ($x) => $x->current_stage)->map->waitingDays();
        $this->assertSame(0, $days->min());
        $this->assertGreaterThanOrEqual(13, $days->max());
        $this->assertLessThanOrEqual(15, $days->max());
        $this->assertSame(3, $a->where('status', 'draft')->count());
        $this->assertNull($a->where('status', 'draft')->first()->appeal_no);
    }

    public function test_the_rules_hold_for_every_demo_appeal(): void
    {
        $this->artisan('candidacy:demo-seed')->assertSuccessful();
        $appeals = CandidacyAppeal::where('is_demo', true)->orderBy('id')->get();
        $byStudent = $appeals->groupBy(fn ($a) => $a->student_id ? 'u'.$a->student_id : 'd'.$a->demo_profile_id);

        $twelve = $twoApproved = 0;
        foreach ($byStudent as $key => $list) {
            $this->assertLessThanOrEqual(12, $list->where('status', 'approved')->sum('approved_months'), "{$key} allowance");
            $this->assertLessThanOrEqual(1, $list->whereIn('status', CandidacyAppeal::OPEN_STATUSES)->count(), "{$key} one open");
            $this->assertLessThanOrEqual(1, $list->where('status', 'draft')->count(), "{$key} one draft");
            $rejected = $list->firstWhere('status', 'rejected');
            if ($rejected) {
                $this->assertSame($rejected->id, $list->sortBy('id')->last()->id, "{$key}: nothing after a final rejection");
            }
            $twelve += $list->where('status', 'approved')->sum('approved_months') === 12 ? 1 : 0;
            $twoApproved += $list->where('status', 'approved')->count() >= 2 ? 1 : 0;
        }
        $this->assertGreaterThanOrEqual(1, $twelve, 'a student at exactly 12 of 12 months');
        $this->assertGreaterThanOrEqual(2, $twoApproved, 'students with two approved appeals');

        foreach ($appeals->whereNotNull('appeal_no') as $a) {
            $this->assertMatchesRegularExpression('/^SCA-\d+-\d\d$/', $a->appeal_no);
            $this->assertNotNull($a->submitted_at);
            $this->assertGreaterThanOrEqual($a->submitted_at->toDateString(), $a->expiry_at_submission->toDateString(), "{$a->appeal_no} submitted before its expiry");
            $this->assertNotNull($a->events()->where('action', 'submitted')->first(), $a->appeal_no);
            $this->assertTrue($a->documents()->where('kind', 'official_form')->exists());
        }

        foreach ($appeals->where('status', 'approved') as $a) {
            $this->assertNotNull($a->memo_path, $a->appeal_no);
            Storage::disk('local')->assertExists($a->memo_path);
            $this->assertSame($a->requested_months, $a->approved_months);
            $this->assertSame(4, $a->approvals()->whereIn('decision', ['approved', 'verified'])->count());
            $this->artisan('candidacy:verify-memo', ['appeal_no' => $a->appeal_no])->assertSuccessful();
        }

        // the closed one belongs to a past-expiry candidacy and was returned before it expired
        $closed = $appeals->firstWhere('status', 'closed_expired');
        $this->assertSame(22001002, (int) $closed->student->matric_no);
        $this->assertGreaterThan($closed->events()->where('action', 'returned')->first()->occurred_at, $closed->closed_at);
    }

    public function test_in_flight_appeals_are_routed_to_the_real_cis_staff_and_the_real_candidacies_are_aligned(): void
    {
        $this->artisan('candidacy:demo-seed')->assertSuccessful();
        $lecturer = CandidacyStaffProfile::where('user_id', User::where('email', 'supervisor@utp.edu.my')->value('id'))->first();
        $chair = CandidacyStaffProfile::where('user_id', User::where('email', 'chair@utp.edu.my')->value('id'))->first();

        $this->assertGreaterThanOrEqual(3, CandidacyAppeal::where('status', 'with_supervisor')->where('supervisor_profile_id', $lecturer->id)->count());
        $this->assertGreaterThanOrEqual(2, CandidacyAppeal::where('status', 'with_chair')->where('chair_profile_id', $chair->id)->count());
        $this->assertTrue(StudyCandidacy::all()->every(fn ($c) => $c->department_id && $c->original_expiry_date));
    }

    public function test_the_demo_students_expiry_dates_are_never_changed(): void
    {
        $before = DemoStudentProfile::orderBy('id')->pluck('candidacy_expiry_date', 'id')->map(fn ($d) => (string) $d)->all();

        $this->artisan('candidacy:demo-seed')->assertSuccessful();

        $this->assertSame($before, DemoStudentProfile::orderBy('id')->pluck('candidacy_expiry_date', 'id')->map(fn ($d) => (string) $d)->all());
    }

    public function test_demo_clear_removes_only_demo_data_and_files(): void
    {
        $real = $this->counts();
        $realFiles = Storage::disk('local')->allFiles();
        $this->artisan('candidacy:demo-seed')->assertSuccessful();
        $this->assertNotEmpty(Storage::disk('local')->allFiles('candidacy'));

        $this->artisan('candidacy:demo-clear')->assertSuccessful();

        $after = $this->counts();
        $this->assertSame(0, CandidacyAppeal::count());
        $this->assertSame(0, CandidacyStaffProfile::where('is_demo', true)->count());
        $this->assertSame(0, CandidacyDepartment::where('is_demo', true)->count());
        $this->assertSame($real['users'], $after['users']);
        $this->assertSame($real['demo_student_profiles'], $after['demo_student_profiles']);
        $this->assertSame(Storage::disk('local')->allFiles(), $realFiles); // every demo file is gone
        $this->assertSame(3, CandidacyStaffProfile::whereIn('user_id', User::pluck('id'))->count()); // the real profiles stay
        $this->assertTrue(CandidacyDepartment::where('code', 'CIS')->exists());

        $this->artisan('candidacy:demo-seed')->assertSuccessful(); // and it can be seeded again
        $this->assertGreaterThanOrEqual(38, CandidacyAppeal::count());
    }

    public function test_check_allowance_still_passes_after_seeding(): void
    {
        $this->artisan('candidacy:demo-seed')->assertSuccessful();

        $this->artisan('candidacy:check-allowance')->assertSuccessful();
    }
}
