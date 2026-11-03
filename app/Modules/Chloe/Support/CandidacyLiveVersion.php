<?php

namespace App\Modules\Chloe\Support;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyAppealEvent;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Core\Models\User;

/**
 * A short fingerprint of what a candidacy page shows. The page polls it (every 15 s while visible); when it changes the
 * page reloads its own live region. It contains no personal data, only a hash.
 */
final class CandidacyLiveVersion
{
    public static function forStudent(User $user): string
    {
        $appeals = CandidacyAppeal::where('student_id', $user->id)->orderBy('id')->get(['id', 'status', 'round', 'current_stage', 'updated_at', 'last_action_at']);
        $candidacy = StudyCandidacy::where('student_id', $user->id)->first(['id', 'candidacy_expiry_date', 'extension_months_used', 'status', 'updated_at']);

        return sha1(json_encode([$appeals->toArray(), $candidacy?->toArray()]));
    }

    public static function forAppeal(CandidacyAppeal $appeal): string
    {
        return sha1(json_encode([
            $appeal->only(['id', 'status', 'round', 'current_stage', 'updated_at', 'last_action_at', 'memo_path']),
            CandidacyAppealEvent::where('candidacy_appeal_id', $appeal->id)->max('id'),
            $appeal->documents()->count(),
        ]));
    }
}
