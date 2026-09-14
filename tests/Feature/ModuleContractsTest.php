<?php

namespace Tests\Feature;

use App\Modules\Core\Contracts\SuppliesAttendance;
use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Services\StudentDashboard;
use App\Modules\Core\Support\Role;
use App\Modules\Norhanis\Models\TravelDetail;
use App\Modules\Nureen\Models\AttendanceRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The seams between Core and the module folders.
 *
 * These are the things that make the six-folder layout work, and the things
 * that break quietly: a chain whose shape depends on the application's own
 * data, a registry that has to describe a module it was never edited to know
 * about, and a Core dashboard reading a teammate's data without naming their
 * classes.
 */
class ModuleContractsTest extends TestCase
{
    use RefreshDatabase;

    protected function student(): User
    {
        return User::create([
            'name' => 'Test Student',
            'email' => 'student@test.my',
            'password' => 'password',
            'role' => Role::STUDENT,
            'matric_no' => '22009999',
        ]);
    }

    protected function travelApplication(User $student, bool $international): Application
    {
        $application = Application::create([
            'student_id' => $student->id,
            'module_type' => 'travel',
            'status' => Application::STATUS_DRAFT,
        ]);

        TravelDetail::create([
            'application_id' => $application->id,
            'type_of_request' => 'conference',
            'travel_start_date' => now()->addWeek(),
            'travel_end_date' => now()->addWeeks(2),
            'duration_days' => 8,
            'reason_for_travel' => 'Fieldwork',
            'destination_address' => $international ? 'Jakarta' : 'Ipoh',
            'is_international' => $international,
        ]);

        return $application;
    }

    /**
     * The reference module's conditional routing. Local travel finishes at the
     * Chair; international continues to CGS and the Dean. Same form, same
     * module, chain chosen from the application's own data -- which is what
     * lets the student's stepper show the right number of steps immediately.
     */
    public function test_travel_chains_differ_by_destination(): void
    {
        $student = $this->student();
        $module = app(ModuleRegistry::class)->get('travel');

        $local = $module->stages($this->travelApplication($student, international: false));
        $this->assertSame(['supervisor', 'chair'], array_map(fn ($s) => $s->key, $local));
        // The Chair has the final say on local travel, so that is where the
        // 'approved' verb lives.
        $this->assertSame('approved', $local[1]->decision);

        $abroad = $module->stages($this->travelApplication($student, international: true));
        $this->assertSame(
            ['supervisor', 'chair', 'cgs_review', 'dean'],
            array_map(fn ($s) => $s->key, $abroad)
        );
        $this->assertSame('approved', $abroad[3]->decision);
    }

    /**
     * stages(null) must return the SUPERSET, not the default chain. The
     * sidebar and the approval queues are built from it, so a stage only some
     * applications reach would otherwise be invisible to the role that owns
     * it -- a bug this project has already had once.
     */
    public function test_the_stage_superset_reaches_every_conditional_role(): void
    {
        $queues = app(ModuleRegistry::class)->queuesForRole(Role::DEAN_PGR);

        $this->assertNotEmpty(
            array_filter($queues, fn ($q) => $q['module']->key() === 'travel'),
            'The Dean owns a travel stage that only international applications reach, '
            .'and must still have a queue for it.'
        );
    }

    /** Every registered module must satisfy the contract the engine relies on. */
    public function test_every_registered_module_is_wired_correctly(): void
    {
        $modules = app(ModuleRegistry::class)->all();

        $this->assertNotEmpty($modules);

        foreach ($modules as $key => $module) {
            $this->assertInstanceOf(WorkflowModule::class, $module);
            $this->assertSame($key, $module->key(), 'A module is registered under a key it does not claim.');
            $this->assertNotEmpty($module->stages(null), "Module '{$key}' declares an empty stage chain.");
            $this->assertTrue(
                \Illuminate\Support\Facades\Route::has($module->queueRoute()),
                "Module '{$key}' names a queue route that is not registered."
            );

            if ($module->createRoute() !== null) {
                $this->assertTrue(
                    \Illuminate\Support\Facades\Route::has($module->createRoute()),
                    "Module '{$key}' names a create route that is not registered."
                );
            }
        }
    }

    /** Core reads attendance through the contract, never through Nureen's model. */
    public function test_core_reads_attendance_through_the_contract(): void
    {
        $student = $this->student();

        AttendanceRecord::create([
            'student_id' => $student->id,
            'period_end' => now()->subMonth(),
            'sessions_attended' => 18,
            'sessions_total' => 20,
        ]);

        $reading = app(SuppliesAttendance::class)->latestFor($student);

        // 18/20 = 90%, derived on save rather than trusted from the upload.
        $this->assertSame(90.0, $reading->percentage);
        $this->assertFalse($reading->at_risk);

        // And the dashboard gets the same figure without importing the model.
        $this->assertSame(90.0, (new StudentDashboard($student))->attendance()->percentage);
    }

    /**
     * With no module supplying attendance, the panel holds its skeleton rather
     * than fataling -- the degradation the class_exists() guard used to give.
     */
    public function test_the_attendance_panel_degrades_when_nothing_supplies_it(): void
    {
        $student = $this->student();

        // Simulate the module being absent by dropping the binding.
        app()->forgetInstance(SuppliesAttendance::class);
        app()->offsetUnset(SuppliesAttendance::class);

        $dashboard = new StudentDashboard($student);

        $this->assertNull($dashboard->attendance());
        $this->assertNull($dashboard->predictedAttendance());
        $this->assertArrayHasKey('attendance', $dashboard->unavailable());
    }
}
