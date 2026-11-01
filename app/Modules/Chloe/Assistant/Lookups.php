<?php

namespace App\Modules\Chloe\Assistant;

use App\Modules\Chloe\Models\LockerKey;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationLocation;
use App\Modules\Chloe\Models\WorkstationRequest;
use App\Modules\Chloe\Support\StudentDirectory;
use App\Modules\Chloe\Support\WorkstationEligibility;
use App\Modules\Chloe\Support\WorkstationStatistics;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use Illuminate\Support\Carbon;

/**
 * PART 3. Every lookup: who may use it, what it needs, and the prose
 * answer it builds -- read-only throughout, every number read live (no
 * caching, no snapshot), using the exact same queries/classes the rest of
 * the module already uses (WorkstationStatistics, WorkstationEligibility,
 * StudentDirectory). Nothing here ever changes a row.
 *
 * Each `run*()` method returns:
 *   ['outcome' => 'answered', 'text' => <prose, no footer>, 'links' => [...], 'resultCount' => int]
 *   ['outcome' => 'asked_back', 'text' => <the question to ask back>]
 * Assistant\Pipeline appends the "Based on live data..."/"Interpreted as..."
 * footer to every 'answered' result except the ineligibility message (see
 * available_seats below), which is a complete answer on its own.
 */
final class Lookups
{
    /** @return array<int, string> */
    public static function forRole(string $role): array
    {
        $names = [];

        foreach (AssistantRoles::set($role) as $plain) {
            $names = array_merge($names, match ($plain) {
                Role::STUDENT => [...self::studentNames(), ...CandidacyAssistant::lookupNames('student')],
                AssistantRoles::APPROVER => CandidacyAssistant::lookupNames('approver'),
                default => [...self::cgsNames(), ...CandidacyAssistant::lookupNames('cgs')],
            });
        }

        return array_values(array_unique($names));
    }

    public static function isAllowed(string $name, string $role): bool
    {
        return in_array($name, self::forRole($role), true);
    }

    /** @return array<int, string> */
    protected static function studentNames(): array
    {
        // Round 4 PART B.2: block_comparison is available to BOTH roles -- a student's own copy is scoped to their own gender's rooms only, same as available_seats.
        return ['my_workstation', 'my_locker_key', 'available_seats', 'block_comparison'];
    }

    /** @return array<int, string> */
    protected static function cgsNames(): array
    {
        return [
            'seat_availability', 'overall_stats', 'seat_lookup', 'student_lookup',
            'keys_pending_collection', 'keys_pending_return', 'awaiting_key_return_seats',
            'termination_notices', 'maintenance_seats', 'room_occupancy',
            'candidacy_expiring', 'releases_this_week',
            // PART F.4.
            'seat_release_outlook', 'almost_full_rooms', 'insights_summary',
            // Round 4 PART B.2.
            'block_comparison',
            // Round 8 PART 5.1: the SAME numbers the Insights page's own
            // charts show, reusing the same WorkstationStatistics
            // methods so the two can never disagree by construction.
            'programme_breakdown', 'gender_comparison', 'key_status_overview',
            'candidacy_expiry_distribution', 'length_of_stay_distribution',
            'seat_status_by_block', 'return_outlook',
            // Router catalogue: period-parameterised forecast, distinct
            // from seat_release_outlook's own fixed "next 3 months"
            // summary -- reuses WorkstationStatistics::seatReleaseForecast(),
            // the same function the Insights page's own forecast chart
            // already reads, so the two can never disagree.
            'release_forecast',
        ];
    }

    /**
     * Short descriptions for the model to choose from -- it never sees
     * more than this plus the question itself. Parameter schemas here are
     * a HINT to the model only; every value is re-validated independently
     * below before it is ever used (PrivacyMasker placeholders included).
     *
     * @return array<string, array{description: string, parameters: array<string, string>}>
     */
    public static function definitionsForRole(string $role): array
    {
        $all = [
            'my_workstation' => ['description' => "The student's own block, room, seat, loan-valid-until date, and any termination notice.", 'parameters' => []],
            'my_locker_key' => ['description' => "The student's own locker key status and dates.", 'parameters' => []],
            'available_seats' => ['description' => "Available seat counts per room, in the student's own gender's rooms only.", 'parameters' => []],
            'block_comparison' => ['description' => 'Compares Block N2 and Block J2 side by side -- which is fuller, and the seat counts for each.', 'parameters' => []],

            'seat_availability' => ['description' => 'Available/Occupied/Maintenance/Awaiting-Key-Return counts per room.', 'parameters' => ['block' => 'optional, "N2" or "J2"', 'room_code' => 'optional']],
            'overall_stats' => ['description' => 'Total seat counts across the whole catalogue.', 'parameters' => []],
            'seat_lookup' => ['description' => 'Status, occupant and key status for one seat number.', 'parameters' => ['seat_number' => 'integer 1-315']],
            'student_lookup' => ['description' => "One student's seat, key status and loan-valid-until date, by 8-digit student ID.", 'parameters' => ['student_id' => '8 digits']],
            'keys_pending_collection' => ['description' => 'How many locker keys are waiting to be collected.', 'parameters' => []],
            'keys_pending_return' => ['description' => 'How many locker keys are waiting to be returned, and how many are overdue.', 'parameters' => []],
            'awaiting_key_return_seats' => ['description' => 'How many seats are Awaiting Key Return.', 'parameters' => []],
            'termination_notices' => ['description' => 'How many seats are currently in a termination notice period.', 'parameters' => []],
            'maintenance_seats' => ['description' => 'How many seats are under maintenance, and which ones.', 'parameters' => []],
            'room_occupancy' => ['description' => 'Occupancy percentage per room.', 'parameters' => []],
            'candidacy_expiring' => ['description' => "How many current seat holders' candidacy expires within N months.", 'parameters' => ['months' => 'optional integer 1-12, default 3']],
            'releases_this_week' => ['description' => 'How many seats were released in the last 7 days.', 'parameters' => []],

            'seat_release_outlook' => ['description' => 'Which seats are likely to become free in the next few months: termination notices, Awaiting Key Return, and candidacy expiring.', 'parameters' => []],
            'release_forecast' => ['description' => 'How many seats may be released in ONE specific month, quarter or year (a forecast maximum, same source as the Insights page forecast chart) -- not a fixed "next 3 months" summary like seat_release_outlook.', 'parameters' => ['period' => 'required: a month as "YYYY-MM" (e.g. "2026-11"), or a quarter/year/other span as "YYYY-MM..YYYY-MM" (e.g. "2027-01..2027-03")']],
            'almost_full_rooms' => ['description' => 'Which rooms are almost full (occupancy at or above the almost-full threshold).', 'parameters' => []],
            'insights_summary' => ['description' => 'The Insights page key highlights, as a few short sentences.', 'parameters' => []],

            // Round 8 PART 5.1 -- same numbers as the Insights page's own charts.
            'programme_breakdown' => ['description' => 'Top programmes by share of current seat holders, e.g. "Master of Science in Information Technology (Full-Time): 23 seat holders (14.6%)". Optional block or gender to narrow it.', 'parameters' => ['block' => 'optional, "N2" or "J2"', 'gender' => 'optional, "male" or "female"']],
            'gender_comparison' => ['description' => 'Male rooms versus female rooms: seat counts and occupancy percentage for each, e.g. to answer "male versus female occupancy". Optional block.', 'parameters' => ['block' => 'optional, "N2" or "J2"']],
            'key_status_overview' => ['description' => 'Locker key status counts among current seat holders: not requested, pending collection, collected, return pending. Optional block or gender.', 'parameters' => ['block' => 'optional, "N2" or "J2"', 'gender' => 'optional, "male" or "female"']],
            'candidacy_expiry_distribution' => ['description' => 'Current seat holders by how soon their candidacy expires: 0-3, 3-6, 6-12, over 12 months. Optional block or gender.', 'parameters' => ['block' => 'optional, "N2" or "J2"', 'gender' => 'optional, "male" or "female"']],
            'length_of_stay_distribution' => ['description' => 'Current seat holders by time since allocation: under 6, 6-12, 12-18, over 18 months. Optional block or gender.', 'parameters' => ['block' => 'optional, "N2" or "J2"', 'gender' => 'optional, "male" or "female"']],
            'seat_status_by_block' => ['description' => 'Occupied/Available/Maintenance/Awaiting-Key-Return counts for EACH block side by side, e.g. "seats by status per block".', 'parameters' => []],
            'return_outlook' => ['description' => 'Locker keys due back: due within 7 days, due in 8-14 days, overdue. Optional block or gender.', 'parameters' => ['block' => 'optional, "N2" or "J2"', 'gender' => 'optional, "male" or "female"']],
        ];

        $all += CandidacyAssistant::definitions();

        return array_intersect_key($all, array_flip(self::forRole($role)));
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{outcome: string, text: string, links?: array, resultCount?: ?int}
     */
    public static function run(string $name, array $parameters, User $user, string $role): array
    {
        if (str_starts_with($name, 'cand_')) {
            return CandidacyAssistant::run($name, $parameters, $user);
        }

        return match ($name) {
            'my_workstation' => self::myWorkstation($user),
            'my_locker_key' => self::myLockerKey($user),
            'available_seats' => self::availableSeats($user),
            'block_comparison' => self::blockComparison($user, $role),
            'seat_availability' => self::seatAvailability($parameters),
            'overall_stats' => self::overallStats(),
            'seat_lookup' => self::seatLookup($parameters),
            'student_lookup' => self::studentLookup($parameters),
            'keys_pending_collection' => self::keysPendingCollection(),
            'keys_pending_return' => self::keysPendingReturn(),
            'awaiting_key_return_seats' => self::awaitingKeyReturnSeats(),
            'termination_notices' => self::terminationNotices(),
            'maintenance_seats' => self::maintenanceSeats(),
            'room_occupancy' => self::roomOccupancy(),
            'candidacy_expiring' => self::candidacyExpiring($parameters),
            'releases_this_week' => self::releasesThisWeek(),
            'seat_release_outlook' => self::seatReleaseOutlook(),
            'almost_full_rooms' => self::almostFullRooms(),
            'insights_summary' => self::insightsSummary(),
            'programme_breakdown' => self::programmeBreakdown($parameters),
            'gender_comparison' => self::genderComparison($parameters),
            'key_status_overview' => self::keyStatusOverview($parameters),
            'candidacy_expiry_distribution' => self::candidacyExpiryDistribution($parameters),
            'length_of_stay_distribution' => self::lengthOfStayDistribution($parameters),
            'seat_status_by_block' => self::seatStatusByBlock(),
            'return_outlook' => self::returnOutlook($parameters),
            'release_forecast' => self::releaseForecast($parameters),
            default => ['outcome' => 'asked_back', 'text' => "I don't recognise that question. Could you rephrase it?"],
        };
    }

    // -------------------------------------------------------------
    // Student lookups -- always the logged-in user; any student
    // identifier the model returned for these three is ignored.
    // -------------------------------------------------------------

    protected static function myWorkstation(User $user): array
    {
        $request = WorkstationRequest::with(['workstation.location', 'activeTerminationNotice'])
            ->where('student_id', $user->id)
            ->where('status', WorkstationRequest::STATUS_CONFIRMED)
            ->latest('requested_at')
            ->first();

        if (! $request) {
            return ['outcome' => 'answered', 'text' => 'You do not currently hold a workstation.', 'links' => [
                ['label' => 'Select a workstation', 'url' => route('workstation.select')],
            ], 'resultCount' => 0];
        }

        $loanValidUntil = StudyCandidacy::where('student_id', $user->id)->value('candidacy_expiry_date');
        $seat = $request->workstation;
        $notice = $request->activeTerminationNotice;

        $text = "Your workstation is Seat {$seat->seat_code}, {$seat->location->room_code} (Block {$seat->location->block}).";

        if ($loanValidUntil) {
            $text .= ' Loan valid until '.Carbon::parse($loanValidUntil)->format('j M Y').'.';
        }

        if ($notice) {
            $text .= ' A termination notice is active: vacate by '.$notice->vacate_by_date->format('j M Y').'.';
        }

        return ['outcome' => 'answered', 'text' => $text, 'links' => [
            ['label' => 'My Workstation', 'url' => route('workstation.select')],
        ], 'resultCount' => 1];
    }

    protected static function myLockerKey(User $user): array
    {
        $request = WorkstationRequest::where('student_id', $user->id)
            ->where('status', WorkstationRequest::STATUS_CONFIRMED)
            ->latest('requested_at')
            ->first();

        $key = $request
            ? LockerKey::where('workstation_request_id', $request->id)->latest('requested_at')->first()
            : LockerKey::where('student_id', $user->id)->where('status', LockerKey::STATUS_RETURN_PENDING)->latest('requested_at')->first();

        if (! $key) {
            return ['outcome' => 'answered', 'text' => 'You have no locker key on record.', 'links' => [
                ['label' => 'My Workstation', 'url' => route('workstation.select')],
            ], 'resultCount' => 0];
        }

        $labels = [
            LockerKey::STATUS_REQUESTED => 'Pending Collection',
            LockerKey::STATUS_COLLECTED => 'Collected',
            LockerKey::STATUS_RETURN_PENDING => 'Return Pending',
            LockerKey::STATUS_RETURNED => 'Returned',
            LockerKey::STATUS_CANCELLED => 'Cancelled',
            LockerKey::STATUS_LOST => 'Lost',
        ];

        $text = 'Your locker key status: '.($labels[$key->status] ?? ucfirst($key->status)).'.';
        $text .= ' Requested '.$key->requested_at->format('j M Y').'.';

        if ($key->status === LockerKey::STATUS_REQUESTED) {
            $text .= ' Collect by '.$key->collectByDate()->format('j M Y').'.';
        }
        if ($key->return_due_at) {
            $text .= ' Return due '.$key->return_due_at->format('j M Y').'.';
        }

        return ['outcome' => 'answered', 'text' => $text, 'links' => [
            ['label' => 'My Workstation', 'url' => route('workstation.select')],
        ], 'resultCount' => 1];
    }

    protected static function availableSeats(User $user): array
    {
        $identity = StudentDirectory::fromUser($user);

        if (! WorkstationEligibility::isEligible($identity)) {
            return ['outcome' => 'answered', 'text' => WorkstationEligibility::INELIGIBLE_MESSAGE, 'links' => [], 'resultCount' => null, 'skipFooter' => true];
        }

        if (! $identity->gender) {
            return ['outcome' => 'answered', 'text' => 'Your gender information is missing. Please contact the Centre for Graduate Studies (CGS).', 'links' => [], 'resultCount' => null, 'skipFooter' => true];
        }

        $rooms = WorkstationStatistics::availableSeatsByGender($identity->gender);
        $total = array_sum(array_column($rooms, 'available'));

        $lines = collect($rooms)->map(fn ($r) => "{$r['room_code']}: {$r['available']}")->implode(', ');
        $text = "Available seats in your gender's rooms: {$total} total ({$lines}).";

        return ['outcome' => 'answered', 'text' => $text, 'links' => [
            ['label' => 'Select a workstation', 'url' => route('workstation.select')],
        ], 'resultCount' => $total];
    }

    /**
     * Round 4 PART B.2: N2 vs J2 side by side -- available to BOTH roles.
     * A student is scoped to their own gender's rooms only (same rule as
     * available_seats above); CGS sees the full block totals plus the
     * male/female split within each. Always leads with the COMPARISON
     * (which block is fuller, by how much) before any numbers -- never a
     * per-room list.
     */
    protected static function blockComparison(User $user, string $role): array
    {
        $onlyGender = null;

        if ($role === Role::STUDENT) {
            $identity = StudentDirectory::fromUser($user);

            if (! $identity->gender) {
                return ['outcome' => 'answered', 'text' => 'Your gender information is missing. Please contact the Centre for Graduate Studies (CGS).', 'links' => [], 'resultCount' => null, 'skipFooter' => true];
            }

            $onlyGender = $identity->gender;
        }

        $comparison = WorkstationStatistics::blockComparison($onlyGender);
        $n2 = $comparison['N2'];
        $j2 = $comparison['J2'];

        if ($n2['occupancy_percent'] === $j2['occupancy_percent']) {
            $lead = "Block N2 and Block J2 are equally full, both at {$n2['occupancy_percent']}% occupied.";
        } else {
            $fuller = $n2['occupancy_percent'] > $j2['occupancy_percent'] ? $n2 : $j2;
            $lesser = $fuller['block'] === 'N2' ? $j2 : $n2;
            $diff = round(abs($n2['occupancy_percent'] - $j2['occupancy_percent']), 1);
            $lead = "Block {$fuller['block']} is fuller than Block {$lesser['block']} by {$diff} percentage point(s) ({$fuller['occupancy_percent']}% vs {$lesser['occupancy_percent']}%).";
        }

        $text = $lead
            ." N2: {$n2['total']} seats, {$n2['occupied']} occupied, {$n2['available']} available, {$n2['maintenance']} maintenance."
            ." J2: {$j2['total']} seats, {$j2['occupied']} occupied, {$j2['available']} available, {$j2['maintenance']} maintenance.";

        if ($onlyGender === null) {
            $text .= " Male: N2 {$n2['male']['occupied']}/{$n2['male']['total']}, J2 {$j2['male']['occupied']}/{$j2['male']['total']}."
                ." Female: N2 {$n2['female']['occupied']}/{$n2['female']['total']}, J2 {$j2['female']['occupied']}/{$j2['female']['total']}.";
        }

        $link = $role === Role::STUDENT
            ? ['label' => 'Select a workstation', 'url' => route('workstation.select')]
            : ['label' => 'Workstation Management', 'url' => route('workstation.cgs.operations')];

        return ['outcome' => 'answered', 'text' => $text, 'links' => [$link], 'resultCount' => $n2['total'] + $j2['total']];
    }

    // -------------------------------------------------------------
    // CGS lookups
    // -------------------------------------------------------------

    protected static function seatAvailability(array $parameters): array
    {
        $block = isset($parameters['block']) ? strtoupper((string) $parameters['block']) : null;
        $roomCode = isset($parameters['room_code']) ? (string) $parameters['room_code'] : null;

        if ($block !== null && ! in_array($block, ['N2', 'J2'], true)) {
            return ['outcome' => 'asked_back', 'text' => 'Which block do you mean, N2 or J2?'];
        }
        if ($roomCode !== null && ! WorkstationLocation::where('room_code', $roomCode)->exists()) {
            return ['outcome' => 'asked_back', 'text' => 'Which room do you mean? Please give the room code.'];
        }

        $rooms = WorkstationStatistics::seatAvailability($block, $roomCode);

        if ($rooms === []) {
            return ['outcome' => 'answered', 'text' => 'No rooms matched.', 'links' => [
                ['label' => 'Workstation Management', 'url' => route('workstation.cgs.operations')],
            ], 'resultCount' => 0];
        }

        $lines = collect($rooms)->map(fn ($r) => "{$r['room_code']} (Block {$r['block']}): {$r['available']} Available, {$r['occupied']} Occupied, {$r['maintenance']} Maintenance, {$r['awaiting_key_return']} Awaiting Key Return")->implode('; ');

        return ['outcome' => 'answered', 'text' => $lines, 'links' => [
            ['label' => 'Workstation Management', 'url' => route('workstation.cgs.operations', array_filter(['block' => $block, 'location_id' => null]))],
        ], 'resultCount' => count($rooms)];
    }

    protected static function overallStats(): array
    {
        $s = WorkstationStatistics::overall();
        $text = "Total {$s['total']} seats: {$s['available']} Available, {$s['occupied']} Occupied, {$s['maintenance']} Maintenance, {$s['awaiting_key_return']} Awaiting Key Return. "
            ."{$s['key_pending_collection']} locker keys pending collection, {$s['key_return_overdue']} overdue for return.";

        return ['outcome' => 'answered', 'text' => $text, 'links' => [
            ['label' => 'Workstation Management', 'url' => route('workstation.cgs.operations')],
        ], 'resultCount' => $s['total']];
    }

    protected static function seatLookup(array $parameters): array
    {
        $seatNumber = self::toInt($parameters['seat_number'] ?? null);

        if ($seatNumber === null || $seatNumber < 1 || $seatNumber > 315) {
            return ['outcome' => 'asked_back', 'text' => 'Which seat number do you mean (1 to 315)?'];
        }

        $seat = Workstation::with(['location', 'activeRequest.student', 'activeRequest.demoProfile', 'activeRequest.lockerKey', 'activeRequest.activeTerminationNotice'])->find($seatNumber);

        if (! $seat) {
            return ['outcome' => 'answered', 'text' => "Seat {$seatNumber} does not exist.", 'links' => [], 'resultCount' => 0];
        }

        $statusLabels = ['available' => 'Available', 'pending' => 'Pending', 'occupied' => 'Occupied', 'disabled' => 'Maintenance', 'reserved' => 'Reserved', 'awaiting_key_return' => 'Awaiting Key Return'];
        $text = "Seat {$seat->seat_code}, {$seat->location->room_code} (Block {$seat->location->block}): ".($statusLabels[$seat->status] ?? $seat->status).'.';

        $occupant = $seat->activeRequest?->occupant();

        if ($occupant) {
            $text .= " Occupant: {$occupant->name} ({$occupant->studentId}).";
            $key = $seat->activeRequest->lockerKey;
            if ($key) {
                $text .= ' Key status: '.ucfirst(str_replace('_', ' ', $key->status)).'.';
            }
            $notice = $seat->activeRequest->activeTerminationNotice;
            if ($notice) {
                $text .= ' Vacate by '.$notice->vacate_by_date->format('j M Y').'.';
            }
        }

        return ['outcome' => 'answered', 'text' => $text, 'links' => [
            ['label' => 'Seat Details', 'url' => route('workstation.cgs.seat', $seat->id)],
        ], 'resultCount' => 1];
    }

    protected static function studentLookup(array $parameters): array
    {
        $studentId = (string) ($parameters['student_id'] ?? '');

        if (PrivacyMasker::isEmailPlaceholder($studentId) || str_contains($studentId, '@')) {
            return ['outcome' => 'asked_back', 'text' => 'Please use a Student ID or seat number.'];
        }
        if (! preg_match('/^\d{8}$/', $studentId)) {
            return ['outcome' => 'asked_back', 'text' => 'Which student ID do you mean (8 digits)?'];
        }

        $identity = StudentDirectory::findByStudentId($studentId);

        if (! $identity) {
            return ['outcome' => 'answered', 'text' => "No student found with ID {$studentId}.", 'links' => [], 'resultCount' => 0];
        }

        $request = WorkstationRequest::with('workstation.location')
            ->when($identity->user, fn ($q) => $q->where('student_id', $identity->user->id))
            ->when($identity->demoProfile, fn ($q) => $q->where('demo_profile_id', $identity->demoProfile->id))
            ->where('status', WorkstationRequest::STATUS_CONFIRMED)
            ->latest('requested_at')
            ->first();

        if (! $request) {
            return ['outcome' => 'answered', 'text' => "{$identity->name} ({$studentId}) does not currently hold a workstation.", 'links' => [], 'resultCount' => 1];
        }

        $seat = $request->workstation;
        $key = LockerKey::where('workstation_request_id', $request->id)->latest('requested_at')->first();
        $loanValidUntil = $identity->user ? StudyCandidacy::where('student_id', $identity->user->id)->value('candidacy_expiry_date') : null;

        $text = "{$identity->name} ({$studentId}): Seat {$seat->seat_code}, {$seat->location->room_code} (Block {$seat->location->block}).";
        if ($key) {
            $text .= ' Key status: '.ucfirst(str_replace('_', ' ', $key->status)).'.';
        }
        if ($loanValidUntil) {
            $text .= ' Loan valid until '.Carbon::parse($loanValidUntil)->format('j M Y').'.';
        }

        return ['outcome' => 'answered', 'text' => $text, 'links' => [
            ['label' => 'Seat Details', 'url' => route('workstation.cgs.seat', $seat->id)],
        ], 'resultCount' => 1];
    }

    protected static function keysPendingCollection(): array
    {
        $total = WorkstationStatistics::keysPendingCollection();

        return ['outcome' => 'answered', 'text' => "{$total} locker key(s) pending collection.", 'links' => [
            ['label' => 'Filtered list', 'url' => route('workstation.cgs.operations', ['key_status' => 'requested'])],
        ], 'resultCount' => $total];
    }

    protected static function keysPendingReturn(): array
    {
        $s = WorkstationStatistics::keysPendingReturn();
        $text = "{$s['total']} locker key(s) pending return, {$s['overdue']} overdue. The most overdue cases are at the top of the filtered list, soonest due next.";

        return ['outcome' => 'answered', 'text' => $text, 'links' => [
            ['label' => 'Filtered list', 'url' => route('workstation.cgs.operations', ['key_status' => 'return_pending'])],
        ], 'resultCount' => $s['total']];
    }

    protected static function awaitingKeyReturnSeats(): array
    {
        $total = WorkstationStatistics::awaitingKeyReturnSeats();

        return ['outcome' => 'answered', 'text' => "{$total} seat(s) Awaiting Key Return.", 'links' => [
            ['label' => 'Filtered list', 'url' => route('workstation.cgs.operations', ['seat_status' => 'awaiting_key_return'])],
        ], 'resultCount' => $total];
    }

    protected static function terminationNotices(): array
    {
        $total = WorkstationStatistics::terminationNoticeSeats();

        return ['outcome' => 'answered', 'text' => "{$total} seat(s) currently in a termination notice period.", 'links' => [
            ['label' => 'Filtered list', 'url' => route('workstation.cgs.operations', ['seat_status' => 'termination_notice'])],
        ], 'resultCount' => $total];
    }

    protected static function maintenanceSeats(): array
    {
        $byRoom = WorkstationStatistics::maintenanceSeatsByRoom();
        $total = array_sum(array_map('count', $byRoom));

        if ($total === 0) {
            return ['outcome' => 'answered', 'text' => 'No seats are currently under maintenance.', 'links' => [
                ['label' => 'Filtered list', 'url' => route('workstation.cgs.operations', ['seat_status' => 'disabled'])],
            ], 'resultCount' => 0];
        }

        $lines = collect($byRoom)->map(fn ($seats, $room) => "{$room}: Seat ".implode(', ', $seats))->implode('; ');

        return ['outcome' => 'answered', 'text' => "{$total} seat(s) under maintenance -- {$lines}.", 'links' => [
            ['label' => 'Filtered list', 'url' => route('workstation.cgs.operations', ['seat_status' => 'disabled'])],
        ], 'resultCount' => $total];
    }

    protected static function roomOccupancy(): array
    {
        $rooms = WorkstationStatistics::roomOccupancy();
        $lines = collect($rooms)->map(fn ($r) => "{$r['room_code']} (Block {$r['block']}): {$r['occupied_percent']}%")->implode('; ');

        return ['outcome' => 'answered', 'text' => "Occupancy by room -- {$lines}.", 'links' => [
            ['label' => 'Workstation Management', 'url' => route('workstation.cgs.operations')],
        ], 'resultCount' => count($rooms)];
    }

    protected static function candidacyExpiring(array $parameters): array
    {
        $months = isset($parameters['months']) ? self::toInt($parameters['months']) : 3;
        $months ??= 3;

        if ($months < 1 || $months > 12) {
            return ['outcome' => 'asked_back', 'text' => 'How many months should I check (1 to 12)?'];
        }

        $count = WorkstationStatistics::candidacyExpiringCount($months);

        return ['outcome' => 'answered', 'text' => "{$count} current seat holder(s) have a candidacy expiring within {$months} month(s). Appeals may extend candidacy by up to 12 months.", 'links' => [
            ['label' => 'Workstation Management', 'url' => route('workstation.cgs.operations')],
        ], 'resultCount' => $count];
    }

    protected static function releasesThisWeek(): array
    {
        $count = WorkstationStatistics::releasesThisWeek();

        return ['outcome' => 'answered', 'text' => "{$count} seat(s) released in the last 7 days.", 'links' => [
            ['label' => 'History', 'url' => route('workstation.cgs.export.history')],
        ], 'resultCount' => $count];
    }

    /** C1: three non-personal release categories, never merged into one number -- see WorkstationStatistics::seatReleaseOutlook()'s own doc for why. */
    protected static function seatReleaseOutlook(): array
    {
        $o = WorkstationStatistics::seatReleaseOutlook(3);

        $lines = [];
        $lines[] = "Termination notices: {$o['termination']['count']} seat(s)".
            ($o['termination']['earliest_vacate_by'] ? ", earliest vacate-by {$o['termination']['earliest_vacate_by']}." : '.').
            ' Released automatically after their vacate-by date.';
        $lines[] = "Awaiting Key Return: {$o['awaiting_key_return']['count']} seat(s). Available again once CGS marks the key as returned.";
        $byMonth = collect($o['candidacy_expiring']['by_month'])->map(fn ($c, $m) => "{$m}: {$c}")->implode(', ');
        $lines[] = "Candidacy expiring within 3 months: {$o['candidacy_expiring']['count']} seat holder(s)".($byMonth ? " ({$byMonth})" : '').
            '. Their loan ends at expiry unless an appeal extends it (up to 12 months in total), so treat this as the maximum.';

        return ['outcome' => 'answered', 'text' => implode("\n", $lines), 'links' => [
            ['label' => 'Termination notices', 'url' => route('workstation.cgs.operations', ['seat_status' => 'termination_notice'])],
            ['label' => 'Awaiting Key Return', 'url' => route('workstation.cgs.operations', ['seat_status' => 'awaiting_key_return'])],
        ], 'resultCount' => $o['termination']['count'] + $o['awaiting_key_return']['count'] + $o['candidacy_expiring']['count']];
    }

    /**
     * Router catalogue: a period-parameterised forecast. The period
     * arrives pre-validated by Pipeline (PeriodExtractor::validate() --
     * "a model-supplied period is checked, never trusted"); this method
     * never re-parses free text, only the normalised ['kind','year',
     * 'month'|'quarter'] shape. Sums WorkstationStatistics::
     * seatReleaseForecast()'s own per-month rows over the period's
     * months-ahead range -- the exact same function and numbers the
     * Insights forecast chart reads, so the two can never disagree.
     */
    protected static function releaseForecast(array $parameters): array
    {
        // fromString() accepts the router's "2026-11" / "2027-01..2027-03" form or an already-validated array, and re-validates either way.
        $period = PeriodExtractor::fromString($parameters['period'] ?? null);

        if ($period === null) {
            return ['outcome' => 'asked_back', 'text' => 'Which month, quarter or year would you like the forecast for?'];
        }

        [$firstMonthsAhead, $lastMonthsAhead] = PeriodExtractor::monthsAheadRange($period);

        if ($firstMonthsAhead < 0) {
            return ['outcome' => 'asked_back', 'text' => 'That period has already passed -- which upcoming month, quarter or year would you like instead?'];
        }

        $monthly = WorkstationStatistics::seatReleaseForecast($lastMonthsAhead + 1);
        $slice = array_slice($monthly, $firstMonthsAhead, $lastMonthsAhead - $firstMonthsAhead + 1);

        $releases = array_sum(array_column($slice, 'releases'));
        $fromCandidacy = array_sum(array_column($slice, 'from_candidacy'));
        $fromTermination = array_sum(array_column($slice, 'from_termination'));
        $label = PeriodExtractor::label($period);

        $text = "{$releases} seat(s) may be released in {$label}: {$fromCandidacy} from candidacy expiry, {$fromTermination} from termination notices. This is a maximum, not a prediction -- an appeal can extend a candidacy by up to 12 months.";

        return ['outcome' => 'answered', 'text' => $text, 'links' => [
            ['label' => 'Insights forecast', 'url' => route('workstation.cgs.insights')],
        ], 'resultCount' => $releases];
    }

    /** C2: config('assistant.almost_full_percent') is the threshold, same number the Insights page would use for emphasis. */
    protected static function almostFullRooms(): array
    {
        $threshold = (float) config('assistant.almost_full_percent', 90);
        $rooms = WorkstationStatistics::almostFullRooms($threshold);

        if ($rooms === []) {
            $fullest = WorkstationStatistics::fullestRoom();
            $text = $fullest
                ? "No room is at {$threshold}% yet. The fullest is {$fullest['room_code']} at {$fullest['occupied_percent']}%."
                : "No room is at {$threshold}% yet.";

            return ['outcome' => 'answered', 'text' => $text, 'links' => [], 'resultCount' => 0];
        }

        $lines = collect($rooms)->map(fn ($r) => "{$r['room_code']}: {$r['occupied_percent']}% occupied, {$r['available']} available")->implode('; ');
        $links = collect($rooms)->take(5)->map(fn ($r) => ['label' => $r['room_code'], 'url' => route('workstation.cgs.room', $r['id'])])->all();

        return ['outcome' => 'answered', 'text' => $lines, 'links' => $links, 'resultCount' => count($rooms)];
    }

    /** Round 8 PART 5.1: optional gender parameter, shared by every new lookup below. */
    protected static function genderParam(array $parameters): ?string
    {
        if (! isset($parameters['gender'])) {
            return null;
        }
        $gender = strtolower((string) $parameters['gender']);

        return in_array($gender, ['male', 'female'], true) ? $gender : null;
    }

    protected static function scopeOrAskBack(array $parameters): array
    {
        if (isset($parameters['block'])) {
            $block = strtoupper((string) $parameters['block']);
            if (! in_array($block, ['N2', 'J2'], true)) {
                return ['error' => ['outcome' => 'asked_back', 'text' => 'Which block do you mean, N2 or J2?']];
            }
        } else {
            $block = null;
        }

        return ['block' => $block, 'gender' => self::genderParam($parameters)];
    }

    protected static function programmeBreakdown(array $parameters): array
    {
        $scope = self::scopeOrAskBack($parameters);
        if (isset($scope['error'])) {
            return $scope['error'];
        }

        $rows = WorkstationStatistics::programmeBreakdown(8, $scope['gender'], $scope['block']);
        $total = array_sum(array_column($rows, 'count'));

        if ($total === 0) {
            return ['outcome' => 'answered', 'text' => 'No current seat holders match that scope.', 'links' => [
                ['label' => 'Insights', 'url' => route('workstation.cgs.insights')],
            ], 'resultCount' => 0];
        }

        $lines = collect($rows)->map(fn ($r) => "{$r['label']}: {$r['count']} seat holder(s) (".round($r['count'] / $total * 100, 1).'%)')->implode('; ');

        return ['outcome' => 'answered', 'text' => $lines, 'links' => [
            ['label' => 'Insights', 'url' => route('workstation.cgs.insights')],
        ], 'resultCount' => count($rows)];
    }

    protected static function genderComparison(array $parameters): array
    {
        $scope = self::scopeOrAskBack($parameters);
        if (isset($scope['error'])) {
            return $scope['error'];
        }

        $rows = WorkstationStatistics::occupancyByGender($scope['block']);
        $lines = collect($rows)->map(fn ($r) => ucfirst($r['gender'])." rooms: {$r['occupied']}/{$r['total']} occupied ({$r['occupied_percent']}%), {$r['available']} available")->implode('; ');

        return ['outcome' => 'answered', 'text' => $lines, 'links' => [
            ['label' => 'Insights', 'url' => route('workstation.cgs.insights')],
        ], 'resultCount' => count($rows)];
    }

    protected static function keyStatusOverview(array $parameters): array
    {
        $scope = self::scopeOrAskBack($parameters);
        if (isset($scope['error'])) {
            return $scope['error'];
        }

        $o = WorkstationStatistics::keyStatusOverviewFromFactRows($scope['gender'], $scope['block']);
        $total = array_sum($o);
        $text = "Among {$total} current seat holder(s): {$o['not_requested']} Not Requested, {$o['pending_collection']} Pending Collection, {$o['collected']} Collected, {$o['return_pending']} Return Pending.";

        return ['outcome' => 'answered', 'text' => $text, 'links' => [
            ['label' => 'Insights', 'url' => route('workstation.cgs.insights')],
        ], 'resultCount' => $total];
    }

    protected static function candidacyExpiryDistribution(array $parameters): array
    {
        $scope = self::scopeOrAskBack($parameters);
        if (isset($scope['error'])) {
            return $scope['error'];
        }

        $rows = WorkstationStatistics::candidacyExpiryBuckets($scope['gender'], $scope['block']);
        $lines = collect($rows)->map(fn ($r) => "{$r['label']}: {$r['count']}")->implode('; ');
        $total = array_sum(array_column($rows, 'count'));

        return ['outcome' => 'answered', 'text' => "Candidacy expiry of current seat holders -- {$lines}.", 'links' => [
            ['label' => 'Insights', 'url' => route('workstation.cgs.insights')],
        ], 'resultCount' => $total];
    }

    protected static function lengthOfStayDistribution(array $parameters): array
    {
        $scope = self::scopeOrAskBack($parameters);
        if (isset($scope['error'])) {
            return $scope['error'];
        }

        $rows = WorkstationStatistics::lengthOfStayBuckets($scope['gender'], $scope['block']);
        $lines = collect($rows)->map(fn ($r) => "{$r['label']}: {$r['count']}")->implode('; ');
        $total = array_sum(array_column($rows, 'count'));

        return ['outcome' => 'answered', 'text' => "Length of stay of current seat holders -- {$lines}.", 'links' => [
            ['label' => 'Insights', 'url' => route('workstation.cgs.insights')],
        ], 'resultCount' => $total];
    }

    /** Same numbers as the Insights page's "How many seats are in each state?" grouped bar chart -- built from the same fact rows (WorkstationStatistics::seatStatusByBlock()). */
    protected static function seatStatusByBlock(): array
    {
        $byBlock = WorkstationStatistics::seatStatusByBlock();
        $lines = collect($byBlock)->map(fn ($s, $block) => "Block {$block}: {$s['occupied']} Occupied, {$s['available']} Available, {$s['maintenance']} Maintenance, {$s['awaiting_key_return']} Awaiting Key Return (of {$s['total']})")->implode('; ');
        $total = array_sum(array_column($byBlock, 'total'));

        return ['outcome' => 'answered', 'text' => $lines, 'links' => [
            ['label' => 'Insights', 'url' => route('workstation.cgs.insights')],
        ], 'resultCount' => $total];
    }

    protected static function returnOutlook(array $parameters): array
    {
        $scope = self::scopeOrAskBack($parameters);
        if (isset($scope['error'])) {
            return $scope['error'];
        }

        $o = WorkstationStatistics::keyReturnOutlook($scope['gender'], $scope['block']);
        $text = "{$o['due_7_days']} key(s) due within 7 days, {$o['due_14_days']} due in 8-14 days, {$o['overdue']} overdue.";

        return ['outcome' => 'answered', 'text' => $text, 'links' => [
            ['label' => 'Filtered list', 'url' => route('workstation.cgs.operations', ['key_status' => 'return_pending'])],
        ], 'resultCount' => $o['due_7_days'] + $o['due_14_days'] + $o['overdue']];
    }

    protected static function insightsSummary(): array
    {
        $lines = WorkstationStatistics::keyHighlights();

        return ['outcome' => 'answered', 'text' => implode("\n", $lines), 'links' => [
            ['label' => 'Insights', 'url' => route('workstation.cgs.insights')],
        ], 'resultCount' => count($lines)];
    }

    protected static function toInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value))) {
            return (int) $value;
        }

        return null;
    }
}
