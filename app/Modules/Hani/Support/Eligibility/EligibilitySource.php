<?php

namespace App\Modules\Hani\Support\Eligibility;

use App\Modules\Core\Models\User;

/**
 * Something that can check one eligibility item for the system instead of
 * leaving it to the student's tick. Listed in config('hani.eligibility.sources').
 *
 * Today only the candidacy period has a data source (Norhanis's candidacies
 * table). RCS results, semester registration and fees are stored nowhere in
 * UResearch, so those items stay self-declared until a source exists; each
 * would be one more class here.
 */
interface EligibilitySource
{
    /** The viva_eligibility column this source checks. */
    public function column(): string;

    /**
     * SELF_DECLARED when there is no data for this student, VERIFIED or
     * NOT_MET when there is. Must not throw: a failure is a FAILED result.
     */
    public function check(User $student): EligibilityResult;
}
