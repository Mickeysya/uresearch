<?php

namespace App\Modules\Chloe\Services;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyAppealApproval;
use App\Modules\Chloe\Models\CandidacyAppealEvent;
use App\Modules\Chloe\Models\CandidacyAppealDocument;
use App\Modules\Chloe\Models\CandidacyStaffAssignment;
use App\Modules\Chloe\Models\CandidacyStaffProfile;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Notifications\CandidacyAppealNotice;
use App\Modules\Chloe\Policies\CandidacyAppealPolicy;
use App\Modules\Chloe\Support\CandidacyStaffDirectory;
use App\Modules\Chloe\Support\CandidacyVerification;
use App\Modules\Chloe\Support\StudentDirectory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * The state machine of the Study Candidacy Appeal -- the ONLY code that writes candidacy_appeals.status / current_stage
 * (the appeal's twin of the shared WorkflowEngine rule). Every method runs in one transaction on a locked row, writes the
 * history events, and sends the notifications after the commit.
 *
 *   draft -> with_supervisor -> with_chair -> with_cgs -> with_dean -> approved | rejected (final)
 *   any stage but the last can RETURN the appeal to the student (status returned); a Dean return goes back through CGS.
 */
class CandidacyAppealService
{
    /** The stage that follows each stage on approval. */
    protected const NEXT = ['supervisor' => 'chair', 'chair' => 'cgs', 'cgs' => 'dean'];

    /** @var array<int, callable> */
    protected array $afterCommit = [];

    public function __construct(
        protected CandidacySignatureService $signatures,
        protected CandidacyMemoService $memos,
    ) {}

    // ------------------------------------------------------------------ eligibility

    /** @return list<string> the reasons the student cannot appeal now; empty = may appeal */
    public function eligibilityProblems(?StudyCandidacy $candidacy, ?int $months = null): array
    {
        if (! $candidacy) {
            return ['No study candidacy record was found for you.'];
        }

        $problems = [];
        $today = $this->today();

        if ($candidacy->status !== StudyCandidacy::STATUS_ACTIVE) {
            $problems[] = 'Your study candidacy is not active.';
        }

        if ($candidacy->candidacy_expiry_date->toDateString() < $today->toDateString()) {
            $problems[] = 'Your study candidacy has already expired.';
        }

        if ($candidacy->candidacyAppeals()->whereIn('status', CandidacyAppeal::OPEN_STATUSES)->exists() || $candidacy->hasOpenAppeal()) {
            $problems[] = 'You already have an appeal in progress.';
        }

        if ($candidacy->hasFinalRejection()) {
            $problems[] = 'An earlier appeal was rejected by the Dean. That decision is final.';
        }

        $remaining = $candidacy->remainingAppealMonths();

        if ($remaining < 1) {
            $problems[] = 'You have used the maximum of '.config('candidacy.max_extension_months').' months of extension.';
        } elseif ($months !== null && ($months < 1 || $months > $remaining)) {
            $problems[] = "You can request between 1 and {$remaining} month(s).";
        }

        return $problems;
    }

    // ------------------------------------------------------------------ student side

    /**
     * Create or update the student's one draft. No appeal number yet. $data may hold: reason, phase, percent_complete,
     * rcs_status, rcs_date, rcs_expected_date, rcs_category, gsc_extension, vc_extension, requested_months,
     * supervisor_profile_id, declaration (bool) and publications (list of title/venue/status/year).
     *
     * @param  array<string, mixed>  $data
     */
    public function saveDraft(User $student, array $data): CandidacyAppeal
    {
        return DB::transaction(function () use ($student, $data) {
            $candidacy = StudyCandidacy::where('student_id', $student->id)->lockForUpdate()->first();

            if (! $candidacy) {
                throw ValidationException::withMessages(['candidacy' => 'No study candidacy record was found for you.']);
            }

            $appeal = CandidacyAppeal::where('student_id', $student->id)->where('status', CandidacyAppeal::STATUS_DRAFT)->lockForUpdate()->first()
                ?? new CandidacyAppeal(['student_id' => $student->id, 'study_candidacy_id' => $candidacy->id, 'status' => CandidacyAppeal::STATUS_DRAFT, 'round' => 1]);

            $this->fillSections($appeal, $data);
            $appeal->department_id = $candidacy->department_id;
            $appeal->save();
            $this->syncPublications($appeal, $data);

            return $appeal;
        });
    }

    /** Submit the draft: assign the number, fix the people, start at the supervisor. */
    public function submit(User $student, CandidacyAppeal $draft): CandidacyAppeal
    {
        return $this->run(function () use ($student, $draft) {
            $appeal = CandidacyAppeal::whereKey($draft->id)->lockForUpdate()->firstOrFail();
            $this->authorize($student, 'edit', $appeal);

            if (! $appeal->isDraft()) {
                throw ValidationException::withMessages(['appeal' => 'This appeal has already been submitted.']);
            }

            $candidacy = StudyCandidacy::whereKey($appeal->study_candidacy_id)->lockForUpdate()->firstOrFail();
            $this->assertComplete($appeal, $candidacy);

            $department = $candidacy->department;
            $supervisor = $department
                ? CandidacyStaffDirectory::lecturers($department)->firstWhere('id', $appeal->supervisor_profile_id)
                : null;
            $chair = $department ? CandidacyStaffDirectory::chair($department) : null;
            $dean = CandidacyStaffDirectory::dean($department);

            if (! $supervisor || ! $chair || ! $dean) {
                throw ValidationException::withMessages(['supervisor_profile_id' => 'Pick your supervisor from your department. If the list is empty or has no Chair or Dean, contact the Centre for Graduate Studies.']);
            }

            $sequence = ((int) CandidacyAppeal::where('student_id', $appeal->student_id)->max('sequence_no')) + 1;
            $identity = StudentDirectory::forUserId($student->id);
            $now = now();

            $from = $appeal->status;
            $appeal->forceFill([
                'sequence_no' => $sequence,
                'appeal_no' => sprintf('SCA-%s-%02d', $identity?->studentId ?? $student->matric_no, $sequence),
                'chair_profile_id' => $chair->id,
                'dean_profile_id' => $dean->id,
                'expiry_at_submission' => $candidacy->candidacy_expiry_date,
                'round' => 1,
                'submitted_at' => $now,
            ]);
            $this->moveTo($appeal, CandidacyAppeal::STAGE_SUPERVISOR, $now);
            $appeal->save();

            $this->event($appeal, $student, 'submitted', null, $from);
            $this->afterCommit[] = fn () => $this->notifyStage($appeal, 'turn');

            return $appeal;
        });
    }

    /** Resubmit a returned appeal to the stage that returned it (a Dean return goes through CGS first). */
    public function resubmit(User $student, CandidacyAppeal $returned, array $data = []): CandidacyAppeal
    {
        return $this->run(function () use ($student, $returned, $data) {
            $appeal = CandidacyAppeal::whereKey($returned->id)->lockForUpdate()->firstOrFail();
            $this->authorize($student, 'edit', $appeal);

            if ($appeal->status !== CandidacyAppeal::STATUS_RETURNED) {
                throw ValidationException::withMessages(['appeal' => 'Only a returned appeal can be resubmitted.']);
            }

            $candidacy = StudyCandidacy::whereKey($appeal->study_candidacy_id)->first();
            $expiry = $candidacy?->candidacy_expiry_date ?? $appeal->expiry_at_submission;

            if ($expiry && $expiry->toDateString() < $this->today()->toDateString()) {
                throw ValidationException::withMessages(['appeal' => 'Your candidacy has expired, so this appeal can no longer be resubmitted.']);
            }

            $this->fillSections($appeal, $data);
            $this->assertComplete($appeal, $candidacy, resubmitting: true);

            $by = $appeal->returned_by_stage;
            $target = $by === CandidacyAppeal::STAGE_DEAN ? CandidacyAppeal::STAGE_CGS : $by;
            $from = $appeal->status;

            $appeal->round = $appeal->round + 1;
            $appeal->needs_cgs_recheck = $by === CandidacyAppeal::STAGE_DEAN;
            $appeal->returned_by_stage = null;
            $this->moveTo($appeal, $target, now());
            $appeal->save();
            $this->syncPublications($appeal, $data);

            $this->event($appeal, $student, 'resubmitted', $target, $from);
            $this->afterCommit[] = fn () => $this->notifyStage($appeal, 'turn');

            return $appeal;
        });
    }

    // ------------------------------------------------------------------ approver side

    /**
     * @param  'approve'|'verify'|'return'|'reject'  $action
     * @param  list<string>  $reasons  reason keys from config('candidacy.reasons.<stage>.<action>')
     */
    public function decide(User $actor, CandidacyAppeal $locked, string $action, array $reasons = [], ?string $remark = null, ?bool $documentsComplete = null): CandidacyAppeal
    {
        return $this->run(function () use ($actor, $locked, $action, $reasons, $remark, $documentsComplete) {
            $appeal = CandidacyAppeal::whereKey($locked->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, 'act', $appeal);

            $stage = $appeal->current_stage;
            $remark = $remark !== null ? trim($remark) : null;
            $remark = $remark === '' ? null : $remark;

            $allowed = match ($stage) {
                'cgs' => ['verify', 'return'],
                'dean' => ['approve', 'return', 'reject'],
                default => ['approve', 'return'],
            };

            if (! in_array($action, $allowed, true)) {
                throw ValidationException::withMessages(['action' => 'That action is not available at this step.']);
            }

            if (in_array($action, ['return', 'reject'], true)) {
                $this->assertReasons($stage, $action, $reasons, $remark);
            }

            if ($stage === 'cgs' && $action === 'verify' && $documentsComplete !== true) {
                throw ValidationException::withMessages(['documents_complete' => 'Confirm that the documents are complete to verify, or return the appeal to the student.']);
            }

            $profile = $this->profileFor($actor, $appeal, $stage);

            if (in_array($action, ['approve'], true) && in_array($stage, config('candidacy.signature_required_stages'), true) && ! $profile?->hasSignature()) {
                throw ValidationException::withMessages(['signature' => 'Upload your signature under My Signature before approving.']);
            }

            $now = now();
            $decision = ['approve' => 'approved', 'verify' => 'verified', 'return' => 'returned', 'reject' => 'rejected'][$action];

            $approval = $this->recordApproval($appeal, $stage, $actor, $profile, $decision, $now, $remark, $stage === 'cgs' ? $documentsComplete : null, signed: $decision !== 'returned' && $decision !== 'rejected');
            $from = $appeal->status;

            $this->event($appeal, $actor, $decision, $stage, $from, null, $reasons, $remark);

            match (true) {
                $decision === 'returned' => $this->applyReturn($appeal, $stage, $now),
                $decision === 'rejected' => $this->applyRejection($appeal, $now),
                $stage === 'dean' => $this->applyFinalApproval($appeal, $approval, $now),
                default => $this->advance($appeal, $stage, $now),
            };

            $appeal->last_action_at = $now;
            $appeal->save();

            $this->queueDecisionNotices($appeal, $stage, $decision, $reasons, $remark);

            return $appeal;
        });
    }

    /** Daily job: a returned appeal whose candidacy has expired is closed (it can no longer be resubmitted). */
    public function closeExpired(bool $dryRun = false): Collection
    {
        $closed = collect();
        $today = $this->today()->toDateString();

        CandidacyAppeal::with('candidacy')->where('status', CandidacyAppeal::STATUS_RETURNED)->orderBy('id')->each(function (CandidacyAppeal $appeal) use ($dryRun, $today, $closed) {
            $expiry = $appeal->candidacy?->candidacy_expiry_date ?? $appeal->expiry_at_submission;

            if (! $expiry || $expiry->toDateString() >= $today) {
                return;
            }

            if ($dryRun) {
                $closed->push($appeal);

                return;
            }

            $this->run(function () use ($appeal, $closed) {
                $locked = CandidacyAppeal::whereKey($appeal->id)->lockForUpdate()->first();

                if (! $locked || $locked->status !== CandidacyAppeal::STATUS_RETURNED) {
                    return;
                }

                $from = $locked->status;
                $now = now();
                $locked->forceFill([
                    'status' => CandidacyAppeal::STATUS_CLOSED_EXPIRED, 'current_stage' => null, 'final_outcome' => CandidacyAppeal::OUTCOME_CLOSED_EXPIRED,
                    'closed_at' => $now, 'last_action_at' => $now,
                ])->save();

                $this->event($locked, null, 'closed_expired', $locked->returned_by_stage, $from);
                $this->afterCommit[] = fn () => $this->notifyStudent($locked, 'closed_expired');
                $closed->push($locked);
            });
        });

        return $closed;
    }

    // ------------------------------------------------------------------ internals

    protected function today(): Carbon
    {
        return Carbon::now('Asia/Kuala_Lumpur')->startOfDay();
    }

    protected function authorize(User $user, string $ability, CandidacyAppeal $appeal): void
    {
        if (! app(CandidacyAppealPolicy::class)->{$ability}($user, $appeal)) {
            throw new AuthorizationException('You are not allowed to do that on this appeal.');
        }
    }

    /** One transaction; notifications go out only after it committed. */
    protected function run(callable $work): mixed
    {
        $this->afterCommit = [];
        $result = DB::transaction($work);
        $callbacks = $this->afterCommit;
        $this->afterCommit = [];

        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                report($e); // a failed e-mail never undoes a committed decision
            }
        }

        return $result;
    }

    protected function moveTo(CandidacyAppeal $appeal, string $stage, \DateTimeInterface $at): bool
    {
        $appeal->forceFill(['current_stage' => $stage, 'status' => CandidacyAppeal::STAGE_STATUS[$stage], 'stage_entered_at' => $at, 'last_action_at' => $at]);

        return true;
    }

    protected function advance(CandidacyAppeal $appeal, string $stage, \DateTimeInterface $at): void
    {
        $this->moveTo($appeal, self::NEXT[$stage], $at);

        if ($stage === CandidacyAppeal::STAGE_CGS) {
            $appeal->needs_cgs_recheck = false; // the re-check after a Dean return is done; the Dean sees it next
        }
    }

    protected function applyReturn(CandidacyAppeal $appeal, string $stage, \DateTimeInterface $at): void
    {
        $appeal->forceFill([
            'status' => CandidacyAppeal::STATUS_RETURNED, 'current_stage' => null, 'returned_by_stage' => $stage, 'stage_entered_at' => $at,
            'needs_cgs_recheck' => $stage === CandidacyAppeal::STAGE_DEAN,
        ]);
    }

    protected function applyRejection(CandidacyAppeal $appeal, \DateTimeInterface $at): void
    {
        $appeal->forceFill([
            'status' => CandidacyAppeal::STATUS_REJECTED, 'current_stage' => null, 'final_outcome' => CandidacyAppeal::OUTCOME_REJECTED_FINAL, 'closed_at' => $at,
        ]);

        // The dismissal logic treats a rejected candidate with the reason "appeal rejected".
        if ($appeal->study_candidacy_id) {
            StudyCandidacy::whereKey($appeal->study_candidacy_id)->update(['last_rejection_at' => $at]);
        }
    }

    protected function applyFinalApproval(CandidacyAppeal $appeal, CandidacyAppealApproval $dean, \DateTimeInterface $at): void
    {
        $candidacy = StudyCandidacy::whereKey($appeal->study_candidacy_id)->lockForUpdate()->firstOrFail();
        $months = (int) $appeal->requested_months;

        if ($months < 1 || $months > $candidacy->remainingAppealMonths()) {
            throw ValidationException::withMessages(['appeal' => 'The requested months no longer fit the candidate\'s remaining allowance.']);
        }

        $newExpiry = $candidacy->candidacy_expiry_date->copy()->addMonthsNoOverflow($months);

        $candidacy->forceFill([
            'original_expiry_date' => $candidacy->original_expiry_date ?? $candidacy->candidacy_expiry_date,
            'candidacy_expiry_date' => $newExpiry,
            'extension_months_used' => $candidacy->allowanceUsed() + $months,
        ])->save();

        $appeal->forceFill([
            'status' => CandidacyAppeal::STATUS_APPROVED, 'current_stage' => null, 'approved_months' => $months, 'new_expiry_date' => $newExpiry,
            'final_outcome' => CandidacyAppeal::OUTCOME_APPROVED, 'closed_at' => $at, 'needs_cgs_recheck' => false,
        ]);
        $appeal->save();

        if ($memo = $this->memos->generate($appeal->fresh())) {
            [$path, $name] = $memo;
            $appeal->forceFill(['memo_path' => $path, 'memo_filename' => $name, 'memo_generated_at' => $at]);
        }
    }

    protected function fillSections(CandidacyAppeal $appeal, array $data): void
    {
        $fields = ['reason', 'phase', 'percent_complete', 'rcs_status', 'rcs_date', 'rcs_expected_date', 'rcs_category', 'requested_months', 'supervisor_profile_id'];

        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $appeal->{$field} = $data[$field] === '' ? null : $data[$field];
            }
        }

        foreach (['gsc_extension', 'vc_extension'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $appeal->{$flag} = (bool) $data[$flag];
            }
        }

        if (array_key_exists('declaration', $data)) {
            $appeal->declaration_accepted_at = $data['declaration'] ? ($appeal->declaration_accepted_at ?? now()) : null;
        }
    }

    protected function syncPublications(CandidacyAppeal $appeal, array $data): void
    {
        if (! array_key_exists('publications', $data)) {
            return;
        }

        $appeal->publications()->delete();

        foreach ((array) $data['publications'] as $row) {
            if (trim((string) ($row['title'] ?? '')) === '') {
                continue;
            }

            $appeal->publications()->create([
                'title' => $row['title'], 'venue' => $row['venue'] ?? null, 'status' => $row['status'] ?? null, 'year' => $row['year'] ?? null,
            ]);
        }
    }

    protected function assertComplete(CandidacyAppeal $appeal, ?StudyCandidacy $candidacy, bool $resubmitting = false): void
    {
        $errors = [];

        if (! $resubmitting && ($problems = $this->eligibilityProblems($candidacy, (int) $appeal->requested_months))) {
            $errors['eligibility'] = implode(' ', $problems);
        } elseif ($resubmitting && $candidacy && ((int) $appeal->requested_months < 1 || (int) $appeal->requested_months > $candidacy->remainingAppealMonths())) {
            $errors['requested_months'] = "You can request between 1 and {$candidacy->remainingAppealMonths()} month(s).";
        }

        if (trim((string) $appeal->reason) === '') {
            $errors['reason'] = 'Explain why you need the extension.';
        }

        if (! $appeal->phase || ! $appeal->rcs_status) {
            $errors['phase'] = 'Fill in the research progress (phase and RCS status).';
        }

        if (! $appeal->declaration_accepted_at) {
            $errors['declaration'] = 'Accept the declaration to submit.';
        }

        if (! $appeal->documents()->where('kind', CandidacyAppealDocument::KIND_OFFICIAL_FORM)->exists()) {
            $errors['official_form'] = 'Upload the official appeal form (.xlsx).';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @param list<string> $reasons */
    protected function assertReasons(string $stage, string $action, array $reasons, ?string $remark): void
    {
        $allowed = array_keys((array) config("candidacy.reasons.{$stage}.".($action === 'reject' ? 'reject' : 'return'), []));
        $reasons = array_values(array_unique($reasons));

        if ($reasons === [] || array_diff($reasons, $allowed)) {
            throw ValidationException::withMessages(['reasons' => 'Pick at least one reason from the list.']);
        }

        if (in_array('other', $reasons, true) && $remark === null) {
            throw ValidationException::withMessages(['remark' => 'A remark is required when the reason is "Other".']);
        }
    }

    protected function profileFor(User $actor, CandidacyAppeal $appeal, string $stage): ?CandidacyStaffProfile
    {
        return match ($stage) {
            'supervisor' => $appeal->supervisorProfile,
            'chair' => $appeal->chairProfile,
            'dean' => $appeal->deanProfile,
            default => CandidacyStaffDirectory::profileForUser($actor), // CGS: the acting user's own profile, if they have one
        };
    }

    protected function recordApproval(CandidacyAppeal $appeal, string $stage, User $actor, ?CandidacyStaffProfile $profile, string $decision, \DateTimeInterface $at, ?string $remark, ?bool $complete, bool $signed): CandidacyAppealApproval
    {
        [$path, $hash] = $signed && $profile ? $this->signatures->snapshot($profile, $appeal->id.'-r'.$appeal->round.'-'.$stage) : [null, null];
        $decidedAt = Carbon::instance($at)->startOfSecond();

        return CandidacyAppealApproval::create([
            'candidacy_appeal_id' => $appeal->id, 'round' => $appeal->round, 'stage' => $stage,
            'profile_id' => $profile?->id, 'user_id' => $actor->id,
            'full_name' => $profile?->full_name ?? $actor->name,
            'designation' => $profile?->designation ?? ($stage === 'cgs' ? 'Centre for Graduate Studies (CGS)' : ucfirst($stage)),
            'signature_snapshot_path' => $path, 'signature_snapshot_sha256' => $hash,
            'decision' => $decision, 'documents_complete' => $complete, 'remark' => $remark, 'decided_at' => $decidedAt,
            'verification_code' => CandidacyVerification::code((string) $appeal->appeal_no, $stage, $profile?->id, $decidedAt),
        ]);
    }

    /** @param list<string>|null $reasons */
    protected function event(CandidacyAppeal $appeal, ?User $actor, string $action, ?string $stage, ?string $from, ?string $to = null, ?array $reasons = null, ?string $remark = null): void
    {
        CandidacyAppealEvent::record([
            'candidacy_appeal_id' => $appeal->id,
            'actor_kind' => $actor ? $this->kindOf($actor, $appeal) : 'system',
            'actor_user_id' => $actor?->id,
            'actor_name' => $actor?->name ?? 'System',
            'actor_role' => $actor?->role,
            'action' => $action, 'stage' => $stage, 'round' => $appeal->round,
            'reason_codes' => $reasons ?: null, 'remark' => $remark,
            'from_status' => $from, 'to_status' => $appeal->status,
        ]);
    }

    protected function kindOf(User $actor, CandidacyAppeal $appeal): string
    {
        if ($appeal->student_id === $actor->id) {
            return 'student';
        }

        return match (true) {
            $actor->role === Role::DEAN_PGR => 'dean',
            $actor->role === Role::CHAIR => 'chair',
            $actor->role === Role::SUPERVISOR => 'lecturer',
            default => 'cgs',
        };
    }

    // ------------------------------------------------------------------ notifications

    /** @param list<string> $reasons */
    protected function queueDecisionNotices(CandidacyAppeal $appeal, string $stage, string $decision, array $reasons, ?string $remark): void
    {
        $vars = ['reasons' => $this->reasonLabels($stage, $decision, $reasons), 'remarks' => $remark ?? '—', 'decision' => $decision === 'verified' ? 'verified' : $decision, 'stage' => $this->stageLabel($stage)];

        $this->afterCommit[] = function () use ($appeal, $decision, $vars) {
            $appeal = $appeal->fresh();

            match ($decision) {
                'approved', 'verified' => $appeal->status === CandidacyAppeal::STATUS_APPROVED
                    ? $this->notifyFinal($appeal)
                    : [$this->notifyStudent($appeal, 'approved_student', $vars), $this->notifyStage($appeal, 'turn')],
                'returned' => [
                    $this->notifyStudent($appeal, 'returned_student', $vars, approved: false),
                    $this->notifyPeople($appeal, 'returned_info', $vars, approved: false),
                ],
                'rejected' => [
                    $this->notifyStudent($appeal, 'rejected_student', $vars, approved: false),
                    $this->notifyPeople($appeal, 'rejected', $vars, approved: false),
                ],
            };
        };
    }

    protected function notifyFinal(CandidacyAppeal $appeal): void
    {
        $this->notifyStudent($appeal, 'final_student', [], memo: true);
        $this->notifyPeople($appeal, 'final_info');
    }

    protected function notifyStage(CandidacyAppeal $appeal, string $template): void
    {
        $appeal = $appeal->fresh();

        if (! $appeal->current_stage) {
            return;
        }

        $this->send($this->stageRecipients($appeal), $template, $this->vars($appeal, ['stage' => $this->stageLabel($appeal->current_stage)], staff: true), nextStage: $this->stageLabel($appeal->current_stage));
    }

    /** @param array<string,mixed> $extra */
    protected function notifyStudent(CandidacyAppeal $appeal, string $template, array $extra = [], bool $approved = true, bool $memo = false): void
    {
        $identity = $appeal->identity();

        if (! $identity) {
            return;
        }

        $notice = new CandidacyAppealNotice($template, $this->vars($appeal, $extra), $approved, null, $memo ? $appeal->memo_path : null, $memo ? $appeal->memo_filename : null);
        $identity->notify($notice);
    }

    /** Everyone named on the appeal and the CGS recipients, except nobody twice. */
    protected function notifyPeople(CandidacyAppeal $appeal, string $template, array $extra = [], bool $approved = true): void
    {
        $people = collect([$appeal->supervisorProfile?->user, $appeal->chairProfile?->user, $appeal->deanProfile?->user])->filter()
            ->merge($this->cgsUsers())->unique('id')->values();

        $this->send($people, $template, $this->vars($appeal, $extra, staff: true), approved: $approved);
    }

    /** @return Collection<int, User> */
    protected function stageRecipients(CandidacyAppeal $appeal): Collection
    {
        return match ($appeal->current_stage) {
            'supervisor' => collect([$appeal->supervisorProfile?->user])->filter(),
            'chair' => collect([$appeal->chairProfile?->user])->filter(),
            'dean' => collect([$appeal->deanProfile?->user])->filter(),
            'cgs' => $this->cgsUsers(),
            default => collect(),
        };
    }

    /** @return Collection<int, User> */
    protected function cgsUsers(): Collection
    {
        return User::whereIn('role', (array) config('candidacy.cgs_roles'))->get();
    }

    /** @param Collection<int, User> $users */
    protected function send(Collection $users, string $template, array $vars, bool $approved = true, ?string $nextStage = null): void
    {
        if ($users->isNotEmpty()) {
            Notification::send($users, new CandidacyAppealNotice($template, $vars, $approved, $nextStage));
        }
    }

    /** @return array<string, string> */
    protected function vars(CandidacyAppeal $appeal, array $extra = [], bool $staff = false): array
    {
        $identity = $appeal->identity();

        return $extra + [
            'student_name' => $identity?->name ?? 'Student',
            'appeal_no' => $appeal->displayNo(),
            'stage' => $appeal->current_stage ? $this->stageLabel($appeal->current_stage) : '',
            'decision' => '',
            'reasons' => '—',
            'remarks' => '—',
            'requested_months' => (string) $appeal->requested_months,
            'approved_months' => (string) $appeal->approved_months,
            'new_expiry_date' => $appeal->new_expiry_date?->format('j M Y') ?? '',
            'expiry_date' => ($appeal->candidacy?->candidacy_expiry_date ?? $appeal->expiry_at_submission)?->format('j M Y') ?? '',
            'link' => $this->linkFor($appeal, $staff),
        ];
    }

    protected function linkFor(CandidacyAppeal $appeal, bool $staff): string
    {
        $name = $staff ? 'candidacy.portal.review' : 'candidacy.appeals.show';

        return \Illuminate\Support\Facades\Route::has($name) ? route($name, $appeal) : route('candidacy.status');
    }

    public function stageLabel(string $stage): string
    {
        return ['supervisor' => 'Lecturer/Supervisor', 'chair' => 'Chair of Department', 'cgs' => 'CGS verification', 'dean' => 'Dean of PGR'][$stage] ?? ucfirst($stage);
    }

    /** @param list<string> $reasons */
    protected function reasonLabels(string $stage, string $decision, array $reasons): string
    {
        $list = (array) config("candidacy.reasons.{$stage}.".($decision === 'rejected' ? 'reject' : 'return'), []);

        return $reasons === [] ? '—' : implode('; ', array_map(fn ($key) => $list[$key] ?? $key, $reasons));
    }
}
