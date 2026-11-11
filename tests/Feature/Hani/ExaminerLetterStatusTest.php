<?php

namespace Tests\Feature\Hani;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Hani\Exports\ExaminerListExport;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerMerge;
use App\Modules\Hani\Models\ExaminerNomination;
use App\Modules\Hani\Models\VivaExaminerProgress;
use App\Modules\Hani\Support\PanelSeats;
use App\Modules\Jason\Models\AppointmentDetail;
use App\Modules\Jason\Models\AppointmentExaminer;
use App\Modules\Jason\Support\AppointmentSheet;
use App\Modules\Jason\Support\ReleasedPanels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The Examiner Report and its export say who already has an appointment
 * letter, so nobody is sent a second one. Jason's dispatch and the AE-entered
 * date both count; where both exist, Jason's wins.
 */
class ExaminerLetterStatusTest extends TestCase
{
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected const CONTRACT = 'App\\Modules\\Core\\Contracts\\SuppliesAppointmentDispatch';

    /** Heading => cell, for every data row. */
    protected function export(array $query = []): array
    {
        $response = $this->actingAs($this->nonExec())
            ->get(route('examiner-nomination.export', ['format' => 'csv'] + $query))
            ->assertOk();

        $handle = fopen($response->baseResponse->getFile()->getPathname(), 'r');
        $headings = fgetcsv($handle);
        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = array_combine($headings, $row);
        }

        fclose($handle);

        return $rows;
    }

    protected ?User $nonExecUser = null;

    protected function nonExec(): User
    {
        return $this->nonExecUser ??= $this->cgs();
    }

    protected function panel(string $matric, string $status = Application::STATUS_APPROVED, array $seats = []): ExaminerNomination
    {
        $supervisor = User::where('email', 'sup@test.my')->first() ?? $this->supervisor();
        $student = $this->student($matric, "{$matric}@test.my", [
            'name' => "Candidate {$matric}", 'supervisor_id' => $supervisor->id, 'department' => 'Computing',
        ]);

        $application = Application::create(['student_id' => $student->id, 'submitted_by_id' => $supervisor->id,
            'module_type' => 'examiner_nomination', 'status' => $status, 'current_stage' => $status === Application::STATUS_APPROVED ? null : 'cgs_compile']);

        if ($status === Application::STATUS_APPROVED) {
            ApprovalHistory::create(['application_id' => $application->id, 'approver_id' => $supervisor->id,
                'stage_key' => 'cgs_release', 'stage_label' => 'Release to CGS operations', 'decision' => 'released']);
        }

        $nomination = ExaminerNomination::create($seats + [
            'application_id' => $application->id,
            'thesis_title' => "Thesis {$matric}",
            'internal_main_id' => $this->examiner("InMain {$matric}", 'internal')->id,
            'internal_backup_id' => $this->examiner("InBackup {$matric}", 'internal')->id,
            'external_main_id' => $this->examiner("ExMain {$matric}", 'external')->id,
        ]);

        if ($status === Application::STATUS_APPROVED) {
            app(PanelSeats::class)->release($nomination);
        }

        return $nomination->fresh();
    }

    protected function examiner(string $name, string $type): Examiner
    {
        return Examiner::create(['name' => $name, 'email' => str($name)->slug().'@example.com', 'department' => 'Computing',
            'faculty' => 'FSMC', 'type' => $type, 'institution' => $type === 'external' ? 'Universiti Malaya' : null, 'is_active' => true]);
    }

    /** The date an Academic Executive enters on the examiner progress page. */
    protected function enterLetterDate(ExaminerNomination $nomination, string $seat, string $date): void
    {
        $student = $nomination->application->student;
        $notice = Application::create(['student_id' => $student->id, 'submitted_by_id' => $student->id,
            'module_type' => 'viva_voce', 'status' => Application::STATUS_APPROVED]);

        VivaExaminerProgress::create(['viva_application_id' => $notice->id, 'nomination_id' => $nomination->id, 'seat' => $seat,
            'examiner_id' => $nomination->{$seat}, 'letter_sent_at' => $date, 'letter_source' => 'manual']);
    }

    /** Stands in for Jason's AppointmentDispatch: [application id, examiner id, date]. */
    protected function bindDispatch(array $sent): void
    {
        app()->instance(self::CONTRACT, new class($sent)
        {
            public function __construct(protected array $sent) {}

            public function packSentAt(int $applicationId, int $examinerId): ?\DateTimeInterface
            {
                foreach ($this->sent as [$application, $examiner, $date]) {
                    if ($application === $applicationId && $examiner === $examinerId) {
                        return new \DateTimeImmutable($date);
                    }
                }

                return null;
            }
        });
    }

    /** Jason's module binds the contract; these tests are about what happens without it. */
    protected function unbindDispatch(): void
    {
        app()->offsetUnset(self::CONTRACT);
    }

    protected function ae(): User
    {
        return tap($this->academicExec(), fn (User $ae) => $ae->update(['department' => 'Computing']));
    }

    public function test_existing_columns_keep_their_order_and_jason_reads_the_sheet_as_before(): void
    {
        $headings = (new ExaminerListExport(collect()))->headings();

        $this->assertSame([
            'Application No.', 'Faculty', 'Department', 'Candidate', 'Matric No', 'Programme',
            'Thesis Title', 'Supervisor',
            'Internal Main', 'Internal Main Dept', 'Internal Backup',
            'External Main', 'External Main Institution', 'External Backup',
            'Stage', 'Status', 'Submitted',
            'Internal Main Email', 'External Main Email',
        ], array_slice($headings, 0, 19));

        $this->assertSame([
            'Internal Main Letter Status', 'Internal Backup Letter Status',
            'External Main Letter Status', 'External Backup Letter Status',
            'Letter Needed For', 'Replaced Examiners',
            'DAC Endorsed',
        ], array_slice($headings, 19));

        // Jason's importer now reads the six new columns on purpose (the letter
        // status per seat, AppointmentSheet::SLOTS), so they may map -- but only
        // to keys of their own: every original heading keeps its column.
        $original = AppointmentSheet::mapHeader(array_slice($headings, 0, 19));
        $mapped = AppointmentSheet::mapHeader($headings);

        $this->assertSame($original, array_intersect_key($mapped, $original),
            'A new column must not claim one of the importer\'s headings.');
        // "DAC Endorsed" (the last column, 2026-11-12) is for people to read;
        // Jason's importer ignores it, and it claims none of his headings.
        $this->assertSame(range(19, count($headings) - 2), array_values(array_diff_key($mapped, $original)),
            'Each new column maps to a key of its own.');
        $this->assertNotContains(count($headings) - 1, $mapped, 'DAC Endorsed claims no importer key.');
    }

    public function test_without_the_binding_the_entered_date_decides_and_sent_seats_are_not_flagged(): void
    {
        $this->unbindDispatch();

        $bothSent = $this->panel('22000001');
        $this->enterLetterDate($bothSent, 'internal_main_id', '2026-11-01');
        $this->enterLetterDate($bothSent, 'external_main_id', '2026-11-02');

        $oneSent = $this->panel('22000002');
        $this->enterLetterDate($oneSent, 'internal_main_id', '2026-11-03');

        $noneSent = $this->panel('22000003');
        $this->panel('22000004', Application::STATUS_PENDING);

        $rows = collect($this->export())->keyBy(fn (array $row) => $row['Application No.']);

        $this->assertEqualsCanonicalizing([$oneSent->application_id, $noneSent->application_id], $rows->keys()->all(),
            'Awaiting letters is released panels with a main seat Not sent.');

        $partial = $rows[$oneSent->application_id];
        $this->assertSame('Sent 03 Nov 2026 (entered by AE)', $partial['Internal Main Letter Status']);
        $this->assertSame('Not sent', $partial['External Main Letter Status']);
        $this->assertSame('External Main: ExMain 22000002', $partial['Letter Needed For'],
            'Only the seat still owed a letter is flagged.');
        $this->assertSame('InMain 22000002', $partial['Internal Main'], 'The name column stays for the importer.');

        $this->assertSame('Internal Main: InMain 22000003; External Main: ExMain 22000003', $rows[$noneSent->application_id]['Letter Needed For']);
    }

    public function test_bound_with_only_an_entered_date_counts_as_sent_by_the_ae(): void
    {
        $nomination = $this->panel('22000001');
        $this->enterLetterDate($nomination, 'external_main_id', '2026-11-02');
        $this->bindDispatch([[$nomination->application_id, $nomination->internal_main_id, '2026-11-01']]);

        $row = $this->export(['scope' => 'full'])[0];

        $this->assertSame('Sent 01 Nov 2026 (letter module)', $row['Internal Main Letter Status']);
        $this->assertSame('Sent 02 Nov 2026 (entered by AE)', $row['External Main Letter Status']);
        $this->assertSame([], $this->export(), 'The AE date counts, so no second letter is asked for.');
    }

    public function test_bound_with_both_dates_jasons_wins(): void
    {
        $nomination = $this->panel('22000001');
        $this->enterLetterDate($nomination, 'internal_main_id', '2026-10-20');
        $this->bindDispatch([[$nomination->application_id, $nomination->internal_main_id, '2026-11-01']]);

        $row = $this->export()[0];

        $this->assertSame('Sent 01 Nov 2026 (letter module)', $row['Internal Main Letter Status']);
        $this->assertSame('Not sent', $row['External Main Letter Status']);
        $this->assertSame('External Main: ExMain 22000001', $row['Letter Needed For']);
    }

    public function test_a_date_copied_from_jasons_module_is_not_labelled_as_entered_by_the_ae(): void
    {
        $this->unbindDispatch();

        $nomination = $this->panel('22000001');
        $this->enterLetterDate($nomination, 'internal_main_id', '2026-11-01');
        VivaExaminerProgress::query()->update(['letter_source' => 'dispatch']);

        $this->assertSame('Sent 01 Nov 2026 (letter module)', $this->export()[0]['Internal Main Letter Status']);
    }

    /** The real classes on both sides of both contracts, not stand-ins. */
    public function test_the_contracts_join_the_real_modules(): void
    {
        $nomination = $this->panel('22000001');

        $panels = ReleasedPanels::all();
        $this->assertCount(1, $panels);
        $this->assertSame($nomination->application_id, $panels[0]['nomination_application_id']);
        $this->assertContains($nomination->internal_main_id, array_column($panels[0]['examiners'], 'hani_examiner_id'));

        $appointment = Application::create(['student_id' => $nomination->application->student_id,
            'submitted_by_id' => $this->nonExec()->id, 'module_type' => 'appointment_letter', 'status' => Application::STATUS_APPROVED]);
        AppointmentDetail::create(['application_id' => $appointment->id, 'nomination_application_id' => $nomination->application_id]);
        AppointmentExaminer::create(['application_id' => $appointment->id, 'hani_examiner_id' => $nomination->internal_main_id,
            'examiner_name' => 'InMain 22000001', 'examiner_type' => 'internal', 'examiner_institution' => 'UTP',
            'examiner_address' => 'UTP', 'examiner_email' => 'inmain@example.com', 'examiner_expertise' => 'Computing',
            'pack_sent_at' => '2026-11-01 10:00:00']);

        $row = $this->export()[0];
        $this->assertSame('Sent 01 Nov 2026 (letter module)', $row['Internal Main Letter Status']);
        $this->assertSame('Not sent', $row['External Main Letter Status']);
    }

    public function test_a_replacement_shows_only_the_new_examiner_as_not_sent(): void
    {
        $nomination = $this->panel('22000001', seats: ['internal_backup_id' => null]);
        $old = $nomination->internalMain;
        $this->bindDispatch([
            [$nomination->application_id, $old->id, '2026-11-01'],
            [$nomination->application_id, $nomination->external_main_id, '2026-11-02'],
        ]);

        Carbon::setTestNow('2026-11-05 09:00:00');
        app(PanelSeats::class)->decline($nomination, 'internal_main_id', 'Declined.', $this->ae());

        $row = $this->export(['scope' => 'full'])[0];
        $this->assertSame('Replaced 05 Nov 2026', $row['Internal Main Letter Status'], 'The seat is empty and the letter is spent.');
        $this->assertSame([], $this->export(), 'Nobody to write to yet.');

        Carbon::setTestNow('2026-11-06 09:00:00');
        $new = $this->examiner('New Internal', 'internal');
        app(PanelSeats::class)->replace($nomination->fresh(), 'internal_main_id', $new, $this->seniorExec());

        $row = $this->export()[0];
        $this->assertSame('Not sent', $row['Internal Main Letter Status']);
        $this->assertSame('New Internal', $row['Internal Main']);
        $this->assertSame('Sent 02 Nov 2026 (letter module)', $row['External Main Letter Status']);
        $this->assertSame('Internal Main: New Internal', $row['Letter Needed For']);
        $this->assertSame("Internal Main: {$old->name}, Replaced 05 Nov 2026", $row['Replaced Examiners']);

        $this->actingAs($this->nonExec())->get(route('examiner-nomination.report'))
            ->assertOk()->assertSee('Letters: 1/2 sent')->assertSee("{$old->name}: Replaced 05 Nov 2026");

        Carbon::setTestNow();
    }

    public function test_a_promoted_backup_is_not_sent_and_someone_who_never_had_a_letter_is_not_replaced(): void
    {
        $this->unbindDispatch();

        $nomination = $this->panel('22000001');
        $backup = $nomination->internalBackup;

        app(PanelSeats::class)->decline($nomination, 'internal_main_id', 'Declined.', $this->ae());

        $row = $this->export()[0];

        $this->assertSame($backup->name, $row['Internal Main']);
        $this->assertSame('Not sent', $row['Internal Main Letter Status']);
        $this->assertSame('', $row['Internal Backup Letter Status']);
        $this->assertSame('', $row['Replaced Examiners']);
    }

    public function test_a_letter_under_a_merged_record_still_counts_for_the_person(): void
    {
        $nomination = $this->panel('22000001');
        $kept = $this->examiner('Kept Record', 'external');
        $duplicate = $nomination->externalMain;

        $nomination->update(['external_main_id' => $kept->id]);
        ExaminerMerge::create(['kept_id' => $kept->id, 'duplicate_id' => $duplicate->id, 'merged_by_id' => $this->seniorExec()->id, 'summary' => []]);

        $this->bindDispatch([
            [$nomination->application_id, $nomination->internal_main_id, '2026-11-01'],
            [$nomination->application_id, $duplicate->id, '2026-11-02'],
        ]);

        $row = $this->export(['scope' => 'full'])[0];

        $this->assertSame('Sent 02 Nov 2026 (letter module)', $row['External Main Letter Status']);
        $this->assertSame('', $row['Replaced Examiners'], 'The same person, not a replacement.');
        $this->assertSame([], $this->export());
    }

    public function test_full_list_keeps_every_row_and_flags_nothing(): void
    {
        $this->unbindDispatch();

        $released = $this->panel('22000001');
        $pending = $this->panel('22000002', Application::STATUS_PENDING);

        $rows = collect($this->export(['scope' => 'full']))->keyBy(fn (array $row) => $row['Application No.']);

        $this->assertCount(2, $rows);
        $this->assertSame('Not sent', $rows[$released->application_id]['Internal Main Letter Status']);
        $this->assertSame('Not sent', $rows[$released->application_id]['Internal Backup Letter Status']);
        $this->assertSame('', $rows[$released->application_id]['External Backup Letter Status'], 'An empty seat has no status.');
        $this->assertSame('', $rows[$released->application_id]['Letter Needed For']);
        $this->assertSame('', $rows[$pending->application_id]['Internal Main Letter Status'], 'No letter is due before release.');
    }

    public function test_a_panel_already_examined_is_not_awaiting_a_letter(): void
    {
        $this->unbindDispatch();

        $nomination = $this->panel('22000001');
        app(PanelSeats::class)->complete($nomination);

        $this->assertSame([], $this->export());
        $this->assertCount(1, $this->export(['scope' => 'full']));
    }

    public function test_an_academic_executive_only_gets_their_own_department_and_a_bad_scope_is_refused(): void
    {
        $this->unbindDispatch();

        $this->panel('22000001');
        $other = $this->panel('22000002');
        $other->application->student->update(['department' => 'Chemical Engineering']);

        $response = $this->actingAs($this->ae())->get(route('examiner-nomination.export', ['format' => 'csv']))->assertOk();
        $csv = file_get_contents($response->baseResponse->getFile()->getPathname());

        $this->assertStringContainsString('Candidate 22000001', $csv);
        $this->assertStringNotContainsString('Candidate 22000002', $csv);

        $this->actingAs($this->nonExec())->get(route('examiner-nomination.export', ['scope' => 'everything']))->assertSessionHasErrors('scope');
    }

    public function test_the_report_shows_a_badge_per_released_candidate_and_both_downloads(): void
    {
        $this->unbindDispatch();

        $sent = $this->panel('22000001');
        $this->enterLetterDate($sent, 'internal_main_id', '2026-11-01');
        $this->enterLetterDate($sent, 'external_main_id', '2026-11-02');
        $this->panel('22000002');
        $this->panel('22000003', Application::STATUS_PENDING);

        $response = $this->actingAs($this->nonExec())->get(route('examiner-nomination.report'))->assertOk();

        $response->assertSee('Letters: 2/2 sent')
            ->assertSee('Letters: 0/2 sent')
            ->assertSee('Letter: Sent 01 Nov 2026 (entered by AE)')
            ->assertSee('Letter: Not sent')
            ->assertSee('Download Excel: awaiting letters')
            ->assertSee('Download Excel: full list')
            ->assertSee(e(route('examiner-nomination.export', ['format' => 'xlsx', 'scope' => 'full'])), false);

        $this->assertSame(2, substr_count($response->getContent(), 'Letters: '), 'The panel still pending has no badge.');
    }
}
