<?php

namespace Tests\Feature\Hani;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\VivaDetail;
use App\Modules\Hani\Models\VivaExaminerProgress;
use App\Modules\Hani\Models\VivaReminderLog;
use App\Modules\Hani\Notifications\EvaluationOverdue;
use App\Modules\Hani\Notifications\VivaAwaitingAcademicExec;
use App\Modules\Hani\Notifications\VivaOutcome;
use App\Modules\Hani\Notifications\VivaReminder;
use App\Modules\Hani\Support\ModuleTracker;
use App\Modules\Hani\Support\VivaProgress;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Hani\Concerns\BuildsVivas;
use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Step 5: the result from the chairman's report, the deadlines and examiner
 * housekeeping it sets, the corrections, and the category 4 hand-off.
 */
class VivaResultTest extends TestCase
{
    use BuildsVivas;
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();
        Carbon::setTestNow('2027-03-15 09:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A viva held on 1 March 2027. */
    protected function heldViva(string $matric = '22001001', string $programme = 'MSc Full-Time'): array
    {
        $viva = $this->viva($matric, $programme);
        $viva['detail']->update(['viva_at' => '2027-03-01 10:00', 'venue' => 'Room 1', 'chair_name' => 'Prof Chair', 'chair_email' => 'chair@example.com']);

        return $viva;
    }

    protected function record(array $viva, int $category)
    {
        return $this->actingAs($viva['ae'])->post(route('viva.results.record', $viva['notice']), [
            'result_category' => $category,
            'viva_report' => UploadedFile::fake()->create('viva-report.pdf', 200, 'application/pdf'),
        ]);
    }

    public function test_each_category_sets_its_deadline_and_examiner_state(): void
    {
        $expect = [
            1 => ['2027-04-01', null, null],
            2 => ['2027-06-01', null, null],
            3 => ['2027-09-01', null, null],
            4 => [null, '2027-09-01', '2028-03-01'],
            5 => [null, null, null],
        ];

        foreach ($expect as $category => [$due, $opens, $closes]) {
            $viva = $this->heldViva('2200100'.$category);
            $this->record($viva, $category)->assertSessionHasNoErrors();

            $detail = $viva['detail']->fresh();
            $this->assertSame($category, $detail->result_category);
            $this->assertSame($due, $detail->corrections_due_at?->toDateString(), "category {$category} due");
            $this->assertSame($opens, $detail->resubmission_opens_at?->toDateString(), "category {$category} opens");
            $this->assertSame($closes, $detail->resubmission_closes_at?->toDateString(), "category {$category} closes");
            $this->assertSame('Viva Report', $viva['notice']->documents()->latest('id')->value('doc_type'));

            $internal = Examiner::find($viva['panel']->internal_main_id);
            $backup = Examiner::find($viva['panel']->internal_backup_id);

            if ($category === 4) {
                // Held for the re-viva: still Assigned, no gap started.
                $this->assertSame('2028-03-01', $internal->assigned_until->toDateString());
                $this->assertNull($internal->last_examination_date);
                $this->assertSame(Examiner::STATE_ASSIGNED, $internal->state());
            } else {
                // Evaluation complete: the 90-day gap starts today.
                $this->assertNull($internal->assigned_until);
                $this->assertSame('2027-03-15', $internal->last_examination_date->toDateString());
                $this->assertSame(Examiner::STATE_ON_GAP, $internal->state());
            }
            $this->assertNull($backup->last_examination_date, 'the backup did not examine');

            Notification::assertSentTo($viva['student'], VivaOutcome::class, fn ($n) => $n->kind === 'result_recorded');
        }

        // Category 5 is a cross on the tracker; 4 is not.
        $fail = VivaProgress::for(User::where('matric_no', '22001005')->sole());
        $this->assertSame('rejected', ModuleTracker::viva($fail)['steps'][4]['state']);
        $this->assertStringContainsString('final outcome', ModuleTracker::viva($fail)['now']);
    }

    public function test_a_result_waits_for_the_viva_and_is_recorded_once(): void
    {
        $viva = $this->viva();
        $viva['detail']->update(['viva_at' => '2027-03-20 10:00']);
        $this->record($viva, 2)->assertSessionHasErrors('result_category');

        $viva['detail']->update(['viva_at' => '2027-03-01 10:00']);
        $this->record($viva, 2)->assertSessionHasNoErrors();
        $this->record($viva, 3)->assertSessionHasErrors('result_category');
        $this->assertSame(2, $viva['detail']->fresh()->result_category);

        $other = $this->academicExecFor('Chemical Engineering');
        $this->actingAs($other)->post(route('viva.results.record', $viva['notice']), ['result_category' => 1])->assertForbidden();
    }

    public function test_category_4_asks_the_senior_executive_to_log_a_reviva_and_the_sweep_stays_quiet(): void
    {
        $senior = User::create(['name' => 'Puan Waheeda', 'email' => 'waheeda@test.my', 'password' => 'password', 'role' => Role::SENIOR_EXEC_CGS]);
        $viva = $this->heldViva();

        $this->record($viva, 4)->assertSessionHasNoErrors();

        $link = route('reviva.create', ['student' => $viva['student']->id]);
        Notification::assertSentTo($senior, VivaOutcome::class, fn ($n) => $n->kind === 'reviva_to_log' && $n->toMail($senior)->actionUrl === $link);

        // The Senior Executive can open the form with the student pre-selected.
        $this->actingAs($senior)->get($link)->assertOk()
            ->assertSee('value="'.$viva['student']->id.'"'."\n".'                            selected', false);

        $this->artisan('examiners:sweep')->assertSuccessful();
        Notification::assertNotSentTo($viva['ae'], EvaluationOverdue::class);

        $this->actingAs($viva['student'])->get(route('viva.eligibility'))
            ->assertSee('Category 4 (Re-examination)')
            ->assertSee('Resubmit for re-examination between 1 Sep 2027 and 1 Mar 2028');
    }

    public function test_the_window_closing_without_a_reviva_frees_the_examiners_once_and_a_logged_reviva_keeps_them(): void
    {
        $quiet = $this->heldViva('22001001');
        $logged = $this->heldViva('22001002');
        $this->record($quiet, 4);
        $this->record($logged, 4);

        // A re-viva is logged for the second student in month 11.
        Carbon::setTestNow('2028-02-01 09:00');
        $senior = User::create(['name' => 'Puan Waheeda', 'email' => 'waheeda@test.my', 'password' => 'password', 'role' => Role::SENIOR_EXEC_CGS]);
        $this->actingAs($senior)->post(route('reviva.store'), [
            'student_id' => $logged['student']->id,
            'thesis' => UploadedFile::fake()->create('thesis.pdf', 100, 'application/pdf'),
            'summary_of_corrections' => UploadedFile::fake()->create('summary.pdf', 20, 'application/pdf'),
        ])->assertSessionHasNoErrors();
        $this->assertSame('2029-02-01', Examiner::find($logged['panel']->internal_main_id)->assigned_until->toDateString());

        Carbon::setTestNow('2028-03-02 07:45');
        $this->artisan('viva:reminders')->assertSuccessful();
        $this->artisan('viva:reminders')->assertSuccessful();

        $freed = Examiner::find($quiet['panel']->internal_main_id);
        $this->assertNull($freed->assigned_until);
        $this->assertNull($freed->last_examination_date, 'freed with no gap');
        $this->assertNotNull($quiet['detail']->fresh()->examiners_freed_at);
        Notification::assertSentToTimes($quiet['ae'], VivaReminder::class, 1);

        $this->assertNotNull(Examiner::find($logged['panel']->internal_main_id)->assigned_until);
        $this->assertNull($logged['detail']->fresh()->examiners_freed_at);
    }

    public function test_corrections_submitted_and_accepted_complete_the_tracker(): void
    {
        $viva = $this->heldViva();
        $this->record($viva, 2);

        $this->actingAs($viva['student'])->get(route('viva.eligibility'))
            ->assertSee('Category 2 (Minor)')
            ->assertSee("Chairman's viva report", false)
            ->assertSee('Upload Corrected Final Thesis');

        // Not before the student has submitted.
        $this->actingAs($viva['ae'])->post(route('viva.results.accept', $viva['notice']))->assertSessionHasErrors('corrections');

        $this->actingAs($viva['student'])->post(route('viva.corrections.store'), [
            'corrected_thesis' => UploadedFile::fake()->create('final.pdf', 500, 'application/pdf'),
        ])->assertSessionHasNoErrors();
        Notification::assertSentTo($viva['ae'], VivaAwaitingAcademicExec::class);
        $this->assertSame('Corrected Final Thesis', $viva['notice']->documents()->latest('id')->value('doc_type'));

        $this->actingAs($viva['ae'])->get(route('viva.results.index'))->assertSee('Corrections submitted (1)');
        $this->actingAs($viva['ae'])->post(route('viva.results.accept', $viva['notice']))->assertSessionHasNoErrors();

        $tracker = ModuleTracker::viva(VivaProgress::for($viva['student']));
        $this->assertSame('completed', $tracker['steps'][4]['state']);
        $this->assertNull($tracker['now']);
        Notification::assertSentTo($viva['student'], VivaOutcome::class, fn ($n) => $n->kind === 'corrections_accepted');

        $this->actingAs($viva['student'])->get(route('viva.corrections.create'))->assertRedirect(route('viva.eligibility'));
        $this->actingAs($viva['supervisor'])->get(route('viva.supervisees'))->assertSee('Corrections accepted');
    }

    public function test_correction_reminders_and_overdue_notice_are_sent_once(): void
    {
        $viva = $this->heldViva();
        $this->record($viva, 2);   // due 1 June 2027

        Carbon::setTestNow('2027-05-03 07:45');   // 29 days left
        $this->artisan('viva:reminders');
        $this->artisan('viva:reminders');
        Carbon::setTestNow('2027-05-26 07:45');   // 6 days left
        $this->artisan('viva:reminders');
        Carbon::setTestNow('2027-06-02 07:45');   // overdue
        $this->artisan('viva:reminders');
        $this->artisan('viva:reminders');

        $this->assertSame(['corrections_30d', 'corrections_7d', 'corrections_overdue'], VivaReminderLog::orderBy('id')->pluck('kind')->all());
        Notification::assertSentToTimes($viva['student'], VivaReminder::class, 2);
        Notification::assertSentTo($viva['ae'], VivaReminder::class, fn ($n) => $n->kind === 'corrections_overdue');
    }

    public function test_students_see_the_final_result_but_never_examiner_names_or_their_categories(): void
    {
        $viva = $this->heldViva();
        $this->actingAs($viva['ae'])->get(route('viva.examiners.show', $viva['notice']));
        $rows = VivaExaminerProgress::current()->get()->keyBy('seat');
        $rows['internal_main_id']->update(['category' => 2]);
        $rows['external_main_id']->update(['category' => 4]);
        $this->record($viva, 2);

        foreach ([route('viva.eligibility'), route('viva.corrections.create'), route('applications.show', $viva['notice']), route('documents.index')] as $url) {
            $html = $this->actingAs($viva['student'])->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('Re-examination', $html, $url);
            $this->assertStringNotContainsString('External: 4', $html, $url);
            foreach ($viva['panel']->examiners() as $examiner) {
                $this->assertStringNotContainsString($examiner->name, $html, $url);
            }
        }

        $this->actingAs($viva['student'])->get(route('viva.eligibility'))->assertSee('Category 2 (Minor)');
    }
}
