<?php

namespace App\Modules\Chloe\Policies;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyStaffProfile;
use App\Modules\Core\Models\User;

/**
 * The one place that decides who may see or touch an appeal. Every route (pages, downloads, signature images, actions)
 * asks this class, so no route can forget a check. Rules: a student sees only their own appeals; staff see an appeal only
 * when they are one of its named people; the CGS stage is open to the roles in config('candidacy.cgs_stage_roles').
 */
class CandidacyAppealPolicy
{
    public function view(User $user, CandidacyAppeal $appeal): bool
    {
        if ($appeal->student_id !== null && $appeal->student_id === $user->id) {
            return true;
        }

        return ! $appeal->isDraft() && ($this->isNamedPerson($user, $appeal) || $this->isCgs($user));
    }

    /** Edit (draft) or resubmit (returned): the owner only. */
    public function edit(User $user, CandidacyAppeal $appeal): bool
    {
        return $appeal->student_id !== null
            && $appeal->student_id === $user->id
            && in_array($appeal->status, [CandidacyAppeal::STATUS_DRAFT, CandidacyAppeal::STATUS_RETURNED], true);
    }

    /** Decide: only the named person of the stage the appeal waits at (CGS stage: the CGS roles). */
    public function act(User $user, CandidacyAppeal $appeal): bool
    {
        return match ($appeal->current_stage) {
            CandidacyAppeal::STAGE_SUPERVISOR => $this->owns($user, $appeal->supervisorProfile),
            CandidacyAppeal::STAGE_CHAIR => $this->owns($user, $appeal->chairProfile),
            CandidacyAppeal::STAGE_DEAN => $this->owns($user, $appeal->deanProfile),
            CandidacyAppeal::STAGE_CGS => $this->isCgs($user),
            default => false,
        } && array_key_exists($appeal->current_stage, CandidacyAppeal::STAGE_STATUS)
            && CandidacyAppeal::STAGE_STATUS[$appeal->current_stage] === $appeal->status;
    }

    public function downloadMemo(User $user, CandidacyAppeal $appeal): bool
    {
        return $appeal->status === CandidacyAppeal::STATUS_APPROVED && $appeal->memo_path !== null && $this->view($user, $appeal);
    }

    public function downloadDocument(User $user, CandidacyAppeal $appeal): bool
    {
        return $this->view($user, $appeal);
    }

    /** A signature image is served only to the person it belongs to (everyone else sees it inside a memo). */
    public function viewSignature(User $user, CandidacyStaffProfile $profile): bool
    {
        return $profile->user_id !== null && $profile->user_id === $user->id;
    }

    public function isCgs(User $user): bool
    {
        return in_array($user->role, (array) config('candidacy.cgs_stage_roles'), true);
    }

    protected function isNamedPerson(User $user, CandidacyAppeal $appeal): bool
    {
        return $this->owns($user, $appeal->supervisorProfile)
            || $this->owns($user, $appeal->chairProfile)
            || $this->owns($user, $appeal->deanProfile);
    }

    protected function owns(User $user, ?CandidacyStaffProfile $profile): bool
    {
        return $profile !== null && $profile->user_id !== null && $profile->user_id === $user->id;
    }
}
