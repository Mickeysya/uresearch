<?php

namespace App\Modules\Norhanis\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Norhanis\Models\ClaimsDetail;
use App\Modules\Norhanis\Models\PublicationDetail;
use Illuminate\Support\Collection;

/**
 * Which publications a student may still file a Student Claim for (UAT,
 * 11 Nov 2026): their own, approved at the final stage, a journal, on the
 * "CGS – Pay and Claims" cost centre, and not already claimed. One claim
 * per publication: a claim that is in approval or approved holds it; a
 * rejected claim frees it to be claimed again.
 *
 * The claim form's list, the deep link (?publication=), the server check
 * on submit and the "Start your claim" button all ask this one class.
 */
class ClaimablePublications
{
    /** @return Collection<int, PublicationDetail> newest first */
    public function for(User $student): Collection
    {
        return $this->query($student)->latest('application_id')->get();
    }

    public function isClaimable(User $student, int|string|null $publicationApplicationId): bool
    {
        return is_numeric($publicationApplicationId)
            && $this->query($student)->where('application_id', (int) $publicationApplicationId)->exists();
    }

    /** True once a claim that is not rejected names this publication. */
    public static function isClaimed(int $publicationApplicationId): bool
    {
        return self::liveClaims()->where('publication_application_id', $publicationApplicationId)->exists();
    }

    /** The claim form with this publication already chosen. */
    public static function claimUrl(int $publicationApplicationId): string
    {
        return route('claims.create', ['publication' => $publicationApplicationId]);
    }

    protected function query(User $student)
    {
        return PublicationDetail::query()
            ->where('type_of_request', PublicationDetail::JOURNAL)
            ->where('cost_centre', PublicationDetail::PAY_AND_CLAIMS)
            ->whereIn('application_id', Application::query()
                ->where('module_type', 'publication')
                ->where('student_id', $student->id)
                ->where('status', Application::STATUS_APPROVED)
                ->select('id'))
            ->whereNotIn('application_id', self::liveClaims()->whereNotNull('publication_application_id')->select('publication_application_id'));
    }

    /** Claims that hold their publication: every one but a rejected claim. */
    protected static function liveClaims()
    {
        return ClaimsDetail::query()->whereIn('application_id', Application::query()
            ->where('module_type', 'claims_student')
            ->where('status', '!=', Application::STATUS_REJECTED)
            ->select('id'));
    }
}
