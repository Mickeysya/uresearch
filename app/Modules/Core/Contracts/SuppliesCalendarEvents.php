<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Models\User;
use Carbon\CarbonInterface;

/**
 * Optional companion to WorkflowModule, for modules that own dates.
 *
 * The calendar is a Core screen, but every date worth putting on it lives in
 * a teammate's table -- an RPD deadline, a travel window, a conference, a
 * hardbound cut-off. Core must not name those classes (the same reason
 * SuppliesAttendance exists), so a module hands its own dates over instead
 * and Core only lays them out.
 *
 * Discovered exactly like ProvidesLinks: implement it on a workflow class you
 * already register and ModuleRegistry picks it up. No new wiring.
 *
 * SCOPE YOUR OWN QUERY. Core cannot know which rows this user is allowed to
 * see, so the supplier is responsible for it -- a student gets their own
 * dates, staff get whatever their role legitimately covers, and returning []
 * is always a valid answer.
 */
interface SuppliesCalendarEvents
{
    /**
     * Dated things this user should see between two dates, inclusive.
     *
     * @return array<int, array{
     *     date: CarbonInterface,
     *     title: string,
     *     tone?: string,
     *     meta?: string|null,
     *     url?: string|null
     * }>
     */
    public function calendarEvents(User $user, CarbonInterface $from, CarbonInterface $to): array;
}
