<?php

namespace Tests\Feature\Jason;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Jason\Models\HardboundSubmissionDetail;
use App\Modules\Jason\Models\PoolExaminer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Every screen in this module a human fills in is a stepper — `data-stepper`
 * plus `.fstep` fieldsets, driven by `core::partials.form-stepper`, which is
 * what the other five modules already use.
 *
 * These are render tests on purpose. The stepper is client-side by design
 * (the form still POSTs once, to the same route, with the same fields), so
 * what can go wrong server-side is the markup contract: a missing
 * `data-stepper`, a step that was never closed, or the partial not included
 * at all. All three produce a page that renders fine and a wizard that never
 * appears.
 */
class FormsTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected function assertIsStepper($response, int $steps): void
    {
        $html = $response->assertOk()->getContent();

        $this->assertStringContainsString('data-stepper', $html, 'The form is not marked as a stepper.');
        $this->assertSame(
            $steps,
            substr_count($html, 'class="fstep"'),
            "Expected {$steps} steps on this form."
        );
        // The partial that turns the markup into a wizard.
        $this->assertStringContainsString('form[data-stepper]', $html, 'core::partials.form-stepper was not included.');
    }

    public function test_the_hardbound_submission_form_is_a_stepper(): void
    {
        $student = $this->student(attributes: ['supervisor_id' => $this->supervisor()->id]);

        $response = $this->actingAs($student)->get(route('hardbound.create'));

        $this->assertIsStepper($response, 4);

        // Step 1 is the download, and it has to read as a button. As a link in
        // a list above the form it was being missed, which is the whole reason
        // it is a step of its own.
        $response->assertSee('Get the form')
            ->assertSee('class="btn-secondary"', false)
            ->assertSee(route('hardbound.template', 'submission'), false);
    }

    public function test_the_appeal_form_is_a_stepper_once_there_is_something_to_appeal(): void
    {
        $student = $this->student();

        // Nothing rejected yet: the page is an empty state, and there is no
        // form to make a stepper of.
        $this->actingAs($student)->get(route('hardbound-appeal.create'))
            ->assertOk()
            ->assertSee('You have no hardbound submission to appeal')
            ->assertDontSee('data-stepper', false);

        $rejected = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'hardbound_submission',
            'status' => Application::STATUS_REJECTED,
            'current_stage' => 'cgs_review',
        ]);

        HardboundSubmissionDetail::create([
            'application_id' => $rejected->id,
            'thesis_title' => 'A Study of Something',
            'matric_no' => $student->matric_no,
            'programme' => 'PhD Full-Time',
            'supervisor_name' => 'Dr. Supervisor',
            'viva_date' => now()->subMonth(),
        ]);

        $this->assertIsStepper($this->actingAs($student)->get(route('hardbound-appeal.create')), 3);
    }

    public function test_the_nomination_and_preparation_forms_are_steppers(): void
    {
        $chair = $this->user(Role::CHAIR, [
            'name' => 'Dr. Lim Wei Chun',
            'email' => 'chair@test.my',
            'department' => 'Civil & Environmental Engineering',
        ]);

        $candidate = $this->student(attributes: ['department' => $chair->department]);

        $internal = PoolExaminer::create([
            'examiner_type' => 'internal', 'name' => 'Dr. Internal', 'institution' => 'UTP',
            'email' => 'internal@utp.edu.my', 'expertise' => 'Structures', 'is_active' => true,
        ]);
        $external = PoolExaminer::create([
            'examiner_type' => 'external', 'name' => 'Dr. External', 'institution' => 'USM',
            'email' => 'external@usm.edu.my', 'expertise' => 'Geotechnics', 'is_active' => true,
        ]);

        $this->assertIsStepper($this->actingAs($chair)->get(route('appointment-letter.create')), 2);

        $this->actingAs($chair)
            ->post(route('appointment-letter.store'), [
                'student_id' => $candidate->id,
                'examiners' => [['pool_id' => $internal->id], ['pool_id' => $external->id]],
            ])
            ->assertSessionHasNoErrors();

        $application = Application::where('module_type', 'appointment_letter')->sole();

        // The AE endorses, which is what puts it on the CGS preparation desk.
        app(WorkflowEngine::class)->decide($application, $this->academicExec(), 'approve');
        $this->assertSame('cgs_prep', $application->fresh()->current_stage);

        $this->assertIsStepper(
            $this->actingAs($this->cgs())->get(route('appointment-letter.prepare', $application)),
            3
        );
    }

    /**
     * The workload chart used to load Chart.js from jsdelivr and run an
     * un-nonced inline script — both refused by `script-src 'self' <nonce>`,
     * so the chart silently never drew.
     */
    public function test_the_queue_chart_is_served_and_nonced_from_this_app(): void
    {
        $this->actingAs($this->academicExec());

        $html = $this->get(route('appointment-letter.queue'))->assertOk()->getContent();

        $this->assertStringNotContainsString('cdn.jsdelivr.net', $html);
        $this->assertStringNotContainsString('unpkg.com', $html);
    }
}
