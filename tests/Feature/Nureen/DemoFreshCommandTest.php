<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Haziq\Support\Roles as HaziqRoles;
use App\Modules\Nureen\Models\AttendanceRecord;
use App\Modules\Nureen\Models\AttendanceRiskState;
use App\Modules\Nureen\Models\DemoRecord;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Models\GaExtensionDetail;
use App\Modules\Nureen\Models\SupervisionDetail;
use App\Modules\Nureen\Support\GaExtensionRules;
use App\Modules\Nureen\Support\PdfText;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Nureen\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * nureen:demo-fresh: four applications per module at the stages the demo
 * needs, made through the real routes (so approval_history, references and
 * letters exist), four students' attendance flagged the right way, and
 * --reset removing exactly that -- never a record that was there before.
 */
class DemoFreshCommandTest extends TestCase
{
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected User $existing;

    protected Application $existingApplication;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-22 10:00'));

        // The staff accounts the dev database has.
        $supervisor = $this->user(Role::SUPERVISOR, ['email' => 'supervisor@utp.edu.my', 'name' => 'Dr. Aisyah Rahman', 'department' => 'Computing']);
        $this->user(Role::ADMIN, ['email' => 'admin@utp.edu.my', 'name' => 'System Admin']);
        $this->user(Role::CHAIR, ['email' => 'chair@utp.edu.my', 'name' => 'Dr. Lim Wei Chun', 'department' => 'Computing']);
        $this->user(HaziqRoles::RESEARCH_CENTRE, ['email' => 'researchcentre@utp.edu.my', 'name' => 'Research Centre Officer', 'department' => 'Computing']);
        $this->user(Role::NON_EXEC_CGS, ['email' => 'zulkifly.cgs@utp.edu.my', 'name' => 'Zulkifly', 'department' => 'CGS']);
        $this->user(Role::SENIOR_DIRECTOR_CGS, ['email' => 'director@utp.edu.my', 'name' => 'Prof. Dr. Rahimah Ahmad', 'department' => 'CGS']);

        // Someone with a record already: must be neither used nor touched.
        $this->existing = $this->student('22000999', 'existing@utp.edu.my', [
            'name' => 'Existing Student', 'department' => 'Computing', 'programme' => 'MSc Full-Time', 'supervisor_id' => $supervisor->id,
        ]);
        $this->existingApplication = Application::create([
            'student_id' => $this->existing->id, 'submitted_by_id' => $this->existing->id,
            'module_type' => 'supervision', 'status' => Application::STATUS_DRAFT,
        ]);
        AttendanceRecord::create([
            'student_id' => $this->existing->id, 'period_start' => '2026-10-05', 'period_end' => '2026-10-09',
            'sessions_attended' => 10, 'sessions_total' => 20, 'percentage' => 50, 'at_risk' => true,
        ]);

        foreach (range(1, 16) as $n) {
            $this->student(sprintf('241010%02d', $n), "fresh{$n}@utp.edu.my", [
                'name' => "Fresh Student {$n}", 'department' => 'Computing',
                'programme' => $n % 2 ? 'MSc Full-Time' : 'PhD Full-Time', 'supervisor_id' => $supervisor->id,
            ]);
        }
    }

    /** @return array<int, Application> the demo's applications of one module, oldest first */
    protected function demo(string $moduleType): array
    {
        return Application::whereIn('id', DemoRecord::idsFor('applications'))
            ->where('module_type', $moduleType)->orderBy('id')->get()->all();
    }

    protected function assertAt(Application $application, string $status, ?string $stage = null): void
    {
        $this->assertSame($status, $application->status, "{$application->reference()} status");

        if ($stage) {
            $this->assertSame($stage, $application->current_stage, "{$application->reference()} stage");
        }
    }

    public function test_it_creates_every_case_through_the_real_workflow(): void
    {
        $this->artisan('nureen:demo-fresh')->assertSuccessful();

        // GA Extension: Admin -> CGS -> Supervisor -> Senior Director.
        $ga = $this->demo('ga_extension');
        $this->assertCount(4, $ga);
        $this->assertAt($ga[0], Application::STATUS_PENDING, 'admin_verify');
        $this->assertAt($ga[1], Application::STATUS_PENDING, 'cgs_verify');
        $this->assertAt($ga[2], Application::STATUS_PENDING, 'admin_verify');
        $this->assertSame('returned', ApprovalHistory::where('application_id', $ga[2]->id)->where('stage_key', 'cgs_verify')->value('decision'));
        $this->assertNotEmpty(ApprovalHistory::where('application_id', $ga[2]->id)->where('decision', 'returned')->value('remarks'));
        $this->assertAt($ga[3], Application::STATUS_APPROVED);
        $this->assertSame(['admin_verify', 'cgs_verify', 'supervisor', 'senior_director'],
            ApprovalHistory::where('application_id', $ga[3]->id)->orderBy('id')->pluck('stage_key')->all());

        $detail = GaExtensionDetail::where('application_id', $ga[1]->id)->first();
        foreach (app(GaExtensionRules::class)->check($detail, $ga[1], $ga[1]->documents()->get()) as $check) {
            $this->assertSame(GaExtensionRules::PASS, $check['status'], "{$check['label']}: {$check['reason']}");
        }

        // Each GA Extension student got their approved GA Application through Haziq's chain.
        $gaApplications = $this->demo('ga_application');
        $this->assertCount(4, $gaApplications);
        foreach ($gaApplications as $application) {
            $this->assertAt($application, Application::STATUS_APPROVED);
        }

        // Supervision: Supervisor -> Chair -> CGS.
        $sv = $this->demo('supervision');
        $this->assertCount(4, $sv);
        $this->assertAt($sv[0], Application::STATUS_PENDING, 'supervisor');
        $this->assertAt($sv[1], Application::STATUS_PENDING, 'chair');
        $this->assertAt($sv[2], Application::STATUS_REJECTED, 'supervisor');
        $this->assertNotEmpty($sv[2]->rejection()?->remarks);
        $this->assertAt($sv[3], Application::STATUS_APPROVED);
        $this->assertStringStartsWith('CGS/SVAPPT/', SupervisionDetail::where('application_id', $sv[3]->id)->value('letter_reference'));

        // Certification: Supervisor -> CGS -> Senior Director.
        $cert = $this->demo('ga_certification');
        $this->assertCount(4, $cert);
        $this->assertAt($cert[0], Application::STATUS_PENDING, 'cgs_verify');
        $this->assertAt($cert[1], Application::STATUS_PENDING, 'senior_director');
        $this->assertAt($cert[2], Application::STATUS_REJECTED, 'cgs_verify');
        $this->assertNotEmpty($cert[2]->rejection()?->remarks);
        $this->assertAt($cert[3], Application::STATUS_APPROVED);
        $reference = GaCertificationDetail::where('application_id', $cert[3]->id)->value('reference_number');
        $this->assertStringStartsWith('CGS/GACERT/', $reference);
        $this->get(route('certificate.verify', $reference))->assertOk()->assertSee($reference);

        // Attendance: one good, one by projection, one by rule, one appealing.
        $reasons = AttendanceRiskState::whereIn('id', DemoRecord::idsFor('attendance_risk_states'))
            ->orderBy('student_id')->pluck('reason')->all();
        $this->assertSame([null, 'projected', 'current', 'both'], $reasons);
        $this->assertCount(24, DemoRecord::idsFor('attendance_records'));
        $appeal = $this->demo('attendance_appeal');
        $this->assertCount(1, $appeal);
        $this->assertAt($appeal[0], Application::STATUS_PENDING, 'cgs_review');

        // Sixteen different students, none of them the one with a record already.
        $students = Application::whereIn('id', DemoRecord::idsFor('applications'))->where('module_type', '!=', 'ga_application')
            ->pluck('student_id')->merge(AttendanceRecord::whereIn('id', DemoRecord::idsFor('attendance_records'))->pluck('student_id'))
            ->unique();
        $this->assertCount(16, $students);
        $this->assertNotContains($this->existing->id, $students);

        // Uploads are readable, named PDFs that carry the student's details.
        $letter = $ga[0]->documents()->where('doc_type', "Supervisor's Supporting Letter")->first();
        $this->assertMatchesRegularExpression('/^Supporting-Letter-Fresh-Student-\d+\.pdf$/', $letter->original_name);
        $pdf = Storage::disk('local')->get($letter->path);
        $this->assertStringStartsWith('%PDF', $pdf);
        $text = PdfText::compact($pdf);
        $this->assertStringContainsString(str_replace(' ', '', $ga[0]->student->name), $text);
        $this->assertStringContainsString($ga[0]->student->matric_no, $text);

        // A second run is refused until the first is reset.
        $this->artisan('nureen:demo-fresh')->assertFailed();
    }

    public function test_reset_removes_only_what_the_command_created(): void
    {
        $this->artisan('nureen:demo-fresh')->assertSuccessful();

        $ids = DemoRecord::idsFor('applications');
        $paths = \App\Modules\Core\Models\ApplicationDocument::whereIn('application_id', $ids)->pluck('path');
        $this->assertNotEmpty($paths);

        $this->artisan('nureen:demo-fresh --reset')->assertSuccessful();

        $this->assertSame(0, Application::whereIn('id', $ids)->count());
        $this->assertSame(0, DemoRecord::count());
        foreach ($paths as $path) {
            Storage::disk('local')->assertMissing($path);
        }
        $this->assertSame(1, AttendanceRecord::count(), 'only the existing student\'s attendance is left');
        $this->assertSame(0, AttendanceRiskState::count());

        // The existing student's record is exactly as it was.
        $this->assertSame(Application::STATUS_DRAFT, $this->existingApplication->fresh()->status);
        $this->assertTrue(AttendanceRecord::where('student_id', $this->existing->id)->exists());

        // And it can be run again, cleanly.
        $this->artisan('nureen:demo-fresh')->assertSuccessful();
        $this->assertCount(4, $this->demo('ga_extension'));
    }
}
