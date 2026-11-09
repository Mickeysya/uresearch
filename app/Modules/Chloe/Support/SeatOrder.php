<?php

namespace App\Modules\Chloe\Support;

use Illuminate\Contracts\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * THE one ordering rule for seats, used by every list that shows them: block, then room code, then the seat number as a NUMBER (3, 20, 100, 1001),
 * never as text ("100" before "20" before "3"). seat_code is a string column, so the database orders by CAST(seat_code AS UNSIGNED) and PHP by
 * (int) seat_code. A seat code that is not purely numeric (say "12A") sorts by its leading number, then by its text, then by id, so two seats never
 * trade places between a first load and a live refresh.
 *
 * The live-update JavaScript does not order anything: it swaps the server-rendered region or updates counters in place, so it cannot reorder a list.
 */
final class SeatOrder
{
    /** The number a seat code stands for ("0100" and "100" are both 100; a code with no leading digit is 0). */
    public static function number(mixed $seatCode): int
    {
        return (int) $seatCode;
    }

    /** Order one room's seats (a query on workstations, optionally table-qualified for joins). */
    public static function query(EloquentBuilder|QueryBuilder $query, string $table = ''): EloquentBuilder|QueryBuilder
    {
        $col = ($table !== '' ? $table.'.' : '').'seat_code';
        $id = ($table !== '' ? $table.'.' : '').'id';

        return $query->orderByRaw("CAST({$col} AS UNSIGNED) ASC")->orderBy($col)->orderBy($id);
    }

    /**
     * The CGS landing page's result list: ONE continuous order over every page, by seat number alone (1, 2, 3 ... 315), whatever the block or room.
     * Block, then room code, then the seat text, then id only break a tie (the same number in two rooms), so the order is always the same.
     * Applied to the database query (before it is read) and again to the finished list (before it is cut into pages).
     */
    public static function queryAcrossRooms(EloquentBuilder $query): EloquentBuilder
    {
        $location = fn (string $column) => \App\Modules\Chloe\Models\WorkstationLocation::query()->select($column)->whereColumn('workstation_locations.id', 'workstations.workstation_location_id');

        return $query->orderByRaw('CAST(workstations.seat_code AS UNSIGNED) ASC')
            ->orderBy($location('block'))->orderBy($location('room_code'))
            ->orderBy('workstations.seat_code')->orderBy('workstations.id');
    }

    /**
     * Same rule on a collection.
     *
     * @param  callable(mixed):array{0:string,1:string,2:mixed,3:mixed}  $parts  returns [block, room code, seat code, tie-break id]
     */
    public static function sortAcrossRooms(Collection $items, callable $parts): Collection
    {
        return $items->sort(function ($a, $b) use ($parts) {
            [$ba, $ra, $sa, $ia] = $parts($a);
            [$bb, $rb, $sb, $ib] = $parts($b);

            return [self::number($sa), $ba, $ra, (string) $sa, (int) $ia] <=> [self::number($sb), $bb, $rb, (string) $sb, (int) $ib];
        })->values();
    }

    /**
     * Order a collection of seats or seat rows by block, room code, seat number (one room at a time, or grouped by room).
     *
     * @param  callable(mixed):array{0:string,1:string,2:mixed,3:mixed}  $parts  returns [block, room code, seat code, tie-break id]
     */
    public static function sort(Collection $items, callable $parts): Collection
    {
        return $items->sort(function ($a, $b) use ($parts) {
            [$ba, $ra, $sa, $ia] = $parts($a);
            [$bb, $rb, $sb, $ib] = $parts($b);

            return [$ba, $ra, self::number($sa), (string) $sa, (int) $ia] <=> [$bb, $rb, self::number($sb), (string) $sb, (int) $ib];
        })->values();
    }
}
