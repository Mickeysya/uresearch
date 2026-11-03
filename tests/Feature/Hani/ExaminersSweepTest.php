<?php

namespace Tests\Feature\Hani;

use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerImportBatch;
use App\Modules\Hani\Models\ExaminerNomination;
use App\Modules\Hani\Models\VivaDetail;
use App\Modules\Hani\Notifications\EvaluationOverdue;
use App\Modules\Hani\Support\PanelSeats;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/** `examiners:sweep`: reminders after the viva, once per window; stale previews purged. */
class ExaminersSweepTest extends TestCase
{
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected User $ae;

    protected ExaminerNomination $nomination;

    protected function setUp(): void
    {
        parent::setUp();

        $student = $this->student(attributes: ['department' => 'Computing', 'name' => 'Aiman Hakim']);
        $this->ae = $this->academicExec();
        $this->ae->update(['department' => 'Computing']);

        $make = fn ($name, $type) => Examiner::create(['name' => $name, 'email' => str($name)->slug().'@example.com',
            'department' => 'Computing', 'type' => $type, 'institution' => 'UM', 'is_active' => true]);

        $application = Application::create(['student_id' => $student->id, 'module_type' => 'examiner_nomination',
            'status' => Application::STATUS_APPROVED, 'current_stage' => 'cgs_release']);
        $this->nomination = ExaminerNomination::create([
            'application_id' => $application->id, 'thesis_title' => 'A thesis',
            'internal_main_id' => $make('Dr In', Examiner::TYPE_INTERNAL)->id,
            'external_main_id' => $make('Prof Ex', Examiner::TYPE_EXTERNAL)->id,
        ]);
        app(PanelSeats::class)->release($this->nomination);

        $viva = Application::create(['student_id' => $student->id, 'module_type' => 'viva_voce',
            'status' => Application::STATUS_APPROVED, 'current_stage' => 'academic_exec']);
        VivaDetail::create(['application_id' => $viva->id, 'programme_level' => 'msc', 'thesis_title' => 'A thesis',
            'expected_softbound_date' => now()->subMonth(), 'viva_at' => now()->subDays(2)]);
    }

    public function test_an_overdue_evaluation_is_reminded_once_per_window(): void
    {
        Notification::fake();

        $this->artisan('examiners:sweep')->assertSuccessful();
        Notification::assertSentToTimes($this->ae, EvaluationOverdue::class, 1);

        // Same day, again: the log row is already there.
        $this->artisan('examiners:sweep')->expectsOutputToContain('1 already sent')->assertSuccessful();
        Notification::assertSentToTimes($this->ae, EvaluationOverdue::class, 1);

        // A week later the next window opens.
        $this->travel(7)->days();
        $this->artisan('examiners:sweep')->assertSuccessful();
        Notification::assertSentToTimes($this->ae, EvaluationOverdue::class, 2);
        $this->assertDatabaseCount('examiner_reminder_logs', 2);
    }

    public function test_nothing_is_sent_once_the_evaluation_is_complete_or_before_the_viva(): void
    {
        Notification::fake();

        app(PanelSeats::class)->complete($this->nomination);
        $this->artisan('examiners:sweep')->assertSuccessful();

        VivaDetail::query()->update(['viva_at' => now()->addDays(3)]);
        $this->nomination->examiners()->each->update(['assigned_until' => now()->addDays(100)]);
        $this->artisan('examiners:sweep')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_another_departments_academic_executive_is_not_reminded(): void
    {
        Notification::fake();
        $other = $this->user(\App\Modules\Core\Support\Role::ACADEMIC_EXEC, ['email' => 'ae.other@test.my', 'department' => 'Petroleum Engineering']);

        $this->artisan('examiners:sweep');

        Notification::assertNotSentTo($other, EvaluationOverdue::class);
    }

    public function test_a_dry_run_sends_and_logs_nothing(): void
    {
        Notification::fake();

        $this->artisan('examiners:sweep --dry-run')->expectsOutputToContain('would remind 1 Academic Executive(s)')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertDatabaseCount('examiner_reminder_logs', 0);
    }

    public function test_stale_import_previews_are_purged(): void
    {
        $stale = ExaminerImportBatch::create(['user_id' => $this->ae->id, 'layout' => 'template', 'filename' => 'a.csv', 'rows' => []]);
        $stale->forceFill(['created_at' => now()->subDays(2)])->save();
        $fresh = ExaminerImportBatch::create(['user_id' => $this->ae->id, 'layout' => 'template', 'filename' => 'b.csv', 'rows' => []]);

        $this->artisan('examiners:sweep')->assertSuccessful();

        $this->assertModelMissing($stale);
        $this->assertModelExists($fresh);
    }

    public function test_an_expired_tie_up_is_flagged_on_pending_evaluation(): void
    {
        $this->nomination->examiners()->each->update(['assigned_until' => now()->subDay()]);

        $this->actingAs($this->ae)->get(route('examiner-nomination.pending-evaluation'))
            ->assertOk()
            ->assertSee('without the evaluation being marked complete');
    }

    public function test_the_sweep_is_scheduled_daily_from_the_module(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains($e->command ?? '', 'examiners:sweep'));

        $this->assertCount(1, $events);
        $this->assertSame('30 7 * * *', $events->first()->expression);
    }
}
