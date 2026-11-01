<?php

namespace App\Modules\Chloe\Support;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyAppealApproval;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The tabs of the Approval Portal.
 *
 * Supervisor, Chair and Dean: Pending my action | Ongoing | History -- three tabs that never overlap:
 *   pending  = waiting for me now
 *   ongoing  = I am named on it, it has no final result, and it is NOT waiting for me
 *   history  = I am named on it and it has a final result (approved, rejected, closed expired)
 * CGS roles: Pending my action | Decided by me (CGS sees every appeal under Operations).
 */
final class CandidacyPortalQuery
{
    public const FINAL = [CandidacyAppeal::STATUS_APPROVED, CandidacyAppeal::STATUS_REJECTED, CandidacyAppeal::STATUS_CLOSED_EXPIRED];

    public static function isCgs(User $user): bool
    {
        return in_array($user->role, (array) config('candidacy.cgs_roles'), true);
    }

    /** @return list<string> the tab keys of this user's portal, in order */
    public static function tabs(User $user): array
    {
        return self::isCgs($user) ? ['pending', 'decided'] : ['pending', 'ongoing', 'history'];
    }

    public static function forTab(User $user, string $tab): Builder
    {
        return match ($tab) {
            'ongoing' => self::ongoing($user),
            'history' => self::history($user),
            'decided' => self::decided($user),
            default => self::pending($user),
        };
    }

    /** Appeals this user may act on right now (named person at the stage; CGS roles at the CGS stage). */
    public static function pending(User $user): Builder
    {
        $profile = CandidacyStaffDirectory::profileForUser($user);

        return CandidacyAppeal::query()->where(function (Builder $q) use ($user, $profile) {
            if ($profile) {
                $q->orWhere(fn ($w) => $w->where('status', CandidacyAppeal::STATUS_WITH_SUPERVISOR)->where('supervisor_profile_id', $profile->id))
                    ->orWhere(fn ($w) => $w->where('status', CandidacyAppeal::STATUS_WITH_CHAIR)->where('chair_profile_id', $profile->id))
                    ->orWhere(fn ($w) => $w->where('status', CandidacyAppeal::STATUS_WITH_DEAN)->where('dean_profile_id', $profile->id));
            }

            if (self::isCgs($user)) {
                $q->orWhere('status', CandidacyAppeal::STATUS_WITH_CGS);
            }

            $q->orWhereRaw('1 = 0'); // a user with neither sees nothing
        });
    }

    /** Appeals this user is named on (any state except a draft, which only its student sees). */
    public static function named(User $user): Builder
    {
        $profile = CandidacyStaffDirectory::profileForUser($user);

        return CandidacyAppeal::query()->where('status', '!=', CandidacyAppeal::STATUS_DRAFT)->where(function (Builder $q) use ($profile) {
            if ($profile) {
                $q->orWhere('supervisor_profile_id', $profile->id)->orWhere('chair_profile_id', $profile->id)->orWhere('dean_profile_id', $profile->id);
            }

            $q->orWhereRaw('1 = 0');
        });
    }

    public static function ongoing(User $user): Builder
    {
        return self::named($user)->whereIn('status', CandidacyAppeal::OPEN_STATUSES)
            ->whereNotIn('id', self::pending($user)->select('candidacy_appeals.id'));
    }

    public static function history(User $user): Builder
    {
        return self::named($user)->whereIn('status', self::FINAL);
    }

    /** CGS: appeals this user has decided. */
    public static function decided(User $user): Builder
    {
        return CandidacyAppeal::query()->whereIn('id', CandidacyAppealApproval::where('user_id', $user->id)->select('candidacy_appeal_id'));
    }

    /** "Approved / Returned / Rejected / Not reached": what this user's own step did on the appeal. */
    public static function myDecision(User $user, CandidacyAppeal $appeal): string
    {
        $profile = CandidacyStaffDirectory::profileForUser($user);
        $row = $appeal->approvals->where('profile_id', $profile?->id)->sortBy('id')->last();

        return match ($row?->decision) {
            'approved', 'verified' => 'Approved',
            'returned' => 'Returned',
            'rejected' => 'Rejected',
            default => 'Not reached',
        };
    }

    public static function finalOutcome(CandidacyAppeal $appeal): string
    {
        return match ($appeal->status) {
            CandidacyAppeal::STATUS_APPROVED => 'Approved',
            CandidacyAppeal::STATUS_REJECTED => 'Rejected',
            CandidacyAppeal::STATUS_CLOSED_EXPIRED => 'Closed expired',
            default => '—',
        };
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

    /** Fingerprint of THIS user's tabs only (nothing about anyone else's appeals). */
    public static function version(User $user): string
    {
        $parts = [];

        foreach (self::tabs($user) as $tab) {
            $parts[$tab] = self::forTab($user, $tab)->orderBy('id')->get(['id', 'status', 'round', 'current_stage', 'updated_at'])->toArray();
        }

        return sha1(json_encode($parts));
    }

    /** @return array<string,int> */
    public static function counts(User $user): array
    {
        $out = [];

        foreach (self::tabs($user) as $tab) {
            $out[$tab] = self::forTab($user, $tab)->count();
        }

        return $out;
    }
}
