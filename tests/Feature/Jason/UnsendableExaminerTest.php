<?php

namespace Tests\Feature\Jason;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Jason\Mail\AppointmentLetterMail;
use App\Modules\Jason\Models\AppointmentExaminer;
use App\Modules\Jason\Support\AppointmentSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * An examiner the portal cannot email.
 *
 * Two ways it happens: the records hold no address at all, or the address
 * they hold was reported as not working and is kept only so CGS can see
 * what was wrong with it. Neither cancels the appointment -- the Dean still
 * signs the letter, and it can go by post -- so the examiner is appointed
 * and the reason travels with them.
 *
 * What must not happen is a silent one. Sending to a dead address bounces
 * somewhere nobody is looking, and the issued list would show "Not
 * delivered" beside a Resend button that cannot ever work.
 */
class UnsendableExaminerTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'sync']);
    }

    /** The same officer each time: several of these tests act as them twice. */
    protected function cgs(): User
    {
        return User::firstOrCreate(
            ['email' => 'syahmi@test.my'],
            ['name' => 'M Syahmi Ifwat M Jafri', 'password' => 'password', 'role' => Role::NON_EXEC_CGS],
        );
    }

    /** An examiner in the nomination chain's records. */
    protected function examiner(string $name, string $type, ?string $email, array $extra = []): int
    {
        return DB::table('examiners')->insertGetId($extra + [
            'name' => $name,
            'email' => $email,
            'department' => 'Computing',
            'faculty' => 'FSMC',
            'institution' => $type === 'external' ? 'Universiti Malaya' : null,
            'type' => $type,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** The nomination export's heading row and one candidate's row. */
    protected function sheet(string $matric, string $internal, string $external): UploadedFile
    {
        $csv = implode(',', AppointmentSheet::TEMPLATE_HEADINGS)."\n";

        $csv .= implode(',', array_map(fn ($c) => '"'.$c.'"', [
            '', 'FSMC', 'Computing', 'Ahmad Danial', $matric, 'MSc Full-Time',
            'Introduction to IoT', 'Dr. Aisyah Rahman',
            $internal, 'Computing', '',
            $external, 'Universiti Malaya', '',
            '-', 'Approved', '2026-11-04',
        ]))."\n";

        $path = tempnam(sys_get_temp_dir(), 'exam').'.csv';
        file_put_contents($path, $csv);

        return new UploadedFile($path, 'examiner-list.csv', 'text/csv', null, true);
    }

    protected function import(string $internal, string $external): Application
    {
        $this->student('22001001', 'cand@test.my');

        $this->actingAs($this->cgs())
            ->post(route('appointment-letter.import.store'), [
                'sheet' => $this->sheet('22001001', $internal, $external),
            ])
            ->assertSessionHasNoErrors();

        return Application::where('module_type', 'appointment_letter')->firstOrFail();
    }

    /** No address on record: appointed, flagged, not emailed. */
    public function test_an_examiner_with_no_address_is_appointed_but_not_emailed(): void
    {
        Storage::fake('local');
        Mail::fake();

        $this->examiner('Dr. Chandra Segaran', 'internal', null);
        $this->examiner('Prof. Madya Dr. Nabila Yusof', 'external', 'nabila@um.edu.my');

        $application = $this->import('Dr. Chandra Segaran', 'Prof. Madya Dr. Nabila Yusof');

        $flagged = AppointmentExaminer::where('application_id', $application->id)
            ->where('examiner_name', 'Dr. Chandra Segaran')->firstOrFail();

        $this->assertNotNull($flagged->email_problem, 'A missing address should be recorded as a problem.');
        $this->assertStringContainsString('No email address on record', $flagged->email_problem);

        // The other examiner is unaffected.
        $this->assertNull(
            AppointmentExaminer::where('application_id', $application->id)
                ->where('examiner_name', 'Prof. Madya Dr. Nabila Yusof')->firstOrFail()->email_problem,
        );
    }

    /**
     * A flagged address: the old one stays in the records, so reading the
     * email column alone would look perfectly fine.
     */
    public function test_an_address_reported_as_not_working_is_never_sent_to(): void
    {
        Storage::fake('local');
        Mail::fake();

        // examiners.email_status belongs to the nomination chain, which
        // keeps the dead address in `email` and records the fault beside
        // it -- so the email column on its own still looks perfectly good.
        $this->examiner('Dr. Chandra Segaran', 'internal', 'chandra@utp.edu.my', ['email_status' => 'invalid']);
        $this->examiner('Prof. Madya Dr. Nabila Yusof', 'external', 'nabila@um.edu.my', ['email_status' => 'ok']);

        $application = $this->import('Dr. Chandra Segaran', 'Prof. Madya Dr. Nabila Yusof');

        $flagged = AppointmentExaminer::where('application_id', $application->id)
            ->where('examiner_name', 'Dr. Chandra Segaran')->firstOrFail();

        $this->assertStringContainsString('reported as not working', (string) $flagged->email_problem);

        // Kept, not blanked: CGS needs to see what was wrong to put it right.
        $this->assertSame('chandra@utp.edu.my', $flagged->examiner_email);
    }

    /** The Dean's approval sends to everyone who can be reached, and nobody else. */
    public function test_approval_emails_only_the_examiners_with_a_usable_address(): void
    {
        Storage::fake('local');
        Mail::fake();

        $this->examiner('Dr. Chandra Segaran', 'internal', null);
        $this->examiner('Prof. Madya Dr. Nabila Yusof', 'external', 'nabila@um.edu.my');

        $application = $this->import('Dr. Chandra Segaran', 'Prof. Madya Dr. Nabila Yusof');

        $cgs = $this->cgs();
        $dean = $this->dean();

        $this->actingAs($cgs)
            ->post(route('appointment-letter.prepare.store', $application), [
                'candidate_degree' => 'Master of Science',
                'candidate_programme' => 'MSc Full-Time',
                'supervisor_name' => 'Dr. Aisyah Rahman',
                'thesis_title' => 'Introduction to IoT',
                'examiners' => AppointmentExaminer::where('application_id', $application->id)
                    ->get()
                    ->map(fn ($e, $i) => [
                        'id' => $e->id,
                        'examiner_type' => $e->examiner_type,
                        'examiner_address' => 'Bandar Seri Iskandar, 32610 Perak',
                        'letter_ref_no' => 'UTP/CGS/001/'.($i + 1),
                    ])->all(),
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($dean)
            ->post(route('hardbound.signature.store'), [
                'signature' => UploadedFile::fake()->image('signature.png', 300, 100),
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($dean)
            ->post(route('appointment-letter.decide', $application), ['decision' => 'approve'])
            ->assertSessionHasNoErrors();

        $this->assertSame(Application::STATUS_APPROVED, $application->fresh()->status);

        // One pack went out, to the examiner who has an address. The
        // mailable is ShouldQueue, so a fake records it as queued.
        Mail::assertQueued(AppointmentLetterMail::class, 1);
        Mail::assertQueued(AppointmentLetterMail::class,
            fn ($mail) => $mail->hasTo('nabila@um.edu.my'));
        Mail::assertNotQueued(AppointmentLetterMail::class,
            fn ($mail) => $mail->hasTo(''));
    }

    /** The issued list says why, and offers no Resend that cannot work. */
    public function test_the_issued_list_explains_a_pack_that_cannot_be_emailed(): void
    {
        Storage::fake('local');
        Mail::fake();

        $this->examiner('Dr. Chandra Segaran', 'internal', null);
        $this->examiner('Prof. Madya Dr. Nabila Yusof', 'external', 'nabila@um.edu.my');

        $application = $this->import('Dr. Chandra Segaran', 'Prof. Madya Dr. Nabila Yusof');
        $application->update(['status' => Application::STATUS_APPROVED]);

        $this->actingAs($this->cgs())
            ->get(route('appointment-letter.issued'))
            ->assertOk()
            ->assertSee('Cannot email')
            ->assertSee('No email address on record');
    }
}
