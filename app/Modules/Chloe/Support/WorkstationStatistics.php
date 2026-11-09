<?php

namespace App\Modules\Chloe\Support;

use App\Modules\Chloe\Models\DemoStudentProfile;
use App\Modules\Chloe\Models\LockerKey;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationHistory;
use App\Modules\Chloe\Models\WorkstationLocation;
use App\Modules\Chloe\Models\WorkstationRequest;
use App\Modules\Chloe\Models\WorkstationTerminationNotice;
use App\Modules\Chloe\Support\StudentDirectory;
use Illuminate\Support\Carbon;

/**
 * Every number the CGS stats bar, the assistant's CGS lookups, and the
 * Insights page (a later task) all need -- one place, so none of them can
 * ever quietly drift apart. WorkstationManagementController::stats()
 * delegates to overall() below rather than keeping its own copy of the
 * same five queries.
 *
 * Every method reads live -- nothing here is cached or stored, matching
 * the assistant's own "data is always read live" rule.
 */
final class WorkstationStatistics
{
    /** @return array{total: int, available: int, occupied: int, maintenance: int, awaiting_key_return: int, key_pending_collection: int, key_return_overdue: int} */
    public static function overall(): array
    {
        return [
            'total' => Workstation::count(),
            'available' => Workstation::where('status', Workstation::STATUS_AVAILABLE)->count(),
            'occupied' => Workstation::where('status', Workstation::STATUS_OCCUPIED)->count(),
            'maintenance' => Workstation::where('status', Workstation::STATUS_DISABLED)->count(),
            'awaiting_key_return' => Workstation::where('status', Workstation::STATUS_AWAITING_KEY_RETURN)->count(),
            'key_pending_collection' => self::keysPendingCollection(),
            'key_return_overdue' => self::keysPendingReturn()['overdue'],
        ];
    }

    /**
     * Per-room breakdown, optionally narrowed to one block or one room
     * code -- the shape seat_availability needs either way.
     *
     * @return array<int, array{block: string, room_code: string, gender: string, available: int, occupied: int, maintenance: int, awaiting_key_return: int, total: int}>
     */
    public static function seatAvailability(?string $block = null, ?string $roomCode = null): array
    {
        return WorkstationLocation::query()
            ->when($block, fn ($q) => $q->where('block', $block))
            ->when($roomCode, fn ($q) => $q->where('room_code', $roomCode))
            ->withCount([
                'workstations',
                'workstations as available_count' => fn ($q) => $q->where('status', Workstation::STATUS_AVAILABLE),
                'workstations as occupied_count' => fn ($q) => $q->where('status', Workstation::STATUS_OCCUPIED),
                'workstations as maintenance_count' => fn ($q) => $q->where('status', Workstation::STATUS_DISABLED),
                'workstations as awaiting_count' => fn ($q) => $q->where('status', Workstation::STATUS_AWAITING_KEY_RETURN),
            ])
            ->orderBy('block')->orderBy('room_code')
            ->get()
            ->map(fn (WorkstationLocation $location) => [
                'block' => $location->block,
                'room_code' => $location->room_code,
                'gender' => $location->gender,
                'available' => $location->available_count,
                'occupied' => $location->occupied_count,
                'maintenance' => $location->maintenance_count,
                'awaiting_key_return' => $location->awaiting_count,
                'total' => $location->workstations_count,
            ])
            ->all();
    }

    /** Only the student's own gender's rooms -- available_seats' own scoping. */
    public static function availableSeatsByGender(string $gender): array
    {
        return WorkstationLocation::where('gender', $gender)
            ->withCount(['workstations as available_count' => fn ($q) => $q->where('status', Workstation::STATUS_AVAILABLE)])
            ->orderBy('block')->orderBy('room_code')
            ->get()
            ->map(fn (WorkstationLocation $l) => ['block' => $l->block, 'room_code' => $l->room_code, 'available' => $l->available_count])
            ->all();
    }

    /**
     * Round 4 PART B.2: N2 vs J2 side by side for the new block_comparison
     * lookup -- totals/occupied/available/maintenance/occupancy% per
     * block, plus the male/female split within each. $onlyGender narrows
     * EVERY count (both blocks) to that gender's rooms only -- a student
     * comparing blocks only ever sees their own gender's rooms, same
     * scoping as availableSeatsByGender() above -- and the per-gender
     * split is omitted in that case, since it would just repeat the
     * block totals already shown.
     *
     * @return array<string, array{block: string, total: int, occupied: int, available: int, maintenance: int, occupancy_percent: float, male?: array{total: int, occupied: int}, female?: array{total: int, occupied: int}}>
     */
    public static function blockComparison(?string $onlyGender = null): array
    {
        $result = [];

        foreach (['N2', 'J2'] as $block) {
            $rooms = WorkstationLocation::where('block', $block)
                ->when($onlyGender, fn ($q) => $q->where('gender', $onlyGender))
                ->withCount([
                    'workstations',
                    'workstations as occupied_count' => fn ($q) => $q->where('status', Workstation::STATUS_OCCUPIED),
                    'workstations as available_count' => fn ($q) => $q->where('status', Workstation::STATUS_AVAILABLE),
                    'workstations as maintenance_count' => fn ($q) => $q->where('status', Workstation::STATUS_DISABLED),
                ])
                ->get();

            $total = (int) $rooms->sum('workstations_count');
            $occupied = (int) $rooms->sum('occupied_count');

            $entry = [
                'block' => $block,
                'total' => $total,
                'occupied' => $occupied,
                'available' => (int) $rooms->sum('available_count'),
                'maintenance' => (int) $rooms->sum('maintenance_count'),
                'occupancy_percent' => $total > 0 ? round($occupied / $total * 100, 1) : 0.0,
            ];

            if ($onlyGender === null) {
                foreach ([WorkstationLocation::GENDER_MALE, WorkstationLocation::GENDER_FEMALE] as $gender) {
                    $genderRooms = $rooms->where('gender', $gender);
                    $entry[$gender] = [
                        'total' => (int) $genderRooms->sum('workstations_count'),
                        'occupied' => (int) $genderRooms->sum('occupied_count'),
                    ];
                }
            }

            $result[$block] = $entry;
        }

        return $result;
    }

    /**
     * Seats whose CURRENT key is waiting to be collected.
     *
     * One definition, shared with the seat list: the list's "Key Pending
     * Collection" filter shows a seat when WorkstationManagementController::
     * presentSeat() resolves its displayed key to `requested`, and that key
     * is the seat's active (confirmed) request's latest key. This counts
     * exactly those seats through the same two relations, so the stats bar
     * number and the filtered list can never disagree.
     *
     * It used to count raw locker_keys rows with status `requested`, which
     * also counted a stale pending key left on a request that had already
     * been released (the seat Available again): the bar said 22 while the
     * list, Insights and the seat details all said 21.
     */
    public static function keysPendingCollection(): int
    {
        return Workstation::whereHas(
            'activeRequest.lockerKey',
            fn ($key) => $key->where('locker_keys.status', LockerKey::STATUS_REQUESTED)
        )->count();
    }

    /**
     * Keys waiting to come back, and how many of those are overdue --
     * counted per seat on the seat list's own "displayed key" rule
     * (presentSeat(): the active request's key, or, with no occupant, the
     * seat's latest Return Pending key), so "Key Return Overdue" always
     * equals its filtered list.
     *
     * @return array{total: int, overdue: int}
     */
    public static function keysPendingReturn(): array
    {
        $displayed = Workstation::query()
            ->with(['activeRequest.lockerKey', 'lockerKeysHistory'])
            ->where(fn ($q) => $q
                ->whereHas('lockerKeysHistory', fn ($k) => $k->where('locker_keys.status', LockerKey::STATUS_RETURN_PENDING))
                ->orWhereHas('activeRequest.lockerKey', fn ($k) => $k->where('locker_keys.status', LockerKey::STATUS_RETURN_PENDING)))
            ->get()
            ->map(function (Workstation $seat) {
                $active = $seat->activeRequest;
                $outstanding = $seat->lockerKeysHistory
                    ->filter(fn (LockerKey $k) => $k->status === LockerKey::STATUS_RETURN_PENDING)
                    ->sortByDesc('requested_at')
                    ->first();

                return $active?->lockerKey ?: (! $active?->occupant() ? $outstanding : null);
            })
            ->filter(fn (?LockerKey $k) => $k !== null && $k->status === LockerKey::STATUS_RETURN_PENDING);

        return [
            'total' => $displayed->count(),
            'overdue' => $displayed->filter(fn (LockerKey $k) => $k->isOverdueForReturn())->count(),
        ];
    }

    /**
     * The seat counts on a Room card, added to any WorkstationLocation
     * query -- the Block page's cards and the live stats endpoint both go
     * through this, so a card can never show a different number from the
     * one the live update writes into it.
     */
    public static function withSeatCounts($locations)
    {
        return $locations->withCount([
            'workstations',
            'workstations as available_count' => fn ($q) => $q->where('status', Workstation::STATUS_AVAILABLE),
            'workstations as occupied_count' => fn ($q) => $q->where('status', Workstation::STATUS_OCCUPIED),
            'workstations as maintenance_count' => fn ($q) => $q->where('status', Workstation::STATUS_DISABLED),
        ]);
    }

    /** @return array<int, array{id: int, block: string, room_code: string, available: int, occupied: int, maintenance: int, total: int}> */
    public static function roomCards(): array
    {
        return self::withSeatCounts(WorkstationLocation::query())
            ->orderBy('block')->orderBy('room_code')
            ->get()
            ->map(fn (WorkstationLocation $r) => [
                'id' => $r->id,
                'block' => $r->block,
                'room_code' => $r->room_code,
                'available' => (int) $r->available_count,
                'occupied' => (int) $r->occupied_count,
                'maintenance' => (int) $r->maintenance_count,
                'total' => (int) $r->workstations_count,
            ])
            ->all();
    }

    public static function awaitingKeyReturnSeats(?string $gender = null, ?string $block = null): int
    {
        return self::scopeWorkstations(Workstation::where('status', Workstation::STATUS_AWAITING_KEY_RETURN), $gender, $block)->count();
    }

    public static function terminationNoticeSeats(): int
    {
        return WorkstationTerminationNotice::where('status', WorkstationTerminationNotice::STATUS_ACTIVE)->count();
    }

    /**
     * Round 4 PART C.1: the shared scoping helpers behind every Insights
     * function's $gender/$block parameters below -- null/null (the
     * default everywhere) applies NO filter at all, so every existing
     * unscoped caller (the chat lookups, SuggestedAnswers, overall())
     * keeps reading exactly what it always has. A seat's gender is its
     * room's gender; a "seat holder" is the current occupant of a seat
     * in scope (status CONFIRMED on the WorkstationRequest, including
     * one mid a termination notice -- it is still occupied until
     * actually released).
     */
    protected static function scopeWorkstations($query, ?string $gender, ?string $block)
    {
        if ($gender === null && $block === null) {
            return $query;
        }

        return $query->whereHas('location', function ($q) use ($gender, $block) {
            $q->when($gender, fn ($q2) => $q2->where('gender', $gender));
            $q->when($block, fn ($q2) => $q2->where('block', $block));
        });
    }

    protected static function scopeLocations($query, ?string $gender, ?string $block)
    {
        return $query
            ->when($gender, fn ($q) => $q->where('gender', $gender))
            ->when($block, fn ($q) => $q->where('block', $block));
    }

    /** Confirmed WorkstationRequest rows whose SEAT is in scope -- the base of every seat-holder statistic below (candidacy, length of stay, programme, locker key overview). */
    protected static function scopedConfirmedRequests(?string $gender, ?string $block)
    {
        $query = WorkstationRequest::where('status', WorkstationRequest::STATUS_CONFIRMED);

        if ($gender === null && $block === null) {
            return $query;
        }

        return $query->whereHas('workstation.location', function ($q) use ($gender, $block) {
            $q->when($gender, fn ($q2) => $q2->where('gender', $gender));
            $q->when($block, fn ($q2) => $q2->where('block', $block));
        });
    }

    /**
     * Round 4 PART C.1: one summary row for a scope -- seat totals/
     * occupied/available/maintenance/awaiting-key-return/occupancy% --
     * the Insights page's per-scope headline numbers. Unlike overall()
     * above (which this does NOT replace -- overall() stays the stats
     * bar's own unscoped source), this is scoped and never cached.
     *
     * @return array{total: int, occupied: int, available: int, maintenance: int, awaiting_key_return: int, occupancy_percent: float}
     */
    public static function seatOverview(?string $gender = null, ?string $block = null): array
    {
        $total = self::scopeWorkstations(Workstation::query(), $gender, $block)->count();
        $occupied = self::scopeWorkstations(Workstation::where('status', Workstation::STATUS_OCCUPIED), $gender, $block)->count();

        return [
            'total' => $total,
            'occupied' => $occupied,
            'available' => self::scopeWorkstations(Workstation::where('status', Workstation::STATUS_AVAILABLE), $gender, $block)->count(),
            'maintenance' => self::scopeWorkstations(Workstation::where('status', Workstation::STATUS_DISABLED), $gender, $block)->count(),
            'awaiting_key_return' => self::awaitingKeyReturnSeats($gender, $block),
            'occupancy_percent' => $total > 0 ? round($occupied / $total * 100, 1) : 0.0,
        ];
    }

    /** No personal data -- seat numbers only, grouped by room. @return array<string, array<int, string>> */
    public static function maintenanceSeatsByRoom(): array
    {
        return Workstation::where('status', Workstation::STATUS_DISABLED)
            ->with('location')
            ->get()
            ->groupBy(fn (Workstation $seat) => $seat->location->room_code)
            ->map(fn ($seats) => SeatOrder::sort($seats, fn ($s) => ['', '', $s->seat_code, $s->id])->pluck('seat_code')->all())
            ->all();
    }

    /** Round 4 PART C.1: $gender/$block narrow which ROOMS are listed -- a room is in scope when its own gender/block match (block narrows which rooms appear at all; gender does too, here, unlike the gender tiles below). @return array<int, array{block: string, room_code: string, occupied_percent: float}> */
    public static function roomOccupancy(?string $gender = null, ?string $block = null): array
    {
        return self::scopeLocations(WorkstationLocation::query(), $gender, $block)
            ->withCount([
                'workstations',
                'workstations as occupied_count' => fn ($q) => $q->where('status', Workstation::STATUS_OCCUPIED),
            ])
            ->orderBy('block')->orderBy('room_code')
            ->get()
            ->map(fn (WorkstationLocation $l) => [
                'id' => $l->id,
                'block' => $l->block,
                'room_code' => $l->room_code,
                // Round 3 PART E.4#2: gender and the raw counts, for the
                // Insights room-occupancy card's "occupied/total" label --
                // additive only, the existing room_occupancy chat lookup
                // still reads block/room_code/occupied_percent exactly as before.
                'gender' => $l->gender,
                'occupied' => $l->occupied_count,
                'total' => $l->workstations_count,
                'occupied_percent' => $l->workstations_count > 0 ? round($l->occupied_count / $l->workstations_count * 100, 1) : 0.0,
            ])
            ->all();
    }

    /**
     * Current seat holders only (a confirmed WorkstationRequest), real
     * accounts only -- StudyCandidacy belongs to `users`, a demo profile
     * never has one. Within [now, now+$months].
     */
    public static function candidacyExpiringCount(int $months = 3): int
    {
        $studentIds = WorkstationRequest::where('status', WorkstationRequest::STATUS_CONFIRMED)
            ->whereNotNull('student_id')
            ->pluck('student_id');

        return StudyCandidacy::whereIn('student_id', $studentIds)
            ->where('status', StudyCandidacy::STATUS_ACTIVE)
            ->whereBetween('candidacy_expiry_date', [now()->startOfDay(), now()->addMonths($months)->endOfDay()])
            ->count();
    }

    /** From History only, per the brief -- not a live WorkstationRequest diff. */
    public static function releasesThisWeek(): int
    {
        return WorkstationHistory::whereIn('action', [
            WorkstationHistory::ACTION_SEAT_RELEASED,
            WorkstationHistory::ACTION_SEAT_RELEASED_VACATE_ENDED,
        ])
            ->where('created_at', '>=', Carbon::now()->subDays(7))
            ->count();
    }

    // -------------------------------------------------------------
    // Insights page / assistant's two new aggregate lookups below.
    // Every one of these reads live, no caching, same rule as above.
    // -------------------------------------------------------------

    /** @return array<int, array{block: string, room_code: string, gender: string, occupied_percent: float, available: int}> */
    public static function almostFullRooms(float $thresholdPercent, ?string $gender = null, ?string $block = null): array
    {
        return self::scopeLocations(WorkstationLocation::query(), $gender, $block)
            ->withCount([
                'workstations',
                'workstations as occupied_count' => fn ($q) => $q->where('status', Workstation::STATUS_OCCUPIED),
                'workstations as available_count' => fn ($q) => $q->where('status', Workstation::STATUS_AVAILABLE),
            ])
            ->get()
            ->map(fn (WorkstationLocation $l) => [
                'id' => $l->id,
                'block' => $l->block,
                'room_code' => $l->room_code,
                'gender' => $l->gender,
                'occupied_percent' => $l->workstations_count > 0 ? round($l->occupied_count / $l->workstations_count * 100, 1) : 0.0,
                'available' => $l->available_count,
            ])
            ->filter(fn ($r) => $r['occupied_percent'] >= $thresholdPercent)
            ->sortByDesc('occupied_percent')
            ->values()
            ->all();
    }

    /** The single fullest room, used when almostFullRooms() finds none above the threshold. */
    public static function fullestRoom(?string $gender = null, ?string $block = null): ?array
    {
        return collect(self::roomOccupancy($gender, $block))->sortByDesc('occupied_percent')->first();
    }

    /**
     * C1/F.3#1: three non-personal categories of seat that may free up
     * soon -- each counted and reported on its own line, never merged,
     * because they become free for different reasons on different
     * schedules. $months scopes only the candidacy-expiry category.
     *
     * @return array{termination: array{count: int, earliest_vacate_by: ?string}, awaiting_key_return: array{count: int}, candidacy_expiring: array{count: int, by_month: array<string, int>}}
     */
    public static function seatReleaseOutlook(int $months = 3, ?string $gender = null, ?string $block = null): array
    {
        $terminationNotices = self::scopeTerminationNotices($gender, $block)
            ->orderBy('vacate_by_date')
            ->get();

        $byMonth = [];
        foreach (self::currentSeatHolderCandidacyExpiries($gender, $block) as $expiry) {
            if ($expiry->lt(now()->startOfDay()) || $expiry->gt(now()->addMonths($months)->endOfDay())) {
                continue;
            }

            $key = $expiry->format('M Y');
            $byMonth[$key] = ($byMonth[$key] ?? 0) + 1;
        }

        return [
            'termination' => [
                'count' => $terminationNotices->count(),
                'earliest_vacate_by' => $terminationNotices->first()?->vacate_by_date?->format('j M Y'),
            ],
            'awaiting_key_return' => [
                'count' => self::awaitingKeyReturnSeats($gender, $block),
            ],
            'candidacy_expiring' => [
                'count' => array_sum($byMonth),
                'by_month' => $byMonth,
            ],
        ];
    }

    /** @return array{count: int, earliest_vacate_by: ?string, by_reason: array<string, int>} */
    public static function terminationNoticesDetailed(?string $gender = null, ?string $block = null): array
    {
        $notices = self::scopeTerminationNotices($gender, $block)
            ->orderBy('vacate_by_date')
            ->get();

        return [
            'count' => $notices->count(),
            'earliest_vacate_by' => $notices->first()?->vacate_by_date?->format('j M Y'),
            'by_reason' => $notices->groupBy('reason')->map(fn ($g) => $g->count())->sortDesc()->all(),
        ];
    }

    /** Active termination notices whose underlying request's SEAT is in scope -- the notice still holds the seat occupied, so it stays within scope, same as any other confirmed request. */
    protected static function scopeTerminationNotices(?string $gender, ?string $block)
    {
        $query = WorkstationTerminationNotice::where('status', WorkstationTerminationNotice::STATUS_ACTIVE);

        if ($gender === null && $block === null) {
            return $query;
        }

        return $query->whereHas('workstationRequest.workstation.location', function ($q) use ($gender, $block) {
            $q->when($gender, fn ($q2) => $q2->where('gender', $gender));
            $q->when($block, fn ($q2) => $q2->where('block', $block));
        });
    }

    /**
     * F.3#4: for each of the next $months calendar months, how many seats
     * MAY free up (a candidacy expiring that month, or a termination
     * vacate-by date in that month -- Awaiting Key Return seats are
     * deliberately excluded per the brief, since they are already in
     * progress, not a future release) and the projected cumulative
     * available count if every one of them is released.
     *
     * @return array<int, array{month: string, releases: int, projected_available: int}>
     */
    public static function seatReleaseForecast(int $months = 6, ?string $gender = null, ?string $block = null): array
    {
        $expiries = self::currentSeatHolderCandidacyExpiries($gender, $block);
        $vacateByDates = self::scopeTerminationNotices($gender, $block)->pluck('vacate_by_date');

        $available = self::scopeWorkstations(Workstation::where('status', Workstation::STATUS_AVAILABLE), $gender, $block)->count();
        $forecast = [];
        $cumulative = 0;

        for ($i = 0; $i < $months; $i++) {
            $monthStart = now()->addMonths($i)->startOfMonth();
            $monthEnd = now()->addMonths($i)->endOfMonth();

            $fromCandidacy = $expiries->filter(fn ($d) => $d->between($monthStart, $monthEnd))->count();
            $fromTermination = $vacateByDates->filter(fn ($d) => $d && Carbon::parse($d)->between($monthStart, $monthEnd))->count();

            $releases = $fromCandidacy + $fromTermination;
            $cumulative += $releases;

            $forecast[] = [
                'month' => $monthStart->format('M Y'),
                'releases' => $releases,
                // Round 3 PART E.4: the stacked forecast chart needs the
                // two sources separately, not just their sum.
                'from_candidacy' => $fromCandidacy,
                'from_termination' => $fromTermination,
                'projected_available' => $available + $cumulative,
            ];
        }

        return $forecast;
    }

    /** @return array<int, array{label: string, count: int}> buckets: 0-3, 3-6, 6-12, >12 months from today */
    public static function candidacyExpiryBuckets(?string $gender = null, ?string $block = null): array
    {
        $buckets = ['0-3 months' => 0, '3-6 months' => 0, '6-12 months' => 0, 'more than 12 months' => 0];

        foreach (self::currentSeatHolderCandidacyExpiries($gender, $block) as $expiry) {
            $monthsAway = now()->startOfDay()->diffInMonths($expiry, false);

            if ($monthsAway < 0) {
                continue;
            }

            match (true) {
                $monthsAway <= 3 => $buckets['0-3 months']++,
                $monthsAway <= 6 => $buckets['3-6 months']++,
                $monthsAway <= 12 => $buckets['6-12 months']++,
                default => $buckets['more than 12 months']++,
            };
        }

        return collect($buckets)->map(fn ($count, $label) => ['label' => $label, 'count' => $count])->values()->all();
    }

    /** @return array<int, array{label: string, count: int}> buckets: under 6, 6-12, 12-18, over 18 months, from the allocation (requested_at) date */
    public static function lengthOfStayBuckets(?string $gender = null, ?string $block = null): array
    {
        $buckets = ['under 6 months' => 0, '6-12 months' => 0, '12-18 months' => 0, 'over 18 months' => 0];

        $months = self::scopedConfirmedRequests($gender, $block)
            ->pluck('requested_at')
            ->map(fn ($d) => Carbon::parse($d)->diffInMonths(now()));

        foreach ($months as $monthsHeld) {
            match (true) {
                $monthsHeld < 6 => $buckets['under 6 months']++,
                $monthsHeld < 12 => $buckets['6-12 months']++,
                $monthsHeld < 18 => $buckets['12-18 months']++,
                default => $buckets['over 18 months']++,
            };
        }

        return collect($buckets)->map(fn ($count, $label) => ['label' => $label, 'count' => $count])->values()->all();
    }

    /** @return array<int, array{label: string, count: int}> top $topN programmes among current seat holders, the rest grouped as "Others" */
    public static function programmeBreakdown(int $topN = 5, ?string $gender = null, ?string $block = null): array
    {
        $counts = self::currentSeatHolderIdentities($gender, $block)
            ->map(fn ($identity) => $identity->programme ?: 'Not recorded')
            ->countBy()
            ->sortDesc();

        $top = $counts->take($topN);
        $othersCount = $counts->skip($topN)->sum();

        $result = $top->map(fn ($count, $label) => ['label' => $label, 'count' => $count])->values()->all();

        if ($othersCount > 0) {
            $result[] = ['label' => 'Others', 'count' => $othersCount];
        }

        return $result;
    }

    /** @return array{not_requested: int, pending_collection: int, collected: int, return_pending: int} among CURRENT seat holders only */
    public static function lockerKeyOverviewForSeatHolders(?string $gender = null, ?string $block = null): array
    {
        $requestIds = self::scopedConfirmedRequests($gender, $block)->pluck('id');
        $total = $requestIds->count();

        $latestKeyStatuses = LockerKey::whereIn('workstation_request_id', $requestIds)
            ->orderByDesc('requested_at')
            ->get()
            ->unique('workstation_request_id')
            ->pluck('status');

        $collected = $latestKeyStatuses->filter(fn ($s) => $s === LockerKey::STATUS_COLLECTED)->count();
        $pending = $latestKeyStatuses->filter(fn ($s) => $s === LockerKey::STATUS_REQUESTED)->count();
        $returnPending = $latestKeyStatuses->filter(fn ($s) => $s === LockerKey::STATUS_RETURN_PENDING)->count();
        $notRequested = $total - $collected - $pending - $returnPending;

        return [
            'not_requested' => max($notRequested, 0),
            'pending_collection' => $pending,
            'collected' => $collected,
            'return_pending' => $returnPending,
        ];
    }

    /** @return array{due_7_days: int, due_14_days: int, overdue: int} */
    public static function keyReturnOutlook(?string $gender = null, ?string $block = null): array
    {
        $query = LockerKey::where('status', LockerKey::STATUS_RETURN_PENDING)->whereNotNull('return_due_at');

        if ($gender !== null || $block !== null) {
            $query->whereHas('workstationRequest.workstation.location', function ($q) use ($gender, $block) {
                $q->when($gender, fn ($q2) => $q2->where('gender', $gender));
                $q->when($block, fn ($q2) => $q2->where('block', $block));
            });
        }

        $returnPending = $query->get();

        return [
            'due_7_days' => $returnPending->filter(fn ($k) => $k->return_due_at->between(now(), now()->addDays(7)))->count(),
            'due_14_days' => $returnPending->filter(fn ($k) => $k->return_due_at->between(now(), now()->addDays(14)))->count(),
            'overdue' => $returnPending->filter(fn ($k) => $k->return_due_at->isPast())->count(),
        ];
    }

    /**
     * Round 4 PART C.1: the Insights gender tiles -- male and female,
     * occupied/total/available/percent. $block narrows which ROOMS
     * count (same as roomOccupancy()'s own $block); there is
     * deliberately no $gender parameter here -- these two tiles always
     * show BOTH genders regardless of the page's own gender filter, per
     * the spec ("these two ignore the gender filter but respect the
     * block filter").
     *
     * @return array<int, array{gender: string, total: int, occupied: int, available: int, occupied_percent: float}>
     */
    public static function occupancyByGender(?string $block = null): array
    {
        return WorkstationLocation::query()
            ->when($block, fn ($q) => $q->where('block', $block))
            ->selectRaw(
                'gender, COUNT(workstations.id) as total, '.
                'SUM(CASE WHEN workstations.status = ? THEN 1 ELSE 0 END) as occupied, '.
                'SUM(CASE WHEN workstations.status = ? THEN 1 ELSE 0 END) as available',
                [Workstation::STATUS_OCCUPIED, Workstation::STATUS_AVAILABLE]
            )
            ->join('workstations', 'workstations.workstation_location_id', '=', 'workstation_locations.id')
            ->groupBy('gender')
            ->get()
            ->map(fn ($row) => [
                'gender' => $row->gender,
                'total' => (int) $row->total,
                'occupied' => (int) $row->occupied,
                'available' => (int) $row->available,
                'occupied_percent' => $row->total > 0 ? round($row->occupied / $row->total * 100, 1) : 0.0,
            ])
            ->all();
    }

    /**
     * F.3#1: three to five short, auto-generated sentences from the data
     * above -- no model call, so the Insights page's headline never
     * depends on the assistant's provider being available.
     *
     * @return array<int, string>
     */
    public static function keyHighlights(): array
    {
        $sentences = [];

        $fullest = self::fullestRoom();
        if ($fullest) {
            $sentences[] = "The fullest room is {$fullest['room_code']} at {$fullest['occupied_percent']}% occupied.";
        }

        // Deliberately NOT candidacyExpiringCount() -- that one is the
        // existing chat lookup's real-students-only count (StudyCandidacy
        // has no demo-profile rows at all); every number on this page
        // goes through StudentDirectory so a demo seat holder counts too.
        $expiringSoon = self::seatReleaseOutlook(3)['candidacy_expiring']['count'];
        $sentences[] = "{$expiringSoon} current seat holder(s) have a candidacy expiring within 3 months.";

        $forecast = self::seatReleaseForecast(1);
        $nextMonthReleases = $forecast[0]['releases'] ?? 0;
        $sentences[] = "Up to {$nextMonthReleases} seat(s) may be released next month.";

        $returnOutlook = self::keyReturnOutlook();
        $sentences[] = "{$returnOutlook['overdue']} locker key return(s) are overdue.";

        $almostFull = self::almostFullRooms(90.0);
        if ($almostFull !== []) {
            $sentences[] = count($almostFull).' room(s) are at 90% occupancy or higher.';
        }

        return $sentences;
    }

    /**
     * Round 3 PART E.4#1: the same facts as keyHighlights(), but as rows
     * for the Insights page's own card -- a colour (red/amber/blue) and,
     * where a filtered list exists, a link -- ordered most urgent first:
     * overdue key returns, almost-full rooms, termination notices,
     * candidacy expiring soon, possible releases next month. Never the
     * chat's own text (that stays keyHighlights()'s plain sentences, so
     * the two can be validated against each other independently).
     *
     * @return array<int, array{color: 'red'|'amber'|'blue', text: string, url: ?string}>
     */
    public static function keyHighlightsStructured(?string $gender = null, ?string $block = null): array
    {
        $rows = [];
        // Round 4 PART C.3: every "View ->" link carries the current scope through to the CGS landing page's own query parameter names (block/gender).
        $scopeQuery = array_filter(['block' => $block, 'gender' => $gender]);

        $returnOutlook = self::keyReturnOutlook($gender, $block);
        if ($returnOutlook['overdue'] > 0) {
            $rows[] = [
                'color' => 'red',
                'text' => "{$returnOutlook['overdue']} locker key return(s) are overdue.",
                'url' => route('workstation.cgs.operations', [...$scopeQuery, 'key_status' => 'return_pending']),
            ];
        }

        $almostFull = self::almostFullRooms(90.0, $gender, $block);
        if ($almostFull !== []) {
            $rows[] = [
                'color' => 'amber',
                'text' => count($almostFull).' room(s) are at 90% occupancy or higher.',
                'url' => route('workstation.cgs.operations', $scopeQuery),
            ];
        }

        $termination = self::terminationNoticesDetailed($gender, $block);
        if ($termination['count'] > 0) {
            $rows[] = [
                'color' => 'amber',
                'text' => "{$termination['count']} seat(s) are in a termination notice period.",
                'url' => route('workstation.cgs.operations', [...$scopeQuery, 'seat_status' => 'termination_notice']),
            ];
        }

        $expiringSoon = self::seatReleaseOutlook(3, $gender, $block)['candidacy_expiring']['count'];
        if ($expiringSoon > 0) {
            $rows[] = [
                'color' => 'blue',
                'text' => "{$expiringSoon} current seat holder(s) have a candidacy expiring within 3 months.",
                'url' => null,
            ];
        }

        $forecast = self::seatReleaseForecast(1, $gender, $block);
        $nextMonthReleases = $forecast[0]['releases'] ?? 0;
        if ($nextMonthReleases > 0) {
            $rows[] = [
                'color' => 'blue',
                'text' => "Up to {$nextMonthReleases} seat(s) may be released next month.",
                'url' => null,
            ];
        }

        if ($rows === []) {
            $rows[] = ['color' => 'blue', 'text' => 'Nothing needs attention right now.', 'url' => null];
        }

        return $rows;
    }

    /** Current seat holders (confirmed request), real and demo, resolved through StudentDirectory -- see its own class doc for why. @return \Illuminate\Support\Collection<int, \App\Modules\Chloe\Support\StudentIdentity> */
    protected static function currentSeatHolderIdentities(?string $gender = null, ?string $block = null): \Illuminate\Support\Collection
    {
        return self::scopedConfirmedRequests($gender, $block)
            ->get(['student_id', 'demo_profile_id'])
            ->map(fn (WorkstationRequest $r) => StudentDirectory::forOccupant($r->student_id, $r->demo_profile_id))
            ->filter();
    }

    /** Every current seat holder's candidacy_expiry_date, real (StudyCandidacy) and demo (DemoStudentProfile) alike -- the one place both are read side by side. @return \Illuminate\Support\Collection<int, Carbon> */
    protected static function currentSeatHolderCandidacyExpiries(?string $gender = null, ?string $block = null): \Illuminate\Support\Collection
    {
        return self::currentSeatHolderIdentities($gender, $block)
            ->map(function ($identity) {
                $date = $identity->user
                    ? StudyCandidacy::where('student_id', $identity->user->id)->where('status', StudyCandidacy::STATUS_ACTIVE)->value('candidacy_expiry_date')
                    : $identity->demoProfile?->candidacy_expiry_date;

                return $date ? Carbon::parse($date) : null;
            })
            ->filter();
    }

    // -------------------------------------------------------------
    // Round 5 PART B: one fact row per seat, no PII, for the Insights
    // page's client-side cross-filtering -- see Support\InsightsAggregator
    // (the PHP reference aggregation this is tested against) and
    // public/js/chloe-insights-aggregate.js (the real, client-side one).
    // Every bucket boundary below is copied from this class's own
    // existing bucket methods so the two can never silently drift apart.
    // -------------------------------------------------------------

    /**
     * @return array<int, array{seat_no:int, block:string, room:string, gender:string, status:string, notice:bool, occupied:bool, programme:?string, key:string, expiry:string, stay:string, release_month:string, release_source:string, return_due:string}>
     */
    public static function insightsFactRows(): array
    {
        return Workstation::with(['location', 'activeRequest.activeTerminationNotice', 'lockerKeysHistory'])
            ->get()
            ->map(fn (Workstation $seat) => self::insightsFactRow($seat))
            ->all();
    }

    protected static function insightsFactRow(Workstation $seat): array
    {
        $location = $seat->location;

        // Round 5 PART B: the task's own 'status' enum only has four
        // values -- Pending/Reserved (both 0 rows in every real snapshot
        // so far, both transient mid-selection states) fold into
        // 'available' since neither one is a committed occupant yet,
        // same as every other "not yet occupied" seat; Disabled is
        // relabelled 'maintenance' to match the rest of this page.
        $status = match ($seat->status) {
            Workstation::STATUS_DISABLED => 'maintenance',
            Workstation::STATUS_PENDING, Workstation::STATUS_RESERVED => 'available',
            default => $seat->status,
        };
        $isOccupied = $status === 'occupied';

        $activeRequest = $seat->activeRequest;
        $occupant = $activeRequest?->occupant();

        // An Awaiting Key Return seat has no activeRequest any more (it
        // was already released) -- the previous occupant is found the
        // same way WorkstationManagementController::presentSeat()
        // already does: the latest Return Pending key in this seat's
        // own history, falling back to the latest Lost key (found live
        // -- without this fallback, a seat whose only outstanding key
        // was marked Lost rather than left Return Pending resolved to
        // no identity at all, silently losing its programme).
        $outstandingKey = $seat->lockerKeysHistory
            ->filter(fn (LockerKey $k) => $k->status === LockerKey::STATUS_RETURN_PENDING)
            ->sortByDesc('requested_at')
            ->first()
            ?: $seat->lockerKeysHistory
                ->filter(fn (LockerKey $k) => $k->status === LockerKey::STATUS_LOST)
                ->sortByDesc('requested_at')
                ->first();
        $formerOccupant = (! $occupant && $outstandingKey) ? $outstandingKey->occupant() : null;
        $identity = $occupant ?: $formerOccupant;

        $programme = ($isOccupied || $status === 'awaiting_key_return') ? $identity?->programme : null;
        $notice = $isOccupied && $activeRequest?->activeTerminationNotice !== null;

        $representativeKey = $activeRequest?->lockerKey ?: $outstandingKey;

        // Same four-plus-none split as lockerKeyOverviewForSeatHolders(), one seat at a time.
        $key = match (true) {
            ! $identity => 'none',
            $representativeKey === null => 'not_requested',
            $representativeKey->status === LockerKey::STATUS_REQUESTED => 'pending_collection',
            $representativeKey->status === LockerKey::STATUS_COLLECTED => 'collected',
            $representativeKey->status === LockerKey::STATUS_RETURN_PENDING => 'return_pending',
            default => 'not_requested', // returned/cancelled/lost -- folded into not_requested, same as lockerKeyOverviewForSeatHolders().
        };

        $returnDue = 'none';
        if ($representativeKey && $representativeKey->status === LockerKey::STATUS_RETURN_PENDING && $representativeKey->return_due_at) {
            $dueAt = Carbon::parse($representativeKey->return_due_at);

            if ($dueAt->isPast()) {
                $returnDue = 'overdue';
            } else {
                $daysAway = now()->diffInDays($dueAt, false);
                $returnDue = $daysAway <= 7 ? 'due_7' : ($daysAway <= 14 ? 'due_8_14' : 'none');
            }
        }

        // Candidacy expiry -- only a currently occupied seat with a resolvable candidacy row; same boundaries as candidacyExpiryBuckets().
        $expiryDate = null;
        if ($isOccupied && $occupant) {
            $raw = $occupant->user
                ? StudyCandidacy::where('student_id', $occupant->user->id)->where('status', StudyCandidacy::STATUS_ACTIVE)->value('candidacy_expiry_date')
                : $occupant->demoProfile?->candidacy_expiry_date;
            $expiryDate = $raw ? Carbon::parse($raw) : null;
        }

        $expiry = 'none';
        if ($expiryDate) {
            $monthsAway = now()->startOfDay()->diffInMonths($expiryDate, false);
            $expiry = match (true) {
                $monthsAway <= 3 => '0_3',
                $monthsAway <= 6 => '3_6',
                $monthsAway <= 12 => '6_12',
                default => '12_plus',
            };
        }

        // Length of stay -- only a currently occupied seat with an actual request row; same boundaries as lengthOfStayBuckets().
        $stay = 'none';
        if ($isOccupied && $activeRequest) {
            $monthsHeld = Carbon::parse($activeRequest->requested_at)->diffInMonths(now());
            $stay = match (true) {
                $monthsHeld < 6 => 'lt6',
                $monthsHeld < 12 => '6_12',
                $monthsHeld < 18 => '12_18',
                default => '18_plus',
            };
        }

        // Release month/source -- only a currently occupied seat; the
        // earlier of candidacy expiry and an active termination
        // notice's vacate-by date. "A past expiry counts in the current
        // month" -- clamp a negative months-away to 0 rather than
        // reporting the actual (past) calendar month.
        //
        // Round 6 PART 2.1: no artificial cap here any more -- every
        // occupied seat with a resolvable date gets its REAL "YYYY-MM"
        // month, however far out. The page itself decides how many
        // months to show and where the "Later" cut-off falls (see
        // chloe-insights-aggregate.js's forecastMonths()); this class
        // has no business knowing that.
        $vacateByDate = ($isOccupied && $activeRequest?->activeTerminationNotice?->vacate_by_date)
            ? Carbon::parse($activeRequest->activeTerminationNotice->vacate_by_date)
            : null;

        $releaseMonth = 'none';
        $releaseSource = 'none';

        if ($isOccupied) {
            if ($expiryDate !== null && ($vacateByDate === null || $expiryDate->lte($vacateByDate))) {
                $earliest = $expiryDate;
                $releaseSource = 'candidacy';
            } elseif ($vacateByDate !== null) {
                $earliest = $vacateByDate;
                $releaseSource = 'termination';
            } else {
                $earliest = null;
            }

            if ($earliest !== null) {
                $now = now()->startOfMonth();
                $monthsAway = max(0, $now->diffInMonths($earliest->copy()->startOfMonth(), false));
                $releaseMonth = $now->copy()->addMonths($monthsAway)->format('Y-m');
            }
        }

        return [
            'seat_no' => $seat->id,
            'block' => $location->block,
            'room' => $location->room_code,
            'gender' => $location->gender,
            'status' => $status,
            'notice' => $notice,
            'occupied' => $isOccupied,
            'programme' => $programme,
            'key' => $key,
            'expiry' => $expiry,
            'stay' => $stay,
            'release_month' => $releaseMonth,
            'release_source' => $releaseSource,
            'return_due' => $returnDue,
            // Round 6 PART 2.1/PART 5.2: real URLs, computed once here
            // rather than reconstructed client-side from an id/code map.
            'seat_url' => route('workstation.cgs.seat', $seat->id),
            'room_url' => route('workstation.cgs.room', ['workstationLocation' => $location->id]),
        ];
    }

    /**
     * Meta for the Insights page's embedded JSON -- the room list (for
     * the Room dropdown, the capacity each room-occupancy bar needs, and
     * its own link) and the programme list ordered by count (for the
     * Programme dropdown) -- all derived from the SAME fact rows so they
     * can never disagree with what the rows themselves say.
     *
     * Round 6 PART 2.1: no fixed month list any more -- the forecast
     * chart computes its own time axis (current month to the last month
     * with a release, capped at 36, "Later" beyond that) from the rows'
     * real release_month values.
     *
     * @param  array<int, array<string, mixed>>  $rows  WorkstationStatistics::insightsFactRows()'s own return value
     * @return array{generated_at: string, almost_full_percent: float, rooms: array<int, array{id: int, code: string, block: string, gender: string, capacity: int, url: string}>, programmes: array<int, string>}
     */
    public static function insightsMeta(array $rows): array
    {
        $rooms = WorkstationLocation::query()
            ->withCount('workstations')
            ->orderBy('block')->orderBy('room_code')
            ->get()
            ->map(fn (WorkstationLocation $l) => [
                'id' => $l->id,
                'code' => $l->room_code,
                'block' => $l->block,
                'gender' => $l->gender,
                'capacity' => $l->workstations_count,
                'url' => route('workstation.cgs.room', ['workstationLocation' => $l->id]),
            ])
            ->all();

        $programmes = collect($rows)
            ->pluck('programme')
            ->filter()
            ->countBy()
            ->sortDesc()
            ->keys()
            ->all();

        return [
            'generated_at' => now('Asia/Kuala_Lumpur')->format('j M Y, g:ia'),
            'almost_full_percent' => (float) config('assistant.almost_full_percent', 90),
            'rooms' => $rooms,
            'programmes' => $programmes,
        ];
    }

    /**
     * Round 8 PART 5.1/1.1: seat counts per status, per block -- built
     * from the SAME insightsFactRows() the "How many seats are in each
     * state?" grouped bar chart reads, so the assistant's
     * seat_status_by_block lookup can never disagree with that chart by
     * construction (one source, two readers).
     *
     * @return array<string, array{total: int, occupied: int, available: int, maintenance: int, awaiting_key_return: int}>
     */
    public static function seatStatusByBlock(): array
    {
        $rows = collect(self::insightsFactRows());

        return $rows->groupBy('block')->map(fn ($blockRows) => [
            'total' => $blockRows->count(),
            'occupied' => $blockRows->where('status', 'occupied')->count(),
            'available' => $blockRows->where('status', 'available')->count(),
            'maintenance' => $blockRows->where('status', 'maintenance')->count(),
            'awaiting_key_return' => $blockRows->where('status', 'awaiting_key_return')->count(),
        ])->all();
    }

    /**
     * Round 8 PART 5.1 follow-up (found live): the Keys grouped-bar
     * chart's own "seat holders" population is every fact row with a
     * key record (key !== 'none') -- which, by design, INCLUDES a seat
     * already Awaiting Key Return (its former occupant's key is still
     * outstanding). lockerKeyOverviewForSeatHolders() above answers a
     * narrower, pre-existing question ("current CONFIRMED occupants
     * only") and silently drops that same Return Pending population --
     * calling it from the new key_status_overview lookup gave an answer
     * that disagreed with the chart (0 Return Pending versus the
     * chart's 3). This reads the exact same fact rows the chart reads,
     * so the two can never disagree again.
     *
     * @return array{not_requested: int, pending_collection: int, collected: int, return_pending: int}
     */
    public static function keyStatusOverviewFromFactRows(?string $gender = null, ?string $block = null): array
    {
        $rows = collect(self::insightsFactRows())
            ->when($gender, fn ($r) => $r->where('gender', $gender))
            ->when($block, fn ($r) => $r->where('block', $block))
            ->where('key', '!=', 'none');

        return [
            'not_requested' => $rows->where('key', 'not_requested')->count(),
            'pending_collection' => $rows->where('key', 'pending_collection')->count(),
            'collected' => $rows->where('key', 'collected')->count(),
            'return_pending' => $rows->where('key', 'return_pending')->count(),
        ];
    }
}
