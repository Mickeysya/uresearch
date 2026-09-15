<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Services\ModuleRegistry;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * The Calendar page — a month at a time, built from dates that already exist.
 *
 * Core owns the grid and nothing else. Every date on it comes from a module
 * implementing SuppliesCalendarEvents, because every date worth showing lives
 * in a teammate's table and Core must not name those classes.
 *
 * There is no `events` table and no reason for one: an RPD deadline, a travel
 * window and a conference date are already recorded, and a second copy of them
 * would only be a second thing to keep in sync.
 */
class CalendarController extends Controller
{
    public function index(Request $request, ModuleRegistry $registry)
    {
        $month = $this->monthFrom($request->query('month'));

        // The grid spans whole weeks, so it reaches into the neighbouring
        // months -- and an event landing on one of those spill days has to be
        // fetched too, or the last row of the grid renders empty when it
        // should not.
        $gridStart = $month->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY);
        $gridEnd = $month->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);

        $events = collect($registry->calendarEventsFor($request->user(), $gridStart, $gridEnd))
            ->groupBy(fn (array $e) => Carbon::parse($e['date'])->toDateString());

        // The list beside the grid answers "what is next", which a month view
        // on its own never does -- open it in March and December's deadline is
        // invisible. Looks 12 months ahead regardless of the month shown.
        $upcoming = collect($registry->calendarEventsFor(
            $request->user(),
            Carbon::today(),
            Carbon::today()->addYear(),
        ))->take(8);

        return view('core::calendar.index', [
            'month' => $month,
            'gridStart' => $gridStart,
            'gridEnd' => $gridEnd,
            'events' => $events,
            'upcoming' => $upcoming,
            'today' => Carbon::today(),
            'prevMonth' => $month->copy()->subMonthNoOverflow()->format('Y-m'),
            'nextMonth' => $month->copy()->addMonthNoOverflow()->format('Y-m'),
        ]);
    }

    /**
     * ?month=YYYY-MM, or this month.
     *
     * Anything unparseable falls back rather than throwing: the value is in a
     * URL people share and edit by hand, and a 500 is a poor answer to a typo.
     */
    protected function monthFrom(?string $value): Carbon
    {
        if ($value && preg_match('/^\d{4}-\d{2}$/', $value)) {
            try {
                return Carbon::createFromFormat('Y-m-d', $value.'-01')->startOfDay();
            } catch (\Throwable) {
                // fall through
            }
        }

        return Carbon::today()->startOfMonth();
    }
}
