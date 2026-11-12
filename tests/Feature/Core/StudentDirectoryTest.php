<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Contracts\SuppliesCandidacyStatus;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Models\AttendanceRiskState;
use App\Modules\Nureen\Models\GaExtensionDetail;
use App\Modules\Nureen\Models\SupervisionDetail;
use App\Modules\Nureen\Support\LetterRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The Students page (/students, /students/{id}): who sees whom, search and
 * filters, the CGS-only projection, empty states, and a constant number of
 * queries on the list.
 */
class StudentDirectoryTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected User $aisyah;   // Computing supervisor; co-supervises Chong

    protected User $kumaran;  // Management supervisor

    protected User $ahmad;    // Computing, main: Aisyah, at risk, pending application

    protected User $farah;    // Computing, main: Kumaran

    protected User $chong;    // Management, main: Kumaran, co: Aisyah

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->aisyah = $this->user(Role::SUPERVISOR, ['name' => 'Dr. Aisyah Rahman', 'email' => 'aisyah@test.my', 'department' => 'Computing']);
        $this->kumaran = $this->user(Role::SUPERVISOR, ['name' => 'Prof. Kumaran', 'email' => 'kumaran@test.my', 'department' => 'Management']);

        $this->ahmad = $this->student('22001001', 'ahmad@test.my', ['name' => 'Ahmad Danial', 'programme' => 'MSc IT', 'department' => 'Computing', 'supervisor_id' => $this->aisyah->id]);
        $this->farah = $this->student('22001002', 'farah@test.my', ['name' => 'Nur Farah', 'programme' => 'PhD IT', 'department' => 'Computing', 'supervisor_id' => $this->kumaran->id]);
        $this->chong = $this->student('22001003', 'chong@test.my', ['name' => 'Chong Wei', 'programme' => 'MSc Management', 'department' => 'Management', 'supervisor_id' => $this->kumaran->id]);

        AttendanceRiskState::create(['student_id' => $this->ahmad->id, 'at_risk' => true, 'reason' => 'both', 'current_percentage' => 61.5, 'projected_percentage' => 55.25, 'latest_period_end' => '2026-11-06', 'evaluated_at' => now()]);
        AttendanceRiskState::create(['student_id' => $this->farah->id, 'at_risk' => false, 'current_percentage' => 92, 'projected_percentage' => 90, 'latest_period_end' => '2026-11-06', 'evaluated_at' => now()]);

        // A pending GA Extension for Ahmad, with an IC and account number on file.
        $gax = Application::create(['student_id' => $this->ahmad->id, 'module_type' => 'ga_extension', 'status' => Application::STATUS_PENDING, 'current_stage' => 'admin_verify', 'submitted_at' => now()->subDay()]);
        GaExtensionDetail::create(['application_id' => $gax->id, 'supervisor_id' => $this->aisyah->id, 'current_end_date' => now()->addMonth(), 'requested_new_end_date' => now()->addMonths(7), 'reason_for_extension' => 'Test.', 'final_approver_snapshot' => 'ga_application', 'identity_number' => '010203-08-1234', 'bank_account_no' => '1122 3344 5566']);

        // Aisyah co-supervises Chong; Ahmad's main supervisor was Kumaran before Aisyah.
        $co = Application::create(['student_id' => $this->chong->id, 'module_type' => 'supervision', 'status' => Application::STATUS_APPROVED, 'current_stage' => 'cgs_review', 'submitted_at' => now()->subMonth()]);
        SupervisionDetail::create(['application_id' => $co->id, 'requested_supervisor_id' => $this->aisyah->id, 'justification' => 'x', 'appointment_role' => SupervisionDetail::ROLE_CO, 'appointment_type' => 'new', 'cgs_stage_snapshot' => 'endorse', 'letter_reference' => 'CGS/SVAPPT/2026/0009', 'letter_issued_at' => now()->subWeeks(3)]);
        Storage::disk('local')->put("letters/{$co->id}.pdf", '%PDF-1.4 test');
        ApplicationDocument::create(['application_id' => $co->id, 'doc_type' => LetterRenderer::APPOINTMENT_DOC_TYPE, 'original_name' => 'letter.pdf', 'path' => "letters/{$co->id}.pdf", 'mime_type' => 'application/pdf']);
        $main = Application::create(['student_id' => $this->ahmad->id, 'module_type' => 'supervision', 'status' => Application::STATUS_APPROVED, 'current_stage' => 'cgs_review', 'submitted_at' => now()->subMonths(2)]);
        SupervisionDetail::create(['application_id' => $main->id, 'requested_supervisor_id' => $this->aisyah->id, 'justification' => 'x', 'appointment_role' => SupervisionDetail::ROLE_MAIN, 'appointment_type' => 'existing', 'cgs_stage_snapshot' => 'endorse', 'previous_supervisor_id' => $this->kumaran->id, 'letter_issued_at' => now()->subWeeks(7)]);
    }

    protected function names(User $viewer, array $query = []): array
    {
        $html = $this->actingAs($viewer)->get(route('students.index', $query))->assertOk()->getContent();

        return collect(['Ahmad Danial', 'Nur Farah', 'Chong Wei'])->filter(fn ($n) => str_contains($html, $n))->values()->all();
    }

    /* ---------------------------------------------------------------
     | Access
     |---------------------------------------------------------------*/

    public function test_students_are_refused(): void
    {
        $this->actingAs($this->ahmad)->get(route('students.index'))->assertForbidden();
        $this->actingAs($this->ahmad)->get(route('students.show', $this->farah))->assertForbidden();
    }

    public function test_cgs_dean_and_admin_see_every_student(): void
    {
        foreach ([Role::NON_EXEC_CGS, Role::SENIOR_EXEC_CGS, Role::MANAGER_CGS, Role::SENIOR_DIRECTOR_CGS, Role::DEAN_PGR, Role::ADMIN] as $role) {
            $viewer = $this->user($role);
            $this->assertSame(['Ahmad Danial', 'Nur Farah', 'Chong Wei'], $this->names($viewer), $role);
            $this->actingAs($viewer)->get(route('students.show', $this->chong))->assertOk();
        }
    }

    public function test_a_chair_sees_only_their_own_department(): void
    {
        $chair = $this->user(Role::CHAIR, ['email' => 'chair@test.my', 'department' => 'Computing']);

        $this->assertSame(['Ahmad Danial', 'Nur Farah'], $this->names($chair));
        $this->actingAs($chair)->get(route('students.show', $this->farah))->assertOk();
        $this->actingAs($chair)->get(route('students.show', $this->chong))->assertForbidden();
    }

    public function test_a_supervisor_sees_only_their_own_students_main_or_co(): void
    {
        $this->assertSame(['Ahmad Danial', 'Chong Wei'], $this->names($this->aisyah));
        $this->assertSame(['Nur Farah', 'Chong Wei'], $this->names($this->kumaran));

        $this->actingAs($this->aisyah)->get(route('students.show', $this->chong))->assertOk();
        $this->actingAs($this->aisyah)->get(route('students.show', $this->farah))->assertForbidden();
    }

    public function test_the_old_addresses_redirect_here(): void
    {
        $this->actingAs($this->user(Role::NON_EXEC_CGS))->get('/cgs/students')->assertRedirect('/students');
        $this->actingAs($this->user(Role::ADMIN))->get(route('admin.students.index'))->assertRedirect(route('students.index'));
    }

    /* ---------------------------------------------------------------
     | Search, filters, sort, pages
     |---------------------------------------------------------------*/

    public function test_search_and_every_filter(): void
    {
        $cgs = $this->user(Role::NON_EXEC_CGS);

        $this->assertSame(['Nur Farah'], $this->names($cgs, ['q' => 'farah']));
        $this->assertSame(['Chong Wei'], $this->names($cgs, ['q' => '22001003']));
        $this->assertSame(['Chong Wei'], $this->names($cgs, ['department' => 'Management']));
        $this->assertSame(['Nur Farah'], $this->names($cgs, ['programme' => 'PhD IT']));
        $this->assertSame(['Nur Farah', 'Chong Wei'], $this->names($cgs, ['supervisor' => $this->kumaran->id]));
        $this->assertSame(['Ahmad Danial'], $this->names($cgs, ['at_risk' => 1]));
        $this->assertSame(['Ahmad Danial'], $this->names($cgs, ['pending' => 1]));

        $this->actingAs($cgs)->get(route('students.index', ['q' => 'nobody-like-this']))
            ->assertOk()->assertSee('No students match these filters.');
    }

    public function test_sorting_by_name_and_by_attendance(): void
    {
        $cgs = $this->user(Role::NON_EXEC_CGS);
        $order = fn (array $q) => collect(['Ahmad Danial', 'Nur Farah', 'Chong Wei'])
            ->sortBy(fn ($n) => strpos($this->actingAs($cgs)->get(route('students.index', $q))->getContent(), $n))->values()->all();

        $this->assertSame(['Ahmad Danial', 'Chong Wei', 'Nur Farah'], $order(['sort' => 'name']));
        $this->assertSame(['Nur Farah', 'Chong Wei', 'Ahmad Danial'], $order(['sort' => '-name']));
        // Lowest first; no figure (Chong) last.
        $this->assertSame(['Ahmad Danial', 'Nur Farah', 'Chong Wei'], $order(['sort' => 'attendance']));
        $this->assertSame(['Nur Farah', 'Ahmad Danial', 'Chong Wei'], $order(['sort' => '-attendance']));
    }

    public function test_twenty_per_page(): void
    {
        foreach (range(10, 34) as $i) {
            $this->student('2300'.$i, "s{$i}@test.my", ['name' => "Zz Student {$i}", 'department' => 'Computing']);
        }

        $this->actingAs($this->user(Role::NON_EXEC_CGS))->get(route('students.index'))
            ->assertOk()
            ->assertSee('28 students')
            ->assertSee('showing 1 to 20');
    }

    /* ---------------------------------------------------------------
     | The record
     |---------------------------------------------------------------*/

    public function test_the_projected_figure_is_shown_to_cgs_only(): void
    {
        $this->actingAs($this->user(Role::NON_EXEC_CGS))->get(route('students.show', $this->ahmad))
            ->assertOk()->assertSee('61.5%')->assertSee('Projected')->assertSee('55.3%')->assertSee('At risk');

        $chair = $this->user(Role::CHAIR, ['email' => 'chair@test.my', 'department' => 'Computing']);
        foreach ([$chair, $this->aisyah, $this->user(Role::DEAN_PGR), $this->user(Role::ADMIN)] as $viewer) {
            $this->actingAs($viewer)->get(route('students.show', $this->ahmad))
                ->assertOk()->assertSee('61.5%')->assertDontSee('Projected')->assertDontSee('55.3');
            $this->actingAs($viewer)->get(route('students.index'))->assertDontSee('55.3');
        }
    }

    public function test_the_record_shows_supervision_applications_and_letters_but_never_ic_or_bank(): void
    {
        $cgs = $this->user(Role::NON_EXEC_CGS);

        $this->actingAs($cgs)->get(route('students.show', $this->ahmad))
            ->assertOk()
            ->assertSee('Dr. Aisyah Rahman')                                      // main
            ->assertSee('Prof. Kumaran')->assertSee('Replaced as main supervisor') // previous
            ->assertSee($this->ahmad->applications()->where('module_type', 'ga_extension')->first()->reference())
            ->assertDontSee('010203-08-1234')->assertDontSee('1234 ')->assertDontSee('5566');

        $this->actingAs($cgs)->get(route('students.show', $this->chong))
            ->assertOk()
            ->assertSee('Co-supervisors')->assertSee('Dr. Aisyah Rahman')
            ->assertSee('CGS/SVAPPT/2026/0009')->assertSee('Valid')
            // CGS holds a stage on Supervision, so the staff view is linked.
            ->assertSee(route('nureen.application', Application::where('student_id', $this->chong->id)->first()));
    }

    public function test_empty_states(): void
    {
        $nobody = $this->user(Role::SUPERVISOR, ['email' => 'nobody@test.my']);
        $this->actingAs($nobody)->get(route('students.index'))->assertOk()->assertSee('No students to show yet.');

        $this->actingAs($this->user(Role::NON_EXEC_CGS))->get(route('students.show', $this->farah))
            ->assertOk()->assertSee('No applications yet.')->assertSee('No letters issued yet.');

        // A supervision request with no letter yet is an application, not a letter.
        $this->actingAs($this->user(Role::SENIOR_DIRECTOR_CGS))->get(route('students.show', $this->ahmad))
            ->assertOk()->assertSee('No letters issued yet.');
    }

    /* ---------------------------------------------------------------
     | Performance
     |---------------------------------------------------------------*/

    public function test_the_list_runs_a_fixed_number_of_queries_whatever_the_page_holds(): void
    {
        // Candidacy is answered per student by Chloe's contract (bounded by
        // the page size; see SHARED_CHANGES); stubbed here so the count
        // measures this page's own queries.
        $this->app->instance(SuppliesCandidacyStatus::class, new class implements SuppliesCandidacyStatus
        {
            public function candidacyStatusFor(User $student): ?array
            {
                return null;
            }
        });

        $cgs = $this->user(Role::NON_EXEC_CGS);
        $count = function () use ($cgs) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($cgs)->get(route('students.index'))->assertOk();
            $queries = array_map(fn ($q) => $q['query'], DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $few = $count();
        foreach (range(10, 26) as $i) {
            $this->student('2400'.$i, "t{$i}@test.my", ['name' => "Yy Student {$i}", 'supervisor_id' => $this->kumaran->id]);
        }
        $many = $count();

        // 20 rows run no more queries than 3 (the first request also runs
        // one sidebar query the second has cached).
        $this->assertLessThanOrEqual(count($few), count($many), '3 rows: '.count($few).' queries; 20 rows: '.count($many));
        $this->assertLessThanOrEqual(12, count($many));
    }
}
