<?php

namespace App\Modules\Chloe\Support;

use App\Modules\Chloe\Models\CandidacyAppeal;
use Illuminate\Database\Eloquent\Builder;

/**
 * The CGS Operations list. The summary cards, the tabs and the table all come from this one class, so a card number
 * can never disagree with the list it filters: counts and rows are the same query, narrowed by the same filters, and
 * differ only in the `group`.
 *
 *   in_progress  with supervisor, chair, CGS or dean (does NOT include returned)
 *   returned | approved | rejected
 *   closed       closed_expired: no card, shown under "All" and the status filter only
 */
final class CandidacyOperationsQuery
{
    public const GROUPS = [
        'in_progress' => [CandidacyAppeal::STATUS_WITH_SUPERVISOR, CandidacyAppeal::STATUS_WITH_CHAIR, CandidacyAppeal::STATUS_WITH_CGS, CandidacyAppeal::STATUS_WITH_DEAN],
        'returned' => [CandidacyAppeal::STATUS_RETURNED],
        'approved' => [CandidacyAppeal::STATUS_APPROVED],
        'rejected' => [CandidacyAppeal::STATUS_REJECTED],
    ];

    /** The filters a request may carry, normalised and validated. @return array<string, mixed> */
    public static function filters(array $input): array
    {
        $group = $input['group'] ?? null;
        $status = $input['status'] ?? null;
        $step = $input['step'] ?? null;

        return [
            'group' => array_key_exists((string) $group, self::GROUPS) ? $group : null,
            'q' => isset($input['q']) ? mb_substr(trim((string) $input['q']), 0, 100) : null,
            'department' => (int) ($input['department'] ?? 0) ?: null,
            'status' => in_array($status, array_merge(array_merge(...array_values(self::GROUPS)), [CandidacyAppeal::STATUS_CLOSED_EXPIRED]), true) ? $status : null,
            'step' => in_array($step, ['supervisor', 'chair', 'cgs', 'dean'], true) ? $step : null,
            'months' => (int) ($input['months'] ?? 0) ?: null,
            'waiting' => (int) ($input['waiting'] ?? 0) ?: null,
            'round' => (int) ($input['round'] ?? 0) ?: null,
            'page' => max(1, (int) ($input['page'] ?? 1)),
        ];
    }

    /** Every submitted appeal, narrowed by every filter EXCEPT the group. */
    public static function base(array $f): Builder
    {
        $query = CandidacyAppeal::query()->where('status', '!=', CandidacyAppeal::STATUS_DRAFT);
        CandidacyPortalQuery::filter($query, $f);

        if ($f['status']) {
            $query->where('status', $f['status']);
        }

        if ($f['step']) {
            $query->where('current_stage', $f['step']);
        }

        return $query;
    }

    public static function forGroup(array $f, ?string $group): Builder
    {
        $query = self::base($f);

        return $group ? $query->whereIn('status', self::GROUPS[$group]) : $query;
    }

    /** @return array<string,int> in_progress, returned, approved, rejected, all */
    public static function counts(array $f): array
    {
        $counts = [];

        foreach (array_keys(self::GROUPS) as $group) {
            $counts[$group] = self::forGroup($f, $group)->count();
        }

        $counts['all'] = self::forGroup($f, null)->count();

        return $counts;
    }

    /** Fingerprint of everything the page shows (CGS roles only; no personal data). */
    public static function version(): string
    {
        return sha1(json_encode(CandidacyAppeal::where('status', '!=', 'draft')->orderBy('id')->get(['id', 'status', 'round', 'current_stage', 'updated_at'])->toArray()));
    }
}
