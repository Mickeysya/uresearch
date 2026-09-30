<?php

namespace Tests\Feature\Jason;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Jason\Models\HardboundSubmissionDetail;
use App\Modules\Jason\Support\AppointmentSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

    public function test_the_appeal_form_is_a_stepper(): void
    {
        // An appeal is an extension request, filed before the deadline by a
        // candidate who has not submitted, so there is no precondition to
        // meet and the form is always there. Three steps: the dates, the
        // blank memo to download, and the completed one coming back.
        $this->assertIsStepper(
            $this->actingAs($this->student())->get(route('hardbound-appeal.create')),
            3,
        );
    }

    public function test_the_appeal_form_stands_down_while_one_is_already_moving(): void
    {
        $student = $this->student();

        $open = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'hardbound_appeal',
            'status' => Application::STATUS_PENDING,
            'current_stage' => 'supervisor',
        ]);

        $this->actingAs($student)->get(route('hardbound-appeal.create'))
            ->assertOk()
            // The id is inside its own <b>, so the sentence is asserted in
            // the two pieces the markup actually renders.
            ->assertSee('is still being processed')
            ->assertSee("#{$open->id}")
            ->assertSee("Track appeal #{$open->id}")
            ->assertDontSee('data-stepper', false);
    }

    public function test_the_import_and_preparation_forms_are_steppers(): void
    {
        $cgs = $this->cgs();
        $candidate = $this->student(attributes: ['department' => 'Civil & Environmental Engineering']);

        $this->assertIsStepper($this->actingAs($cgs)->get(route('appointment-letter.import')), 2);

        // Importing the finalised list is what puts a candidate on the CGS
        // preparation desk; nobody fills in a nomination form any more.
        $csv = implode(',', AppointmentSheet::COLUMNS)."\n";

        foreach ([['internal', 'int@test.my'], ['external', 'ext@test.my']] as [$type, $email]) {
            $csv .= implode(',', [
                $candidate->matric_no, 'MSc in Civil Engineering', 'Civil Engineering',
                'Dr. Aisyah Rahman', 'A Study of Something',
                $type, 'Prof '.ucfirst($type), 'Somewhere', $email, 'An address',
            ])."\n";
        }

        $path = tempnam(sys_get_temp_dir(), 'list').'.csv';
        file_put_contents($path, $csv);

        $this->actingAs($cgs)
            ->post(route('appointment-letter.import.store'), [
                'sheet' => new UploadedFile($path, 'list.csv', 'text/csv', null, true),
            ])
            ->assertSessionHasNoErrors();

        $application = Application::where('module_type', 'appointment_letter')->sole();

        $this->assertSame('cgs_prep', $application->current_stage);

        $this->assertIsStepper(
            $this->actingAs($cgs)->get(route('appointment-letter.prepare', $application)),
            3
        );
    }

    /**
     * Core's Chair dashboard still links to the nomination form this module
     * used to own. `route()` throws on a name that does not exist, so the
     * name has to keep answering until Core's two Chair partials are
     * updated -- and what it answers with has to say where the work went.
     */
    public function test_the_retired_nomination_route_explains_itself_to_a_chair(): void
    {
        $chair = $this->user(Role::CHAIR, [
            'name' => 'Dr. Lim Wei Chun',
            'email' => 'chair@test.my',
            'department' => 'Civil & Environmental Engineering',
        ]);

        $this->actingAs($chair)->get(route('appointment-letter.create'))
            ->assertOk()
            ->assertSee('no longer nominated here');
    }

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

    public function test_the_queue_chart_is_served_and_nonced_from_this_app(): void
    {
        $this->actingAs($this->cgs());

        $html = $this->get(route('appointment-letter.queue'))->assertOk()->getContent();

        $this->assertStringNotContainsString('cdn.jsdelivr.net', $html);
        $this->assertStringNotContainsString('unpkg.com', $html);
    }
}
