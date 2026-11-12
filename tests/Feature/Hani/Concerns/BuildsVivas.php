<?php

namespace Tests\Feature\Hani\Concerns;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerNomination;
use App\Modules\Hani\Models\VivaDetail;
use App\Modules\Hani\Models\VivaSoftboundDetail;
use App\Modules\Hani\Support\VivaChecklist;

/**
 * A viva at a given point, built through the engine (submit / decide) for
 * the notice and softbound, and with a released four-seat panel, as
 * ExaminerNominationTest leaves one. For the tests that start after those
 * steps. Fictional people only.
 */
trait BuildsVivas
{
    protected function academicExecFor(string $department): User
    {
        return User::firstOrCreate(
            ['role' => Role::ACADEMIC_EXEC, 'department' => $department],
            ['name' => 'AE '.$department, 'email' => 'ae.'.str($department)->slug().'@test.my', 'password' => 'password'],
        );
    }

    /**
     * @return array{student: User, supervisor: User, ae: User, notice: Application, detail: VivaDetail, panel: ?ExaminerNomination}
     */
    protected function viva(string $matric = '22001001', string $programme = 'MSc Full-Time', string $department = 'Computing', bool $softbound = true, bool $panel = true): array
    {
        $engine = app(WorkflowEngine::class);
        $ae = $this->academicExecFor($department);

        $supervisor = User::create([
            'name' => 'Dr Supervisor '.$matric, 'email' => "sv{$matric}@test.my", 'password' => 'password',
            'role' => Role::SUPERVISOR, 'department' => $department,
        ]);
        $student = User::create([
            'name' => 'Student '.$matric, 'email' => "s{$matric}@test.my", 'password' => 'password',
            'role' => Role::STUDENT, 'matric_no' => $matric, 'programme' => $programme,
            'department' => $department, 'supervisor_id' => $supervisor->id,
        ]);

        $notice = Application::create(['student_id' => $student->id, 'module_type' => 'viva_voce', 'status' => Application::STATUS_DRAFT]);
        $detail = VivaDetail::create([
            'application_id' => $notice->id, 'programme_level' => VivaChecklist::levelFor($student),
            'thesis_title' => 'Thesis of '.$matric, 'expected_softbound_date' => now()->addMonths(4),
        ]);
        $engine->submit($notice);
        if ($notice->current_stage === 'sv_endorsement') {
            $engine->decide($notice, $supervisor, 'approve');
        }
        $engine->decide($notice, $ae, 'approve');

        if ($softbound) {
            $attempt = Application::create(['student_id' => $student->id, 'module_type' => 'viva_softbound', 'status' => Application::STATUS_DRAFT]);
            VivaSoftboundDetail::create([
                'application_id' => $attempt->id, 'viva_application_id' => $notice->id,
                'thesis_title' => 'Thesis of '.$matric, 'similarity_percent' => 12, 'word_count' => 40000,
                'declaration_accepted' => true, 'similarity_source' => 'self_declared',
            ]);
            $engine->submit($attempt);
            $engine->decide($attempt, $ae, 'approve');
        }

        $nomination = null;
        if ($panel) {
            $make = fn (string $seat, string $type) => Examiner::create([
                'name' => ucfirst(str_replace('_', ' ', $seat))." {$matric}", 'email' => "{$seat}.{$matric}@example.com",
                'department' => $type === Examiner::TYPE_INTERNAL ? $department : 'External',
                'faculty' => 'FSMC', 'type' => $type, 'is_active' => true,
                'institution' => $type === Examiner::TYPE_EXTERNAL ? 'Example University' : null,
            ])->id;

            $application = Application::create([
                'student_id' => $student->id, 'submitted_by_id' => $supervisor->id,
                'module_type' => 'examiner_nomination', 'status' => Application::STATUS_APPROVED,
                'current_stage' => 'cgs_release', 'submitted_at' => now(),
            ]);
            $nomination = ExaminerNomination::create([
                'application_id' => $application->id,
                'internal_main_id' => $make('internal_main', Examiner::TYPE_INTERNAL),
                'internal_backup_id' => $make('internal_backup', Examiner::TYPE_INTERNAL),
                'external_main_id' => $make('external_main', Examiner::TYPE_EXTERNAL),
                'external_backup_id' => $make('external_backup', Examiner::TYPE_EXTERNAL),
                'thesis_title' => 'Thesis of '.$matric,
            ]);
            app(\App\Modules\Hani\Support\PanelSeats::class)->release($nomination);
        }

        return compact('student', 'supervisor', 'ae', 'notice', 'detail') + ['panel' => $nomination];
    }
}
