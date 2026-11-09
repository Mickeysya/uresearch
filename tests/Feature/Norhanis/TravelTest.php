<?php

namespace Tests\Feature\Norhanis;

use App\Modules\Core\Contracts\SuppliesTeachingAssistantStatus;
use App\Modules\Core\Models\Application;
use App\Modules\Norhanis\Models\TaHourLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Travel — `docs/scope/norhanis.md` Module 1.
 *
 * Travel is the reference module and the only one whose chain branches, but it
 * had no test: Claims and Publication, both straight-line chains, did. These
 * cover the branch itself and the derived duration, which is the one number on
 * the form the student is not allowed to supply.
 */
class TravelTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    /** Memoised: MakesUsers::user() creates outright, so a second call collides on the unique email. */
    protected ?\App\Modules\Core\Models\User $sup = null;

    protected function sup(): \App\Modules\Core\Models\User
    {
        return $this->sup ??= $this->supervisor();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The supporting document is required, so every submission uploads one.
        \Illuminate\Support\Facades\Storage::fake('local');
    }

    public function test_local_travel_goes_through_cgs_to_the_senior_director(): void
    {
        $application = $this->submit(['is_international' => 0]);

        $this->actingAs($this->sup())
            ->post(route('travel.decide', $application), ['decision' => 'approve']);

        $this->actingAs($this->chair())
            ->post(route('travel.decide', $application), ['decision' => 'approve']);

        // The Chair no longer has the final say on local travel.
        $this->assertSame(Application::STATUS_PENDING, $application->refresh()->status);
        $this->assertSame('cgs_review', $application->current_stage);

        $this->actingAs($this->cgs())
            ->post(route('travel.decide', $application), ['decision' => 'approve']);

        // Local travel ends with the Senior Director CGS, never the Dean.
        $this->assertSame('senior_director', $application->refresh()->current_stage);

        $this->actingAs($this->dean())
            ->post(route('travel.decide', $application), ['decision' => 'approve']);
        $this->assertSame(Application::STATUS_PENDING, $application->refresh()->status);

        $this->actingAs($this->seniorDirector())
            ->post(route('travel.decide', $application), ['decision' => 'approve']);

        $this->assertSame(Application::STATUS_APPROVED, $application->refresh()->status);
    }

    public function test_local_or_international_must_be_chosen(): void
    {
        $student = $this->student();

        $this->actingAs($student)
            ->post(route('travel.store'), array_diff_key($this->payload(), ['is_international' => true]))
            ->assertSessionHasErrors('is_international');

        $this->actingAs($student)
            ->post(route('travel.store'), $this->payload(['is_international' => 'maybe']))
            ->assertSessionHasErrors('is_international');
    }

    public function test_the_supporting_document_is_required(): void
    {
        $this->actingAs($this->student())
            ->post(route('travel.store'), array_diff_key($this->payload(), ['supporting_document' => true]))
            ->assertSessionHasErrors('supporting_document');
    }

    public function test_the_cost_centre_is_optional_and_saved_when_given(): void
    {
        $with = $this->submitAs($this->student('22001001', 'a@test.my'), ['cost_centre' => 'YUTP-015LC0-123']);
        $without = $this->submitAs($this->student('22001002', 'b@test.my'));

        $this->assertSame('YUTP-015LC0-123', \App\Modules\Norhanis\Models\TravelDetail::where('application_id', $with->id)->value('cost_centre'));
        $this->assertNull(\App\Modules\Norhanis\Models\TravelDetail::where('application_id', $without->id)->value('cost_centre'));
    }

    public function test_international_travel_continues_to_cgs_and_the_dean(): void
    {
        $application = $this->submit(['is_international' => 1]);

        $this->actingAs($this->sup())
            ->post(route('travel.decide', $application), ['decision' => 'approve']);

        $this->actingAs($this->chair())
            ->post(route('travel.decide', $application), ['decision' => 'approve']);

        $application->refresh();

        // Same two approvals, but the chain is not finished this time.
        $this->assertSame(Application::STATUS_PENDING, $application->status);
        $this->assertSame('cgs_review', $application->current_stage);

        $this->actingAs($this->cgs())
            ->post(route('travel.decide', $application), ['decision' => 'approve']);

        $this->assertSame('dean', $application->refresh()->current_stage);

        $this->actingAs($this->dean())
            ->post(route('travel.decide', $application), ['decision' => 'approve']);

        $this->assertSame(Application::STATUS_APPROVED, $application->refresh()->status);
    }

    public function test_the_chair_cannot_finish_an_international_application(): void
    {
        $application = $this->submit(['is_international' => 1]);

        $this->actingAs($this->sup())
            ->post(route('travel.decide', $application), ['decision' => 'approve']);

        $this->actingAs($this->chair())
            ->post(route('travel.decide', $application), ['decision' => 'approve']);

        // The Dean must not be skippable by whoever acts next.
        $this->actingAs($this->cgs())
            ->post(route('travel.decide', $application), ['decision' => 'approve']);

        $this->assertNotSame(Application::STATUS_APPROVED, $application->refresh()->status);
    }

    public function test_duration_is_derived_from_the_dates_not_posted(): void
    {
        $application = $this->submit([
            'travel_start_date' => now()->addDays(10)->toDateString(),
            'travel_end_date' => now()->addDays(14)->toDateString(),
            // The legacy form let the student type this. Ignored now.
            'duration_days' => 99,
        ]);

        $detail = \App\Modules\Norhanis\Models\TravelDetail::where('application_id', $application->id)->sole();

        $this->assertSame(5, $detail->duration_days, 'Duration must be computed from the dates, inclusive.');
    }

    public function test_an_end_date_before_the_start_is_rejected(): void
    {
        $this->actingAs($this->student());

        $this->post(route('travel.store'), $this->payload([
            'travel_start_date' => now()->addDays(10)->toDateString(),
            'travel_end_date' => now()->addDays(3)->toDateString(),
        ]))->assertSessionHasErrors('travel_end_date');

        $this->assertSame(0, Application::where('module_type', 'travel')->count());
    }

    public function test_a_ga_gra_student_must_provide_the_ta_plan(): void
    {
        $this->asGa(20);
        $this->actingAs($this->student());

        // Hours completed come from the GA/GRA module, so only the rest is asked.
        $this->post(route('travel.store'), $this->payload())
            ->assertSessionHasErrors(['ta_hours_during_travel', 'ta_management_method'])
            ->assertSessionDoesntHaveErrors('ta_hours_completed');
    }

    public function test_the_ta_plan_is_saved_with_its_dates(): void
    {
        $this->asGa(20);

        $application = $this->submit([
            // Whatever the form claims, the GA/GRA module's figure is kept.
            'is_ga_gra' => 'no',
            'ta_hours_completed' => 99,
            'ta_hours_during_travel' => 8,
            'ta_management_method' => 'rescheduled_after_travel',
            'ta_plan_dates' => [now()->addDays(20)->toDateString(), now()->addDays(21)->toDateString()],
        ]);

        $plan = \App\Modules\Norhanis\Models\TravelTaPlan::where('application_id', $application->id)->sole();

        // Fixed per semester, never trusted from the form.
        $this->assertSame(84, $plan->total_hours_required);
        $this->assertEquals(20, $plan->hours_completed);
        $this->assertEquals(8, $plan->hours_during_travel);
        $this->assertSame('rescheduled_after_travel', $plan->management_method);
        $this->assertCount(2, $plan->dates);
    }

    public function test_no_ta_plan_is_saved_for_a_non_ga_gra_student(): void
    {
        $application = $this->submit(['is_ga_gra' => 'no']);

        $this->assertSame(0, \App\Modules\Norhanis\Models\TravelTaPlan::where('application_id', $application->id)->count());
    }

    public function test_proposed_dates_are_not_required_when_the_method_does_not_need_them(): void
    {
        $this->asGa(10);
        $application = $this->submit([
            'is_ga_gra' => 'yes',
            'ta_hours_completed' => 10,
            'ta_hours_during_travel' => 5,
            'ta_management_method' => 'no_ta_activities_affected',
        ]);

        $plan = \App\Modules\Norhanis\Models\TravelTaPlan::where('application_id', $application->id)->sole();
        $this->assertCount(0, $plan->dates);
    }

    public function test_proposed_dates_are_required_when_the_method_needs_them(): void
    {
        $this->asGa(10);
        $this->actingAs($this->student());

        $this->post(route('travel.store'), $this->payload([
            'is_ga_gra' => 'yes',
            'ta_hours_completed' => 10,
            'ta_hours_during_travel' => 5,
            'ta_management_method' => 'replacement_arrangement',
        ]))->assertSessionHasErrors('ta_plan_dates');
    }

    public function test_the_ta_plan_shows_in_the_supervisors_queue(): void
    {
        $this->asGa(15);
        $this->submit([
            'is_ga_gra' => 'yes',
            'ta_hours_completed' => 15,
            'ta_hours_during_travel' => 6,
            'ta_management_method' => 'completed_before_travel',
        ]);

        $this->actingAs($this->sup())
            ->get(route('travel.queue'))
            ->assertOk()
            ->assertSee('Completed before travel');
    }

    public function test_the_applicant_can_open_the_full_details_page(): void
    {
        $student = $this->student();
        $application = $this->submitAs($student);

        $this->actingAs($student)
            ->get(route('travel.show', $application))
            ->assertOk()
            ->assertSee('Universiti Malaya, Kuala Lumpur');
    }

    public function test_the_current_approver_can_open_the_full_details_page_but_an_unrelated_student_cannot(): void
    {
        $application = $this->submit(['is_international' => 0]);

        $this->actingAs($this->sup())
            ->get(route('travel.show', $application))
            ->assertOk();

        $this->actingAs($this->student('22009999', 'other@test.my'))
            ->get(route('travel.show', $application))
            ->assertForbidden();
    }

    public function test_only_the_applicant_can_update_the_supporting_document_and_only_for_international_travel(): void
    {
        $localStudent = $this->student('22002222', 'student2@test.my');
        $local = $this->submitAs($localStudent, ['is_international' => 0]);

        // Local travel: the page does not apply, even for the applicant.
        $this->actingAs($localStudent)
            ->get(route('travel.document.edit', $local))
            ->assertForbidden();

        $student = $this->student('22003333', 'student3@test.my');
        $international = $this->submitAs($student, ['is_international' => 1]);

        // Someone else's international application.
        $this->actingAs($this->student('22004444', 'student4@test.my'))
            ->get(route('travel.document.edit', $international))
            ->assertForbidden();

        // The applicant, on their own international application: allowed.
        $this->actingAs($student)
            ->get(route('travel.document.edit', $international))
            ->assertOk();
    }

    public function test_updating_the_supporting_document_replaces_the_file_and_logs_the_history(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Notification::fake();

        $student = $this->student();
        $application = $this->submitAs($student, [
            'is_international' => 1,
            'supporting_document' => \Illuminate\Http\UploadedFile::fake()->create('memo-old.pdf', 40, 'application/pdf'),
        ]);

        $old = $application->documents()->where('doc_type', 'Supporting Document')->sole();

        $this->actingAs($student)
            ->post(route('travel.document.update', $application), [
                'supporting_document' => \Illuminate\Http\UploadedFile::fake()->create('memo-new.pdf', 40, 'application/pdf'),
            ])
            ->assertRedirect(route('travel.show', $application));

        $this->assertFalse(\Illuminate\Support\Facades\Storage::disk('local')->exists($old->path));

        $new = $application->documents()->where('doc_type', 'Supporting Document')->sole();
        $this->assertSame('memo-new.pdf', $new->original_name);

        $this->assertDatabaseHas('approval_history', [
            'application_id' => $application->id,
            'remarks' => 'Supporting document updated',
        ]);

        \Illuminate\Support\Facades\Notification::assertSentTo($this->sup(), \App\Modules\Norhanis\Notifications\TravelSupportingDocumentUpdated::class);
    }

    public function test_the_form_renders_with_the_wizard_wired_up(): void
    {
        $this->actingAs($this->student());

        $this->get(route('travel.create'))
            ->assertOk()
            // The two halves of the stepper contract. If a refactor drops
            // either, the form silently reverts to one long scroll.
            ->assertSee('data-stepper', false)
            ->assertSee('class="fstep"', false);
    }

    public function test_a_student_without_a_ga_gra_appointment_gives_no_ta_plan_whatever_the_form_says(): void
    {
        // Haziq's module is present and has no appointment for this student.
        $application = $this->submit([
            'is_ga_gra' => 'yes',
            'ta_hours_completed' => 30,
            'ta_hours_during_travel' => 4,
            'ta_management_method' => 'completed_before_travel',
        ]);

        $this->assertFalse(\App\Modules\Norhanis\Models\TravelDetail::where('application_id', $application->id)->value('is_ga_gra'));
        $this->assertSame(0, \App\Modules\Norhanis\Models\TravelTaPlan::where('application_id', $application->id)->count());
    }

    public function test_the_form_shows_a_ga_their_hours_and_hides_the_ta_step_from_everyone_else(): void
    {
        $this->actingAs($this->student());

        $this->get(route('travel.create'))
            ->assertOk()
            ->assertDontSee('Teaching Assistant (TA) Plan')
            ->assertDontSee('Are you a GA/GRA recipient?');

        $this->asGa(40);

        $this->get(route('travel.create'))
            ->assertOk()
            ->assertSee('You hold an active GA appointment')
            ->assertSee('40 hours (logged since', false)
            ->assertDontSee('Are you a GA/GRA recipient?');
    }

    public function test_without_the_ga_gra_module_the_student_is_asked_as_before(): void
    {
        $this->app->offsetUnset(SuppliesTeachingAssistantStatus::class);

        $student = $this->student();

        $this->actingAs($student)
            ->get(route('travel.create'))
            ->assertSee('Are you a GA/GRA recipient?');

        $application = $this->submitAs($student, [
            'is_ga_gra' => 'yes',
            'ta_hours_completed' => 12,
            'ta_hours_during_travel' => 3,
            'ta_management_method' => 'completed_before_travel',
        ]);

        $this->assertEquals(12, \App\Modules\Norhanis\Models\TravelTaPlan::where('application_id', $application->id)->value('hours_completed'));
    }

    /* ---------------- helpers ---------------- */

    /** Stand in for the GA/GRA module: every student holds an appointment with $hours of TA done. */
    /**
     * Haziq's module says the student is a GA (or GRA), and this module's
     * TA Hours log holds $hours for them this semester -- logged the first
     * time the student is asked about, since the fake only learns who they
     * are then.
     */
    protected function asGa(float $hours, string $type = 'GA'): void
    {
        $this->app->instance(SuppliesTeachingAssistantStatus::class, new class($type, $hours) implements SuppliesTeachingAssistantStatus
        {
            public function __construct(private string $type, private float $hours) {}

            public function currentAppointmentFor(\App\Modules\Core\Models\User $student): ?array
            {
                if ($this->hours > 0 && ! TaHourLog::where('student_id', $student->id)->exists()) {
                    TaHourLog::create([
                        'student_id' => $student->id,
                        'activity_type' => 'Lab session',
                        'activity_date' => now()->toDateString(),
                        'hours' => $this->hours,
                    ]);
                }

                return ['type' => $this->type, 'since' => now()->subYear()];
            }
        });
    }

    protected function submit(array $overrides = []): Application
    {
        return $this->submitAs($this->student(), $overrides);
    }

    protected function submitAs(\App\Modules\Core\Models\User $student, array $overrides = []): Application
    {
        $student->update(['supervisor_id' => $this->sup()->id]);

        $this->actingAs($student)
            ->post(route('travel.store'), $this->payload($overrides))
            ->assertRedirect(route('applications.index'));

        return Application::where('student_id', $student->id)->where('module_type', 'travel')->sole();
    }

    protected function payload(array $overrides = []): array
    {
        return $overrides + [
            'type_of_request' => 'research_attachment',
            'travel_start_date' => now()->addDays(7)->toDateString(),
            'travel_end_date' => now()->addDays(9)->toDateString(),
            'reason_for_travel' => 'Reservoir simulation workshop',
            'destination_address' => 'Universiti Malaya, Kuala Lumpur',
            'is_international' => 0,
            'contact_person_email' => 'contact@utp.edu.my',
            'supporting_document' => \Illuminate\Http\UploadedFile::fake()->create('memo.pdf', 20, 'application/pdf'),
            'is_ga_gra' => 'no',
        ];
    }
}
