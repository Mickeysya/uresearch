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
        $this->assertGreaterThanOrEqual(50, $first['candidacy_appeals']);
        $this->assertLessThanOrEqual(70, $first['candidacy_appeals']);
    }

    public function test_nine_departments_with_the_agreed_roster(): void
    {
        $this->artisan('candidacy:demo-seed')->assertSuccessful();

        $this->assertSame(9, CandidacyDepartment::count());
        $this->assertSame(['Faculty of Engineering' => 6, 'Faculty of Science, Management & Computing' => 3], CandidacyDepartment::all()->countBy('faculty')->sortKeys()->all());
        $this->assertSame(0, CandidacyDepartment::where('name', 'like', '%Geosciences & Petroleum%')->count());

        foreach (CandidacyDepartment::all() as $dept) {
            $roles = CandidacyStaffAssignment::where('department_id', $dept->id)->get()->groupBy('role');
            $lecturers = $roles->get('lecturer', collect())->count();
            $this->assertGreaterThanOrEqual(5, $lecturers, $dept->code);
            $this->assertLessThanOrEqual(7, $lecturers, $dept->code);
            $this->assertSame(1, $roles->get('chair', collect())->count(), "{$dept->code}: exactly one Department Chair");
            $this->assertFalse($roles->has('dean'), "{$dept->code}: a department has no Dean");
        }

        // 9 chairs, none a lecturer; nobody holds two roles or two departments
        $this->assertSame(9, CandidacyStaffAssignment::where('role', 'chair')->count());
        $this->assertSame(0, CandidacyStaffAssignment::where('role', 'dean')->count());
        $this->assertSame(0, CandidacyStaffAssignment::select('staff_profile_id')->groupBy('staff_profile_id')->havingRaw('count(*) > 1')->get()->count());
        $chairIds = CandidacyStaffAssignment::where('role', 'chair')->pluck('staff_profile_id');
        $this->assertSame(0, CandidacyStaffAssignment::where('role', 'lecturer')->whereIn('staff_profile_id', $chairIds)->count());

        // the fake staff have no login; exactly the real lecturer and chair have one (plus the Dean and a CGS profile that are not in departments)
        $fake = CandidacyStaffProfile::where('is_demo', true);
        $this->assertSame(0, (clone $fake)->whereNotNull('user_id')->count());
        $this->assertSame(8, (clone $fake)->whereIn('id', $chairIds)->count());
        $names = CandidacyStaffProfile::pluck('full_name');
        $this->assertSame($names->count(), $names->unique()->count(), 'all names are unique');
        $this->assertSame(0, (clone $fake)->get()->filter(fn ($p) => User::where('name', $p->full_name)->exists())->count(), 'no fake name equals an account name');
        foreach ((clone $fake)->get() as $p) {
            $this->assertMatchesRegularExpression('/^(Dr\.|Ir\. Dr\.|Assoc\. Prof\. Dr\.|Prof\. Dr\.|Prof\. Ir\. Dr\.) /', $p->full_name);
        }

        // the real lecturer and chair belong to Computer & Information Sciences; the real lecturer is one of 5 to 7 there
        $cis = CandidacyDepartment::where('code', 'CIS')->first();
        $real = CandidacyStaffProfile::whereIn('user_id', User::whereIn('email', ['supervisor@utp.edu.my', 'chair@utp.edu.my'])->pluck('id'))->get();
        $this->assertCount(2, $real);
        $this->assertTrue($real->every(fn ($p) => ! $p->hasSignature() && ! $p->is_demo));
        $this->assertSame(2, CandidacyStaffAssignment::where('department_id', $cis->id)->whereIn('staff_profile_id', $real->pluck('id'))->count());
    }

    public function test_there_is_exactly_one_dean_and_every_appeal_uses_the_roster(): void
    {
        $this->artisan('candidacy:demo-seed')->assertSuccessful();
        $dean = CandidacyStaffProfile::where('user_id', User::where('role', Role::DEAN_PGR)->value('id'))->first();

        $this->assertNull($dean->department_id);
        $this->assertSame(1, CandidacyStaffProfile::where('designation', 'like', '%Dean%')->count(), 'one Dean in our tables');
        $this->assertSame([$dean->id], CandidacyAppeal::distinct()->pluck('dean_profile_id')->all());
        $this->assertSame(0, \App\Modules\Chloe\Models\CandidacyAppealApproval::where('designation', 'like', '%Dean%')->where('profile_id', '!=', $dean->id)->count());

        foreach (CandidacyAppeal::with(['supervisorProfile', 'chairProfile'])->get() as $a) {
            $chairOf = CandidacyStaffAssignment::where('department_id', $a->department_id)->where('role', 'chair')->value('staff_profile_id');
            $this->assertSame($chairOf, $a->chair_profile_id, "{$a->appeal_no}: the Chair of its department");
            $this->assertTrue(CandidacyStaffAssignment::where('department_id', $a->department_id)->where('role', 'lecturer')->where('staff_profile_id', $a->supervisor_profile_id)->exists(), "{$a->appeal_no}: a lecturer of its department");
        }
    }

    public function test_every_department_that_has_students_has_at_least_two_appeals_and_computer_and_information_sciences_has_the_agreed_mix(): void
    {
        $this->artisan('candidacy:demo-seed')->assertSuccessful();
        $byDept = CandidacyAppeal::where('is_demo', true)->get()->groupBy('department_id');
        $withStudents = DemoStudentProfile::pluck('programme')->map(fn ($p) => \App\Modules\Chloe\Support\CandidacyDepartmentResolver::codeFor($p, '1'))->unique();

        foreach (CandidacyDepartment::whereIn('code', $withStudents)->get() as $d) {
            $this->assertGreaterThanOrEqual(2, ($byDept[$d->id] ?? collect())->count(), $d->code);
        }

        $cis = CandidacyDepartment::where('code', 'CIS')->first();
        $a = CandidacyAppeal::where('department_id', $cis->id)->get();
        $lec = CandidacyStaffProfile::where('user_id', User::where('email', 'supervisor@utp.edu.my')->value('id'))->first();
        $chair = CandidacyStaffProfile::where('user_id', User::where('email', 'chair@utp.edu.my')->value('id'))->first();

        $this->assertGreaterThanOrEqual(12, $a->count());
        $this->assertGreaterThanOrEqual(3, $a->where('status', 'with_supervisor')->where('supervisor_profile_id', $lec->id)->count());
        $this->assertGreaterThanOrEqual(3, $a->where('status', 'with_chair')->where('chair_profile_id', $chair->id)->count());
        $this->assertGreaterThanOrEqual(2, $a->where('status', 'with_cgs')->count());
        $this->assertGreaterThanOrEqual(2, $a->where('status', 'with_dean')->count());
        $this->assertGreaterThanOrEqual(1, $a->where('status', 'returned')->count());
        $this->assertGreaterThanOrEqual(2, $a->where('status', 'approved')->count());
        $this->assertGreaterThanOrEqual(1, $a->where('status', 'rejected')->count());
        $this->assertGreaterThanOrEqual(1, $a->where('supervisor_profile_id', $lec->id)->whereIn('status', ['with_chair', 'with_cgs', 'with_dean', 'returned'])->count(), 'Ongoing for the real lecturer');

        // the real Dean has appeals waiting from several departments
        $this->assertGreaterThanOrEqual(3, CandidacyAppeal::where('status', 'with_dean')->distinct()->pluck('department_id')->count());
        $this->assertGreaterThanOrEqual(3, CandidacyAppeal::where('status', 'with_cgs')->count());
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

    public function test_the_real_candidacies_get_a_department_and_the_live_demo_accounts_none_of_the_demo_appeals(): void
    {
        $live = User::create(['name' => 'Live Student', 'email' => 'student@utp.edu.my', 'password' => 'password', 'role' => Role::STUDENT, 'matric_no' => '22001001', 'programme' => 'MSc Petroleum Engineering']);
        StudyCandidacy::create(['student_id' => $live->id, 'programme_start_date' => '2025-01-01', 'candidacy_expiry_date' => '2027-06-10', 'status' => 'active']);

        $this->artisan('candidacy:demo-seed')->assertSuccessful();

        $this->assertTrue(StudyCandidacy::all()->every(fn ($c) => $c->department_id && $c->original_expiry_date));
        $this->assertSame('CIS', CandidacyDepartment::find(StudyCandidacy::where('student_id', $live->id)->value('department_id'))->code);
        $this->assertSame(0, CandidacyAppeal::where('student_id', $live->id)->count(), 'no demo appeal for a live demo account');
        $this->assertSame(0, StudyCandidacy::where('student_id', $live->id)->value('extension_months_used'));
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
        $this->assertSame(0, \App\Modules\Chloe\Models\CandidacyAppealEvent::count(), 'the history went with its appeals (cascade)');
        $this->assertSame(0, CandidacyStaffProfile::where('is_demo', true)->count());
        $this->assertSame(0, CandidacyDepartment::where('is_demo', true)->count());
        $this->assertSame($real['users'], $after['users']);
        $this->assertSame($real['demo_student_profiles'], $after['demo_student_profiles']);
        $this->assertSame(Storage::disk('local')->allFiles(), $realFiles); // every demo file is gone
        $this->assertSame(3, CandidacyStaffProfile::whereIn('user_id', User::pluck('id'))->count()); // the real profiles stay
        $this->assertTrue(CandidacyDepartment::where('code', 'CIS')->exists());

        $this->artisan('candidacy:demo-seed')->assertSuccessful(); // and it can be seeded again
        $this->assertGreaterThanOrEqual(50, CandidacyAppeal::count());
    }

    public function test_check_allowance_still_passes_after_seeding(): void
    {
        $this->artisan('candidacy:demo-seed')->assertSuccessful();

        $this->artisan('candidacy:check-allowance')->assertSuccessful();
    }
}
