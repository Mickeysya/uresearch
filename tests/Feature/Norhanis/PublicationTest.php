<?php

namespace Tests\Feature\Norhanis;

use App\Modules\Core\Models\Application;
use App\Modules\Norhanis\Models\PublicationDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Norhanis\Concerns\FilesPublications;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Publication funding — `docs/scope/norhanis.md`.
 *
 * The form itself: what it insists on before anything is saved. The author
 * list and the publication type are both read *before* validate() runs,
 * because they decide what else is required, and anything read before
 * validation is unchecked input.
 *
 * What happens after filing is in PublicationCertificationTest (authors) and
 * PublicationEvaluationTest (reviewers).
 */
class PublicationTest extends TestCase
{
    use FilesPublications;
    use MakesUsers;
    use RefreshDatabase;

    public function test_a_fresh_form_carries_no_error_markup_so_it_opens_on_the_first_step(): void
    {
        // The form stepper opens on the first step that holds a .field-error.
        // The signature box once carried an empty, hidden one for upload
        // errors, which opened every new form on the Authors step.
        $this->actingAs($this->student())
            ->get(route('publication.create'))
            ->assertOk()
            ->assertDontSee('class="field-error"', false)
            ->assertDontSee('class="is-invalid"', false);
    }

    public function test_a_scalar_author_list_is_a_validation_error_not_a_server_error(): void
    {
        // count() on a string is a TypeError in PHP 8, so an unvalidated
        // scalar here used to return a blank 500 instead of a form error.
        $response = $this->actingAs($this->student())
            ->post(route('publication.store'), ['authors' => 'not-an-array']);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('authors');
    }

    public function test_a_missing_author_list_is_a_validation_error(): void
    {
        $this->actingAs($this->student())
            ->post(route('publication.store'), [])
            ->assertSessionHasErrors('authors');
    }

    public function test_the_documents_that_are_required_when_filing(): void
    {
        $response = $this->actingAs($this->student())
            ->post(route('publication.store'), array_diff_key($this->payload(), $this->documents()));

        // Every document is required, as CGS asked.
        $response->assertSessionHasErrors([
            'invoice', 'index_proof', 'turnitin_report', 'letter_of_acceptance',
            'conference_journal_details', 'manuscript',
            'response_to_reviewer_comment', 'corrected_conference_journal_paper',
        ]);
        // The Authorship Contribution Form is gone, however many authors.
        $response->assertSessionDoesntHaveErrors(['authorship_contribution_form']);
    }

    public function test_the_cost_centre_is_chosen_from_the_list(): void
    {
        $student = $this->student();

        $this->actingAs($student)
            ->post(route('publication.store'), $this->payload(['cost_centre' => 'CGS(015BD1-001)']))
            ->assertSessionHasErrors('cost_centre');

        // "Others" needs the student's own words.
        $this->actingAs($student)
            ->post(route('publication.store'), $this->payload(['cost_centre' => 'others']))
            ->assertSessionHasErrors('cost_centre_other');
    }

    public function test_the_letter_of_undertaking_and_split_memo_come_from_the_cost_centre(): void
    {
        Storage::fake('local');
        Notification::fake();

        $this->actingAs($this->student())
            ->post(route('publication.store'), $this->payload(['cost_centre' => 'cgs_letter_of_undertaking']))
            ->assertSessionHasNoErrors();

        $lou = PublicationDetail::sole();
        $this->assertTrue($lou->wants_letter_of_undertaking);
        $this->assertSame('CGS – Letter of Undertaking', $lou->costCentreLabel());

        $this->actingAs($this->student('22001009', 'other@test.my', ['email' => 'other@test.my']))
            ->post(route('publication.store'), $this->payload([
                'cost_centre' => 'split_memo',
                'authors' => [['author_name' => 'Other Student', 'email' => 'other@test.my']],
            ]))
            ->assertSessionHasNoErrors();

        // A split memo is handled outside UResearch: nothing to sign here,
        // so only the student's own certification and the reviewers remain.
        $split = PublicationDetail::where('cost_centre', 'split_memo')->sole();
        $this->assertFalse($split->wants_letter_of_undertaking);
        $this->assertSame('Split Memo', $split->costCentreLabel());
    }

    public function test_a_journal_is_filed_with_two_reviewers_and_waits_for_them(): void
    {
        Storage::fake('local');
        Notification::fake();

        $this->actingAs($student = $this->student())
            ->post(route('publication.store'), $this->payload(['venue' => 'Online']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('publication.sign-offs', Application::sole()));

        $application = Application::where('module_type', 'publication')->sole();
        $detail = PublicationDetail::where('application_id', $application->id)->sole();

        $this->assertSame(Application::STATUS_DRAFT, $application->status);
        $this->assertNull($detail->conference_scope);
        $this->assertSame('Online', $detail->venue);
        $this->assertSame([1, 2], $detail->activeEvaluations->pluck('position')->all());
        $this->assertSame(1, $application->documents()->where('doc_type', 'Manuscript for Review')->count());
        $this->assertSame('CGS – Pay and Claims', $detail->costCentreLabel());
    }

    public function test_the_form_renders_with_no_downloads_left_on_it(): void
    {
        $this->actingAs($this->student())
            ->get(route('publication.create'))
            ->assertOk()
            ->assertSee('Cost Centre to be Utilized')
            ->assertDontSee('Fee in Ringgit')
            ->assertDontSee('Authorship Contribution Form')
            ->assertDontSee('Download');
    }

    public function test_a_conference_must_say_whether_it_is_local_or_international(): void
    {
        $this->actingAs($this->student())
            ->post(route('publication.store'), $this->payload(['type_of_request' => 'publication_conference']))
            ->assertSessionHasErrors('conference_scope');
    }

    public function test_an_international_conference_needs_a_third_reviewer(): void
    {
        Storage::fake('local');
        Notification::fake();

        $student = $this->student();
        $conference = ['type_of_request' => 'publication_conference', 'conference_scope' => 'international'];

        $this->actingAs($student)
            ->post(route('publication.store'), $this->payload($conference))
            ->assertSessionHasErrors('reviewers');

        $this->actingAs($student)
            ->post(route('publication.store'), $this->payload($conference + ['reviewers' => $this->reviewers(3)]))
            ->assertSessionHasNoErrors();

        $detail = PublicationDetail::sole();

        $this->assertSame('international', $detail->conference_scope);
        $this->assertSame(3, $detail->activeEvaluations()->count());
    }

    public function test_a_local_conference_keeps_only_two_reviewers(): void
    {
        Storage::fake('local');
        Notification::fake();

        // A third reviewer posted for a two-reviewer publication is dropped.
        $this->actingAs($this->student())
            ->post(route('publication.store'), $this->payload([
                'type_of_request' => 'publication_conference',
                'conference_scope' => 'local',
                'reviewers' => $this->reviewers(3),
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, PublicationDetail::sole()->activeEvaluations()->count());
    }

    public function test_a_reviewer_must_be_utp_staff_and_not_an_author_or_named_twice(): void
    {
        $student = $this->student();
        $file = fn (array $reviewers) => $this->actingAs($student)
            ->post(route('publication.store'), $this->payload(['reviewers' => $reviewers]));

        $file([
            ['name' => 'Dr. Siti Mariam', 'email' => 'siti.mariam@utp.edu.my'],
            ['name' => 'Prof. Outside', 'email' => 'outside@example.edu'],
        ])->assertSessionHasErrors('reviewers.1.email');

        $file([
            ['name' => 'Dr. Siti Mariam', 'email' => 'siti.mariam@utp.edu.my'],
            ['name' => 'Dr. Siti M.', 'email' => 'Siti.Mariam@utp.edu.my'],
        ])->assertSessionHasErrors('reviewers.0.email');

        // The student's own address is a UTP one here, so only the
        // author-as-reviewer rule can be what refuses it.
        $utpStudent = $this->student('22001009', 'ahmad@utp.edu.my');

        $this->actingAs($utpStudent)
            ->post(route('publication.store'), $this->payload([
                'authors' => [['author_name' => 'Ahmad Danial', 'email' => 'ahmad@utp.edu.my']],
                'reviewers' => [
                    ['name' => 'Dr. Siti Mariam', 'email' => 'siti.mariam@utp.edu.my'],
                    ['name' => 'Ahmad Danial', 'email' => 'ahmad@utp.edu.my'],
                ],
            ]))
            ->assertSessionHasErrors('reviewers');

        $this->assertSame(0, Application::count());
    }
}
