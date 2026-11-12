<?php

namespace Tests\Feature\Norhanis;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Norhanis\Models\ClaimsDetail;
use App\Modules\Norhanis\Models\PublicationDetail;
use App\Modules\Norhanis\Models\TravelDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The supervisor stage of Travel, Publication and Claims belongs to the
 * student's OWN supervisor (users.supervisor_id), not to every supervisor.
 * Core checks only the role, so the module scopes it: the queue, the
 * decision, and Core's bulk decide (refused via DecidesOneAtATime).
 * See Concerns\ApprovesAndNotifies.
 */
class SupervisorScopeTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    /** module key => [queue route, decide route] */
    protected const MODULES = [
        'travel' => ['travel.queue', 'travel.decide'],
        'publication' => ['publication.queue', 'publication.decide'],
        'claims_student' => ['claims.queue', 'claims.decide'],
    ];

    protected User $mine;

    protected User $other;

    protected User $myStudent;

    protected User $theirStudent;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        // Same department on purpose: the rule is about who supervises the
        // student, not which department they are in.
        $this->mine = $this->user('supervisor', ['name' => 'Dr. Aisyah Rahman', 'email' => 'aisyah@test.my', 'department' => 'Computing']);
        $this->other = $this->user('supervisor', ['name' => 'Dr. Kumaran Vellu', 'email' => 'kumaran@test.my', 'department' => 'Computing']);

        $this->myStudent = $this->student('22001001', 'farah@test.my', ['name' => 'Nur Farah Adilah', 'department' => 'Computing', 'supervisor_id' => $this->mine->id]);
        $this->theirStudent = $this->student('22001002', 'ganesh@test.my', ['name' => 'Ganesh Pillai', 'department' => 'Computing', 'supervisor_id' => $this->other->id]);
    }

    public function test_a_supervisor_queue_lists_only_their_own_students(): void
    {
        foreach (self::MODULES as $module => [$queue]) {
            $myApplication = $this->atSupervisorStage($module, $this->myStudent);
            $theirApplication = $this->atSupervisorStage($module, $this->theirStudent);

            $this->actingAs($this->mine)->get(route($queue))
                ->assertOk()
                ->assertSee('Nur Farah Adilah')
                ->assertDontSee('Ganesh Pillai');

            $this->actingAs($this->other)->get(route($queue))
                ->assertOk()
                ->assertSee('Ganesh Pillai')
                ->assertDontSee('Nur Farah Adilah');

            $myApplication->delete();
            $theirApplication->delete();
        }
    }

    public function test_another_supervisor_cannot_decide_even_from_a_hand_built_request(): void
    {
        foreach (self::MODULES as $module => [, $decide]) {
            $application = $this->atSupervisorStage($module, $this->myStudent);

            foreach (['approve', 'reject'] as $decision) {
                $this->actingAs($this->other)
                    ->post(route($decide, $application), ['decision' => $decision, 'remarks' => 'Not mine'])
                    ->assertSessionHas('error', "Only the student's own supervisor can decide this application.");
            }

            $application->refresh();
            $this->assertSame(Application::STATUS_PENDING, $application->status, $module);
            $this->assertSame('supervisor', $application->current_stage, $module);
            $this->assertSame(0, $application->history()->count(), $module);
        }
    }

    public function test_the_students_own_supervisor_still_decides_it(): void
    {
        foreach (self::MODULES as $module => [, $decide]) {
            $application = $this->atSupervisorStage($module, $this->myStudent);

            $this->actingAs($this->mine)
                ->post(route($decide, $application), ['decision' => 'approve'])
                ->assertSessionHasNoErrors()
                ->assertSessionMissing('error');

            $application->refresh();
            $this->assertSame('chair', $application->current_stage, $module);
            $this->assertSame($this->mine->id, $application->history()->sole()->approver_id, $module);
        }
    }

    public function test_cores_bulk_decide_is_refused_so_it_cannot_skip_the_check(): void
    {
        foreach (array_keys(self::MODULES) as $module) {
            $application = $this->atSupervisorStage($module, $this->myStudent);

            $this->actingAs($this->other)
                ->post(route('queue.decide-bulk', $module), ['ids' => [$application->id], 'decision' => 'approve'])
                ->assertForbidden();

            $this->assertSame('supervisor', $application->refresh()->current_stage, $module);
        }
    }

    public function test_a_student_with_no_supervisor_on_record_is_decided_by_no_supervisor(): void
    {
        $orphan = $this->student('22001003', 'orphan@test.my', ['name' => 'No Supervisor Yet', 'department' => 'Computing']);
        $application = $this->atSupervisorStage('travel', $orphan);

        $this->actingAs($this->mine)->get(route('travel.queue'))->assertDontSee('No Supervisor Yet');
        $this->actingAs($this->mine)
            ->post(route('travel.decide', $application), ['decision' => 'approve'])
            ->assertSessionHas('error');

        $this->assertSame('supervisor', $application->refresh()->current_stage);
    }

    /** A pending application of $module, waiting at its supervisor stage. */
    protected function atSupervisorStage(string $module, User $student): Application
    {
        $application = Application::create([
            'student_id' => $student->id,
            'module_type' => $module,
            'status' => Application::STATUS_DRAFT,
        ]);

        match ($module) {
            'travel' => TravelDetail::create([
                'application_id' => $application->id,
                'type_of_request' => 'data_collection',
                'travel_start_date' => now()->addDays(20),
                'travel_end_date' => now()->addDays(22),
                'duration_days' => 3,
                'reason_for_travel' => 'Fieldwork',
                'destination_address' => 'Kuala Lumpur',
                'is_international' => false,
                'contact_person_email' => 'contact@test.my',
                'is_ga_gra' => false,
            ]),
            'publication' => PublicationDetail::create([
                'application_id' => $application->id,
                'type_of_request' => 'publication_journal',
                'title_of_paper' => 'Graph Networks',
                'title_of_conference_journal' => 'Journal of Petroleum Science',
                'organizer_publisher' => 'Elsevier',
                'conference_journal_fee' => 2500,
                'currency_type' => 'MYR',
                'cost_centre' => 'cgs_pay_and_claims',
                'conference_start_date' => now()->addDays(30),
                'conference_end_date' => now()->addDays(32),
            ]),
            'claims_student' => ClaimsDetail::create([
                'application_id' => $application->id,
                'bank_name' => 'Maybank',
                'bank_account_no' => '1234567890',
                'total_claims' => 500,
            ]),
        };

        $application = app(WorkflowEngine::class)->submit($application);
        $this->assertSame('supervisor', $application->current_stage);

        return $application;
    }
}
