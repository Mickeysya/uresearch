<?php

namespace Tests\Feature\Hani;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Hani\Models\VivaDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Both viva decisions do more than move a stage (the supervisor prompt on
 * acknowledgement; the mandatory reason and "returned" email on a softbound
 * return), so Core's bulk decide must refuse them and the queues must not
 * offer the checkboxes.
 */
class VivaBulkSafetyTest extends TestCase
{
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected User $ae;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->ae = $this->academicExec();
        $this->ae->update(['department' => 'Computing']);
    }

    protected function pending(string $module): Application
    {
        $student = $this->student(attributes: ['programme' => 'MSc Full-Time', 'department' => 'Computing']);

        $application = Application::create(['student_id' => $student->id, 'module_type' => $module, 'status' => Application::STATUS_DRAFT]);

        if ($module === 'viva_voce') {
            VivaDetail::create([
                'application_id' => $application->id, 'programme_level' => 'MSC',
                'thesis_title' => 'A thesis', 'expected_softbound_date' => now()->addMonths(4),
            ]);
        }

        return app(\App\Modules\Core\Services\WorkflowEngine::class)->submit($application);
    }

    public function test_bulk_acknowledge_of_notices_is_refused(): void
    {
        $notice = $this->pending('viva_voce');

        $this->actingAs($this->ae)
            ->post(route('queue.decide-bulk', 'viva_voce'), ['ids' => [$notice->id], 'decision' => 'approve'])
            ->assertForbidden();

        $this->assertSame(Application::STATUS_PENDING, $notice->fresh()->status);

        $this->actingAs($this->ae)->get(route('viva.queue'))
            ->assertOk()
            ->assertDontSee('name="ids[]"', false);
    }

    public function test_bulk_return_of_softbound_theses_is_refused(): void
    {
        $softbound = $this->pending('viva_softbound');

        $this->actingAs($this->ae)
            ->post(route('queue.decide-bulk', 'viva_softbound'), ['ids' => [$softbound->id], 'decision' => 'reject'])
            ->assertForbidden();

        $this->assertSame(Application::STATUS_PENDING, $softbound->fresh()->status);
    }
}
