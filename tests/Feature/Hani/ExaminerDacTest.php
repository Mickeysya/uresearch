<?php

namespace Tests\Feature\Hani;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\ApplicationDecided;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerDacRecord;
use App\Modules\Hani\Notifications\NominationNotEndorsed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * DAC Compilation (UAT 11 Nov 2026): the AE compiles her department's
 * nominations for the DAC, and only DAC-endorsed nominations reach CGS.
 */
class ExaminerDacTest extends TestCase
{
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected User $ae;

    protected User $supervisor;

    protected User $senior;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();

        $this->supervisor = $this->supervisor();
        $this->supervisor->update(['department' => 'Computing']);
        $this->ae = $this->academicExec();
        $this->ae->update(['department' => 'Computing']);
        $this->senior = $this->seniorExec();
    }

    protected function examiner(string $name, string $type, string $department = 'Computing'): Examiner
    {
        return Examiner::create([
            'name' => $name, 'email' => str($name)->slug().'@example.com', 'type' => $type, 'is_active' => true,
            'department' => $type === Examiner::TYPE_EXTERNAL ? 'External' : $department,
            'institution' => $type === Examiner::TYPE_EXTERNAL ? 'Universiti Malaya' : null, 'faculty' => 'FSMC',
        ]);
    }

    protected function nominate(string $matric, string $department = 'Computing'): Application
    {
        $student = $this->student($matric, "s{$matric}@test.my", ['name' => "Candidate {$matric}", 'supervisor_id' => $this->supervisor->id, 'department' => $department]);
        $this->actingAs($this->supervisor)->post(route('examiner-nomination.store'), [
            'student_id' => $student->id, 'thesis_title' => "Thesis {$matric}",
            'internal_main_id' => $this->examiner("Dr Internal {$matric}", Examiner::TYPE_INTERNAL, $department)->id,
            'internal_backup_id' => $this->examiner("Dr Backup {$matric}", Examiner::TYPE_INTERNAL, $department)->id,
            'external_main_id' => $this->examiner("Prof External {$matric}", Examiner::TYPE_EXTERNAL)->id,
            'external_backup_id' => $this->examiner("Prof ExtBackup {$matric}", Examiner::TYPE_EXTERNAL)->id,
        ])->assertSessionHasNoErrors();

        return Application::where('module_type', 'examiner_nomination')->where('student_id', $student->id)->sole();
    }

    public function test_the_page_lists_only_the_aes_department_at_stage_one_and_the_sidebar_links_it(): void
    {
        $mine = $this->nominate('22001001');
        $this->supervisor->update(['department' => 'Management']);
        $other = $this->nominate('22001002', 'Management');

        $this->actingAs($this->ae)->get(route('examiner-nomination.dac'))->assertOk()
            ->assertSee('Candidate 22001001')->assertDontSee('Candidate 22001002')
            ->assertSee('Record DAC approval')->assertSee('Not endorsed by DAC');
        $this->actingAs($this->ae)->get(route('dashboard'))->assertSee(route('examiner-nomination.dac'), false);
        $this->actingAs($this->supervisor)->get(route('examiner-nomination.dac'))->assertForbidden();
    }

    public function test_the_compiled_list_downloads_as_xlsx_and_pdf(): void
    {
        $one = $this->nominate('22001001');
        $this->nominate('22001003');

        $this->actingAs($this->ae)->get(route('examiner-nomination.dac.export', ['format' => 'xlsx', 'ids' => [$one->id]]))
            ->assertOk()->assertDownload();
        $this->actingAs($this->ae)->get(route('examiner-nomination.dac.export', ['format' => 'pdf']))
            ->assertOk()->assertHeader('content-type', 'application/pdf');

        $rows = (new \App\Modules\Hani\Exports\DacCompilationExport(
            \App\Modules\Hani\Models\ExaminerNomination::with('application.student')->where('application_id', $one->id)->get(), [],
        ))->array();
        $this->assertSame('Candidate 22001001', $rows[0][2]);
        $this->assertSame('Dr Internal 22001001', $rows[0][7]);
        $this->assertSame('Universiti Malaya', $rows[0][12]);
    }

    public function test_recording_dac_approval_moves_each_to_the_senior_executive_with_a_history_row(): void
    {
        $one = $this->nominate('22001001');
        $two = $this->nominate('22001003');

        $this->actingAs($this->ae)->post(route('examiner-nomination.dac.endorse'), ['ids' => [$one->id, $two->id]])
            ->assertSessionHasErrors('meeting_date');

        $this->actingAs($this->ae)->post(route('examiner-nomination.dac.endorse'), [
            'ids' => [$one->id, $two->id], 'meeting_date' => now()->subDay()->toDateString(), 'reference' => 'DAC/CS/2026/11',
            'minutes' => UploadedFile::fake()->create('minutes.pdf', 50, 'application/pdf'),
        ])->assertSessionHasNoErrors()->assertSessionHas('status');

        foreach ([$one, $two] as $application) {
            $application->refresh();
            $this->assertSame('cgs_compile', $application->current_stage);
            $row = $application->history()->where('stage_key', 'academic_exec')->sole();
            $this->assertSame('endorsed', $row->decision);
            $this->assertStringContainsString('DAC/CS/2026/11', $row->remarks);
            $this->assertStringContainsString(now()->subDay()->format('j M Y'), $row->remarks);
            $record = ExaminerDacRecord::endorsementFor($application->id);
            $this->assertNotNull($record->minutes_path);
            Storage::disk('local')->assertExists($record->minutes_path);
        }

        // Later stage cards and the Examiner Report say so.
        $label = 'DAC endorsed on '.now()->subDay()->format('j M Y').' (ref DAC/CS/2026/11)';
        $this->actingAs($this->senior)->get(route('examiner-nomination.queue', ['stage' => 'cgs_compile']))->assertOk()->assertSee($label);
        $this->actingAs($this->senior)->get(route('examiner-nomination.report'))->assertOk()->assertSee($label);
    }

    public function test_not_endorsed_needs_a_reason_rejects_and_tells_the_supervisor_and_student(): void
    {
        $one = $this->nominate('22001001');

        $this->actingAs($this->ae)->post(route('examiner-nomination.dac.not-endorsed'), ['ids' => [$one->id]])->assertSessionHasErrors('reason');

        $this->actingAs($this->ae)->post(route('examiner-nomination.dac.not-endorsed'), [
            'ids' => [$one->id], 'reason' => 'The external main examiner was appointed for this supervisor last semester.',
        ])->assertSessionHasNoErrors();

        $this->assertSame(Application::STATUS_REJECTED, $one->fresh()->status);
        $this->assertSame(ExaminerDacRecord::NOT_ENDORSED, ExaminerDacRecord::sole()->outcome);
        Notification::assertSentTo($this->supervisor, NominationNotEndorsed::class, fn ($n) => str_contains($n->reason, 'last semester'));
        Notification::assertSentTo($one->student, ApplicationDecided::class);

        // The supervisor may nominate again.
        $this->actingAs($this->supervisor)->get(route('examiner-nomination.create'))->assertOk();
    }

    public function test_the_ae_cannot_push_a_nomination_to_cgs_without_a_dac_record(): void
    {
        $one = $this->nominate('22001001');

        $this->actingAs($this->ae)->post(route('examiner-nomination.decide', $one), ['decision' => 'approve'])
            ->assertRedirect(route('examiner-nomination.dac'));
        $this->assertSame('academic_exec', $one->fresh()->current_stage);
        $this->assertSame(0, ExaminerDacRecord::count());

        $this->actingAs($this->ae)->post(route('queue.decide-bulk', 'examiner_nomination'), ['ids' => [$one->id], 'decision' => 'approve'])
            ->assertForbidden();
        $this->assertSame('academic_exec', $one->fresh()->current_stage);

        $this->actingAs($this->ae)->get(route('examiner-nomination.queue'))->assertOk()
            ->assertSee('Department compilation &amp; DAC endorsement', false)->assertSee('DAC Compilation');
    }

    public function test_a_long_coauthor_flag_is_a_short_wrapping_pill_with_the_paper_behind_show_details(): void
    {
        $one = $this->nominate('22001001');
        $title = 'A very long paper title about constraint-based examiner matching that used to run off the page';
        Examiner::where('name', 'Dr Internal 22001001')->sole()->publications()
            ->create(['title' => $title, 'year' => 2024, 'coauthors' => $this->supervisor->name]);

        // DAC Compilation: the shared pill, not the uppercase status badge,
        // and a fixed-layout table inside a scroll container.
        $this->actingAs($this->ae)->get(route('examiner-nomination.dac'))->assertOk()
            ->assertSee('Check: Co-author with supervisor (2024)')
            ->assertSee('Show details')
            ->assertSee($title)
            ->assertSee('class="coi-flag is-warning"', false)
            ->assertSee('class="dac-scroll"', false)
            ->assertSeeInOrder(['Internal (main):', 'Dr Internal 22001001', '· Computing'])
            ->assertDontSee('class="status-badge returned"', false);

        // The same pill on the AE's stage-1 card and on the Examiner Report.
        $this->actingAs($this->ae)->get(route('examiner-nomination.queue'))->assertOk()
            ->assertSee('Check: Co-author with supervisor (2024)')->assertSee('Show details');
        $this->actingAs($this->senior)->get(route('examiner-nomination.report'))->assertOk()
            ->assertSee('Check: Co-author with supervisor (2024)');

        // The export and the full message keep the whole sentence.
        $flag = collect(\App\Modules\Hani\Http\Controllers\ExaminerDacController::flags(
            \App\Modules\Hani\Models\ExaminerNomination::with('application.student')->where('application_id', $one->id)->get()
        )[$one->id])->flatten(1)->firstWhere('rule', 'coauthor');
        $this->assertStringContainsString($title, $flag['message']);
        $this->assertSame('Co-author with supervisor (2024)', $flag['summary']);
    }

    public function test_another_departments_ae_cannot_record_for_it(): void
    {
        $one = $this->nominate('22001001');
        $otherAe = User::create(['name' => 'AE Management', 'email' => 'ae.mgmt@test.my', 'password' => 'password', 'role' => Role::ACADEMIC_EXEC, 'department' => 'Management']);

        $this->actingAs($otherAe)->post(route('examiner-nomination.dac.endorse'), ['ids' => [$one->id], 'meeting_date' => now()->toDateString()])
            ->assertSessionHas('error');
        $this->assertSame('academic_exec', $one->fresh()->current_stage);
    }
}
