<?php

namespace App\Modules\Nureen\Database\Seeders;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\CgsAssignment;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Database\Seeders\Support\DemoDocuments;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Models\GaExtensionDetail;
use App\Modules\Nureen\Models\SupervisionDetail;
use App\Modules\Nureen\Support\WorkingDayCalendar;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/**
 * Applications on time and past their Client Charter in each of GA
 * Extension, Supervision and Certification, plus a few decided ones for the
 * compliance report -- so the Expected Decision Dates page, the approver
 * queues' due dates and the analytics page all have something to show.
 *
 *     php artisan db:seed --class="App\Modules\Nureen\Database\Seeders\NureenClientCharterDemoSeeder"
 *
 * Every application goes through WorkflowEngine::submit()/decide() (rule
 * 1). To give them realistic ages, the clock is moved back to "N working
 * days ago" for each step (Carbon::setTestNow), so submitted_at and each
 * approval_history row carry the date that step really happened on -- no
 * timestamp is rewritten afterwards. Ages are counted on the same
 * WorkingDayCalendar the charter uses, from whenever this is run.
 *
 * Each application gets the supporting documents its form would require,
 * as valid one-page placeholder PDFs marked as demo data
 * (Support\DemoDocuments).
 *
 * One student per scenario, matric numbers 22091001-22091024, emails
 * charter-<matric>@utp.edu.my, password `password`. Idempotent: a student
 * who already has an application of that module is skipped, so each matric
 * number always maps to exactly one application.
 */
class NureenClientCharterDemoSeeder extends Seeder
{
    protected WorkflowEngine $engine;

    protected WorkingDayCalendar $calendar;

    protected CarbonImmutable $today;

    public function run(): void
    {
        Notification::fake();

        $this->engine = app(WorkflowEngine::class);
        $this->calendar = app(WorkingDayCalendar::class);
        $this->today = CarbonImmutable::now();

        $supervisor = User::where('email', 'supervisor@utp.edu.my')->firstOrFail();
        $chair = User::where('email', 'chair@utp.edu.my')->firstOrFail();
        // The officer Nureen's modules are assigned to (zulkifly.cgs, see
        // config/cgs_assignment.php) when that account exists -- the engine
        // refuses any other CGS officer -- else the general CGS account.
        $cgs = app(CgsAssignment::class)->officer('ga_extension')
            ?? User::where('email', 'cgs@utp.edu.my')->firstOrFail();
        $director = User::where('email', 'director@utp.edu.my')->firstOrFail();

        // [matric, mode, steps: [working days ago => actor|null for submission]]
        $gaExtension = [
            ['22091001', 'cgs', [1 => null]],                                   // supervisor, on time
            ['22091002', 'cgs', [6 => null]],                                   // supervisor, overdue
            ['22091003', 'cgs', [9 => null, 5 => $supervisor]],                 // cgs_verify, overdue
            ['22091004', 'cgs', [3 => null, 0 => $supervisor]],                 // cgs_verify, on time
            ['22091005', 'senior_director', [8 => null, 6 => $supervisor, 4 => $cgs]], // senior_director: no charter
            ['22091006', 'cgs', [20 => null, 18 => $supervisor, 17 => $cgs]],   // approved within charter
            ['22091007', 'cgs', [15 => null, 10 => $supervisor, 7 => $cgs]],    // approved, both stages late
        ];

        foreach ($gaExtension as [$matric, $mode, $steps]) {
            $this->scenario($matric, 'ga_extension', $supervisor, $steps, fn (Application $a) => GaExtensionDetail::create([
                'application_id' => $a->id,
                'supervisor_id' => $supervisor->id,
                'current_end_date' => $this->today->addMonth()->toDateString(),
                'requested_new_end_date' => $this->today->addMonths(7)->toDateString(),
                'reason_for_extension' => 'Client Charter demo: thesis write-up needs the assistantship to continue for one more semester.',
                'final_approver_snapshot' => $mode,
            ]));
        }

        $supervision = [
            ['22091011', [2 => null]],                                          // supervisor, on time
            ['22091012', [9 => null, 5 => $supervisor]],                        // chair, overdue
            ['22091013', [20 => null, 17 => $supervisor, 12 => $chair]],        // approved, chair late
        ];

        foreach ($supervision as [$matric, $steps]) {
            $this->scenario($matric, 'supervision', $supervisor, $steps, fn (Application $a) => SupervisionDetail::create([
                'application_id' => $a->id,
                'requested_supervisor_id' => $supervisor->id,
                'justification' => 'Client Charter demo: research area matches the supervisor\'s current grant.',
                'appointment_role' => SupervisionDetail::ROLE_CO,
                'cgs_stage_snapshot' => 'automatic',
            ]));
        }

        $certification = [
            ['22091021', [5 => null]],                                          // supervisor, overdue
            ['22091022', [3 => null, 1 => $supervisor]],                        // cgs_verify, on time
            ['22091023', [10 => null, 8 => $supervisor, 4 => $cgs]],            // senior_director, overdue
            ['22091024', [25 => null, 23 => $supervisor, 22 => $cgs, 19 => $director]], // approved, director late
        ];

        foreach ($certification as [$matric, $steps]) {
            $this->scenario($matric, 'ga_certification', $supervisor, $steps, fn (Application $a) => GaCertificationDetail::create([
                'application_id' => $a->id,
                'supervisor_id' => $supervisor->id,
                'appointment_type' => 'GA',
                'period_start' => $this->today->subMonths(6)->toDateString(),
                'period_end' => $this->today->toDateString(),
                'purpose' => 'Client Charter demo.',
                'course_code' => 'TEB1013',
                'course_name' => 'Structured Programming',
                'course_owner_name' => 'Dr. Aisyah Rahman',
                'semester' => 'May 2026',
            ]));
        }

        Carbon::setTestNow();

        $this->command?->info('Seeded Client Charter demo applications for matric numbers 22091001-22091024.');
    }

    /**
     * @param  array<int, User|null>  $steps  working days ago => who decided (null = submission)
     */
    protected function scenario(string $matric, string $module, User $supervisor, array $steps, callable $detail): void
    {
        $student = User::updateOrCreate(['email' => "charter-{$matric}@utp.edu.my"], [
            'name' => 'Charter Demo '.substr($matric, -2),
            'role' => Role::STUDENT,
            'matric_no' => $matric,
            'department' => 'Computing',
            'supervisor_id' => $supervisor->id,
            'password' => Hash::make('password'),
        ]);

        if (Application::where('student_id', $student->id)->where('module_type', $module)->exists()) {
            return;
        }

        $application = null;

        foreach ($steps as $daysAgo => $actor) {
            Carbon::setTestNow($this->workingDaysAgo($daysAgo));

            if ($actor === null) {
                $application = Application::create([
                    'student_id' => $student->id,
                    'submitted_by_id' => $student->id,
                    'module_type' => $module,
                    'status' => Application::STATUS_DRAFT,
                ]);
                $detail($application);
                app(DemoDocuments::class)->attachRequired($application);
                $this->engine->submit($application);

                // GA Extension's Admin paperwork-verification stage, added
                // 2026-11-10, didn't exist when every $gaExtension step
                // below was written against "submit lands at supervisor" --
                // cleared the same day as submission here rather than
                // renumbering every scenario's days-ago keys, since a
                // same-day paperwork check is realistic anyway.
                if ($module === 'ga_extension') {
                    $this->engine->decide($application, $this->adminUser(), 'approve');
                }
            } else {
                $this->engine->decide($application->refresh(), $actor, 'approve');
            }
        }

        Carbon::setTestNow();
    }

    protected function adminUser(): User
    {
        return User::firstOrCreate(
            ['email' => 'admin@utp.edu.my'],
            ['name' => 'Admin Office', 'role' => Role::ADMIN, 'password' => Hash::make('password')]
        );
    }

    /** 10am on the latest working day exactly $days working days before today. */
    protected function workingDaysAgo(int $days): CarbonImmutable
    {
        $date = $this->today->startOfDay();

        while (! $this->calendar->isWorkingDay($date) || $this->calendar->workingDaysBetween($date, $this->today) < $days) {
            $date = $date->subDay();
        }

        // Never in the future: 0 working days ago on a weekday is "earlier today".
        return $date->isSameDay($this->today) ? $this->today->subMinutes(5) : $date->setTime(10, 0);
    }
}
