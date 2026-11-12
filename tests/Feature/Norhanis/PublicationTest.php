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

    // ---- Conference / publication dates --------------------------------------

    public function test_an_end_date_before_the_start_date_is_rejected(): void
    {
        $this->actingAs($this->student())
            ->post(route('publication.store'), $this->payload([
                'conference_start_date' => now()->addDays(30)->toDateString(),
                'conference_end_date' => now()->addDays(29)->toDateString(),
            ]))
            ->assertSessionHasErrors(['conference_end_date' => 'The end date must be on or after the start date.']);

        $this->assertSame(0, PublicationDetail::count());
    }

    public function test_a_same_day_conference_is_accepted(): void
    {
        Storage::fake('local');
        Notification::fake();
        $day = now()->addDays(30)->toDateString();

        $this->actingAs($this->student())
            ->post(route('publication.store'), $this->payload(['conference_start_date' => $day, 'conference_end_date' => $day]))
            ->assertSessionHasNoErrors();

        $this->assertSame($day, PublicationDetail::sole()->conference_end_date->toDateString());
    }

    public function test_an_end_date_after_the_start_date_is_accepted(): void
    {
        Storage::fake('local');
        Notification::fake();

        $this->actingAs($this->student())
            ->post(route('publication.store'), $this->payload([
                'conference_start_date' => now()->addDays(30)->toDateString(),
                'conference_end_date' => now()->addDays(33)->toDateString(),
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(now()->addDays(33)->toDateString(), PublicationDetail::sole()->conference_end_date->toDateString());
    }

    public function test_the_form_keeps_the_end_date_from_going_before_the_start_date(): void
    {
        $student = $this->student();

        $this->actingAs($student)
            ->post(route('publication.store'), $this->payload([
                'conference_start_date' => now()->addDays(30)->toDateString(),
                'conference_end_date' => now()->addDays(29)->toDateString(),
            ]));

        $this->get(route('publication.create'))->assertOk()
            ->assertSee('min="'.now()->addDays(30)->toDateString().'"', false)
            ->assertSee('getElementById("conference_start_date")', false)
            ->assertSee('getElementById("conference_end_date")', false);
    }

    // ---- Authors -----------------------------------------------------------

    public function test_every_author_field_is_required_for_every_author(): void
    {
        $student = $this->student();
        $fields = ['author_name', 'email', 'designation', 'organisation', 'role_contribution'];

        foreach ($fields as $field) {
            foreach ([0, 1] as $index) {
                $payload = $this->payload(['authors' => [
                    ['author_name' => 'Ahmad Danial', 'email' => 'student@test.my'],
                    ['author_name' => 'Dr. Co Author', 'email' => 'co.author@example.edu'],
                ]]);
                $payload['authors'][$index][$field] = '';

                $this->actingAs($student)
                    ->post(route('publication.store'), $payload)
                    ->assertSessionHasErrors("authors.{$index}.{$field}");
            }
        }

        $this->assertSame(0, PublicationDetail::count());
    }

    public function test_author_one_is_prefilled_from_the_students_account_and_the_rest_start_empty(): void
    {
        $student = $this->student(attributes: ['programme' => 'PhD in Chemical Engineering (Full-Time)']);

        $response = $this->actingAs($student)->get(route('publication.create'))->assertOk();

        // Author 1: name, email, designation and organisation filled, all editable.
        foreach ([
            'author_0_name' => $student->name,
            'author_0_email' => $student->email,
            'author_0_designation' => 'PhD Student',
            'author_0_organisation' => 'Universiti Teknologi PETRONAS',
        ] as $id => $value) {
            $response->assertSee('id="'.$id.'" required maxlength', false)
                ->assertSee('value="'.e($value).'"', false);
        }
        $response->assertDontSee('id="author_0_designation" readonly', false);

        // Role / Contribution is the student's to say; no Author 2 row until added,
        // and the row added from the template starts blank.
        $response->assertSee('id="author_0_role" required maxlength="150" value=""', false)
            ->assertDontSee('id="author_1_name"', false)
            ->assertSee('name="authors[__INDEX__][designation]" required id="author___INDEX___designation" placeholder', false);
    }

    public function test_the_designation_follows_the_programme_and_falls_back_to_postgraduate_student(): void
    {
        $designation = fn (?string $programme) => \App\Modules\Norhanis\Support\ApplicantAuthor::designation($programme);

        $this->assertSame('PhD Student', $designation('PhD Part-Time'));
        $this->assertSame("Master's Student", $designation('MSc Full-Time'));
        $this->assertSame("Master's Student", $designation('Master of Science in Petroleum Engineering (Full-Time)'));
        $this->assertSame('Postgraduate Student', $designation(null));
    }

    public function test_an_edited_author_one_is_saved_as_typed(): void
    {
        Storage::fake('local');
        Notification::fake();

        $this->actingAs($this->student())
            ->post(route('publication.store'), $this->payload(['authors' => [[
                'author_name' => 'Ahmad Danial bin Rahman', 'email' => 'student@test.my',
                'designation' => 'Graduate Research Assistant', 'organisation' => 'UTP, Department of Computing',
                'role_contribution' => 'Lead author',
            ]]]))
            ->assertSessionHasNoErrors();

        $author = PublicationDetail::sole()->authors()->sole();
        $this->assertSame('Graduate Research Assistant', $author->designation);
        $this->assertSame('UTP, Department of Computing', $author->organisation);
    }

    // ---- One file per document -------------------------------------------

    public function test_each_publication_document_takes_exactly_one_file(): void
    {
        $student = $this->student();

        $this->actingAs($student)
            ->post(route('publication.store'), $this->payload(['invoice' => [$this->pdf('a.pdf'), $this->pdf('b.pdf')]]))
            ->assertSessionHasErrors(['invoice' => 'Attach one file only for the Invoice.']);
        $this->assertSame(0, PublicationDetail::count());

        $page = $this->get(route('publication.create'))->assertOk()
            ->assertSee('Compile the file into 1 if have more than 1 file.')
            ->assertDontSee('multiple', false)
            ->assertDontSee('name="invoice[]"', false);

        // The allowed types and size are as before.
        $page->assertSee('One file, PDF, JPG, JPEG, PNG or DOCX, 5&nbsp;MB max.', false);
    }

    public function test_one_file_per_document_is_stored_and_listed_once(): void
    {
        Storage::fake('local');
        Notification::fake();

        $this->actingAs($this->student())
            ->post(route('publication.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $application = Application::where('module_type', 'publication')->sole();
        $counts = $application->documents()->get()->countBy('doc_type');

        foreach (['Invoice', 'Index Proof', 'Turnitin Report', 'Letter of Acceptance', 'Conference/Journal Details'] as $type) {
            $this->assertSame(1, $counts[$type] ?? 0, $type);
        }
    }

    /**
     * What a conference request needs beyond a journal's: its venue, and its
     * only allowed cost centre with the words "Others" needs.
     */
    protected const CONFERENCE_FIELDS = ['venue' => 'Kuala Lumpur Convention Centre', 'cost_centre' => 'others', 'cost_centre_other' => 'FRG research grant'];

    public function test_a_conference_needs_a_venue_and_a_journal_keeps_none(): void
    {
        Storage::fake('local');
        Notification::fake();

        $student = $this->student();
        $conference = ['type_of_request' => 'publication_conference', 'conference_scope' => 'local'] + self::CONFERENCE_FIELDS;

        $this->actingAs($student)
            ->post(route('publication.store'), $this->payload(['venue' => ''] + $conference))
            ->assertSessionHasErrors('venue');

        $this->actingAs($student)
            ->post(route('publication.store'), $this->payload($conference))
            ->assertSessionHasNoErrors();
        $this->assertSame('Kuala Lumpur Convention Centre', PublicationDetail::sole()->venue);

        // A journal: no venue needed, and one posted anyway is not kept.
        PublicationDetail::query()->delete();
        Application::query()->delete();
        $this->actingAs($student)
            ->post(route('publication.store'), $this->payload(['venue' => 'Somewhere']))
            ->assertSessionHasNoErrors();
        $this->assertNull(PublicationDetail::sole()->venue);
    }

    public function test_the_venue_label_has_no_conference_only_note(): void
    {
        $this->actingAs($this->student())->get(route('publication.create'))
            ->assertOk()
            ->assertSee('<label for="venue" data-conference-only>Venue</label>', false)
            ->assertDontSee('conference only, e.g. the city and hall');
    }

    public function test_a_conference_may_use_only_the_others_cost_centre(): void
    {
        Storage::fake('local');
        Notification::fake();

        $student = $this->student();
        $conference = ['type_of_request' => 'publication_conference', 'conference_scope' => 'local'];

        // Every other option is refused, even posted from an edited form.
        foreach (['cgs_pay_and_claims', 'cgs_letter_of_undertaking', 'split_memo'] as $costCentre) {
            $this->actingAs($student)
                ->post(route('publication.store'), $this->payload($conference + ['cost_centre' => $costCentre]))
                ->assertSessionHasErrors(['cost_centre' => 'A conference request can only use the "Others" cost centre.']);
        }
        $this->assertSame(0, PublicationDetail::count());

        $this->actingAs($student)
            ->post(route('publication.store'), $this->payload($conference + self::CONFERENCE_FIELDS))
            ->assertSessionHasNoErrors();

        $detail = PublicationDetail::sole();
        $this->assertSame('others', $detail->cost_centre);
        $this->assertSame('Others: FRG research grant', $detail->costCentreLabel());
    }

    public function test_a_journal_may_use_any_of_the_four_cost_centres(): void
    {
        Storage::fake('local');
        Notification::fake();

        $choices = ['cgs_pay_and_claims', 'cgs_letter_of_undertaking', 'split_memo', 'others'];

        foreach ($choices as $i => $costCentre) {
            $email = "journal{$i}@test.my";

            $this->actingAs($this->student('2200200'.$i, $email))
                ->post(route('publication.store'), $this->payload([
                    'cost_centre' => $costCentre,
                    'cost_centre_other' => 'Department fund',
                    'authors' => [['author_name' => 'Journal Student', 'email' => $email]],
                ]))
                ->assertSessionHasNoErrors();
        }

        $this->assertEqualsCanonicalizing($choices, PublicationDetail::pluck('cost_centre')->all());
    }

    public function test_the_form_lists_the_renamed_split_memo_and_marks_the_journal_only_options(): void
    {
        $this->actingAs($this->student())->get(route('publication.create'))
            ->assertOk()
            ->assertSee('CGS &amp; Grant - Split Memo', false)
            ->assertDontSee('>Split Memo<', false)
            ->assertSee('<option value="split_memo"', false)
            ->assertSee('data-journal-only', false);
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
        $this->assertSame('CGS & Grant - Split Memo', $split->costCentreLabel());
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
        $this->assertNull($detail->venue); // a journal has no venue
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
            ->post(route('publication.store'), $this->payload(['type_of_request' => 'publication_conference'] + self::CONFERENCE_FIELDS))
            ->assertSessionHasErrors('conference_scope');
    }

    public function test_an_international_conference_needs_a_third_reviewer(): void
    {
        Storage::fake('local');
        Notification::fake();

        $student = $this->student();
        $conference = ['type_of_request' => 'publication_conference', 'conference_scope' => 'international'] + self::CONFERENCE_FIELDS;

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
            ] + self::CONFERENCE_FIELDS))
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
