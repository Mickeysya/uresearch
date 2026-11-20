<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Nureen\Database\Seeders\NureenVideoDemoSeeder;
use App\Modules\Nureen\Database\Seeders\Support\DemoDocuments;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Models\GaExtensionDetail;
use App\Modules\Nureen\Models\SupervisionDetail;
use App\Modules\Nureen\Notifications\ApplicationAwaitingYou;
use App\Modules\Nureen\Support\GaExtensionRules;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Nureen\Concerns\FilesGaExtensions;
use Tests\Feature\Nureen\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Feature\Nureen\Concerns\ParsesPdf;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * NureenVideoDemoSeeder: one record per module for the demo video, each
 * waiting at the first stage of its LIVE chain, with every System check
 * passing, and reused rather than duplicated on a re-run. In-memory
 * database and a fake disk -- nothing reaches the dev database.
 */
class VideoDemoSeederTest extends TestCase
{
    use FilesGaExtensions;
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use ParsesPdf;
    use RefreshDatabase;

    protected User $student;

    protected User $supervisor;

    protected User $admin;

    protected User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00'));

        // The accounts DatabaseSeeder makes on the dev database.
        $this->supervisor = $this->user(Role::SUPERVISOR, ['email' => 'supervisor@utp.edu.my', 'name' => 'Dr. Aisyah Rahman', 'department' => 'Computing']);
        $this->admin = $this->user(Role::ADMIN, ['email' => 'admin@utp.edu.my', 'name' => 'System Admin']);
        $this->user(Role::CHAIR, ['email' => 'chair@utp.edu.my', 'name' => 'Dr. Lim Wei Chun', 'department' => 'Computing']);
        $this->user(Role::NON_EXEC_CGS, ['email' => 'cgs@utp.edu.my', 'name' => 'M Syahmi Ifwat M Jafri', 'department' => 'CGS']);
        $this->officer = $this->user(Role::NON_EXEC_CGS, ['email' => 'zulkifly.cgs@utp.edu.my', 'name' => 'Zulkifly', 'department' => 'CGS']);
        $this->user(Role::SENIOR_DIRECTOR_CGS, ['email' => 'director@utp.edu.my', 'name' => 'Prof. Dr. Rahimah Ahmad', 'department' => 'CGS']);

        $this->student = $this->student('22001001', NureenVideoDemoSeeder::STUDENT_EMAIL, [
            'programme' => 'MSc Full-Time',
            'department' => 'Computing',
            'supervisor_id' => $this->supervisor->id,
        ]);

        // Like the dev database: an approved GA application ending on the
        // 27th, which would start an extension on the 28th -- not allowed.
        $this->approveGaApplication($this->student, '2026-12-27');
    }

    /** @return array<int, string> */
    protected function chain(Application $application): array
    {
        return array_map(fn (Stage $s) => $s->key, app(ModuleRegistry::class)->get($application->module_type)->stages($application));
    }

    protected function demoRecord(string $moduleType): Application
    {
        return Application::where('module_type', $moduleType)->where('student_id', $this->student->id)->sole();
    }

    public function test_one_record_per_module_waits_at_the_first_stage_of_the_live_chain(): void
    {
        $this->artisan('db:seed', ['--class' => NureenVideoDemoSeeder::class])
            ->expectsOutputToContain('zulkifly.cgs@utp.edu.my')
            ->expectsOutputToContain('admin@utp.edu.my')
            ->assertSuccessful();
        $this->seed(NureenVideoDemoSeeder::class);

        $expected = [
            'ga_extension' => ['admin_verify', 'cgs_verify', 'supervisor', 'senior_director'],
            'supervision' => ['supervisor', 'chair', 'cgs_review'],
            'ga_certification' => ['supervisor', 'cgs_verify', 'senior_director'],
            'attendance_appeal' => ['cgs_review'],
        ];

        foreach ($expected as $moduleType => $chain) {
            // sole(): two runs, still one record each.
            $application = $this->demoRecord($moduleType);

            $this->assertSame($chain, $this->chain($application), $moduleType);
            $this->assertSame(Application::STATUS_PENDING, $application->status, $moduleType);
            $this->assertSame($chain[0], $application->current_stage, $moduleType);
            $this->assertNotNull($application->submitted_at);
            $this->assertSame(0, $application->history()->count(), "{$moduleType}: no decision is seeded");

            // Every document its own form requires, each a PDF that opens.
            $types = $application->documents()->pluck('doc_type')->all();
            foreach (app(DemoDocuments::class)->requiredTypes($application) as $required) {
                $this->assertContains($required, $types, "{$moduleType} is missing \"{$required}\"");
            }
            foreach ($application->documents as $document) {
                $this->assertGreaterThanOrEqual(1, $this->pdfPageCount(Storage::disk('local')->get($document->path), $document->original_name));
            }
        }

        $this->assertSame('ga_application', GaExtensionDetail::where('application_id', $this->demoRecord('ga_extension')->id)->value('final_approver_snapshot'));
        $this->assertSame('endorse', SupervisionDetail::where('application_id', $this->demoRecord('supervision')->id)->value('cgs_stage_snapshot'));
    }

    public function test_every_system_check_passes(): void
    {
        $this->seed(NureenVideoDemoSeeder::class);

        $gaExtension = $this->demoRecord('ga_extension');
        $detail = GaExtensionDetail::where('application_id', $gaExtension->id)->sole();
        $checks = app(GaExtensionRules::class)->check($detail, $gaExtension, $gaExtension->documents()->get());

        $this->assertCount(8, $checks);
        foreach ($checks as $check) {
            $this->assertSame(GaExtensionRules::PASS, $check['status'], "{$check['label']}: {$check['reason']}");
        }

        // Rolled forward from the GA application's 27 Dec to the next allowed end day.
        $this->assertSame('2026-12-31', $detail->current_end_date->toDateString());
        $this->assertSame('2027-06-30', $detail->requested_new_end_date->toDateString());
        $this->assertSame('msc', $detail->level_of_study);
        $this->assertStringStartsWith('Video demo', $detail->reason_for_extension);
        $this->assertContains('Proof of Journal Article Acceptance', $gaExtension->documents()->pluck('doc_type')->all());

        $certification = GaCertificationDetail::where('application_id', $this->demoRecord('ga_certification')->id)->sole();
        $this->assertSame('pass', $certification->eligibilityCheck()['status']);
        $this->assertStringStartsWith('Video demo', $certification->purpose);
        $this->assertLessThanOrEqual(config('nureen.ga_certification.purpose_max'), mb_strlen($certification->purpose));

        $supervision = SupervisionDetail::where('application_id', $this->demoRecord('supervision')->id)->sole();
        $this->assertFalse($supervision->needsPgfacMemo());
        $this->assertSame($this->supervisor->id, $supervision->requested_supervisor_id);
    }

    public function test_each_first_approver_is_told_once_despite_a_rerun(): void
    {
        $this->seed(NureenVideoDemoSeeder::class);
        $this->seed(NureenVideoDemoSeeder::class);

        Notification::assertSentToTimes($this->admin, ApplicationAwaitingYou::class, 1);        // GA Extension
        Notification::assertSentToTimes($this->supervisor, ApplicationAwaitingYou::class, 2);   // Supervision + Certification
        Notification::assertSentToTimes($this->officer, ApplicationAwaitingYou::class, 1);      // Attendance Appeal
    }

    public function test_the_assigned_officer_can_decide_and_a_finished_record_is_not_reused(): void
    {
        $this->seed(NureenVideoDemoSeeder::class);
        $appeal = $this->demoRecord('attendance_appeal');

        app(WorkflowEngine::class)->decide($appeal, $this->officer, 'approve');
        $this->assertSame(Application::STATUS_APPROVED, $appeal->fresh()->status);

        // A finished take stays as history; the next run makes a fresh one.
        $this->seed(NureenVideoDemoSeeder::class);

        $appeals = Application::where('module_type', 'attendance_appeal')->where('student_id', $this->student->id)->get();
        $this->assertCount(2, $appeals);
        $this->assertSame(1, $appeals->where('status', Application::STATUS_PENDING)->count());
        // The other three were still pending, so they were reused.
        $this->assertSame(1, Application::where('module_type', 'ga_extension')->count());
    }
}
