<?php

namespace Tests\Feature\Hani;

use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use App\Modules\Core\Contracts\SuppliesAppointmentDispatch;
use App\Modules\Core\Contracts\SuppliesReleasedExaminers;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerNomination;
use App\Modules\Hani\Notifications\ExaminersReleased;
use App\Modules\Hani\Support\PanelSeats;
use App\Modules\Hani\Support\ReleasedExaminerProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\MakesUsers;
use Tests\Feature\Hani\Concerns\DecidesNominations;
use Tests\TestCase;

/**
 * The hand-off to Jason's appointment letters: the released panels, the
 * Core contracts, the release notification and the export's
 * Application No. column.
 */
class ReleasedExaminerProviderTest extends TestCase
{
    use DecidesNominations;
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected User $supervisor;

    protected function nomination(string $status, string $matric, array $seats = []): ExaminerNomination
    {
        $this->supervisor ??= $this->supervisor();

        $student = $this->student($matric, "{$matric}@test.my", [
            'name' => "Candidate {$matric}", 'supervisor_id' => $this->supervisor->id,
            'department' => 'Computing', 'programme' => 'PhD Full-Time',
        ]);

        $make = fn ($name, $type) => Examiner::create(['name' => "{$name} {$matric}", 'email' => strtolower("{$name}.{$matric}@example.com"),
            'department' => 'Computing', 'faculty' => 'FSMC', 'type' => $type, 'institution' => $type === 'external' ? 'Universiti Malaya' : null, 'is_active' => true]);

        $application = Application::create(['student_id' => $student->id, 'submitted_by_id' => $this->supervisor->id,
            'module_type' => 'examiner_nomination', 'status' => $status, 'current_stage' => 'cgs_release']);

        if ($status === Application::STATUS_APPROVED) {
            ApprovalHistory::create(['application_id' => $application->id, 'approver_id' => $this->supervisor->id,
                'stage_key' => 'cgs_release', 'stage_label' => 'Release to CGS operations', 'decision' => 'released']);
        }

        return ExaminerNomination::create($seats + [
            'application_id' => $application->id,
            'thesis_title' => "Thesis {$matric}",
            'internal_main_id' => $make('InMain', 'internal')->id,
            'internal_backup_id' => $make('InBackup', 'internal')->id,
            'external_main_id' => $make('ExMain', 'external')->id,
        ]);
    }

    public function test_only_released_panels_are_handed_over_in_the_agreed_shape(): void
    {
        $released = $this->nomination(Application::STATUS_APPROVED, '22000001');
        $this->nomination(Application::STATUS_PENDING, '22000002');

        $panels = app(ReleasedExaminerProvider::class)->releasedPanels();

        $this->assertCount(1, $panels);
        $panel = $panels[0];

        $this->assertSame($released->id, $panel['nomination_id']);
        $this->assertNotNull($panel['released_at']);
        $this->assertSame(['id', 'name', 'matric_id', 'programme', 'degree', 'department'], array_keys($panel['student']));
        $this->assertSame('22000001', $panel['student']['matric_id']);
        $this->assertSame('PhD', $panel['student']['degree']);
        $this->assertSame(['name' => 'Dr. Aisyah Rahman', 'email' => 'sup@test.my'], $panel['supervisor']);
        $this->assertSame('Thesis 22000001', $panel['thesis_title']);
        $this->assertSame(['id', 'name', 'email', 'email_problem', 'department', 'faculty'], array_keys($panel['examiners']['internal_main']));
        $this->assertSame(['id', 'name', 'email', 'email_problem', 'institution', 'department'], array_keys($panel['examiners']['external_main']));

        // The id is examiners.id: the key packSentAt() is asked with.
        $released->refresh();
        foreach (['internal_main', 'internal_backup', 'external_main'] as $seat) {
            $this->assertSame($released->{$seat.'_id'}, $panel['examiners'][$seat]['id']);
        }
        $this->assertNull($panel['examiners']['external_backup'], 'An empty backup seat is null.');

        $this->assertNull(app(ReleasedExaminerProvider::class)->panelForStudent(
            Application::where('status', Application::STATUS_PENDING)->value('student_id')
        ));
        $this->assertSame([], app(ReleasedExaminerProvider::class)->releasedPanels(now()->addDay()));
    }

    public function test_the_panel_handed_over_is_the_current_one_after_a_decline(): void
    {
        $nomination = $this->nomination(Application::STATUS_APPROVED, '22000001');
        app(PanelSeats::class)->release($nomination);
        $ae = $this->academicExec();
        $ae->update(['department' => 'Computing']);

        app(PanelSeats::class)->decline($nomination, 'internal_main_id', 'Declined.', $ae);

        $panel = app(ReleasedExaminerProvider::class)->panelForStudent($nomination->application->student_id);

        $this->assertSame('InBackup 22000001', $panel['examiners']['internal_main']['name']);
        // The promoted backup's own id travels with them into the main seat.
        $this->assertSame($nomination->fresh()->internal_main_id, $panel['examiners']['internal_main']['id']);
        $this->assertSame(Examiner::where('name', 'InBackup 22000001')->value('id'), $panel['examiners']['internal_main']['id']);
        $this->assertNull($panel['examiners']['internal_backup']);
    }

    public function test_the_contracts_are_bound_and_the_panel_page_reads_jasons_dispatch(): void
    {
        $nomination = $this->nomination(Application::STATUS_APPROVED, '22000001');
        $senior = $this->seniorExec();

        $this->assertInstanceOf(ReleasedExaminerProvider::class, app(SuppliesReleasedExaminers::class));
        $this->assertCount(1, app(SuppliesReleasedExaminers::class)->releasedPanels());

        // Taken away, the panel page drops the column and nothing breaks.
        unset($this->app[SuppliesAppointmentDispatch::class]);
        $this->actingAs($senior)->get(route('examiner-panel.show', $nomination))
            ->assertOk()->assertDontSee('<th>Appointment letter</th>', false);

        app()->instance(SuppliesAppointmentDispatch::class, new class implements SuppliesAppointmentDispatch {
            public function packSentAt(int $applicationId, int $examinerId): ?\DateTimeInterface
            {
                return new \DateTimeImmutable('2026-11-01');
            }
        });

        $this->actingAs($senior)->get(route('examiner-panel.show', $nomination))
            ->assertOk()->assertSee('<th>Appointment letter</th>', false)->assertSee('Sent 1 Nov 2026');
    }

    public function test_release_tells_every_non_executive_and_the_export_carries_the_application_number(): void
    {
        Notification::fake();

        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id, 'department' => 'Computing']);
        $ae = $this->academicExec();
        $ae->update(['department' => 'Computing']);
        $senior = $this->seniorExec();
        $nonExecs = [$this->cgs(), $this->user(\App\Modules\Core\Support\Role::NON_EXEC_CGS, ['email' => 'cgs2@test.my'])];

        $make = fn ($name, $type) => Examiner::create(['name' => $name, 'email' => str($name)->slug().'@example.com',
            'department' => 'Computing', 'type' => $type, 'institution' => 'UM', 'is_active' => true]);

        $this->actingAs($supervisor)->post(route('examiner-nomination.store'), [
            'student_id' => $student->id, 'thesis_title' => 'A thesis',
            'internal_main_id' => $make('Dr In', 'internal')->id, 'external_main_id' => $make('Prof Ex', 'external')->id,
        ]);
        $application = Application::sole();

        $this->decideNomination($ae, $application, ['decision' => 'approve']);
        $this->decideNomination($senior, $application, ['decision' => 'approve']);
        $this->decideNomination($this->seniorDirector(), $application, ['decision' => 'approve']);
        $this->decideNomination($this->dean(), $application, ['decision' => 'approve']);

        // Not before release: the engine's own emails to the candidate are not this.
        Notification::assertNotSentTo($nonExecs, ExaminersReleased::class);

        $this->decideNomination($senior, $application, ['decision' => 'approve']);

        foreach ($nonExecs as $nonExec) {
            Notification::assertSentTo($nonExec, ExaminersReleased::class,
                fn ($n) => $n->toMail($nonExec)->subject === 'New examiner list released');
        }

        $csv = $this->actingAs($nonExecs[0])->get(route('examiner-nomination.export', ['format' => 'csv']))->assertOk();
        $rows = array_map('str_getcsv', array_filter(explode("\n", file_get_contents($csv->baseResponse->getFile()->getPathname()))));

        $this->assertSame('Application No.', $rows[0][0]);
        $this->assertSame((string) $application->id, $rows[1][0]);
    }
}
