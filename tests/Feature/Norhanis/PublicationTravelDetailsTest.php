<?php

namespace Tests\Feature\Norhanis;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Norhanis\Models\PublicationDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Norhanis\Concerns\FilesPublications;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Where a publication's Travel Details and type-dependent labels show
 * (UAT, 11 Nov 2026): a conference reads "Title of Conference" / "Conference
 * Fee" and carries Travel Details; a journal reads "Title of Journal" /
 * "Journal Fee" and has none. Checked on the approver's queue card, the
 * student's View details page, the summary PDF, the Authorship
 * Certification and the Paper Evaluation Sheet. Also the migration that
 * moved old conference dates and venues into the Travel Details.
 */
class PublicationTravelDetailsTest extends TestCase
{
    use FilesPublications;
    use MakesUsers;
    use RefreshDatabase;

    protected User $student;

    protected User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();

        $this->supervisor = $this->supervisor();
        $this->student = $this->student(attributes: ['supervisor_id' => $this->supervisor->id]);
    }

    public function test_the_queue_card_shows_travel_details_and_labels_for_a_conference(): void
    {
        $this->pending('publication_conference');

        $this->actingAs($this->supervisor)->get(route('publication.queue'))
            ->assertOk()
            ->assertSee('<b>Title of Conference:</b>', false)
            ->assertSee('<b>Conference Fee:</b>', false)
            ->assertSee('<b>Travel Details</b>', false)
            ->assertSee('<b>Travel Start Date:</b> '.now()->addDays(30)->format('j M Y'), false)
            ->assertSee('<b>Duration of Travelling (Days):</b> 3', false)
            ->assertSee('<b>Destination Address:</b> Kuala Lumpur Convention Centre', false)
            ->assertSee('Dr. Siti Mariam · 012-3456789 · siti.mariam@utp.edu.my')
            ->assertDontSee('Conference / Journal')
            ->assertDontSee('Venue:');
    }

    public function test_the_queue_card_omits_travel_details_for_a_journal(): void
    {
        $this->pending('publication_journal');

        $this->actingAs($this->supervisor)->get(route('publication.queue'))
            ->assertOk()
            ->assertSee('<b>Title of Journal:</b>', false)
            ->assertSee('<b>Journal Fee:</b>', false)
            ->assertDontSee('Travel Details')
            ->assertDontSee('Travel Start Date')
            ->assertDontSee('Conference / Journal');
    }

    public function test_the_students_page_lists_the_details_for_each_type(): void
    {
        $conference = $this->pending('publication_conference');

        $this->actingAs($this->student)->get(route('applications.show', $conference))
            ->assertOk()
            ->assertSee('Title of Conference')
            ->assertSee('Conference Fee')
            ->assertSee('Travel Start Date')
            ->assertSee('Reason for Travelling')
            ->assertSee('Contact Person While Travelling');

        $journal = $this->pending('publication_journal');

        $this->actingAs($this->student)->get(route('applications.show', $journal))
            ->assertOk()
            ->assertSee('Title of Journal')
            ->assertSee('Journal Fee')
            ->assertDontSee('Travel Start Date')
            ->assertDontSee('Reason for Travelling');
    }

    public function test_the_summary_pdf_shows_travel_details_for_a_conference_only(): void
    {
        $conference = $this->summaryHtml($this->pending('publication_conference'));

        $this->assertStringContainsString('<h3>Travel Details</h3>', $conference);
        $this->assertStringContainsString('<td class="label">Title of Conference</td>', $conference);
        $this->assertStringContainsString('<td class="label">Conference Fee</td>', $conference);
        $this->assertStringContainsString('<td class="label">Duration of Travelling (Days)</td><td>3</td>', $conference);
        $this->assertStringNotContainsString('Venue', $conference);
        $this->assertStringNotContainsString('Conference / Journal', $conference);

        $journal = $this->summaryHtml($this->pending('publication_journal'));

        $this->assertStringNotContainsString('Travel Details', $journal);
        $this->assertStringContainsString('<td class="label">Title of Journal</td>', $journal);
        $this->assertStringContainsString('<td class="label">Journal Fee</td>', $journal);
    }

    public function test_the_authorship_certification_names_the_conference_or_the_journal(): void
    {
        foreach (['publication_conference' => 'Title of Conference', 'publication_journal' => 'Title of Journal'] as $type => $label) {
            $detail = PublicationDetail::with('authors')->where('application_id', $this->pending($type)->id)->sole();

            $pdf = view('norhanis::publication.certification_pdf', ['application' => $detail->application, 'detail' => $detail])->render();
            $this->assertStringContainsString("<td>{$label}:</td>", $pdf);

            $page = $this->get($this->certifyUrl($detail->authors->first()))->assertOk();
            $page->assertSee("<b>{$label}:</b>", false)->assertDontSee('Conference / Journal');
        }
    }

    public function test_the_paper_evaluation_sheet_names_the_type_and_has_no_venue_or_date(): void
    {
        foreach (['publication_conference' => 'CONFERENCE', 'publication_journal' => 'JOURNAL'] as $type => $key) {
            $detail = PublicationDetail::with('authors')->where('application_id', $this->pending($type)->id)->sole();
            $evaluation = $detail->evaluations()->create(['position' => 1, 'reviewer_name' => 'Dr. Faridah Osman', 'reviewer_email' => 'faridah.osman@utp.edu.my']);

            $pdf = view('norhanis::publication.evaluation_pdf', ['application' => $detail->application, 'detail' => $detail, 'evaluation' => $evaluation])->render();
            $this->assertStringContainsString('<td class="key">'.$key.'</td>', $pdf);
            $this->assertStringNotContainsString('VENUE', $pdf);
            $this->assertStringNotContainsString('<td class="key">DATE</td>', $pdf);

            $label = $type === 'publication_conference' ? 'Title of Conference' : 'Title of Journal';
            $this->get($this->evaluationUrl($evaluation))->assertOk()
                ->assertSee("<th>{$label}</th>", false)
                ->assertDontSee('<th>Venue</th>', false)
                ->assertDontSee('Conference / Journal');
        }
    }

    public function test_the_migration_copies_conference_dates_into_the_travel_details(): void
    {
        $migration = require base_path('app/Modules/Norhanis/Database/Migrations/2026_11_11_000200_publication_travel_details_replace_conference_dates.php');

        // Back to the old columns, as a database from before this change.
        $migration->down();
        $this->assertTrue(Schema::hasColumn('publication_details', 'conference_start_date'));

        $conference = $this->oldRow('publication_conference', '2026-12-01', '2026-12-03', 'Singapore Expo');
        $sameDay = $this->oldRow('publication_conference', '2026-12-10', '2026-12-10', null);
        $journal = $this->oldRow('publication_journal', '2026-12-01', '2026-12-31', null);

        $migration->up();

        foreach (['conference_start_date', 'conference_end_date', 'venue'] as $gone) {
            $this->assertFalse(Schema::hasColumn('publication_details', $gone), $gone);
        }

        $row = fn (int $id) => DB::table('publication_details')->find($id);

        $this->assertSame(['2026-12-01', '2026-12-03', 3, 'Singapore Expo'], $this->travelOf($row($conference)));
        $this->assertSame(['2026-12-10', '2026-12-10', 1, null], $this->travelOf($row($sameDay)));
        // A journal's old dates meant nothing and are not carried over.
        $this->assertSame([null, null, null, null], $this->travelOf($row($journal)));
    }

    public function test_the_migration_down_puts_conference_dates_back(): void
    {
        $migration = require base_path('app/Modules/Norhanis/Database/Migrations/2026_11_11_000200_publication_travel_details_replace_conference_dates.php');

        $id = PublicationDetail::where('application_id', $this->pending('publication_conference')->id)->value('id');

        $migration->down();

        $old = DB::table('publication_details')->find($id);
        $this->assertSame(now()->addDays(30)->toDateString(), substr((string) $old->conference_start_date, 0, 10));
        $this->assertSame(now()->addDays(32)->toDateString(), substr((string) $old->conference_end_date, 0, 10));
        $this->assertSame('Kuala Lumpur Convention Centre', $old->venue);
        $this->assertFalse(Schema::hasColumn('publication_details', 'travel_start_date'));

        $migration->up();
    }

    // ---- Helpers ---------------------------------------------------------------

    /** A publication of this type at the Supervisor's stage, past its sign-offs. */
    protected function pending(string $type): Application
    {
        $conference = $type === 'publication_conference';

        $application = Application::create(['student_id' => $this->student->id, 'module_type' => 'publication', 'status' => Application::STATUS_DRAFT]);

        $detail = PublicationDetail::create([
            'application_id' => $application->id,
            'type_of_request' => $type,
            'conference_scope' => $conference ? 'local' : null,
            'title_of_paper' => 'Reservoir Simulation with Graph Networks',
            'title_of_conference_journal' => $conference ? 'Asia Petroleum Conference' : 'Journal of Petroleum Science',
            'organizer_publisher' => 'Elsevier',
            'conference_journal_fee' => 2500,
            'currency_type' => 'MYR',
            'cost_centre' => $conference ? 'others' : 'cgs_pay_and_claims',
            'cost_centre_other' => $conference ? 'FRG research grant' : null,
        ] + ($conference ? $this->travelDetails() + ['duration_days' => 3] : []));

        $detail->authors()->create(['author_name' => $this->student->name, 'email' => $this->student->email]);

        return app(WorkflowEngine::class)->submit($application);
    }

    protected function summaryHtml(Application $application): string
    {
        $application = $application->fresh(['history.approver', 'student', 'documents']);

        return view('norhanis::pack.summary_pdf', [
            'application' => $application,
            'student' => $application->student,
            'module' => $application->module(),
            'stage' => $application->currentStage(),
            'history' => $application->history,
            'documents' => $application->documents,
            'included' => null,
            'travel' => null,
            'taPlan' => null,
            'claim' => null,
            'publication' => PublicationDetail::with('authors', 'evaluations')->where('application_id', $application->id)->first(),
            'generatedBy' => $this->supervisor,
        ])->render();
    }

    protected function oldRow(string $type, string $start, string $end, ?string $venue): int
    {
        $application = Application::create(['student_id' => $this->student->id, 'module_type' => 'publication', 'status' => Application::STATUS_APPROVED]);

        return DB::table('publication_details')->insertGetId([
            'application_id' => $application->id,
            'type_of_request' => $type,
            'title_of_paper' => 'Old paper',
            'title_of_conference_journal' => 'Old title',
            'organizer_publisher' => 'Old publisher',
            'conference_journal_fee' => 100,
            'currency_type' => 'MYR',
            'cost_centre' => 'others',
            'cost_centre_other' => 'Grant',
            'conference_start_date' => $start,
            'conference_end_date' => $end,
            'venue' => $venue,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{0: ?string, 1: ?string, 2: ?int, 3: ?string} */
    protected function travelOf(object $row): array
    {
        return [
            $row->travel_start_date ? substr((string) $row->travel_start_date, 0, 10) : null,
            $row->travel_end_date ? substr((string) $row->travel_end_date, 0, 10) : null,
            $row->duration_days !== null ? (int) $row->duration_days : null,
            $row->destination_address,
        ];
    }
}
