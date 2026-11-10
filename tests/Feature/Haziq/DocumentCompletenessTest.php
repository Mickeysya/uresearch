<?php

namespace Tests\Feature\Haziq;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Haziq\Models\DocumentCompletenessReminder;
use App\Modules\Haziq\Models\GaApplicationDetail;
use App\Modules\Haziq\Models\GraApplicationDetail;
use App\Modules\Haziq\Models\GraGaStudentProfile;
use App\Modules\Haziq\Notifications\IncompleteDocumentsReminder;
use App\Modules\Haziq\Support\DocumentCompleteness;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Document completeness never gates the approval chain for most of it --
 * confirmed explicitly 2026-11-07: "application boleh terus ke approver
 * lain even document tak complete." It is a parallel tracking + reminder
 * concern only, see Support\DocumentCompleteness's own docblock. The one
 * deliberate exception, added 2026-11-10 ("admin taleh tekan verify
 * selagi document semua tak complete"): Admin's own admin_verify decision
 * specifically IS blocked while something required is still missing --
 * every later stage still isn't.
 */
class DocumentCompletenessTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected function profile(User $student): GraGaStudentProfile
    {
        return GraGaStudentProfile::firstOrCreate(
            ['user_id' => $student->id],
            [
                'level_of_study' => 'phd',
                'programme' => 'PhD Petroleum Engineering',
                'admission_date' => '2024-09-01',
                'bank_name' => 'Maybank',
                'bank_account_no' => '1234567890',
            ]
        );
    }

    protected function graApplication(User $student, string $status = Application::STATUS_PENDING, string $applicationType = 'new'): Application
    {
        $profile = $this->profile($student);

        $application = Application::create([
            'student_id' => $student->id,
            'module_type' => 'gra_application',
            'status' => $status,
            'current_stage' => $status === Application::STATUS_PENDING ? 'admin_verify' : null,
            'submitted_at' => now(),
        ]);

        GraApplicationDetail::create([
            'application_id' => $application->id,
            'profile_id' => $profile->id,
            'application_type' => $applicationType,
            'project_leader_name' => 'Dr. Lim',
            'project_title' => 'Reservoir Simulation under Uncertainty',
            'funding_source' => 'UTP Internal Grant',
            'service_start_date' => '2026-12-01',
            'service_end_date' => '2028-12-01',
        ]);

        return $application;
    }

    protected function gaApplication(User $student, string $status = Application::STATUS_PENDING): Application
    {
        $profile = $this->profile($student);

        $application = Application::create([
            'student_id' => $student->id,
            'module_type' => 'ga_application',
            'status' => $status,
            'current_stage' => $status === Application::STATUS_PENDING ? 'admin_verify' : null,
            'submitted_at' => now(),
        ]);

        GaApplicationDetail::create([
            'application_id' => $application->id,
            'profile_id' => $profile->id,
            'service_start_date' => '2026-12-01',
            'service_end_date' => '2027-06-01',
            'proposed_allowance' => 1500,
        ]);

        return $application;
    }

    /**
     * doc_type on the real row is the document's LABEL, not its field key
     * -- GaApplicationController::attachDocuments() passes $label straight
     * to DocumentStore::attach(), same as GRA's own. $key here is one of
     * DocumentCompleteness::requiredDocuments()'s own array keys, so the
     * label it stores can never drift from what missingFor() compares
     * against.
     */
    protected function attach(Application $application, string $key): ApplicationDocument
    {
        $label = DocumentCompleteness::requiredDocuments($application->module_type)[$key];

        return ApplicationDocument::create([
            'application_id' => $application->id,
            'doc_type' => $label,
            'original_name' => "{$key}.pdf",
            'path' => "fake/{$key}.pdf",
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
        ]);
    }

    /* -----------------------------------------------------------------
     | missingFor() / isComplete()
     |------------------------------------------------------------------*/

    public function test_a_gra_application_with_no_documents_is_missing_all_eight(): void
    {
        $application = $this->graApplication($this->student());

        $this->assertCount(8, DocumentCompleteness::missingFor($application));
        $this->assertFalse(DocumentCompleteness::isComplete($application));
    }

    public function test_a_ga_application_only_needs_its_own_three_documents(): void
    {
        $application = $this->gaApplication($this->student());
        $this->attach($application, 'resume');
        $this->attach($application, 'academic_transcript');
        $this->attach($application, 'visa_or_ic');

        $this->assertTrue(DocumentCompleteness::isComplete($application));
    }

    /* -----------------------------------------------------------------
     | trackedApplications() scope
     |------------------------------------------------------------------*/

    public function test_a_draft_is_never_tracked(): void
    {
        $application = $this->graApplication($this->student(), Application::STATUS_DRAFT);

        $this->assertCount(0, DocumentCompleteness::trackedApplications());
    }

    public function test_a_rejected_application_is_never_tracked(): void
    {
        $this->graApplication($this->student(), Application::STATUS_REJECTED);

        $this->assertCount(0, DocumentCompleteness::trackedApplications());
    }

    public function test_an_approved_or_returned_application_is_still_tracked(): void
    {
        $this->graApplication($this->student('22001001', 's1@test.my'), Application::STATUS_APPROVED);
        $this->graApplication($this->student('22001002', 's2@test.my'), Application::STATUS_RETURNED);

        $this->assertCount(2, DocumentCompleteness::trackedApplications());
    }

    public function test_a_gra_extension_is_never_tracked_even_though_it_shares_the_module_type(): void
    {
        $student = $this->student();
        $new = $this->graApplication($student, Application::STATUS_PENDING, 'new');
        $extension = $this->graApplication($student, Application::STATUS_PENDING, 'extension');

        $tracked = DocumentCompleteness::trackedApplications('gra_application');

        $this->assertTrue($tracked->contains('id', $new->id));
        $this->assertFalse($tracked->contains('id', $extension->id));
    }

    public function test_module_filter_scopes_to_one_module_only(): void
    {
        $this->graApplication($this->student('22001001', 's1@test.my'));
        $this->gaApplication($this->student('22001002', 's2@test.my'));

        $this->assertCount(1, DocumentCompleteness::trackedApplications('gra_application'));
        $this->assertCount(1, DocumentCompleteness::trackedApplications('ga_application'));
        $this->assertCount(2, DocumentCompleteness::trackedApplications());
    }

    /* -----------------------------------------------------------------
     | Admin's own verify is gated; nothing after it is
     |------------------------------------------------------------------*/

    public function test_admin_cannot_verify_a_gra_application_while_documents_are_still_missing(): void
    {
        $admin = $this->admin();
        $application = $this->graApplication($this->student(), Application::STATUS_PENDING);

        $this->assertFalse(DocumentCompleteness::isComplete($application));

        $this->actingAs($admin)->post(route('gra-application.decide', $application), ['decision' => 'approve'])
            ->assertRedirect();

        $this->assertSame('admin_verify', $application->fresh()->current_stage);
    }

    public function test_admin_can_verify_a_gra_application_once_every_document_is_attached(): void
    {
        $admin = $this->admin();
        $application = $this->graApplication($this->student(), Application::STATUS_PENDING);
        foreach (array_keys(DocumentCompleteness::requiredDocuments('gra_application')) as $key) {
            $this->attach($application, $key);
        }

        $this->actingAs($admin)->post(route('gra-application.decide', $application), ['decision' => 'approve'])
            ->assertRedirect();

        $this->assertSame('cgs_review', $application->fresh()->current_stage);
    }

    public function test_admin_cannot_verify_a_ga_application_while_documents_are_still_missing(): void
    {
        $admin = $this->admin();
        $application = $this->gaApplication($this->student(), Application::STATUS_PENDING);

        $this->actingAs($admin)->post(route('ga-application.decide', $application), ['decision' => 'approve'])
            ->assertRedirect();

        $this->assertSame('admin_verify', $application->fresh()->current_stage);
    }

    public function test_a_gra_extension_is_never_gated_on_documents_at_admin_verify(): void
    {
        $admin = $this->admin();
        $student = $this->student();
        $this->graApplication($student, Application::STATUS_APPROVED, 'new');
        $extension = $this->graApplication($student, Application::STATUS_PENDING, 'extension');

        // Never gated on these documents at Submit either -- see
        // DocumentCompleteness's own docblock -- so Admin's own stage
        // doesn't start requiring them here just because it does for a
        // 'new' application.
        $this->actingAs($admin)->post(route('gra-application.decide', $extension), ['decision' => 'approve'])
            ->assertRedirect();

        $this->assertSame('cgs_review', $extension->fresh()->current_stage);
    }

    public function test_an_incomplete_application_still_advances_through_every_later_stage(): void
    {
        $application = $this->graApplication($this->student(), Application::STATUS_PENDING);
        foreach (array_keys(DocumentCompleteness::requiredDocuments('gra_application')) as $key) {
            $this->attach($application, $key);
        }
        $this->actingAs($this->admin())->post(route('gra-application.decide', $application), ['decision' => 'approve']);
        // Delete one attached document back out, so the application moves
        // on with something missing again -- proving only Admin's own
        // stage actually checks this.
        ApplicationDocument::where('application_id', $application->id)
            ->where('doc_type', DocumentCompleteness::requiredDocuments('gra_application')['resume'])
            ->delete();

        $this->assertFalse(DocumentCompleteness::isComplete($application->fresh()));

        $this->actingAs($this->cgs())
            ->post(route('gra-application.decide', $application), ['decision' => 'approve', 'proposed_allowance' => 1000])
            ->assertRedirect();

        $this->assertSame('supervisor', $application->fresh()->current_stage);
    }

    /* -----------------------------------------------------------------
     | remind() / the weekly command
     |------------------------------------------------------------------*/

    public function test_remind_notifies_the_student_and_records_the_reminder(): void
    {
        Notification::fake();
        $student = $this->student();
        $application = $this->graApplication($student);

        DocumentCompleteness::remind($application);

        Notification::assertSentTo($student, IncompleteDocumentsReminder::class);
        $this->assertSame(1, DocumentCompletenessReminder::where('application_id', $application->id)->value('reminder_count'));
    }

    public function test_remind_does_nothing_once_every_document_is_attached(): void
    {
        Notification::fake();
        $student = $this->student();
        $application = $this->gaApplication($student);
        $this->attach($application, 'resume');
        $this->attach($application, 'academic_transcript');
        $this->attach($application, 'visa_or_ic');

        DocumentCompleteness::remind($application);

        Notification::assertNothingSent();
        $this->assertSame(0, DocumentCompletenessReminder::where('application_id', $application->id)->count());
    }

    public function test_the_weekly_command_reminds_every_incomplete_application_once(): void
    {
        Notification::fake();
        $gra = $this->student('22001001', 'gra@test.my');
        $ga = $this->student('22001002', 'ga@test.my');
        $graApp = $this->graApplication($gra);
        $gaApp = $this->gaApplication($ga);
        $this->attach($gaApp, 'resume');
        $this->attach($gaApp, 'academic_transcript');
        $this->attach($gaApp, 'visa_or_ic');

        $this->artisan('document-completeness:remind')->assertSuccessful();

        Notification::assertSentTo($gra, IncompleteDocumentsReminder::class);
        Notification::assertNotSentTo($ga, IncompleteDocumentsReminder::class);
    }

    public function test_the_weekly_command_does_not_remind_twice_inside_the_same_week(): void
    {
        Notification::fake();
        $student = $this->student();
        $this->graApplication($student);

        $this->artisan('document-completeness:remind');
        $this->artisan('document-completeness:remind');

        Notification::assertSentToTimes($student, IncompleteDocumentsReminder::class, 1);
    }

    public function test_the_weekly_command_reminds_again_once_a_week_has_passed(): void
    {
        Notification::fake();
        $student = $this->student();
        $this->travelTo(Carbon::parse('2026-11-02 08:00'));
        $this->graApplication($student);

        $this->artisan('document-completeness:remind');

        $this->travelTo(Carbon::parse('2026-11-09 08:01'));
        $this->artisan('document-completeness:remind');

        Notification::assertSentToTimes($student, IncompleteDocumentsReminder::class, 2);
    }

    /* -----------------------------------------------------------------
     | The report page
     |------------------------------------------------------------------*/

    public function test_a_student_cannot_view_the_report(): void
    {
        $this->actingAs($this->student())->get(route('document-completeness.index'))->assertForbidden();
    }

    public function test_only_admin_can_view_the_report(): void
    {
        $this->graApplication($this->student());

        $this->actingAs($this->admin())->get(route('document-completeness.index'))->assertOk();

        // Confirmed explicitly 2026-11-10: "document completeness analysis
        // ni boleh tengok by admin je user lain taleh tengok" -- narrower
        // than Stage Gate/Allowance's shared monitoring audience.
        foreach ([$this->cgs(), $this->supervisor(), $this->seniorDirector()] as $viewer) {
            $this->actingAs($viewer)->get(route('document-completeness.index'))->assertForbidden();
        }
    }

    public function test_only_admin_can_send_a_reminder_to_all(): void
    {
        $this->graApplication($this->student());

        foreach ([$this->cgs(), $this->supervisor(), $this->seniorDirector()] as $viewer) {
            $this->actingAs($viewer)->post(route('document-completeness.remind-all'))->assertForbidden();
        }
    }

    public function test_the_report_shows_each_students_missing_documents_and_the_right_counts(): void
    {
        $complete = $this->student('22001001', 'complete@test.my');
        $incomplete = $this->student('22001002', 'incomplete@test.my');
        $this->graApplication($incomplete);
        $gaApp = $this->gaApplication($complete);
        $this->attach($gaApp, 'resume');
        $this->attach($gaApp, 'academic_transcript');
        $this->attach($gaApp, 'visa_or_ic');

        $this->actingAs($this->admin())->get(route('document-completeness.index'))->assertOk()
            ->assertSee('Resume/CV')
            ->assertSee('Document completeness: 1 complete, 1 incomplete', false);
    }

    public function test_the_module_filter_narrows_the_report(): void
    {
        $gra = $this->student('22001001', 's1@test.my');
        $ga = $this->student('22001002', 's2@test.my');
        $this->graApplication($gra);
        $this->gaApplication($ga);

        $this->actingAs($this->admin())->get(route('document-completeness.index', ['module' => 'gra_application']))
            ->assertOk()->assertSee($gra->matric_no)->assertDontSee($ga->matric_no);
    }

    public function test_send_reminder_to_all_notifies_only_the_incomplete_ones_and_respects_the_filter(): void
    {
        Notification::fake();
        $incompleteGra = $this->student('22001001', 'gra@test.my');
        $incompleteGa = $this->student('22001002', 'ga@test.my');
        $this->graApplication($incompleteGra);
        $this->gaApplication($incompleteGa);

        $this->actingAs($this->admin())
            ->post(route('document-completeness.remind-all', ['module' => 'gra_application']))
            ->assertRedirect(route('document-completeness.index', ['module' => 'gra_application']));

        Notification::assertSentTo($incompleteGra, IncompleteDocumentsReminder::class);
        Notification::assertNotSentTo($incompleteGa, IncompleteDocumentsReminder::class);
    }

    /* -----------------------------------------------------------------
     | The student's own page
     |------------------------------------------------------------------*/

    public function test_the_student_sees_their_own_missing_documents_on_their_application_page(): void
    {
        $student = $this->student();
        $application = $this->graApplication($student);

        $this->actingAs($student)->get(route('gra-application.mine', $application))->assertOk()
            ->assertSee('Still missing 8 documents')
            ->assertSee('Resume/CV');
    }

    public function test_the_missing_documents_banner_disappears_once_every_document_is_attached(): void
    {
        $student = $this->student();
        $application = $this->gaApplication($student);
        $this->attach($application, 'resume');
        $this->attach($application, 'academic_transcript');
        $this->attach($application, 'visa_or_ic');

        $this->actingAs($student)->get(route('ga-application.mine', $application))->assertOk()
            ->assertDontSee('Still missing');
    }

    public function test_an_extension_application_never_shows_the_missing_documents_banner(): void
    {
        $student = $this->student();
        $extension = $this->graApplication($student, Application::STATUS_PENDING, 'extension');

        $this->actingAs($student)->get(route('gra-application.mine', $extension))->assertOk()
            ->assertDontSee('Still missing');
    }

    public function test_an_approver_never_sees_the_banner_on_the_same_show_page(): void
    {
        $student = $this->student();
        $application = $this->graApplication($student);

        $this->actingAs($this->admin())->get(route('gra-application.show', $application))->assertOk()
            ->assertDontSee('Still missing');
    }
}
