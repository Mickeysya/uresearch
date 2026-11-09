<?php

namespace Tests\Feature\Jason;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Jason\Models\AppointmentDetail;
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
 * Importing the same examiner list twice.
 *
 * The ordinary way it happens: the officer is not sure the first upload
 * went in, so they upload again. Before this, that opened a second set of
 * appointments, which the Dean would approve and which would send a second
 * appointment letter to examiners who already had one -- over a second
 * signature, with a second honorarium on it.
 *
 * A row already done is not an error in the file, so it does not reject
 * the file. It is skipped and reported, and anything genuinely new on the
 * same sheet still imports.
 */
class ReimportTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'sync']);
    }

    protected function cgs(): User
    {
        return User::firstOrCreate(
            ['email' => 'syahmi@test.my'],
            ['name' => 'M Syahmi Ifwat M Jafri', 'password' => 'password', 'role' => Role::NON_EXEC_CGS],
        );
    }

    protected function candidate(string $matric, string $email): User
    {
        return User::firstOrCreate(
            ['email' => $email],
            ['name' => 'Candidate '.$matric, 'password' => 'password',
                'role' => Role::STUDENT, 'matric_no' => $matric],
        );
    }

    protected function examiner(string $name, string $type): int
    {
        return DB::table('examiners')->insertGetId([
            'name' => $name,
            'email' => str_replace([' ', '.'], ['.', ''], strtolower($name)).'@test.my',
            'department' => 'Computing', 'faculty' => 'FSMC',
            'institution' => $type === 'external' ? 'Universiti Malaya' : null,
            'type' => $type, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * A sheet in the export's own column order.
     *
     * @param  array<int, array<string, string>>  $rows
     */
    protected function sheet(array $rows): UploadedFile
    {
        $headings = array_merge(AppointmentSheet::TEMPLATE_HEADINGS, [
            'Internal Main Letter Status', 'Internal Backup Letter Status',
            'External Main Letter Status', 'External Backup Letter Status',
            'Letter Needed For', 'Replaced Examiners',
        ]);

        $csv = implode(',', $headings)."\n";

        foreach ($rows as $row) {
            $csv .= implode(',', array_map(fn ($c) => '"'.str_replace('"', '""', (string) $c).'"', [
                $row['nomination'] ?? '', 'FSMC', 'Computing', 'Candidate', $row['matric'],
                'MSc Full-Time', 'Introduction to IoT', 'Dr. Aisyah Rahman',
                $row['internal'], 'Computing', '',
                $row['external'], 'Universiti Malaya', '',
                '-', 'Approved', '2026-11-04',
                $row['internal_status'] ?? 'Not sent', '',
                $row['external_status'] ?? 'Not sent', '', '', '',
            ]))."\n";
        }

        $path = tempnam(sys_get_temp_dir(), 'exam').'.csv';
        file_put_contents($path, $csv);

        return new UploadedFile($path, 'examiner-list.csv', 'text/csv', null, true);
    }

    protected function appointments(): int
    {
        return Application::where('module_type', 'appointment_letter')->count();
    }

    /** A nomination of the chain's own, so "Application No." resolves. */
    protected function nomination(User $student, int $internal, int $external): int
    {
        $application = Application::create([
            'student_id' => $student->id, 'submitted_by_id' => $this->cgs()->id,
            'module_type' => 'examiner_nomination', 'status' => Application::STATUS_APPROVED,
        ]);

        DB::table('examiner_nominations')->insert([
            'application_id' => $application->id,
            'internal_main_id' => $internal, 'external_main_id' => $external,
            'thesis_title' => 'Introduction to IoT',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $application->id;
    }

    /* ---------------- the same file twice ---------------- */

    public function test_the_same_file_imported_twice_creates_nothing_the_second_time(): void
    {
        Storage::fake('local');
        Mail::fake();

        $student = $this->candidate('22001001', 'a@test.my');
        $internal = $this->examiner('Dr. Chandra Segaran', 'internal');
        $external = $this->examiner('Prof. Nabila Yusof', 'external');
        $nomination = $this->nomination($student, $internal, $external);

        $row = ['matric' => '22001001', 'nomination' => $nomination,
            'internal' => 'Dr. Chandra Segaran', 'external' => 'Prof. Nabila Yusof'];

        $this->actingAs($this->cgs())
            ->post(route('appointment-letter.import.store'), ['sheet' => $this->sheet([$row])])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->appointments());

        $this->actingAs($this->cgs())
            ->post(route('appointment-letter.import.store'), ['sheet' => $this->sheet([$row])])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'Nothing new on that list')
                && str_contains($m, 'already appointed'));

        $this->assertSame(1, $this->appointments(), 'The second import must create nothing.');
        $this->assertSame(2, AppointmentExaminer::count(), 'No second set of examiner rows.');
    }

    /** One new, one already done: the new one still goes in. */
    public function test_a_mixed_sheet_imports_only_the_new_candidate(): void
    {
        Storage::fake('local');
        Mail::fake();

        $done = $this->candidate('22001001', 'a@test.my');
        $fresh = $this->candidate('22001002', 'b@test.my');
        $internal = $this->examiner('Dr. Chandra Segaran', 'internal');
        $external = $this->examiner('Prof. Nabila Yusof', 'external');

        $first = $this->nomination($done, $internal, $external);
        $second = $this->nomination($fresh, $internal, $external);

        $rowA = ['matric' => '22001001', 'nomination' => $first,
            'internal' => 'Dr. Chandra Segaran', 'external' => 'Prof. Nabila Yusof'];
        $rowB = ['matric' => '22001002', 'nomination' => $second,
            'internal' => 'Dr. Chandra Segaran', 'external' => 'Prof. Nabila Yusof'];

        $this->actingAs($this->cgs())
            ->post(route('appointment-letter.import.store'), ['sheet' => $this->sheet([$rowA])])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->appointments());

        $this->actingAs($this->cgs())
            ->post(route('appointment-letter.import.store'), ['sheet' => $this->sheet([$rowA, $rowB])])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($m) => str_contains($m, '1 new appointment created')
                && str_contains($m, '1 row skipped'));

        $this->assertSame(2, $this->appointments());

        // The new candidate's, not a second one for the old.
        $this->assertSame(1, AppointmentDetail::where('nomination_application_id', $second)->count());
        $this->assertSame(1, AppointmentDetail::where('nomination_application_id', $first)->count());
    }

    /** With no Application No. the candidate's own appointments answer. */
    public function test_a_sheet_without_an_application_number_still_skips_a_done_candidate(): void
    {
        Storage::fake('local');
        Mail::fake();

        $this->candidate('22001001', 'a@test.my');
        $this->examiner('Dr. Chandra Segaran', 'internal');
        $this->examiner('Prof. Nabila Yusof', 'external');

        $row = ['matric' => '22001001', 'nomination' => '',
            'internal' => 'Dr. Chandra Segaran', 'external' => 'Prof. Nabila Yusof'];

        $this->actingAs($this->cgs())
            ->post(route('appointment-letter.import.store'), ['sheet' => $this->sheet([$row])]);

        $this->assertSame(1, $this->appointments());

        $this->actingAs($this->cgs())
            ->post(route('appointment-letter.import.store'), ['sheet' => $this->sheet([$row])]);

        $this->assertSame(1, $this->appointments());
    }

    /* ---------------- the sheet's own letter status ---------------- */

    /** A seat the chain says is already lettered is not appointed again. */
    public function test_a_seat_marked_sent_by_an_academic_executive_is_skipped(): void
    {
        Storage::fake('local');
        Mail::fake();

        $student = $this->candidate('22001001', 'a@test.my');
        $internal = $this->examiner('Dr. Chandra Segaran', 'internal');
        $external = $this->examiner('Prof. Nabila Yusof', 'external');
        $nomination = $this->nomination($student, $internal, $external);

        $this->actingAs($this->cgs())
            ->post(route('appointment-letter.import.store'), [
                'sheet' => $this->sheet([[
                    'matric' => '22001001', 'nomination' => $nomination,
                    'internal' => 'Dr. Chandra Segaran', 'external' => 'Prof. Nabila Yusof',
                    'internal_status' => 'Sent 01 Nov 2026 (entered by AE)',
                ]]),
            ])
            ->assertSessionHasNoErrors();

        $names = AppointmentExaminer::pluck('examiner_name')->all();

        $this->assertSame(['Prof. Nabila Yusof'], $names,
            'Only the seat without a letter should be appointed.');
    }

    /** The module's own sends count the same way. */
    public function test_a_seat_marked_sent_by_the_letter_module_is_skipped(): void
    {
        Storage::fake('local');
        Mail::fake();

        $student = $this->candidate('22001001', 'a@test.my');
        $internal = $this->examiner('Dr. Chandra Segaran', 'internal');
        $external = $this->examiner('Prof. Nabila Yusof', 'external');
        $nomination = $this->nomination($student, $internal, $external);

        $this->actingAs($this->cgs())
            ->post(route('appointment-letter.import.store'), [
                'sheet' => $this->sheet([[
                    'matric' => '22001001', 'nomination' => $nomination,
                    'internal' => 'Dr. Chandra Segaran', 'external' => 'Prof. Nabila Yusof',
                    'external_status' => 'Sent 02 Nov 2026 (letter module)',
                ]]),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(['Dr. Chandra Segaran'], AppointmentExaminer::pluck('examiner_name')->all());
    }

    /** "Replaced" describes whoever left, so the new holder still needs one. */
    public function test_a_replaced_seat_is_not_treated_as_sent(): void
    {
        $this->assertFalse(AppointmentSheet::saysLetterSent('Replaced 05 Nov 2026'));
        $this->assertFalse(AppointmentSheet::saysLetterSent('Not sent'));
        $this->assertFalse(AppointmentSheet::saysLetterSent(''));
        $this->assertTrue(AppointmentSheet::saysLetterSent('Sent 01 Nov 2026 (entered by AE)'));
        $this->assertTrue(AppointmentSheet::saysLetterSent('sent 01 nov 2026 (letter module)'));
    }

    /**
     * The two halves together, which is where a gap would hide.
     *
     * A panel is imported and one examiner's letter goes out. The panel
     * then changes. Re-importing must not open a second appointment -- the
     * row is already done -- and the examiner who has no letter must still
     * have a way to get one. That way is "Letter needed", not the import.
     */
    public function test_a_part_sent_panel_is_skipped_on_import_but_reaches_letter_needed(): void
    {
        Storage::fake('local');
        Mail::fake();

        $student = $this->candidate('22001001', 'a@test.my');
        $internal = $this->examiner('Dr. Chandra Segaran', 'internal');
        $external = $this->examiner('Prof. Nabila Yusof', 'external');
        $nomination = $this->nomination($student, $internal, $external);

        $row = ['matric' => '22001001', 'nomination' => $nomination,
            'internal' => 'Dr. Chandra Segaran', 'external' => 'Prof. Nabila Yusof'];

        $this->actingAs($this->cgs())
            ->post(route('appointment-letter.import.store'), ['sheet' => $this->sheet([$row])])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->appointments());

        // The internal declines; the chain promotes someone into the seat.
        $promoted = $this->examiner('Dr. Tan Boon Keat', 'internal');

        DB::table('examiner_nominations')
            ->where('application_id', $nomination)
            ->update(['internal_main_id' => $promoted]);

        // Re-importing opens nothing: this nomination is already appointed.
        $this->actingAs($this->cgs())
            ->post(route('appointment-letter.import.store'), [
                'sheet' => $this->sheet([$row + ['internal' => 'Dr. Tan Boon Keat']]),
            ])
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'Nothing new on that list'));

        $this->assertSame(1, $this->appointments(), 'Re-import must not open a second appointment.');

        // The promoted examiner has no letter, so Letter needed offers one.
        $needed = \App\Modules\Jason\Support\ReleasedPanels::lettersNeeded();

        $this->assertContains($promoted, array_column($needed, 'hani_examiner_id'),
            'The promoted examiner should be offered a letter.');

        $this->assertNotContains($external, array_column($needed, 'hani_examiner_id'),
            'The examiner who already has a letter must never be offered another.');

        // And taking it up opens its own appointment, not a second panel.
        $this->actingAs($this->cgs())
            ->post(route('appointment-letter.promoted'), [
                'nomination_application_id' => $nomination,
                'hani_examiner_id' => $promoted,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $this->appointments());

        $opened = Application::where('module_type', 'appointment_letter')->latest('id')->firstOrFail();

        $this->assertSame(
            ['Dr. Tan Boon Keat'],
            AppointmentExaminer::where('application_id', $opened->id)->pluck('examiner_name')->all(),
        );
    }

    /* ---------------- real errors still reject ---------------- */

    public function test_a_genuine_error_still_rejects_the_whole_file(): void
    {
        Storage::fake('local');

        $this->examiner('Dr. Chandra Segaran', 'internal');
        $this->examiner('Prof. Nabila Yusof', 'external');

        $this->actingAs($this->cgs())
            ->post(route('appointment-letter.import.store'), [
                'sheet' => $this->sheet([[
                    'matric' => '22009999', 'nomination' => '',
                    'internal' => 'Dr. Chandra Segaran', 'external' => 'Prof. Nabila Yusof',
                ]]),
            ])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Nothing was imported')
                && str_contains($m, '22009999'));

        $this->assertSame(0, $this->appointments());
    }

    /** The database refuses a second row for the same examiner regardless. */
    public function test_the_unique_index_is_the_backstop(): void
    {
        Storage::fake('local');
        Mail::fake();

        $student = $this->candidate('22001001', 'a@test.my');
        $internal = $this->examiner('Dr. Chandra Segaran', 'internal');
        $external = $this->examiner('Prof. Nabila Yusof', 'external');
        $nomination = $this->nomination($student, $internal, $external);

        $this->actingAs($this->cgs())
            ->post(route('appointment-letter.import.store'), [
                'sheet' => $this->sheet([[
                    'matric' => '22001001', 'nomination' => $nomination,
                    'internal' => 'Dr. Chandra Segaran', 'external' => 'Prof. Nabila Yusof',
                ]]),
            ]);

        $row = AppointmentExaminer::firstOrFail();

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        AppointmentExaminer::create($row->only([
            'application_id', 'hani_examiner_id', 'examiner_type', 'examiner_role',
            'examiner_name', 'examiner_institution', 'examiner_email',
        ]));
    }
}
