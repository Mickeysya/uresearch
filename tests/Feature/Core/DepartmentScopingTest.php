<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Role;
use App\Modules\Norhanis\Models\TravelDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * A department can now have more than one Chair or Academic Executive (see
 * Role::isDepartmentScoped() and the admin/CGS "add a user" screen this
 * unlocked), which is only real if each one's queue and decide button are
 * actually confined to their own department rather than the whole institute.
 *
 * Travel is the subject only because it is the reference module and its
 * Chair stage is what ChairDashboardTest and QueueTest already build
 * fixtures against. ApprovesApplications::queueFor() and
 * WorkflowEngine::decide() are the two places every module shares, so what
 * holds here holds for every other Chair- and Academic-Executive-owned queue
 * without a test of its own.
 */
class DepartmentScopingTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected function travelAwaitingChair(string $department): Application
    {
        $student = $this->user(Role::STUDENT, [
            'name' => 'Candidate '.$department,
            'email' => strtolower(str_replace([' ', '&'], ['-', 'and'], $department)).'-'.uniqid().'@test.my',
            'matric_no' => '22'.random_int(100000, 999999),
            'department' => $department,
        ]);

        $application = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'travel',
            'status' => Application::STATUS_PENDING,
            'current_stage' => 'chair',
            'submitted_at' => now()->subDay(),
        ]);

        TravelDetail::create([
            'application_id' => $application->id,
            'type_of_request' => 'Field Visit',
            'travel_start_date' => now()->addMonth(),
            'travel_end_date' => now()->addMonth()->addDays(3),
            'duration_days' => 4,
            'reason_for_travel' => 'Conference',
            'destination_address' => 'Kuala Lumpur',
            'is_international' => false,
        ]);

        return $application;
    }

    protected function chairOf(string $department): \App\Modules\Core\Models\User
    {
        return $this->user(Role::CHAIR, [
            'name' => 'Chair of '.$department,
            'email' => strtolower(str_replace([' ', '&'], ['-', 'and'], $department)).'-chair@test.my',
            'department' => $department,
        ]);
    }

    public function test_a_chairs_queue_only_shows_their_own_department(): void
    {
        $mine = $this->travelAwaitingChair('Civil Engineering');
        $this->travelAwaitingChair('Petroleum Engineering');

        $html = $this->actingAs($this->chairOf('Civil Engineering'))
            ->get(route('travel.queue', ['stage' => 'chair']))
            ->assertOk()
            ->assertSee('1 application awaiting')
            ->getContent();

        $this->assertStringContainsString('value="'.$mine->id.'"', $html);
    }

    public function test_a_chair_with_no_department_sees_only_undeclared_applications(): void
    {
        // Every seeded test account before this feature had a null
        // department, and the smoke test in QueueTest still creates one
        // that way for every stage. Null has to keep meaning "unassigned",
        // matching only students who are themselves unassigned, not "see
        // everything".
        $this->travelAwaitingChair('Civil Engineering');

        $chairWithNoDepartment = $this->user(Role::CHAIR, ['name' => 'Chair', 'email' => 'chair-none@test.my']);

        $this->actingAs($chairWithNoDepartment)
            ->get(route('travel.queue', ['stage' => 'chair']))
            ->assertOk()
            ->assertSee('Nothing is waiting on you right now.');
    }

    public function test_a_chair_cannot_decide_an_application_outside_their_department(): void
    {
        $theirs = $this->travelAwaitingChair('Petroleum Engineering');

        $this->actingAs($this->chairOf('Civil Engineering'))
            ->post(route('travel.decide', $theirs), ['decision' => 'approve'])
            ->assertSessionHas('error');

        $this->assertSame('chair', $theirs->fresh()->current_stage);
        $this->assertSame(Application::STATUS_PENDING, $theirs->fresh()->status);
    }

    public function test_a_chair_can_still_decide_their_own_departments_application(): void
    {
        $mine = $this->travelAwaitingChair('Civil Engineering');

        $this->actingAs($this->chairOf('Civil Engineering'))
            ->post(route('travel.decide', $mine), ['decision' => 'approve'])
            ->assertSessionHas('status');

        // Chair is local travel's last stage, so an approval here sets
        // status rather than moving current_stage on to anything else.
        $this->assertSame(Application::STATUS_APPROVED, $mine->fresh()->status);
    }

    public function test_bulk_deciding_skips_rows_outside_the_actors_department(): void
    {
        $mine = $this->travelAwaitingChair('Civil Engineering');
        $theirs = $this->travelAwaitingChair('Petroleum Engineering');

        $this->actingAs($this->chairOf('Civil Engineering'))
            ->post(route('queue.decide-bulk', 'travel'), [
                'ids' => [$mine->id, $theirs->id],
                'decision' => 'approve',
            ])
            ->assertSessionHas('status', '1 application approved. 1 skipped, already decided or not yours to act on.');

        $this->assertSame(Application::STATUS_APPROVED, $mine->fresh()->status);
        $this->assertSame(Application::STATUS_PENDING, $theirs->fresh()->status);
        $this->assertSame('chair', $theirs->fresh()->current_stage);
    }
}
