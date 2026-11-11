<?php

namespace Tests\Feature\Haziq;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Haziq\Models\GaApplicationDetail;
use App\Modules\Haziq\Models\GraApplicationDetail;
use App\Modules\Haziq\Models\TermsAcceptance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * GRA and GA Application saved drafts on "My Drafts" -- a page owned by
 * Nureen's module (Support\Drafts::MODULES), extended here to also list
 * these two module types. Continue/Delete land on Haziq's own routes
 * (editDraft()/destroyDraft()), never on Nureen's Support\Drafts.
 */
class DraftIntegrationTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    /** MakesUsers::student(), plus both Scheme Terms accepted -- create()/store() gate on these. */
    protected function studentWithTermsAccepted(string $matric = '22001001', string $email = 'student@test.my'): \App\Modules\Core\Models\User
    {
        $student = $this->student($matric, $email);
        TermsAcceptance::create(['user_id' => $student->id, 'scheme' => TermsAcceptance::GRA, 'accepted_at' => now()]);
        TermsAcceptance::create(['user_id' => $student->id, 'scheme' => TermsAcceptance::GA, 'accepted_at' => now()]);

        return $student;
    }

    protected function graFields(): array
    {
        return [
            'project_leader_name' => 'Dr. Lim',
            'project_title' => 'Reservoir Simulation under Uncertainty',
            'funding_source' => 'UTP Internal Grant',
            'service_start_date' => '2026-12-01',
            'proposed_allowance' => '1500',
            'level_of_study' => 'phd',
            'programme' => 'PhD Petroleum Engineering',
            'admission_date' => '2024-09-01',
            'bank_name' => 'Maybank',
            'bank_account_no' => '1234567890',
        ];
    }

    protected function gaFields(): array
    {
        return [
            'service_start_date' => '2026-12-01',
            'service_end_date' => '2027-06-01',
            'proposed_allowance' => '1500',
            'level_of_study' => 'master',
            'programme' => 'MSc Data Science',
            'admission_date' => '2025-09-01',
        ];
    }

    protected function graDraft(): Application
    {
        return Application::where('module_type', 'gra_application')->where('status', Application::STATUS_DRAFT)->latest('id')->firstOrFail();
    }

    protected function gaDraft(): Application
    {
        return Application::where('module_type', 'ga_application')->where('status', Application::STATUS_DRAFT)->latest('id')->firstOrFail();
    }

    public function test_a_saved_gra_and_ga_draft_both_appear_on_my_drafts(): void
    {
        $student = $this->studentWithTermsAccepted();

        $this->actingAs($student)->post(route('gra-application.store'), $this->graFields() + ['action' => 'save_draft'])->assertRedirect(route('gra-application.create'));
        $gra = $this->graDraft();

        $this->actingAs($student)->post(route('ga-application.store'), $this->gaFields() + ['action' => 'save_draft'])->assertRedirect(route('ga-application.create'));
        $ga = $this->gaDraft();

        $this->actingAs($student)->get(route('nureen.drafts'))->assertOk()
            ->assertSee('GRA Application')
            ->assertSee('Reservoir Simulation under Uncertainty')
            ->assertSee(route('gra-application.draft.edit', $gra))
            ->assertSee(route('gra-application.draft.destroy', $gra))
            ->assertSee('GA Application')
            ->assertSee(route('ga-application.draft.edit', $ga))
            ->assertSee(route('ga-application.draft.destroy', $ga));
    }

    public function test_the_drafts_badge_on_the_dashboard_counts_both_modules(): void
    {
        $student = $this->studentWithTermsAccepted();

        $this->actingAs($student)->post(route('gra-application.store'), $this->graFields() + ['action' => 'save_draft']);
        $this->actingAs($student)->post(route('ga-application.store'), $this->gaFields() + ['action' => 'save_draft']);

        $this->actingAs($student)->get(route('dashboard'))->assertOk()->assertSee('My Drafts (2)');
    }

    public function test_continuing_a_gra_draft_reopens_the_prefilled_form(): void
    {
        $student = $this->studentWithTermsAccepted();
        $this->actingAs($student)->post(route('gra-application.store'), $this->graFields() + ['action' => 'save_draft']);
        $gra = $this->graDraft();

        $this->actingAs($student)->get(route('gra-application.draft.edit', $gra))->assertOk()
            ->assertSee('Reservoir Simulation under Uncertainty');
    }

    public function test_continuing_a_ga_draft_reopens_the_prefilled_form(): void
    {
        $student = $this->studentWithTermsAccepted();
        $this->actingAs($student)->post(route('ga-application.store'), $this->gaFields() + ['action' => 'save_draft']);
        $ga = $this->gaDraft();

        $this->actingAs($student)->get(route('ga-application.draft.edit', $ga))->assertOk()
            ->assertSee('MSc Data Science');
    }

    public function test_another_student_cannot_continue_or_delete_someone_elses_draft(): void
    {
        $student = $this->studentWithTermsAccepted();
        $this->actingAs($student)->post(route('gra-application.store'), $this->graFields() + ['action' => 'save_draft']);
        $gra = $this->graDraft();

        $other = $this->studentWithTermsAccepted('22001002', 'other@test.my');

        $this->actingAs($other)->get(route('gra-application.draft.edit', $gra))->assertForbidden();
        $this->actingAs($other)->delete(route('gra-application.draft.destroy', $gra))->assertForbidden();
        $this->actingAs($other)->get(route('nureen.drafts'))->assertDontSee('Reservoir Simulation under Uncertainty');

        $this->assertSame(Application::STATUS_DRAFT, $gra->fresh()->status);
    }

    public function test_deleting_a_gra_draft_removes_it_completely(): void
    {
        $student = $this->studentWithTermsAccepted();
        $this->actingAs($student)->post(route('gra-application.store'), $this->graFields() + ['action' => 'save_draft']);
        $gra = $this->graDraft();

        $this->actingAs($student)->delete(route('gra-application.draft.destroy', $gra))->assertRedirect(route('nureen.drafts'));

        $this->assertNull(Application::find($gra->id));
        $this->assertSame(0, GraApplicationDetail::where('application_id', $gra->id)->count());
        $this->assertSame(0, ApplicationDocument::where('application_id', $gra->id)->count());
    }

    public function test_deleting_a_ga_draft_removes_it_completely(): void
    {
        $student = $this->studentWithTermsAccepted();
        $this->actingAs($student)->post(route('ga-application.store'), $this->gaFields() + ['action' => 'save_draft']);
        $ga = $this->gaDraft();

        $this->actingAs($student)->delete(route('ga-application.draft.destroy', $ga))->assertRedirect(route('nureen.drafts'));

        $this->assertNull(Application::find($ga->id));
        $this->assertSame(0, GaApplicationDetail::where('application_id', $ga->id)->count());
    }

    public function test_a_submitted_application_is_not_reachable_as_a_draft(): void
    {
        $student = $this->studentWithTermsAccepted();
        $application = Application::create([
            'student_id' => $student->id,
            'module_type' => 'gra_application',
            'status' => Application::STATUS_PENDING,
            'current_stage' => 'supervisor',
        ]);

        $this->actingAs($student)->get(route('gra-application.draft.edit', $application))->assertNotFound();
        $this->actingAs($student)->delete(route('gra-application.draft.destroy', $application))->assertNotFound();
    }
}
