<?php

namespace Tests\Feature\Norhanis;

use App\Modules\Core\Models\Application;
use App\Modules\Norhanis\Models\PublicationDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use App\Modules\Norhanis\Support\TravelDates;
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

    // ---- No venue, no publication dates (UAT, 11 Nov 2026) -------------------

    public function test_the_venue_and_the_old_dates_are_gone(): void
    {
        $student = $this->student();

        Storage::fake('local');
        Notification::fake();

        $html = $this->actingAs($student)->get(route('publication.create'))->assertOk()->getContent();

        foreach (['name="venue"', 'conference_start_date', 'conference_end_date', 'Conference / Publication Start Date', '>Venue<'] as $gone) {
            $this->assertStringNotContainsString($gone, $html, $gone);
        }

        foreach (['venue', 'conference_start_date', 'conference_end_date'] as $column) {
            $this->assertFalse(Schema::hasColumn('publication_details', $column), $column);
        }

        // Posted anyway (an old tab, an edited form): accepted without them.
        $this->actingAs($student)
            ->post(route('publication.store'), $this->payload([
                'venue' => 'Somewhere',
                'conference_start_date' => now()->addDays(3)->toDateString(),
                'conference_end_date' => now()->subDays(3)->toDateString(),
            ]))
            ->assertSessionHasNoErrors();
    }

    // ---- Travel Details: a conference only ------------------------------------

    public function test_a_conference_requires_every_travel_detail(): void
    {
        $student = $this->student();

        $conference = ['type_of_request' => 'publication_conference', 'conference_scope' => 'local'];

        foreach (array_keys($this->travelDetails()) as $field) {
            $this->actingAs($student)
                ->post(route('publication.store'), $this->payload($conference + $this->conferenceFields([$field => ''])))
                ->assertSessionHasErrors($field);
        }

        $this->actingAs($student)
            ->post(route('publication.store'), $this->payload($conference + $this->conferenceFields(['contact_person_email' => 'not-an-email'])))
            ->assertSessionHasErrors('contact_person_email');

        $this->assertSame(0, PublicationDetail::count());
    }

    public function test_a_conference_stores_its_travel_details_with_the_duration_worked_out(): void
    {
        Storage::fake('local');
        Notification::fake();

        $this->actingAs($this->student())
            ->post(route('publication.store'), $this->payload(['type_of_request' => 'publication_conference', 'conference_scope' => 'local']
                + $this->conferenceFields([
                    'travel_start_date' => now()->addDays(30)->toDateString(),
                    'travel_end_date' => now()->addDays(33)->toDateString(),
                    // A posted duration is never trusted.
                    'duration_days' => '99',
                ])))
            ->assertSessionHasNoErrors();

        $detail = PublicationDetail::sole();
        $this->assertSame(now()->addDays(30)->toDateString(), $detail->travel_start_date->toDateString());
        $this->assertSame(now()->addDays(33)->toDateString(), $detail->travel_end_date->toDateString());
        $this->assertSame(4, $detail->duration_days);
        $this->assertSame('Present the paper', $detail->reason_for_travel);
        $this->assertSame('Kuala Lumpur Convention Centre', $detail->destination_address);
        $this->assertSame(['Dr. Siti Mariam', '012-3456789', 'siti.mariam@utp.edu.my'], [$detail->contact_person_name, $detail->contact_person_no, $detail->contact_person_email]);
    }

    public function test_a_journal_ignores_any_travel_details_posted(): void
    {
        Storage::fake('local');
        Notification::fake();

        // Even ones no rule would accept: a journal's are never validated.
        $this->actingAs($this->student())
            ->post(route('publication.store'), $this->payload($this->travelDetails([
                'travel_start_date' => now()->subYear()->toDateString(),
                'travel_end_date' => now()->subYears(2)->toDateString(),
                'contact_person_email' => 'not-an-email',
            ])))
            ->assertSessionHasNoErrors();

        $detail = PublicationDetail::sole();
        foreach ([...PublicationDetail::TRAVEL_FIELDS, 'duration_days'] as $column) {
            $this->assertNull($detail->{$column}, $column);
        }
        $this->assertSame([], $detail->travelDetailRows());
    }

    public function test_the_travel_end_date_must_be_on_or_after_the_start_date(): void
    {
        $student = $this->student();

        Storage::fake('local');
        Notification::fake();

        $conference = ['type_of_request' => 'publication_conference', 'conference_scope' => 'local'];

        $this->actingAs($student)
            ->post(route('publication.store'), $this->payload($conference + $this->conferenceFields([
                'travel_start_date' => now()->addDays(30)->toDateString(),
                'travel_end_date' => now()->addDays(29)->toDateString(),
            ])))
            ->assertSessionHasErrors(['travel_end_date' => 'The end date must be on or after the start date.']);

        $this->actingAs($student)
            ->post(route('publication.store'), $this->payload($conference + $this->conferenceFields([
                'travel_start_date' => now()->subDay()->toDateString(),
                'travel_end_date' => now()->addDay()->toDateString(),
            ])))
            ->assertSessionHasErrors(['travel_start_date' => 'Travel cannot start in the past.']);

        $this->assertSame(0, PublicationDetail::count());

        // Same day: allowed, one day long.
        $day = now()->addDays(30)->toDateString();
        $this->actingAs($student)
            ->post(route('publication.store'), $this->payload($conference + $this->conferenceFields(['travel_start_date' => $day, 'travel_end_date' => $day])))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, PublicationDetail::sole()->duration_days);
    }

    public function test_the_duration_counts_both_days_like_travel(): void
    {
        $this->assertSame(1, TravelDates::durationDays('2026-12-01', '2026-12-01'));
        $this->assertSame(3, TravelDates::durationDays('2026-12-01', '2026-12-03'));
        $this->assertSame(32, TravelDates::durationDays('2026-12-31', '2027-01-31'));
        // Across the end of February in a leap year.
        $this->assertSame(3, TravelDates::durationDays('2028-02-28', '2028-03-01'));
    }

    public function test_the_travel_details_sit_in_the_paper_step_for_a_conference_only(): void
    {
        $html = $this->actingAs($this->student())->get(route('publication.create'))->assertOk()->getContent();

        // No step of its own, so a journal never stops on it: a section of
        // the Paper step, hidden for a journal by the conference-only switch.
        $this->assertStringNotContainsString('data-label="Travel Details"', $html);
        $paper = substr($html, strpos($html, 'data-label="Paper"'), strpos($html, 'data-label="Cost"') - strpos($html, 'data-label="Paper"'));
        $this->assertStringContainsString('<div class="pub-travel" data-conference-only>', $paper);
        $this->assertStringContainsString('<h3 class="pub-travel-head">Travel Details</h3>', $paper);
        $this->assertStringContainsString('<h4 class="pub-travel-subhead">Contact Person While Travelling</h4>', $paper);

        foreach (['Travel Start Date', 'Travel End Date', 'Duration of Travelling (Days)', 'Reason for Travelling', 'Destination Address', 'Contact No.', 'Email Address'] as $label) {
            $this->assertStringContainsString($label, $paper, $label);
        }
        $this->assertMatchesRegularExpression('/<input type="text" id="duration_days" readonly/', $html);
        $this->assertStringNotContainsString('name="duration_days"', $html);
        $this->assertMatchesRegularExpression('/id="travel_start_date"[^>]*min="'.now()->toDateString().'"/s', $html);
        // The shared date-range script locks the end to the start and fills the duration.
        $this->assertStringContainsString('getElementById("travel_start_date")', $html);
        $this->assertStringContainsString('getElementById("travel_end_date")', $html);
        $this->assertStringContainsString('getElementById("duration_days")', $html);
        // No cost centre in the travel section: it stays on the Cost step.
        $travel = substr($paper, strpos($paper, 'class="pub-travel"'));
        $this->assertStringNotContainsString('cost_centre', $travel);
    }

    public function test_a_conference_draft_refills_its_travel_details(): void
    {
        Storage::fake('local');

        $student = $this->student();
        $this->actingAs($student)->post(route('publication.draft.save'), [
            'type_of_request' => 'publication_conference',
            'conference_scope' => 'local',
        ] + $this->travelDetails())->assertRedirect();

        $this->actingAs($student)
            ->get(route('publication.draft.edit', Application::where('module_type', 'publication')->sole()))
            ->assertOk()
            ->assertSee('value="'.now()->addDays(30)->toDateString().'"', false)
            ->assertSee('value="Present the paper"', false)
            ->assertSee('value="012-3456789"', false);
    }

    // ---- Labels follow the request type ----------------------------------------

    public function test_the_title_and_fee_labels_follow_the_request_type(): void
    {
        $html = $this->actingAs($this->student())->get(route('publication.create'))->assertOk()->getContent();

        $this->assertStringContainsString('data-label-conference="Title of Conference" data-label-journal="Title of Journal">Title of Journal</label>', $html);
        $this->assertStringContainsString('data-label-conference="Conference Fee" data-label-journal="Journal Fee">Journal Fee</label>', $html);
        $this->assertStringNotContainsString('Conference / Journal', $html);
        $this->assertStringNotContainsString('Title of Conference / Journal', $html);

        $this->assertSame(['Title of Conference', 'Conference Fee'], [PublicationDetail::venueTitleLabel('publication_conference'), PublicationDetail::feeLabel('publication_conference')]);
        $this->assertSame(['Title of Journal', 'Journal Fee'], [PublicationDetail::venueTitleLabel('publication_journal'), PublicationDetail::feeLabel('publication_journal')]);
    }

    public function test_a_returned_conference_form_reads_title_of_conference(): void
    {
        $this->actingAs($this->student())
            ->from(route('publication.create'))
            ->post(route('publication.store'), $this->payload(['type_of_request' => 'publication_conference', 'conference_scope' => 'local', 'title_of_conference_journal' => '']))
            ->assertSessionHasErrors(['title_of_conference_journal' => 'Enter the title of conference.']);

        $this->get(route('publication.create'))
            ->assertSee('>Title of Conference</label>', false)
            ->assertSee('>Conference Fee</label>', false);
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

    public function test_a_conference_types_its_cost_centre_and_any_dropdown_value_is_ignored(): void
    {
        Storage::fake('local');
        Notification::fake();

        $student = $this->student();
        $conference = ['type_of_request' => 'publication_conference', 'conference_scope' => 'local'] + $this->travelDetails();

        // No text: refused, whatever the dropdown said.
        $this->actingAs($student)
            ->post(route('publication.store'), $this->payload($conference + ['cost_centre' => 'cgs_pay_and_claims']))
            ->assertSessionHasErrors(['cost_centre_other' => 'Enter the cost centre to be utilized.'])
            ->assertSessionDoesntHaveErrors('cost_centre');
        $this->assertSame(0, PublicationDetail::count());

        // With the text, a dropdown value posted from an edited form is
        // ignored: stored as "Others" with the student's words.
        $this->actingAs($student)
            ->post(route('publication.store'), $this->payload($conference + [
                'cost_centre' => 'split_memo',
                'cost_centre_other' => 'FRG research grant',
            ]))
            ->assertSessionHasNoErrors();

        $detail = PublicationDetail::sole();
        $this->assertSame('others', $detail->cost_centre);
        $this->assertSame('FRG research grant', $detail->cost_centre_other);
        $this->assertSame('FRG research grant', $detail->costCentreLabel());
        $this->assertFalse($detail->wants_letter_of_undertaking);
        $this->assertFalse($detail->usesCostCentre(PublicationDetail::SPLIT_MEMO));
    }

    public function test_a_journal_must_choose_one_of_the_four_and_specify_others(): void
    {
        Storage::fake('local');
        Notification::fake();

        $student = $this->student();

        $this->actingAs($student)
            ->post(route('publication.store'), $this->payload(['cost_centre' => null]))
            ->assertSessionHasErrors(['cost_centre' => 'Choose the cost centre to be utilized.']);

        $this->actingAs($student)
            ->post(route('publication.store'), $this->payload(['cost_centre' => 'others']))
            ->assertSessionHasErrors(['cost_centre_other' => 'Please specify the cost centre, as you chose "Others".']);

        // A journal's "Please specify" is kept only for "Others".
        $this->actingAs($student)
            ->post(route('publication.store'), $this->payload(['cost_centre' => 'cgs_pay_and_claims', 'cost_centre_other' => 'stale text']))
            ->assertSessionHasNoErrors();
        $this->assertNull(PublicationDetail::sole()->cost_centre_other);
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

    public function test_the_form_offers_the_journal_list_with_its_help_and_a_typed_field_for_a_conference(): void
    {
        $html = $this->actingAs($this->student())->get(route('publication.create'))
            ->assertOk()
            ->assertSee('CGS &amp; Grant - Split Memo', false)
            ->assertDontSee('>Split Memo<', false)
            ->assertSee('<option value="split_memo"', false)
            ->assertDontSee('data-journal-only', false)
            // The helper text, styled as a hint, under the journal's dropdown.
            ->assertSee('class="field-hint cost-centre-help" data-cost-journal', false)
            ->assertSee('submit a claim in the Student Claims form after your publication has been approved.')
            ->assertSee('<b>Letter of Undertaking (LOU):</b> processed directly by CGS.', false)
            ->assertSee('after approval, you will receive an email with the memo template and the CGS contact to submit the signed memo to.')
            ->assertSee('id="cost_centre_conference"', false)
            ->getContent();

        // A fresh form is a journal until the type says otherwise: the list
        // shown, "Please specify" and the conference field hidden and off.
        $this->assertLabel($html, 'cost_centre_other', hidden: true, text: 'Please specify');
        $this->assertLabel($html, 'cost_centre_conference', hidden: true, text: 'Cost Centre to be Utilized');
        $this->assertLabel($html, 'cost_centre', hidden: false, text: 'Cost Centre to be Utilized');
        $this->assertMatchesRegularExpression('/id="cost_centre_conference"[^>]*hidden disabled/s', $html);
    }

    /** A <label for="$for"> holding $text, shown or hidden. */
    protected function assertLabel(string $html, string $for, bool $hidden, string $text): void
    {
        $this->assertSame(1, preg_match('/<label for="'.preg_quote($for, '/').'"([^>]*)>\\s*'.preg_quote($text, '/').'\\s*<\\/label>/s', $html, $m), "No <label for=\"{$for}\">{$text}</label>");
        $this->assertSame($hidden, (bool) preg_match('/\\bhidden\\b/', $m[1]), "<label for=\"{$for}\"> should be ".($hidden ? 'hidden' : 'shown'));
    }

    public function test_a_conference_draft_refills_the_typed_cost_centre_and_hides_the_list(): void
    {
        Storage::fake('local');

        $student = $this->student();

        $this->actingAs($student)->post(route('publication.draft.save'), [
            'type_of_request' => 'publication_conference',
            'conference_scope' => 'local',
            'title_of_paper' => 'Draft Paper',
            'cost_centre_other' => 'YUTP grant 015LC0-123',
        ])->assertRedirect();

        $draft = Application::where('module_type', 'publication')->sole();

        $html = $this->actingAs($student)->get(route('publication.draft.edit', $draft))
            ->assertOk()
            ->assertSee('value="YUTP grant 015LC0-123"', false)
            ->getContent();

        $this->assertLabel($html, 'cost_centre_conference', hidden: false, text: 'Cost Centre to be Utilized');
        $this->assertLabel($html, 'cost_centre', hidden: true, text: 'Cost Centre to be Utilized');
        $this->assertMatchesRegularExpression('/id="cost_centre_conference"[^>]*\\brequired\\b/s', $html);
        $this->assertMatchesRegularExpression('/id="cost_centre"[^>]*hidden disabled/s', $html);
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
            ->post(route('publication.store'), $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('publication.sign-offs', Application::sole()));

        $application = Application::where('module_type', 'publication')->sole();
        $detail = PublicationDetail::where('application_id', $application->id)->sole();

        $this->assertSame(Application::STATUS_DRAFT, $application->status);
        $this->assertNull($detail->conference_scope);
        $this->assertNull($detail->travel_start_date); // a journal has no travel
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
            ->post(route('publication.store'), $this->payload(['type_of_request' => 'publication_conference'] + $this->conferenceFields()))
            ->assertSessionHasErrors('conference_scope');
    }

    public function test_an_international_conference_needs_a_third_reviewer(): void
    {
        Storage::fake('local');
        Notification::fake();

        $student = $this->student();
        $conference = ['type_of_request' => 'publication_conference', 'conference_scope' => 'international'] + $this->conferenceFields();

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
            ] + $this->conferenceFields()))
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
