<?php

namespace Tests\Feature\Norhanis;

use App\Modules\Core\Models\Application;
use App\Modules\Norhanis\Http\Controllers\PublicationController;
use App\Modules\Norhanis\Models\PublicationDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Publication funding — `docs/scope/norhanis.md`.
 *
 * One narrow thing, for now: the author list is read *before* validate() runs,
 * because its size decides whether the Authorship Contribution Form is
 * mandatory. Anything read before validation is unchecked input, and this is
 * the only place in the portal that does it.
 */
class PublicationTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    /** A 1x1 PNG, as the signature box would post it. */
    public const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

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

    public function test_every_document_is_required_except_the_grant_memo_and_the_contribution_form(): void
    {
        $response = $this->actingAs($this->student())
            ->post(route('publication.store'), $this->payload());

        $response->assertSessionHasErrors([
            'invoice', 'index_proof', 'turnitin_report', 'letter_of_acceptance',
            'conference_journal_details',
            'response_to_reviewer_comment', 'corrected_conference_journal_paper',
            'paper_evaluation_sheet_1', 'paper_evaluation_sheet_2',
        ]);
        $response->assertSessionDoesntHaveErrors([
            'approved_memo_for_utilizing_grant', 'authorship_contribution_form', 'paper_evaluation_sheet_3',
        ]);
    }

    public function test_a_journal_is_submitted_with_two_evaluation_sheets_and_no_grant_memo(): void
    {
        Storage::fake('local');
        Notification::fake();

        $this->supervisor();

        $this->actingAs($this->student())
            ->post(route('publication.store'), $this->payload($this->documents()))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('applications.index'));

        $application = Application::where('module_type', 'publication')->sole();

        $this->assertNull(PublicationDetail::where('application_id', $application->id)->sole()->conference_scope);
        $this->assertSame(2, $application->documents()->where('doc_type', 'like', 'Paper Evaluation Sheet%')->count());
        $this->assertSame(0, $application->documents()->where('doc_type', 'Approved Memo for Utilizing Grant')->count());
    }

    public function test_a_conference_must_say_whether_it_is_local_or_international(): void
    {
        $this->actingAs($this->student())
            ->post(route('publication.store'), $this->payload(['type_of_request' => 'publication_conference']))
            ->assertSessionHasErrors('conference_scope');
    }

    public function test_an_international_conference_needs_a_third_evaluation_sheet(): void
    {
        Storage::fake('local');
        Notification::fake();

        $this->supervisor();
        $student = $this->student();

        $conference = ['type_of_request' => 'publication_conference', 'conference_scope' => 'international'];

        $this->actingAs($student)
            ->post(route('publication.store'), $this->payload($conference + $this->documents()))
            ->assertSessionHasErrors('paper_evaluation_sheet_3');

        $this->actingAs($student)
            ->post(route('publication.store'), $this->payload($conference + $this->documents() + [
                'paper_evaluation_sheet_3' => $this->pdf('sheet-3.pdf'),
            ]))
            ->assertSessionHasNoErrors();

        $application = Application::where('module_type', 'publication')->sole();

        $this->assertSame('international', PublicationDetail::where('application_id', $application->id)->sole()->conference_scope);
        $this->assertSame(3, $application->documents()->where('doc_type', 'like', 'Paper Evaluation Sheet%')->count());
    }

    public function test_a_local_conference_needs_only_two_evaluation_sheets(): void
    {
        Storage::fake('local');
        Notification::fake();

        $this->supervisor();

        $this->actingAs($this->student())
            ->post(route('publication.store'), $this->payload([
                'type_of_request' => 'publication_conference',
                'conference_scope' => 'local',
            ] + $this->documents()))
            ->assertSessionHasNoErrors();
    }

    public function test_the_form_offers_the_blank_templates_and_each_one_downloads(): void
    {
        $student = $this->student();

        $page = $this->actingAs($student)->get(route('publication.create'))->assertOk();

        foreach (array_keys(PublicationController::TEMPLATES) as $slug) {
            $page->assertSee(route('publication.template', $slug));

            $this->actingAs($student)
                ->get(route('publication.template', $slug))
                ->assertOk()
                ->assertDownload();
        }

        $this->actingAs($student)->get(route('publication.template', 'something-else'))->assertNotFound();
    }

    /** A valid journal application, minus its documents. */
    protected function payload(array $overrides = []): array
    {
        return $overrides + [
            'type_of_request' => 'publication_journal',
            'title_of_paper' => 'Reservoir Simulation with Graph Networks',
            'title_of_conference_journal' => 'Journal of Petroleum Science',
            'organizer_publisher' => 'Elsevier',
            'conference_journal_fee' => '2500.00',
            'currency_type' => 'MYR',
            'cost_centre' => 'CGS(015BD1-001)',
            'conference_start_date' => now()->addDays(30)->toDateString(),
            'conference_end_date' => now()->addDays(32)->toDateString(),
            // The student is the only author, and signs on the form, so the
            // application is complete the moment it is filed.
            'authors' => [['author_name' => 'Ahmad Danial', 'email' => 'student@test.my']],
            'applicant_certifies' => '1',
            'applicant_signature' => self::SIGNATURE,
        ];
    }

    /** Every document a two-reviewer publication must carry. */
    protected function documents(): array
    {
        $fields = [
            'invoice', 'index_proof', 'turnitin_report', 'letter_of_acceptance',
            'conference_journal_details',
            'response_to_reviewer_comment', 'corrected_conference_journal_paper',
            'paper_evaluation_sheet_1', 'paper_evaluation_sheet_2',
        ];

        return array_combine($fields, array_map(fn ($field) => $this->pdf($field.'.pdf'), $fields));
    }

    protected function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->create($name, 40, 'application/pdf');
    }
}
