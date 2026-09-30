<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Jason\Listeners\RecordExaminerPackDelivery;
use App\Modules\Jason\Mail\AppointmentLetterMail;
use App\Modules\Jason\Models\AppointmentDetail;
use App\Modules\Jason\Models\AppointmentExaminer;
use App\Modules\Jason\Support\AppointmentSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Appointment Letter: what the module enforces now that examiner selection
 * happens before it.
 *
 * The chain begins with CGS importing the finalised list, so the import is
 * where a bad list has to be caught — a half-imported sheet leaves CGS
 * working out which candidates made it — and ends with the Dean, where the
 * packs go out and four different people have to be told.
 *
 * A pack counts as delivered only when the mail transport accepts it.
 * Dispatch happens after the engine has committed the Dean's approval and
 * cannot be rolled back into it, so nothing else would notice a pack that
 * never went.
 */
class JasonAppointmentLetterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml asks for the sync queue, but an env var already set in
        // the container wins over it, so QUEUE_CONNECTION stays on redis and
        // a queued mailable would be handed to the dev worker instead of
        // being sent here. AppointmentLetterMail is ShouldQueue.
        config(['queue.default' => 'sync']);
    }

    protected function cgs(): User
    {
        return User::firstOrCreate(['email' => 'cgs@test.my'], [
            'name' => 'Puan Waheeda',
            'password' => 'password',
            'role' => Role::NON_EXEC_CGS,
            'department' => 'CGS',
        ]);
    }

    protected function candidate(string $matric = '22001001', string $email = 'cand@test.my'): User
    {
        return User::firstOrCreate(['email' => $email], [
            'name' => 'Ahmad Danial',
            'password' => 'password',
            'role' => Role::STUDENT,
            'matric_no' => $matric,
            'department' => 'Computer & Information Sciences',
        ]);
    }

    /** A sheet with the header row and whatever rows are given. */
    protected function sheet(array $rows, string $name = 'examiner-list.csv'): UploadedFile
    {
        $csv = implode(',', AppointmentSheet::COLUMNS)."\n";

        foreach ($rows as $row) {
            $csv .= implode(',', array_map(fn ($c) => '"'.str_replace('"', '""', (string) $c).'"', $row))."\n";
        }

        $path = tempnam(sys_get_temp_dir(), 'exam').'.csv';
        file_put_contents($path, $csv);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    /** One good row. */
    protected function row(string $matric, string $type, string $email, array $overrides = []): array
    {
        return array_replace([
            $matric, 'MSc in Computer & Information Sciences', 'Computer & Information Sciences',
            'Dr. Aisyah Rahman', 'A Study of Something',
            $type, 'Prof '.ucfirst($type), ucfirst($type).' Institution', $email, 'Somewhere',
        ], $overrides);
    }

    /** A full, valid panel for one candidate. */
    protected function panelFor(string $matric): array
    {
        return [
            $this->row($matric, 'internal', "int-{$matric}@test.my"),
            $this->row($matric, 'external', "ext-{$matric}@test.my"),
        ];
    }

    /* -----------------------------------------------------------------
     | The import
     |------------------------------------------------------------------*/

    public function test_a_list_opens_one_appointment_per_candidate(): void
    {
        $this->candidate('22001001', 'a@test.my');
        $this->candidate('22001002', 'b@test.my');

        $this->actingAs($this->cgs())
            ->post(route('appointment-letter.import.store'), [
                'sheet' => $this->sheet(array_merge($this->panelFor('22001001'), $this->panelFor('22001002'))),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('appointment-letter.queue'));

        $applications = Application::where('module_type', 'appointment_letter')->get();

        $this->assertCount(2, $applications, 'One appointment per candidate, not per row.');

        foreach ($applications as $application) {
            // Straight onto the CGS preparation stage: the decision that
            // produced this list was taken before it arrived.
            $this->assertSame('cgs_prep', $application->current_stage);
            $this->assertSame(2, AppointmentExaminer::where('application_id', $application->id)->count());

            $detail = AppointmentDetail::where('application_id', $application->id)->firstOrFail();
            $this->assertSame('A Study of Something', $detail->thesis_title);
            $this->assertSame('Dr. Aisyah Rahman', $detail->supervisor_name);
        }
    }

    public function test_the_candidate_columns_may_repeat_down_the_rows(): void
    {
        $this->candidate('22001001', 'a@test.my');

        $this->actingAs($this->cgs())
            ->post(route('appointment-letter.import.store'), ['sheet' => $this->sheet($this->panelFor('22001001'))])
            ->assertSessionHasNoErrors();

        $application = Application::where('module_type', 'appointment_letter')->firstOrFail();

        $this->assertSame(
            ['external', 'internal'],
            AppointmentExaminer::where('application_id', $application->id)
                ->pluck('examiner_type')->sort()->values()->all(),
        );
    }

    /**
     * A list is approved as a whole before it reaches CGS, so importing
     * half of it would leave them working out which candidates made it —
     * and the rows that failed are named, because the person repairing the
     * file is looking at row numbers.
     */
    public function test_a_bad_row_imports_nothing_and_says_which_row(): void
    {
        $this->candidate('22001001', 'a@test.my');

        $this->actingAs($this->cgs())
            ->post(route('appointment-letter.import.store'), [
                'sheet' => $this->sheet(array_merge(
                    $this->panelFor('22001001'),
                    // A candidate nobody has heard of.
                    [$this->row('99999999', 'internal', 'ghost@test.my')],
                )),
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, Application::where('module_type', 'appointment_letter')->count());
        $this->assertStringContainsString('Row 4', session('error'));
    }

    public function test_a_panel_needs_an_internal_and_an_external_examiner(): void
    {
        $this->candidate('22001001', 'a@test.my');

        $this->actingAs($this->cgs())
            ->post(route('appointment-letter.import.store'), [
                'sheet' => $this->sheet([
                    $this->row('22001001', 'internal', 'one@test.my'),
                    $this->row('22001001', 'internal', 'two@test.my'),
                ]),
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, Application::where('module_type', 'appointment_letter')->count());
        $this->assertStringContainsString('internal and one external', session('error'));
    }

    public function test_the_same_examiner_cannot_appear_twice_on_one_panel(): void
    {
        $this->candidate('22001001', 'a@test.my');

        $this->actingAs($this->cgs())
            ->post(route('appointment-letter.import.store'), [
                'sheet' => $this->sheet([
                    $this->row('22001001', 'internal', 'same@test.my'),
                    $this->row('22001001', 'external', 'same@test.my'),
                ]),
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, Application::where('module_type', 'appointment_letter')->count());
    }

    /**
     * A reordered sheet is a different file, and importing it by position
     * would put an examiner's email in the address field.
     */
    public function test_a_sheet_with_the_wrong_header_is_refused(): void
    {
        $this->candidate('22001001', 'a@test.my');

        $path = tempnam(sys_get_temp_dir(), 'exam').'.csv';
        file_put_contents($path, "matric_no,examiner_name,degree\n22001001,Prof Somebody,MSc\n");

        $this->actingAs($this->cgs())
            ->post(route('appointment-letter.import.store'), [
                'sheet' => new UploadedFile($path, 'wrong.csv', 'text/csv', null, true),
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, Application::where('module_type', 'appointment_letter')->count());
    }

    public function test_the_examiner_kind_is_read_forgivingly_but_not_guessed(): void
    {
        $this->assertSame('internal', AppointmentSheet::parseType('Internal'));
        $this->assertSame('internal', AppointmentSheet::parseType(' INTERNAL '));
        $this->assertSame('external', AppointmentSheet::parseType('External Examiner'));

        // Anything else is refused by name rather than defaulted, because
        // the kind decides which letter template the examiner receives.
        $this->assertNull(AppointmentSheet::parseType('panel member'));
        $this->assertNull(AppointmentSheet::parseType(''));
    }

    public function test_only_cgs_may_import_a_list(): void
    {
        $chair = User::create([
            'name' => 'Dr Chair', 'email' => 'chair@test.my', 'password' => 'password',
            'role' => Role::CHAIR, 'department' => 'Computer & Information Sciences',
        ]);

        $this->actingAs($chair)->get(route('appointment-letter.import'))->assertForbidden();
        $this->actingAs($chair)->get(route('appointment-letter.issued'))->assertForbidden();
    }

    public function test_the_template_is_downloadable_and_matches_the_importer(): void
    {
        $response = $this->actingAs($this->cgs())
            ->get(route('appointment-letter.template', ['format' => 'csv']))
            ->assertOk();

        // A streamed download is not in the response body until it is read.
        $this->assertStringContainsString(
            implode(',', AppointmentSheet::COLUMNS),
            $response->streamedContent(),
        );
    }

    /* -----------------------------------------------------------------
     | Pack delivery
     |------------------------------------------------------------------*/

    public function test_a_pack_is_marked_delivered_only_when_the_transport_accepts_it(): void
    {
        $application = $this->imported();
        $row = AppointmentExaminer::where('application_id', $application->id)->firstOrFail();

        $this->assertFalse($row->packSent());

        Mail::to($row->examiner_email)->send(
            new AppointmentLetterMail($application, $row, [['name' => 'letter.pdf', 'contents' => '%PDF-1.4']])
        );

        $this->assertTrue($row->fresh()->packSent());
    }

    public function test_mail_from_other_modules_is_left_alone(): void
    {
        $application = $this->imported();
        $row = AppointmentExaminer::where('application_id', $application->id)->firstOrFail();

        // Any other message in the portal reaches the same listener; it must
        // ignore everything not carrying the examiner header.
        Mail::raw('Something else entirely', fn ($m) => $m->to('someone@test.my')->subject('Other'));

        $this->assertFalse($row->fresh()->packSent());
    }

    public function test_cgs_sees_an_undelivered_pack_and_can_resend_it(): void
    {
        Storage::fake('local');

        $application = $this->imported();
        $application->update(['status' => Application::STATUS_APPROVED]);

        $row = AppointmentExaminer::where('application_id', $application->id)->firstOrFail();
        $row->update(['appointed_at' => now()]);

        $cgs = $this->cgs();

        $this->actingAs($cgs)
            ->get(route('appointment-letter.issued'))
            ->assertOk()
            ->assertSee('Not delivered')
            ->assertSee('Resend');

        // Nothing was ever prepared for this nomination, so there is nothing
        // to resend — and CGS is told that rather than shown a false success.
        $this->actingAs($cgs)
            ->post(route('appointment-letter.resend', [$application, $row]))
            ->assertSessionHas('error');
    }

    /** An imported appointment, sitting on the CGS preparation stage. */
    protected function imported(): Application
    {
        $this->candidate('22001001', 'a@test.my');

        $this->actingAs($this->cgs())
            ->post(route('appointment-letter.import.store'), ['sheet' => $this->sheet($this->panelFor('22001001'))])
            ->assertSessionHasNoErrors();

        return Application::where('module_type', 'appointment_letter')->firstOrFail();
    }
}
