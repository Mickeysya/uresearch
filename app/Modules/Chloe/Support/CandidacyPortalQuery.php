<?php

namespace App\Modules\Chloe\Support;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** The two queue tabs of the Approval Portal: appeals waiting for this user, and appeals this user has decided. */
final class CandidacyPortalQuery
{
    /** Appeals this user may act on right now (named person at the stage; CGS roles at the CGS stage). */
    public static function waiting(User $user): Builder
    {
        $profile = CandidacyStaffDirectory::profileForUser($user);
        $query = CandidacyAppeal::query();

        $query->where(function (Builder $q) use ($user, $profile) {
            if ($profile) {
                $q->orWhere(fn ($w) => $w->where('status', CandidacyAppeal::STATUS_WITH_SUPERVISOR)->where('supervisor_profile_id', $profile->id))
                    ->orWhere(fn ($w) => $w->where('status', CandidacyAppeal::STATUS_WITH_CHAIR)->where('chair_profile_id', $profile->id))
                    ->orWhere(fn ($w) => $w->where('status', CandidacyAppeal::STATUS_WITH_DEAN)->where('dean_profile_id', $profile->id));
            }

            if (in_array($user->role, (array) config('candidacy.cgs_roles'), true)) {
                $q->orWhere('status', CandidacyAppeal::STATUS_WITH_CGS);
            }

            $q->orWhereRaw('1 = 0'); // a user with neither sees nothing
        });

        return $query;
    }

    public static function decided(User $user): Builder
    {
        return CandidacyAppeal::query()->whereIn('id', \App\Modules\Chloe\Models\CandidacyAppealApproval::where('user_id', $user->id)->select('candidacy_appeal_id'));
    }

    /** @param array{q?:?string,department?:?int,months?:?int,waiting?:?int,round?:?int} $filters */
    public static function filter(Builder $query, array $filters): Builder
    {
        if ($term = trim((string) ($filters['q'] ?? ''))) {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
            $query->where(function (Builder $q) use ($like) {
                $q->where('appeal_no', 'like', $like)
                    ->orWhereHas('student', fn ($s) => $s->where('name', 'like', $like)->orWhere('matric_no', 'like', $like))
                    ->orWhereHas('demoProfile', fn ($s) => $s->where('name', 'like', $like)->orWhere('matric_no', 'like', $like));
            });
        }

        if (! empty($filters['department'])) {
            $query->where('department_id', (int) $filters['department']);
        }

        if (! empty($filters['months'])) {
            $query->where('requested_months', (int) $filters['months']);
        }

        if (! empty($filters['round'])) {
            $query->where('round', (int) $filters['round']);
        }

        if (! empty($filters['waiting'])) {
            $query->where('stage_entered_at', '<=', now()->subDays((int) $filters['waiting']));
        }

        return $query;
    }

    /** The calm waiting marker: gold from 5 days, red from 10 (config). */
    public static function marker(int $days): ?string
    {
        $cfg = config('candidacy.waiting_marker');

        return $days >= (int) $cfg['red_days'] ? 'red' : ($days >= (int) $cfg['gold_days'] ? 'gold' : null);
    }

    public static function version(User $user): string
    {
        return sha1(json_encode([
            self::waiting($user)->orderBy('id')->get(['id', 'status', 'round', 'updated_at'])->toArray(),
            self::decided($user)->orderBy('id')->get(['id', 'status', 'updated_at'])->toArray(),
        ]));
    }
}
