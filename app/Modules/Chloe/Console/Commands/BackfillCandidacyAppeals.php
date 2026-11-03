<?php

namespace App\Modules\Chloe\Console\Commands;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyAppealApproval;
use App\Modules\Chloe\Models\CandidacyAppealDetail;
use App\Modules\Chloe\Models\CandidacyAppealEvent;
use App\Modules\Chloe\Models\CandidacyAppealPublication;
use App\Modules\Chloe\Models\CandidacyDepartment;
use App\Modules\Chloe\Models\CandidacyStaffAssignment;
use App\Modules\Chloe\Models\CandidacyStaffProfile;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Support\CandidacyStaffDirectory;
use App\Modules\Chloe\Support\CandidacyVerification;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Imports the appeals that still live in the shared engine's tables (applications / approval_history, plus our
 * candidacy_appeal_details) into candidacy_appeals and its children. Idempotent: an appeal already imported
 * (legacy_application_id) is skipped. The legacy data is only READ -- no shared table is edited.
 *
 * Also fills study_candidacies.department_id / original_expiry_date / extension_months_used from that data.
 * --dry-run runs everything inside a transaction and rolls it back, so the counts are the real ones.
 */
class BackfillCandidacyAppeals extends Command
{
    protected $signature = 'candidacy:backfill-appeals {--dry-run : Do everything, report, then roll back}';

    protected $description = 'Import legacy Study Candidacy appeals into the new appeal tables (idempotent)';

    /** @var array<string, int|array> */
    protected array $report = [];

    public function handle(): int
    {
        $this->report = [
            'departments_created' => 0, 'staff_profiles_created' => 0, 'staff_assignments_created' => 0, 'candidacies_updated' => 0,
            'appeals_imported' => 0, 'appeals_already_imported' => 0, 'events_created' => 0, 'approvals_created' => 0,
            'publications_created' => 0, 'unmapped' => [], 'notes' => [],
        ];

        $before = $this->counts();

        DB::beginTransaction();

        try {
            $this->importAppeals();
            $this->fillCandidacies();
            $after = $this->counts();

            if ($this->option('dry-run')) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Backfill failed and was rolled back: '.get_class($e).' -- '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(($this->option('dry-run') ? '[dry run, rolled back] ' : '').'Backfill finished.');
        $this->table(['Table', 'Before', 'After'], collect($before)->map(fn ($n, $t) => [$t, $n, $after[$t]])->values()->all());
        $this->table(['Result', 'Count'], collect($this->report)->filter(fn ($v) => ! is_array($v))->map(fn ($v, $k) => [$k, $v])->values()->all());

        foreach ($this->report['notes'] as $note) {
            $this->line("Note: {$note}");
        }
        foreach ($this->report['unmapped'] as $row) {
            $this->warn("Could not map: {$row}");
        }

        return self::SUCCESS;
    }

    /** @return array<string, int> */
    protected function counts(): array
    {
        return [
            'candidacy_departments' => CandidacyDepartment::count(), 'candidacy_staff_profiles' => CandidacyStaffProfile::count(),
            'candidacy_staff_assignments' => CandidacyStaffAssignment::count(), 'candidacy_appeals' => CandidacyAppeal::count(),
            'candidacy_appeal_events' => CandidacyAppealEvent::count(), 'candidacy_appeal_approvals' => CandidacyAppealApproval::count(),
            'candidacy_appeal_publications (new rows)' => CandidacyAppealPublication::whereNotNull('candidacy_appeal_id')->count(),
        ];
    }

    protected function importAppeals(): void
    {
        $details = CandidacyAppealDetail::with(['application.history.approver', 'application.student', 'candidacy.student', 'publications', 'supervisor'])
            ->get()
            ->sortBy(fn ($d) => [$d->application?->submitted_at?->timestamp ?? 0, $d->id])
            ->values();

        foreach ($details as $detail) {
            $application = $detail->application;

            if (! $application) {
                $this->report['unmapped'][] = "candidacy_appeal_details #{$detail->id}: its shared application row is missing";

                continue;
            }
            if (CandidacyAppeal::where('legacy_application_id', $application->id)->exists()) {
                $this->report['appeals_already_imported']++;

                continue;
            }

            $student = $application->student;
            $candidacy = $detail->candidacy;

            if (! $student || ! $candidacy || ! $student->matric_no) {
                $this->report['unmapped'][] = "application #{$application->id}: student, candidacy or student ID missing";

                continue;
            }

            $this->importOne($detail, $application, $student, $candidacy);
        }
    }

    protected function importOne(CandidacyAppealDetail $detail, Application $application, User $student, StudyCandidacy $candidacy): void
    {
        $department = $this->department($detail->department ?: $student->department);
        $history = $application->history->sortBy('id')->values();

        $profileFor = fn (?User $user, string $role) => $user ? $this->profile($user, $role, $department) : null;
        $supervisor = $profileFor($detail->supervisor, 'lecturer');
        $chairUser = $history->firstWhere('stage_key', 'chair')?->approver;
        $deanUser = $history->firstWhere('stage_key', 'dean')?->approver;
        $chair = $profileFor($chairUser, 'chair');
        $dean = $profileFor($deanUser, 'dean') ?? CandidacyStaffDirectory::defaultDean();
        $chair ??= $department ? CandidacyStaffDirectory::chair($department) : null;

        $returns = $history->where('decision', 'returned')->count();
        $legacyStatus = $application->status;
        $round = 1 + $returns - ($legacyStatus === Application::STATUS_RETURNED ? 1 : 0);

        $stage = $this->stage($application->current_stage);
        [$status, $outcome] = match ($legacyStatus) {
            Application::STATUS_APPROVED => [CandidacyAppeal::STATUS_APPROVED, CandidacyAppeal::OUTCOME_APPROVED],
            Application::STATUS_REJECTED => [CandidacyAppeal::STATUS_REJECTED, CandidacyAppeal::OUTCOME_REJECTED_FINAL],
            Application::STATUS_RETURNED => [CandidacyAppeal::STATUS_RETURNED, null],
            Application::STATUS_PENDING => [CandidacyAppeal::STAGE_STATUS[$stage ?? CandidacyAppeal::STAGE_SUPERVISOR], null],
            default => [CandidacyAppeal::STATUS_DRAFT, null],
        };

        $isApproved = $status === CandidacyAppeal::STATUS_APPROVED;
        $requested = (int) $detail->requested_extension_months;
        $newExpiry = $detail->new_expiry_date;
        $lastAt = $history->last()?->created_at ?? $application->submitted_at ?? $application->created_at;
        $returnedBy = $status === CandidacyAppeal::STATUS_RETURNED ? $this->stage($history->where('decision', 'returned')->last()?->stage_key) : null;

        $sequence = (int) CandidacyAppeal::where('student_id', $student->id)->max('sequence_no') + 1;
        $appealNo = sprintf('SCA-%s-%02d', $student->matric_no, $sequence);

        $memo = $application->documents()->where('doc_type', 'like', '%Memo%')->latest('id')->first();

        $appeal = CandidacyAppeal::create([
            'appeal_no' => $status === CandidacyAppeal::STATUS_DRAFT ? null : $appealNo,
            'sequence_no' => $status === CandidacyAppeal::STATUS_DRAFT ? null : $sequence,
            'student_id' => $student->id, 'study_candidacy_id' => $candidacy->id, 'department_id' => $department?->id,
            'supervisor_profile_id' => $supervisor?->id, 'chair_profile_id' => $chair?->id, 'dean_profile_id' => $dean?->id,
            'status' => $status, 'current_stage' => in_array($status, [CandidacyAppeal::STATUS_RETURNED, CandidacyAppeal::STATUS_APPROVED, CandidacyAppeal::STATUS_REJECTED], true) ? null : $stage,
            'round' => max(1, $round), 'returned_by_stage' => $returnedBy, 'needs_cgs_recheck' => false,
            'requested_months' => $requested ?: null, 'approved_months' => $isApproved ? $requested : null,
            'expiry_at_submission' => $isApproved && $newExpiry ? $newExpiry->copy()->subMonthsNoOverflow($requested) : $candidacy->candidacy_expiry_date,
            'new_expiry_date' => $newExpiry, 'reason' => $detail->reason,
            'phase' => $detail->phase, 'percent_complete' => $detail->writing_completion_percent,
            'rcs_status' => $detail->rcs_status === 'completed' ? 'done' : $detail->rcs_status,
            'rcs_date' => $detail->rcs_date, 'rcs_expected_date' => $detail->rcs_expected_date, 'rcs_category' => $detail->rcs_category,
            'gsc_extension' => (bool) $detail->extension_via_gsc, 'vc_extension' => (bool) $detail->extension_via_vc,
            'declaration_accepted_at' => $detail->disclaimer_acknowledged_at,
            'submitted_at' => $application->submitted_at, 'stage_entered_at' => $lastAt, 'last_action_at' => $lastAt,
            'closed_at' => in_array($status, [CandidacyAppeal::STATUS_APPROVED, CandidacyAppeal::STATUS_REJECTED], true) ? $lastAt : null,
            'final_outcome' => $outcome,
            'memo_path' => $memo?->path, 'memo_filename' => $memo?->original_name, 'memo_generated_at' => $memo?->created_at,
            'legacy_application_id' => $application->id,
        ]);
        $this->report['appeals_imported']++;

        foreach ($detail->publications as $publication) {
            CandidacyAppealPublication::create(['candidacy_appeal_id' => $appeal->id, 'title' => Str::limit((string) $publication->description, 480, '')]);
            $this->report['publications_created']++;
        }

        // Events and approvals from the legacy history.
        $this->event($appeal, ['actor_kind' => 'student', 'actor_user_id' => $student->id, 'actor_name' => $student->name, 'actor_role' => $student->role,
            'action' => 'submitted', 'round' => 1, 'to_status' => CandidacyAppeal::STATUS_WITH_SUPERVISOR, 'occurred_at' => $application->submitted_at ?? $application->created_at]);

        $round = 1;
        foreach ($history as $row) {
            $stageKey = $this->stage($row->stage_key) ?? CandidacyAppeal::STAGE_SUPERVISOR;
            $approver = $row->approver;
            $profile = $approver ? $this->profile($approver, match ($stageKey) { 'dean' => 'dean', 'chair' => 'chair', 'cgs' => 'cgs', default => 'lecturer' }, $department) : null;
            $decision = match ($row->decision) {
                // The shared engine called a supervisor's / chair's approval "endorsed" and CGS's "verified".
                'approved', 'endorsed', 'verified' => $stageKey === CandidacyAppeal::STAGE_CGS ? 'verified' : 'approved',
                'returned' => 'returned', 'rejected' => 'rejected', default => (string) $row->decision,
            };
            $stages = array_keys(CandidacyAppeal::STAGE_STATUS);
            $next = $stages[array_search($stageKey, $stages, true) + 1] ?? null;
            $toStatus = match ($decision) {
                'returned' => CandidacyAppeal::STATUS_RETURNED, 'rejected' => CandidacyAppeal::STATUS_REJECTED,
                default => $next ? CandidacyAppeal::STAGE_STATUS[$next] : CandidacyAppeal::STATUS_APPROVED,
            };

            $this->event($appeal, [
                'actor_kind' => match ($stageKey) { 'supervisor' => 'lecturer', 'chair' => 'chair', 'cgs' => 'cgs', default => 'dean' },
                'actor_user_id' => $approver?->id, 'actor_name' => $approver?->name ?? 'Unknown approver', 'actor_role' => $approver?->role,
                'action' => $decision === 'rejected' ? 'rejected_final' : $decision, 'stage' => $stageKey, 'round' => $round,
                'remark' => $row->remarks, 'from_status' => CandidacyAppeal::STAGE_STATUS[$stageKey], 'to_status' => $toStatus, 'occurred_at' => $row->created_at,
            ]);

            CandidacyAppealApproval::create([
                'candidacy_appeal_id' => $appeal->id, 'round' => $round, 'stage' => $stageKey, 'profile_id' => $profile?->id, 'user_id' => $approver?->id,
                'full_name' => $profile?->full_name ?? ($approver?->name ?? 'Unknown approver'), 'designation' => $profile?->designation ?? '',
                'decision' => $decision, 'remark' => $row->remarks, 'decided_at' => $row->created_at,
                'verification_code' => $appeal->appeal_no ? CandidacyVerification::code($appeal->appeal_no, $stageKey, $profile?->id, $row->created_at) : '',
            ]);
            $this->report['approvals_created']++;

            if ($decision === 'returned') {
                $round++;
            }
        }
    }

    /** @param array<string, mixed> $attributes */
    protected function event(CandidacyAppeal $appeal, array $attributes): void
    {
        CandidacyAppealEvent::record(['candidacy_appeal_id' => $appeal->id] + $attributes);
        $this->report['events_created']++;
    }

    protected function stage(?string $legacyKey): ?string
    {
        return match ($legacyKey) {
            'supervisor' => CandidacyAppeal::STAGE_SUPERVISOR, 'chair' => CandidacyAppeal::STAGE_CHAIR,
            'cgs_verify', 'cgs' => CandidacyAppeal::STAGE_CGS, 'dean' => CandidacyAppeal::STAGE_DEAN, default => null,
        };
    }

    protected function department(?string $name): ?CandidacyDepartment
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        $department = CandidacyDepartment::where('name', $name)->first();

        if (! $department) {
            $base = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', collect(preg_split('/[^A-Za-z0-9]+/', $name))->filter()->map(fn ($w) => $w[0])->implode(''))) ?: 'DEPT';
            $code = $base;
            for ($i = 2; CandidacyDepartment::where('code', $code)->exists(); $i++) {
                $code = $base.$i;
            }
            $department = CandidacyDepartment::create(['code' => $code, 'name' => $name]);
            $this->report['departments_created']++;
        }

        return $department;
    }

    /** The staff profile of a login, created on first use; a lecturer or chair is also assigned to the department. */
    protected function profile(User $user, string $role, ?CandidacyDepartment $department): CandidacyStaffProfile
    {
        $profile = CandidacyStaffProfile::where('user_id', $user->id)->first();

        if (! $profile) {
            $designation = match ($role) {
                'chair' => 'Chair'.($department ? ", {$department->name}" : ''),
                'dean' => 'Dean, Postgraduate and Research',
                'cgs' => 'Centre for Graduate Studies (CGS)',
                default => 'Lecturer'.($department ? ", {$department->name}" : ''),
            };
            $profile = CandidacyStaffProfile::create(['user_id' => $user->id, 'full_name' => $user->name, 'designation' => $designation, 'department_id' => in_array($role, ['dean', 'cgs'], true) ? null : $department?->id]);
            $this->report['staff_profiles_created']++;
        }

        // Only lecturers and chairs belong to a department; the dean and CGS are not assigned to one.
        if ($department && in_array($role, ['lecturer', 'chair'], true)) {
            $exists = CandidacyStaffAssignment::where(['department_id' => $department->id, 'staff_profile_id' => $profile->id, 'role' => $role])->exists();
            $taken = $role === 'chair' && CandidacyStaffAssignment::where(['department_id' => $department->id, 'role' => 'chair'])->exists();

            if (! $exists && ! $taken) {
                CandidacyStaffAssignment::create(['department_id' => $department->id, 'staff_profile_id' => $profile->id, 'role' => $role]);
                $this->report['staff_assignments_created']++;
            } elseif (! $exists && $taken) {
                $this->report['notes'][] = "Department '{$department->name}' already has a chair; '{$user->name}' was not assigned as a second chair.";
            }
        }

        return $profile;
    }

    protected function fillCandidacies(): void
    {
        foreach (StudyCandidacy::with('student')->get() as $candidacy) {
            $approved = (int) CandidacyAppeal::where('study_candidacy_id', $candidacy->id)->where('status', CandidacyAppeal::STATUS_APPROVED)->sum('approved_months');
            $changes = [];

            if ($candidacy->department_id === null && ($department = $this->department($candidacy->student?->department))) {
                $changes['department_id'] = $department->id;
            }
            if ($candidacy->original_expiry_date === null) {
                $changes['original_expiry_date'] = $candidacy->candidacy_expiry_date->copy()->subMonthsNoOverflow($approved)->toDateString();
            }
            if ($candidacy->extension_months_used !== $approved && $candidacy->extension_months_used < $approved) {
                $changes['extension_months_used'] = $approved;
            }
            if ((int) $candidacy->cumulative_extension_months !== $approved) {
                $this->report['notes'][] = "Candidacy #{$candidacy->id}: the legacy counter says {$candidacy->cumulative_extension_months} month(s), approved appeals sum to {$approved}.";
            }

            if ($changes) {
                $candidacy->forceFill($changes)->save();
                $this->report['candidacies_updated']++;
            }
        }
    }
}
