<?php

namespace App\Modules\Hani\Support;

use App\Modules\Hani\Models\Examiner;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The tabs, filters and paging behind the two lists of the examiner pool: CGS's
 * /examiners and the supervisor's read-only table on /examiner-nomination/new.
 * State is derived in PHP (Examiner::state()), so this filters the collection
 * rather than the query.
 */
class ExaminerPoolList
{
    public const PER_PAGE = 8;

    /** The tab: internal, external, or '' for both. */
    public static function type(Request $request): string
    {
        return in_array($request->input('type'), [Examiner::TYPE_INTERNAL, Examiner::TYPE_EXTERNAL], true)
            ? (string) $request->input('type')
            : '';
    }

    public static function inTab(Collection $pool, string $type): Collection
    {
        return $type === '' ? $pool : $pool->where('type', $type);
    }

    /** @return array{'': int, internal: int, external: int} */
    public static function typeCounts(Collection $pool): array
    {
        return [
            '' => $pool->count(),
            Examiner::TYPE_INTERNAL => $pool->where('type', Examiner::TYPE_INTERNAL)->count(),
            Examiner::TYPE_EXTERNAL => $pool->where('type', Examiner::TYPE_EXTERNAL)->count(),
        ];
    }

    /** Scoped to the tab: an external department on the internal tab only filters everything away. */
    public static function departments(Collection $tabPool): Collection
    {
        return $tabPool->pluck('department')->unique()->sort()->values();
    }

    /**
     * The named query parameters as plain strings. A Stringable never === the
     * string Examiner::state() returns, and an array (?search[]=x) is not a
     * filter at all, so it counts as empty rather than breaking the page.
     *
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    public static function filters(Request $request, array $keys): array
    {
        $filters = [];

        foreach ($keys as $key) {
            $value = $request->query($key);
            $filters[$key] = is_string($value) ? $value : '';
        }

        return $filters;
    }

    /** @param  array<string, string>  $filters */
    public static function filtered(Collection $tabPool, array $filters): Collection
    {
        $department = $filters['department'] ?? '';
        $status = $filters['status'] ?? '';
        $search = mb_strtolower($filters['search'] ?? '');

        return $tabPool
            ->when($department !== '', fn ($c) => $c->where('department', $department))
            ->when($status !== '', fn ($c) => $c->filter(fn (Examiner $e) => $e->state() === $status))
            ->when($search !== '', fn ($c) => $c->filter(fn (Examiner $e) => str_contains(mb_strtolower($e->name), $search)
                || str_contains(mb_strtolower($e->department), $search)
                || str_contains(mb_strtolower((string) $e->institution), $search)))
            ->sortBy('name')
            ->values();
    }

    public static function paginate(Collection $filtered, Request $request): LengthAwarePaginator
    {
        $page = max(1, $request->integer('page', 1));

        return new LengthAwarePaginator(
            $filtered->forPage($page, self::PER_PAGE)->values(),
            $filtered->count(),
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );
    }
}
