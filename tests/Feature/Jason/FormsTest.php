<?php

namespace Tests\Feature\Jason;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Jason\Models\AppointmentDetail;
use App\Modules\Jason\Models\AppointmentExaminer;
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

    public function test_the_preparation_form_is_a_stepper(): void
    {
        $candidate = $this->student(attributes: ['department' => 'Civil & Environmental Engineering']);

        $internal = PoolExaminer::create([
            'examiner_type' => 'internal', 'name' => 'Dr. Internal', 'institution' => 'UTP',
            'email' => 'internal@utp.edu.my', 'expertise' => 'Structures', 'is_active' => true,
        ]);
        $external = PoolExaminer::create([
            'examiner_type' => 'external', 'name' => 'Dr. External', 'institution' => 'USM',
            'email' => 'external@usm.edu.my', 'expertise' => 'Geotechnics', 'is_active' => true,
        ]);

        // Nomination has no form of its own any more, so straight into the
        // database, sitting on the stage this test actually exercises.
        $application = Application::create([
            'student_id' => $candidate->id,
            'submitted_by_id' => $this->chair()->id,
            'module_type' => 'appointment_letter',
            'status' => Application::STATUS_PENDING,
            'current_stage' => 'cgs_prep',
        ]);

        AppointmentDetail::create(['application_id' => $application->id]);
        AppointmentExaminer::create(['application_id' => $application->id] + $internal->toSnapshot());
        AppointmentExaminer::create(['application_id' => $application->id] + $external->toSnapshot());

        $this->assertIsStepper(
            $this->actingAs($this->cgs())->get(route('appointment-letter.prepare', $application)),
            3
        );
    }

    /**
     * The list and the add form are two pages. They were one, briefly, and it
     * did not work: `form-stepper` re-casts the whole `.card` it finds the
     * form in, so the page heading and "Step 1 of 3" ended up hoisted above
     * the full examiner table with the wizard appended underneath it.
     */
    public function test_the_examiner_list_and_the_add_form_are_separate_pages(): void
    {
        PoolExaminer::create([
            'examiner_type' => 'internal', 'name' => 'Dr. Internal', 'institution' => 'UTP',
            'email' => 'internal@utp.edu.my', 'expertise' => 'Structures', 'is_active' => true,
        ]);

        $cgs = $this->cgs();

        // The list is a list: no form on it beyond the per-row Remove button.
        $this->actingAs($cgs)->get(route('appointment-letter.examiners'))
            ->assertOk()
            ->assertSee('Dr. Internal')
            ->assertSee(route('appointment-letter.examiners.create'), false)
            ->assertDontSee('data-stepper', false);

        $response = $this->actingAs($cgs)->get(route('appointment-letter.examiners.create'));

        $this->assertIsStepper($response, 2);

        // Nothing here files an application, so the generated review must not
        // claim it goes to an approver.
        $html = $response->getContent();
        $this->assertStringContainsString('data-stepper-review=', $html);
        $this->assertStringNotContainsString('it goes to the first approver', $html);
    }

    /**
     * The preview of a picked signature has to be a data: URI. `img-src` is
     * "'self' data:" with no blob:, so URL.createObjectURL would be refused
     * by the CSP and the preview would silently never appear.
     */
    public function test_the_signature_preview_stays_inside_the_content_security_policy(): void
    {
        $html = $this->actingAs($this->chair())
            ->get(route('hardbound.signature'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('readAsDataURL(', $html);
        // The call, not the word -- the comment above it names the API it avoids.
        $this->assertStringNotContainsString('createObjectURL(', $html);
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
