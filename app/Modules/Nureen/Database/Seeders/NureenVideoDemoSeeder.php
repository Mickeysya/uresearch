<?php

namespace App\Modules\Nureen\Database\Seeders;

use App\Modules\Core\Contracts\SuppliesApprovedGaApplication;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\CgsAssignment;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Nureen\Database\Seeders\Support\DemoDocuments;
use App\Modules\Nureen\Models\AttendanceAppealDetail;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Models\GaExtensionDetail;
use App\Modules\Nureen\Models\SupervisionDetail;
use App\Modules\Nureen\Support\GaExtensionRules;
use App\Modules\Nureen\Support\GaStatus;
use App\Modules\Nureen\Support\SubmissionNotifier;
use App\Modules\Nureen\Support\SupervisorCheck;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One clean record per module for the FYP2 demo video, each submitted and
 * waiting at the FIRST stage of the chain that is live in config now:
 *
 *   GA Extension         admin_verify -> cgs_verify -> supervisor -> senior_director
 *                        (nureen.ga_extension.final_approver = 'ga_application')
 *   Supervision          supervisor -> chair -> cgs_review (endorses, issues the letter)
 *                        (nureen.supervision.cgs_stage = 'endorse')
 *   GA/GRA Certification supervisor -> cgs_verify -> senior_director (issues the PDF)
 *   Attendance Appeal    cgs_review
 *
 * The chains are read from each workflow's stages() at run time, never
 * hard-coded here, so the table printed at the end is always the live one.
 *
 * The student is student@utp.edu.my (Ahmad Danial). Every record's reason,
 * justification or purpose starts "Video demo", which is how a re-run finds
 * it: a "Video demo" record still pending (at any stage) is reused, never
 * duplicated. One that has been approved or rejected is left as history,
 * and the next run makes a fresh one for another take.
 *
 * Built the way each controller's store() builds it: the same detail
 * fields, the same snapshots, valid placeholder PDFs under the labels the
 * real form uses (Support\DemoDocuments), then WorkflowEngine::submit()
 * (rule 1). A newly made record is announced to its first approver through
 * SubmissionNotifier, as a real submission is; a reused one is not.
 *
 * GA Extension is filled so every System check passes (Support\
 * GaExtensionRules), and the seeder re-runs those checks and warns about
 * any that do not.
 *
 * No decisions are seeded, so no CGS officer acts here. All four of these
 * modules' CGS stages belong to the officer in config/cgs_assignment.php
 * (zulkifly.cgs@utp.edu.my); the table names whoever that is.
 *
 *     php artisan db:seed --class="App\Modules\Nureen\Database\Seeders\NureenVideoDemoSeeder"
 */
class NureenVideoDemoSeeder extends Seeder
{
    public const STUDENT_EMAIL = 'student@utp.edu.my';

    public const MARKER = 'Video demo';

    /** Length of the demo GA extension, in months (within rules.extension_months). */
    public const EXTENSION_MONTHS = 6;

    protected WorkflowEngine $engine;

    protected DemoDocuments $documents;

    /** @var array<int, string> lines printed after the table */
    protected array $notes = [];

    public function run(): void
    {
        $this->engine = app(WorkflowEngine::class);
        $this->documents = app(DemoDocuments::class);

        $student = User::where('email', self::STUDENT_EMAIL)->where('role', Role::STUDENT)->first();

        if (! $student) {
            $this->command?->error(self::STUDENT_EMAIL.' does not exist -- run the main seeders (./sync.sh) first.');

            return;
        }

        // GA Extension, Supervision and Certification all go first (or
        // later) to the student's own supervisor: the same check the forms make.
        if ($problem = SupervisorCheck::problemFor($student)) {
            $this->command?->error($problem);

            return;
        }

        $applications = [
            'GA Extension' => $this->gaExtension($student),
            'Supervision' => $this->supervision($student),
            'GA/GRA Certification' => $this->certification($student),
            'Attendance Appeal' => $this->attendanceAppeal($student),
        ];

        $this->printTable($applications);

        foreach ($this->notes as $note) {
            $this->command?->warn($note);
        }
    }

    /* ---------------------------------------------------------------
     | GA Extension
     |---------------------------------------------------------------*/

    protected function gaExtension(User $student): Application
    {
        if ($existing = $this->existing($student, 'ga_extension', GaExtensionDetail::class, 'reason_for_extension')) {
            return $this->checkGaExtension($existing);
        }

        $rules = config('nureen.ga_extension.rules');
        $approved = app()->bound(SuppliesApprovedGaApplication::class)
            ? app(SuppliesApprovedGaApplication::class)->approvedFor($student)
            : null;

        if (! $approved) {
            $this->notes[] = 'GA Extension: '.$student->email.' has no approved GA application, so on camera the student could not open the GA Extension form. The seeded record is still decidable.';
        }

        [$currentEnd, $start] = $this->extensionDates($approved['service_end_date'] ?? null);

        if ($approved && ! $approved['service_end_date']->isSameDay($currentEnd)) {
            $this->notes[] = "GA Extension: the approved GA application #{$approved['application']->id} ends {$approved['service_end_date']->format('j M Y')}, "
                ."so the extension would start {$approved['service_end_date']->copy()->addDay()->format('j M Y')} and fail the "
                .'"starts on the 1st or 16th" check. The demo record uses '.$currentEnd->format('j M Y').' instead (start '.$start->format('j M Y').').';
        }

        $months = max($rules['extension_months']['min'], min(self::EXTENSION_MONTHS, $rules['extension_months']['max']));
        $newEnd = $start->copy()->addMonthsNoOverflow($months)->subDay();

        $level = str_starts_with(strtolower((string) $student->programme), 'phd') ? 'phd' : 'msc';

        // The first stage gate that asks for a proof (Level 2 for MSc and
        // PhD alike): the student is exactly at it when the extension
        // starts, and the proof is attached -- so the check shows a real
        // document rather than "nothing to attach".
        $gate = collect($rules['stage_gates'][$level])->first(fn ($g) => $g['proof'] !== null);
        $admission = $start->copy()->subMonthsNoOverflow($gate['from']);
        $candidacyEnd = $admission->copy()->addYearsNoOverflow($rules['max_candidacy_years'][$level])->subDay();

        $application = DB::transaction(function () use ($student, $currentEnd, $newEnd, $level, $gate, $admission, $candidacyEnd) {
            $application = $this->draft($student, 'ga_extension');

            GaExtensionDetail::create([
                'application_id' => $application->id,
                'supervisor_id' => $student->supervisor_id,
                'current_end_date' => $currentEnd,
                'requested_new_end_date' => $newEnd,
                'reason_for_extension' => self::MARKER.': my MSc data collection with the industry partner runs until mid-2027, '
                    .'and the assistantship lets me keep supporting the undergraduate laboratory classes while I finish it.',
                'additional_info' => config('nureen.ga_extension.additional_fields') === [] ? null : [],
                'final_approver_snapshot' => config('nureen.ga_extension.final_approver'),
                'draft_step' => null,
                'mode_of_study' => 'full_time',
                'study_type' => 'research',
                'admission_date' => $admission,
                'candidature_period' => $admission->format('M Y').' – '.$candidacyEnd->format('M Y'),
                'level_of_study' => $level,
                'contact_no' => $student->contact_no ?: '+60 12-345 6789',
                'contact_email' => $student->email,
                'bank_name' => 'Maybank',
                'bank_account_no' => '1140 2233 4455',
                'identity_number' => '020415-08-1234',
                'holds_visa' => false,
                'visa_expiry_date' => null,
                'publications' => [[
                    'type' => 'journal',
                    'status' => 'accepted',
                    'authors' => 'A. Danial, A. Rahman',
                    'year' => (string) now()->year,
                    'title' => 'Lightweight Anomaly Detection for Campus IoT Networks',
                    'venue' => 'IEEE Access',
                    'database' => 'wos',
                    'quartile' => 'Q2',
                ]],
                'declaration_accepted_at' => now(),
            ]);

            $this->documents->attachRequired($application);
            $this->documents->attach($application, config("nureen.ga_extension.stage_gate_documents.{$gate['proof']}"));

            return $this->engine->submit($application);
        });

        app(SubmissionNotifier::class)->notify($application);

        return $this->checkGaExtension($application);
    }

    /**
     * The current end date and the extension's start: on or after the GA
     * application's own end date, far enough out for the notice period,
     * ending on an allowed end day so the next day is an allowed start day.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function extensionDates(?CarbonInterface $serviceEnd): array
    {
        $rules = config('nureen.ga_extension.rules');

        // A week's margin past the notice period, so "submitted early
        // enough" passes with room to spare.
        $earliest = today()->addMonthsNoOverflow((int) $rules['notice_months'])->addWeek();
        $end = Carbon::parse($serviceEnd && $serviceEnd->gt($earliest) ? $serviceEnd : $earliest)->startOfDay();

        for ($i = 0; $i < 62 && ! ($this->dayAllowed($end, $rules['end_days']) && $this->dayAllowed($end->copy()->addDay(), $rules['start_days'])); $i++) {
            $end->addDay();
        }

        return [$end, $end->copy()->addDay()];
    }

    /** @param  array<int, int|string>  $days  as config('nureen.ga_extension.rules.start_days'/'end_days') */
    protected function dayAllowed(CarbonInterface $date, array $days): bool
    {
        foreach ($days as $day) {
            if ($day === 'last' ? $date->isLastOfMonth() : $date->day === (int) $day) {
                return true;
            }
        }

        return false;
    }

    /** Re-runs the approver's System checks and notes any that do not pass. */
    protected function checkGaExtension(Application $application): Application
    {
        $detail = GaExtensionDetail::where('application_id', $application->id)->first();
        $failing = collect(app(GaExtensionRules::class)->check($detail, $application, $application->documents()->get()))
            ->reject(fn (array $check) => $check['status'] === GaExtensionRules::PASS);

        foreach ($failing as $check) {
            $this->notes[] = "GA Extension #{$application->id}: System check \"{$check['label']}\" is {$check['status']}: {$check['reason']}";
        }

        return $application;
    }

    /* ---------------------------------------------------------------
     | Supervision
     |---------------------------------------------------------------*/

    protected function supervision(User $student): Application
    {
        if ($existing = $this->existing($student, 'supervision', SupervisionDetail::class, 'justification')) {
            return $existing;
        }

        // A Main Supervisor appointment letter for the supervisor already
        // on record ('existing'): not a change of supervisor, so no PGFAC
        // memo is needed and the first stage goes to supervisor@.
        $gaFromRecord = GaStatus::fromRecord($student);

        $application = DB::transaction(function () use ($student, $gaFromRecord) {
            $application = $this->draft($student, 'supervision');

            SupervisionDetail::create([
                'application_id' => $application->id,
                'requested_supervisor_id' => $student->supervisor_id,
                'justification' => self::MARKER.': I need a formal appointment letter for my existing Main Supervisor, '
                    .$student->supervisor->name.', for my candidature file.',
                'appointment_role' => SupervisionDetail::ROLE_MAIN,
                'appointment_type' => 'existing',
                'cgs_stage_snapshot' => config('nureen.supervision.cgs_stage'),
                'co_supervisor_approver_snapshot' => config('nureen.supervision.co_supervisor_approver') === 'dean_pgr' ? 'dean_pgr' : 'chair',
                'is_change_of_supervisor' => false,
                'is_ga' => $gaFromRecord,
                'ga_status_source' => $gaFromRecord !== null ? 'record' : null,
            ]);

            $this->documents->attachRequired($application);

            return $this->engine->submit($application);
        });

        app(SubmissionNotifier::class)->notify($application);

        return $application;
    }

    /* ---------------------------------------------------------------
     | GA/GRA Certification
     |---------------------------------------------------------------*/

    protected function certification(User $student): Application
    {
        if ($existing = $this->existing($student, 'ga_certification', GaCertificationDetail::class, 'purpose')) {
            return $this->checkCertification($existing);
        }

        $application = DB::transaction(function () use ($student) {
            $application = $this->draft($student, 'ga_certification');

            GaCertificationDetail::create([
                'application_id' => $application->id,
                'supervisor_id' => $student->supervisor_id,
                'appointment_type' => 'GA',
                'period_start' => today()->subMonthsNoOverflow(6)->startOfMonth(),
                'period_end' => today(),
                // Printed on the letter ("issued at your request for the
                // following purpose: ..."); within purpose_max (120).
                'purpose' => self::MARKER.': supporting document for a scholarship application.',
                'course_code' => 'TFB2023',
                'course_name' => 'Data Structures and Algorithms',
                'course_owner_name' => $student->supervisor->name,
                'semester' => 'May 2026',
                'study_level' => 'research',
                'level_of_study' => str_starts_with(strtolower((string) $student->programme), 'phd') ? 'phd' : 'master',
                'mode_of_study' => 'full_time',
                'identity_number' => '020415-08-1234',
                'personal_email' => 'ahmad.danial.demo@example.com',
                'declaration_accepted_at' => now(),
                'draft_step' => null,
            ]);

            $this->documents->attachRequired($application);

            return $this->engine->submit($application);
        });

        app(SubmissionNotifier::class)->notify($application);

        return $this->checkCertification($application);
    }

    protected function checkCertification(Application $application): Application
    {
        $check = GaCertificationDetail::where('application_id', $application->id)->first()?->eligibilityCheck();

        if (($check['status'] ?? null) !== 'pass') {
            $this->notes[] = "Certification #{$application->id}: System check is ".($check['status'] ?? 'missing').': '.($check['reason'] ?? '');
        }

        return $application;
    }

    /* ---------------------------------------------------------------
     | Attendance Appeal
     |---------------------------------------------------------------*/

    protected function attendanceAppeal(User $student): Application
    {
        if ($existing = $this->existing($student, 'attendance_appeal', AttendanceAppealDetail::class, 'reason')) {
            return $existing;
        }

        // Not tied to an attendance period: the form allows that, and this
        // student's own attendance rows are left exactly as they are.
        $application = DB::transaction(function () use ($student) {
            $application = $this->draft($student, 'attendance_appeal');

            AttendanceAppealDetail::create([
                'application_id' => $application->id,
                'attendance_record_id' => null,
                'sessions_requested' => null,
                'reason' => self::MARKER.': I was away presenting at a conference and on fieldwork with my supervisor in October; '
                    .'the justification letter and monthly activity breakdown are attached.',
            ]);

            $this->documents->attachRequired($application);

            return $this->engine->submit($application);
        });

        app(SubmissionNotifier::class)->notify($application);

        return $application;
    }

    /* ---------------------------------------------------------------
     | Shared
     |---------------------------------------------------------------*/

    protected function draft(User $student, string $moduleType): Application
    {
        return Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => $moduleType,
            'status' => Application::STATUS_DRAFT,
        ]);
    }

    /**
     * The student's newest still-pending "Video demo" record for this
     * module, or null.
     *
     * @param  class-string<Model>  $detailClass
     */
    protected function existing(User $student, string $moduleType, string $detailClass, string $column): ?Application
    {
        return Application::where('student_id', $student->id)
            ->where('module_type', $moduleType)
            ->where('status', Application::STATUS_PENDING)
            ->whereIn('id', $detailClass::where($column, 'like', self::MARKER.'%')->select('application_id'))
            ->latest('id')
            ->first();
    }

    /** One row per stage of each application's own chain, with who to log in as. */
    protected function printTable(array $applications): void
    {
        $registry = app(ModuleRegistry::class);
        $rows = [];

        foreach ($applications as $label => $application) {
            $application->refresh();
            $stages = $registry->get($application->module_type)->stages($application);
            $current = $this->engine->currentStage($application);

            foreach ($stages as $i => $stage) {
                $rows[] = [
                    $i === 0 ? $label : '',
                    $i === 0 ? '#'.$application->id : '',
                    $i === 0 ? ($current ? "{$current->label} ({$current->key})" : $application->status) : '',
                    ($i + 1).'. '.$stage->label.($current?->key === $stage->key ? '  <- now' : ''),
                    $this->loginFor($application, $stage),
                ];
            }
        }

        $this->command?->table(['Module', 'Application', 'Current stage', 'Chain', 'Log in as'], $rows);
        $this->command?->line('Student: '.self::STUDENT_EMAIL.'. Every password is "password".');
    }

    /** The account that can act at $stage on $application. */
    protected function loginFor(Application $application, Stage $stage): string
    {
        $student = $application->student;

        $user = match ($stage->role) {
            Role::SUPERVISOR => User::find(match ($application->module_type) {
                'supervision' => SupervisionDetail::where('application_id', $application->id)->value('requested_supervisor_id'),
                'ga_extension' => GaExtensionDetail::where('application_id', $application->id)->value('supervisor_id'),
                'ga_certification' => GaCertificationDetail::where('application_id', $application->id)->value('supervisor_id'),
                default => $student?->supervisor_id,
            }),
            Role::NON_EXEC_CGS => app(CgsAssignment::class)->officer($application->module_type)
                ?? $this->firstWithRole(Role::NON_EXEC_CGS),
            default => Role::isDepartmentScoped($stage->role)
                ? $this->firstWithRole($stage->role, $student?->department)
                : $this->firstWithRole($stage->role),
        };

        return $user?->email ?? '(no account with this role)';
    }

    protected function firstWithRole(string $role, ?string $department = null): ?User
    {
        return User::where('role', $role)
            ->when($department, fn ($q) => $q->where('department', $department))
            ->orderBy('id')
            ->first();
    }
}
