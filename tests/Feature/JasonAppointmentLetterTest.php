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
use Illuminate\Support\Facades\DB;
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

    /** A sheet with the export's header row and whatever rows are given. */
    protected function sheet(array $rows, string $name = 'examiner-list.csv'): UploadedFile
    {
        $csv = implode(',', AppointmentSheet::TEMPLATE_HEADINGS)."\n";

        foreach ($rows as $row) {
            $csv .= implode(',', array_map(fn ($c) => '"'.str_replace('"', '""', (string) $c).'"', $row))."\n";
        }

        $path = tempnam(sys_get_temp_dir(), 'exam').'.csv';
        file_put_contents($path, $csv);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    /** An examiner in the records the nomination chain keeps. */
    protected function knownExaminer(string $name, string $type, string $email, ?string $institution = null): void
    {
        DB::table('examiners')->insert([
            'name' => $name,
            'email' => $email,
            'department' => 'Computing',
            'faculty' => 'FSMC',
            'institution' => $institution,
            'type' => $type,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** The four examiners the sample rows name. */
    protected function knownPanel(): void
    {
        $this->knownExaminer('Dr. Chandra Segaran', 'internal', 'chandra@utp.edu.my');
        $this->knownExaminer('Prof. Dr. Rosli Hamid', 'internal', 'rosli@utp.edu.my');
        $this->knownExaminer('Assoc Prof Dr Azimah Binti Hussin', 'external', 'azimah@ukm.edu.my', 'Universiti Kebangsaan Malaysia');
        $this->knownExaminer('Professor Ir Ts Dr Muzamir Bin Isa', 'external', 'muzamir@unimap.edu.my', 'Universiti Malaysia Perlis');
    }

    /**
     * One candidate's row, in the export's column order.
     *
     * @param  array<int, string>  $overrides  by column index
     */
    protected function row(string $matric, array $overrides = []): array
    {
        return array_replace([
            '2', 'FSMC', 'Computing', 'Ahmad Danial', $matric, 'MSc Full-Time',
            'Introduction to IoT', 'Dr. Aisyah Rahman',
            'Dr. Chandra Segaran', 'Civil & Environmental Engineering',
            'Prof. Dr. Rosli Hamid',
            'Assoc Prof Dr Azimah Binti Hussin', 'Universiti Kebangsaan Malaysia',
            'Professor Ir Ts Dr Muzamir Bin Isa',
            '—', 'Approved', '2026-09-14',
        ], $overrides);
    }

    /* -----------------------------------------------------------------
     | The import
     |------------------------------------------------------------------*/

    public function test_a_row_opens_one_appointment_carrying_the_whole_panel(): void
    {
        $this->candidate('22001001', 'a@test.my');
        $this->candidate('22001002', 'b@test.my');
        $this->knownPanel();

        $this->actingAs($this->cgs())
            ->post(route('appointment-letter.import.store'), [
                'sheet' => $this->sheet([$this->row('22001001'), $this->row('22001002')]),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('appointment-letter.queue'));

        $applications = Application::where('module_type', 'appointment_letter')->get();

        $this->assertCount(2, $applications, 'One appointment per candidate, one row per candidate.');

        foreach ($applications as $application) {
            // Straight onto the CGS preparation stage: the decision that
            // produced this list was taken before it arrived.
            $this->assertSame('cgs_prep', $application->current_stage);

            $panel = AppointmentExaminer::where('application_id', $application->id)->get();

            // Main and backup, both sides, all appointed.
            $this->assertSame(4, $panel->count());
            $this->assertEqualsCanonicalizing(
                ['internal main', 'internal backup', 'external main', 'external backup'],
                $panel->map(fn ($e) => $e->examiner_type.' '.$e->examiner_role)->all(),
            );
        }
    }

    /**
     * The sheet names its examiners and nothing else about them. An
     * appointment letter with no address to go to is worse than none, so
     * the email and institution come from the records the nomination chain
     * keeps.
     */
    public function test_each_examiner_is_filled_in_from_the_examiner_records(): void
    {
        $this->candidate('22001001', 'a@test.my');
        $this->knownPanel();

        $this->actingAs($this->cgs())
            ->post(route('appointment-letter.import.store'), ['sheet' => $this->sheet([$this->row('22001001')])])
            ->assertSessionHasNoErrors();

        $application = Application::where('module_type', 'appointment_letter')->firstOrFail();

        $azimah = AppointmentExaminer::where('application_id', $application->id)
            ->where('examiner_name', 'Assoc Prof Dr Azimah Binti Hussin')
            ->firstOrFail();

        $this->assertSame('azimah@ukm.edu.my', $azimah->examiner_email);
        $this->assertSame('external', $azimah->examiner_type);
        $this->assertSame('main', $azimah->examiner_role);
    }

    public function test_an_examiner_the_records_do_not_know_stops_the_import(): void
    {
        $this->candidate('22001001', 'a@test.my');
        $this->knownPanel();

        $this->actingAs($this->cgs())
            ->post(route('appointment-letter.import.store'), [
                'sheet' => $this->sheet([$this->row('22001001', [8 => 'Dr Nobody At All'])]),
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, Application::where('module_type', 'appointment_letter')->count());
        $this->assertStringContainsString('Dr Nobody At All', session('error'));
    }

    /**
     * A list is approved as a whole upstream, so importing half of it would
     * leave CGS working out which candidates made it.
     */
    public function test_a_bad_row_imports_nothing_and_says_which_row(): void
    {
        $this->candidate('22001001', 'a@test.my');
        $this->knownPanel();

        $this->actingAs($this->cgs())
            ->post(route('appointment-letter.import.store'), [
                'sheet' => $this->sheet([$this->row('22001001'), $this->row('99999999')]),
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, Application::where('module_type', 'appointment_letter')->count());
        $this->assertStringContainsString('Row 3', session('error'));
    }

    public function test_a_panel_needs_an_internal_and_an_external_examiner(): void
    {
        $this->candidate('22001001', 'a@test.my');
        $this->knownPanel();

        // Both external slots cleared.
        $this->actingAs($this->cgs())
            ->post(route('appointment-letter.import.store'), [
                'sheet' => $this->sheet([$this->row('22001001', [11 => '', 13 => ''])]),
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, Application::where('module_type', 'appointment_letter')->count());
        $this->assertStringContainsString('internal and one external', session('error'));
    }

    /** A main examiner with no backup beside them is a complete panel. */
    public function test_an_empty_backup_slot_is_not_a_problem(): void
    {
        $this->candidate('22001001', 'a@test.my');
        $this->knownPanel();

        $this->actingAs($this->cgs())
            ->post(route('appointment-letter.import.store'), [
                'sheet' => $this->sheet([$this->row('22001001', [10 => '', 13 => ''])]),
            ])
            ->assertSessionHasNoErrors();

        $application = Application::where('module_type', 'appointment_letter')->firstOrFail();

        $this->assertSame(2, AppointmentExaminer::where('application_id', $application->id)->count());
    }

    public function test_the_same_candidate_cannot_appear_twice_on_one_sheet(): void
    {
        $this->candidate('22001001', 'a@test.my');
        $this->knownPanel();

        $this->actingAs($this->cgs())
            ->post(route('appointment-letter.import.store'), [
                'sheet' => $this->sheet([$this->row('22001001'), $this->row('22001001')]),
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, Application::where('module_type', 'appointment_letter')->count());
    }

    /**
     * An export gains and loses trailing columns as the nomination screen
     * changes, and a list that is right in every way except column order is
     * still the right list.
     */
    public function test_columns_are_read_by_name_in_any_order(): void
    {
        $this->candidate('22001001', 'a@test.my');
        $this->knownPanel();

        $csv = "Thesis Title,Matric No,External Main,Internal Main,Supervisor,Something We Do Not Know\n"
            .'"Introduction to IoT",22001001,"Assoc Prof Dr Azimah Binti Hussin",'
            .'"Dr. Chandra Segaran","Dr. Aisyah Rahman","ignored"'."\n";

        $path = tempnam(sys_get_temp_dir(), 'exam').'.csv';
        file_put_contents($path, $csv);

        $this->actingAs($this->cgs())
            ->post(route('appointment-letter.import.store'), [
                'sheet' => new UploadedFile($path, 'reordered.csv', 'text/csv', null, true),
            ])
            ->assertSessionHasNoErrors();

        $application = Application::where('module_type', 'appointment_letter')->firstOrFail();

        $this->assertSame(2, AppointmentExaminer::where('application_id', $application->id)->count());
        $this->assertSame(
            'Introduction to IoT',
            AppointmentDetail::where('application_id', $application->id)->value('thesis_title'),
        );
    }

    /** Matric No is the one column that ties a row to a candidate. */
    public function test_a_sheet_without_a_matric_column_is_refused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'exam').'.csv';
        file_put_contents($path, "Candidate,Internal Main\nAhmad Danial,Dr. Chandra Segaran\n");

        $this->actingAs($this->cgs())
            ->post(route('appointment-letter.import.store'), [
                'sheet' => new UploadedFile($path, 'no-matric.csv', 'text/csv', null, true),
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, Application::where('module_type', 'appointment_letter')->count());
        $this->assertStringContainsString('Matric No', session('error'));
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
        $csv = $response->streamedContent();

        // fputcsv quotes any heading with a space in it, so each is checked
        // on its own rather than as one joined line.
        foreach (AppointmentSheet::TEMPLATE_HEADINGS as $heading) {
            $this->assertStringContainsString($heading, $csv);
        }

        // The headings the template ships with are ones the importer knows.
        $mapped = AppointmentSheet::mapHeader(AppointmentSheet::TEMPLATE_HEADINGS);
        $this->assertArrayHasKey('matric_no', $mapped);
        $this->assertArrayHasKey('internal_main', $mapped);
        $this->assertArrayHasKey('external_backup', $mapped);
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

    /**
     * A panel of four is eight documents, and the Dean's decision rests on
     * reading them. They are listed on one page and read in place, rather
     * than downloaded a set at a time.
     */
    public function test_the_pack_is_read_on_a_page_rather_than_downloaded(): void
    {
        Storage::fake('local');

        $application = $this->imported();
        $cgs = $this->cgs();

        // Stand in for a prepared pack.
        $document = \App\Modules\Core\Models\ApplicationDocument::create([
            'application_id' => $application->id,
            'uploaded_by_id' => $cgs->id,
            'doc_type' => 'Appointment Letter',
            'original_name' => 'Appointment-Letter-Internal-someone.pdf',
            'path' => 'applications/'.$application->id.'/letter.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
        ]);

        Storage::disk('local')->put($document->path, '%PDF-1.4 letter');

        $this->actingAs($cgs)
            ->get(route('appointment-letter.pack', $application))
            ->assertOk()
            ->assertSee($document->original_name)
            ->assertSee(route('appointment-letter.document', [$application, $document]), false);

        $this->actingAs($cgs)
            ->get(route('appointment-letter.document', [$application, $document]))
            ->assertOk()
            ->assertHeader('content-disposition', 'inline; filename="'.$document->original_name.'"');

        $this->actingAs($cgs)
            ->get(route('appointment-letter.document', [$application, $document, 'download' => 1]))
            ->assertHeader('content-disposition', 'attachment; filename="'.$document->original_name.'"');
    }

    /** An imported appointment, sitting on the CGS preparation stage. */
    protected function imported(): Application
    {
        $this->candidate('22001001', 'a@test.my');
        $this->knownPanel();

        $this->actingAs($this->cgs())
            ->post(route('appointment-letter.import.store'), ['sheet' => $this->sheet([$this->row('22001001')])])
            ->assertSessionHasNoErrors();

        return Application::where('module_type', 'appointment_letter')->firstOrFail();
    }
}
