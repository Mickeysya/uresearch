<?php

namespace App\Modules\Chloe\Services;

use App\Modules\Chloe\Models\LockerKey;
use App\Modules\Chloe\Models\StudentGender;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationHistory;
use App\Modules\Chloe\Models\WorkstationPendingSelection;
use App\Modules\Chloe\Models\WorkstationRequest;
use App\Modules\Chloe\Models\WorkstationTerminationNotice;
use App\Modules\Chloe\Notifications\WorkstationSelectionConfirmed;
use App\Modules\Chloe\Notifications\WorkstationSelectionPendingConfirmation;
use App\Modules\Chloe\Notifications\WorkstationStatusNotice;
use App\Modules\Chloe\Support\ScheduledDelivery;
use App\Modules\Chloe\Support\WorkingDays;
use App\Modules\Core\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * The only code that changes a Workstation's status and creates a
 * WorkstationRequest together — the same job WorkflowEngine does for
 * applications::status, except this module has no approval chain to route
 * through, so it is its own small engine rather than a stage on that one.
 *
 * The race this exists to close: two students submit for the same seat at
 * nearly the same instant. `lockForUpdate()` inside a transaction serialises
 * the two requests — whichever commits first wins the seat, and the second
 * re-reads a row that already says "occupied"/"pending" and is rejected.
 * Without the lock, both requests could read "available" before either
 * writes, and both would succeed.
 */
class WorkstationAllocator
{
    public const PENDING_SELECTION_MINUTES = 5;

    /** Days after a non-automatic release/reassign before a Collected key's return is due. */
    public const RETURN_PERIOD_DAYS = 7;

    /** Term of Use clause 9/16's "five (5) working days" -- named so the assistant's guidance text (Assistant\GuidanceLibrary) can never drift from this number. */
    public const TERMINATION_NOTICE_WORKING_DAYS = 5;

    public const REASON_OTHER = 'Other';

    public const REASONS_REASSIGN = ['Room maintenance or repair', 'Allocation error', 'Special case approved by CGS', self::REASON_OTHER];

    public const REASONS_ASSIGN = ['Student unable to use the system', 'System error', 'Special case approved by CGS', self::REASON_OTHER];

    public const REASONS_MAINTENANCE = ['Repair needed', 'Cleaning or renovation', 'Equipment fault', self::REASON_OTHER];

    public const REASONS_RELEASE_HOLD = ['Key lost', 'Lock replaced', self::REASON_OTHER];

    public const REASONS_STUDENT_RETURN = ['Changing to another workstation', 'No longer need a workstation', 'Completed study', self::REASON_OTHER];

    /** The one reason System ever uses (STEP 7) -- not student/CGS input, so it skips the lists above. */
    public const REASON_STUDY_STATUS_CHANGED = 'Study status changed';

    // PART 3: the Term of Use termination flow replaced the old
    // 14/7/3-day Release Notice pipeline (REASONS_RELEASE_CGS, with its
    // retired "Student completed or dismissed" reason) entirely. Every
    // termination reason below is its own named constant, not a bare
    // string, so Support\WorkstationTerminationTriggers (the automatic
    // side) and the CGS Release dropdown (the manual side) can never
    // drift apart by typo.
    public const REASON_TERMINATION_DEFERMENT = 'Deferment';

    public const REASON_TERMINATION_CHANGE_TO_COURSEWORK = 'Change programme to Coursework';

    public const REASON_TERMINATION_CHANGE_TO_PART_TIME = 'Change programme mode to Part-Time';

    public const REASON_TERMINATION_GRADUATED = 'Graduated';

    public const REASON_TERMINATION_WITHDRAWN = 'Withdrawn';

    public const REASON_TERMINATION_DISMISSAL = 'Dismissal';

    public const REASON_TERMINATION_DEREGISTERED = 'De-registered';

    public const REASON_TERMINATION_DISCIPLINARY = 'Under disciplinary action';

    public const REASON_TERMINATION_ABSENT = 'Absent without prior approval';

    public const REASON_TERMINATION_OTHER = 'Other reason deemed appropriate by the University';

    /** Automatic-only (PART 2) -- never offered in the CGS Release dropdown, only used by the daily job / expiry check. */
    public const REASON_TERMINATION_EXPIRY = 'Loan period ended (candidacy expiry)';

    /** CGS's "Termination (5 working days notice)" dropdown group -- everything above except the automatic-only expiry reason. */
    public const REASONS_TERMINATION = [
        self::REASON_TERMINATION_DEFERMENT,
        self::REASON_TERMINATION_CHANGE_TO_COURSEWORK,
        self::REASON_TERMINATION_CHANGE_TO_PART_TIME,
        self::REASON_TERMINATION_GRADUATED,
        self::REASON_TERMINATION_WITHDRAWN,
        self::REASON_TERMINATION_DISMISSAL,
        self::REASON_TERMINATION_DEREGISTERED,
        self::REASON_TERMINATION_DISCIPLINARY,
        self::REASON_TERMINATION_ABSENT,
        self::REASON_TERMINATION_OTHER,
    ];

    /** CGS's "Immediate correction" dropdown group -- releases right away, no notice period. */
    public const REASONS_RELEASE_IMMEDIATE = ['System error', 'Allocation error', 'Student has vacated'];

    /** The reason list for "Release now" on a seat already in its notice period. */
    public const REASONS_RELEASE_NOW = ['Student has vacated', self::REASON_OTHER];

    public const REASONS_WITHDRAW_TERMINATION = ['Issued by mistake', 'Student situation changed', self::REASON_OTHER];

    /** No email for this one specifically (PART 3 #4) -- the student has already gone, telling them again serves no one. */
    public const REASON_RELEASE_NO_EMAIL = 'Student has vacated';

    /**
     * Step 1 of the student flow: Select doesn't hand over the seat, it
     * starts a 5-minute hold and emails a confirm link (Email 1). The seat
     * goes to Pending, not Occupied, so it disappears from every other
     * student's list immediately without anyone actually owning it yet.
     *
     * @return array{started: bool, selection: ?WorkstationPendingSelection}
     */
    public function beginPendingSelection(User $student, Workstation $seat): array
    {
        $result = DB::transaction(function () use ($student, $seat) {
            $locked = Workstation::whereKey($seat->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== Workstation::STATUS_AVAILABLE) {
                return ['started' => false, 'selection' => null];
            }

            $locked->update(['status' => Workstation::STATUS_PENDING]);

            $selection = WorkstationPendingSelection::create([
                'workstation_id' => $seat->id,
                'student_id' => $student->id,
                'token' => Str::random(64),
                'status' => WorkstationPendingSelection::STATUS_PENDING,
                'expires_at' => now()->addMinutes(self::PENDING_SELECTION_MINUTES),
            ]);

            WorkstationHistory::record(
                seat: $locked,
                actorType: WorkstationHistory::ACTOR_STUDENT,
                actorName: $student->name,
                action: WorkstationHistory::ACTION_SEAT_SELECTED,
                beforeState: Workstation::STATUS_AVAILABLE,
                afterState: Workstation::STATUS_PENDING,
                student: $student,
            );

            return ['started' => true, 'selection' => $selection];
        });

        if ($result['started']) {
            $student->notify(new WorkstationSelectionPendingConfirmation($result['selection']));
        }

        return $result;
    }

    /**
     * Step 2: the confirm link. Only this method ever turns a Pending seat
     * into an Occupied one — clicking the link is what actually grants it,
     * not clicking Select. `already` tells the caller which non-success
     * case it was (expired vs. already used) so the result page can say so.
     *
     * @return array{confirmed: bool, already: ?string, workstation: ?Workstation}
     */
    public function confirmPendingSelection(WorkstationPendingSelection $selection): array
    {
        $result = DB::transaction(function () use ($selection) {
            $locked = WorkstationPendingSelection::whereKey($selection->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== WorkstationPendingSelection::STATUS_PENDING) {
                return ['confirmed' => false, 'already' => $locked?->status, 'workstation' => null];
            }

            if ($locked->expires_at->isPast()) {
                $expiredSeat = Workstation::whereKey($locked->workstation_id)->lockForUpdate()->first();

                $locked->update(['status' => WorkstationPendingSelection::STATUS_EXPIRED]);
                $expiredSeat->update(['status' => Workstation::STATUS_AVAILABLE]);

                WorkstationHistory::record(
                    seat: $expiredSeat,
                    actorType: WorkstationHistory::ACTOR_SYSTEM,
                    actorName: 'System',
                    action: WorkstationHistory::ACTION_PENDING_EXPIRED,
                    beforeState: Workstation::STATUS_PENDING,
                    afterState: Workstation::STATUS_AVAILABLE,
                    student: $locked->student,
                );

                return ['confirmed' => false, 'already' => 'expired', 'workstation' => null];
            }

            $seat = Workstation::whereKey($locked->workstation_id)->lockForUpdate()->first();
            $seat->update(['status' => Workstation::STATUS_OCCUPIED]);

            $locked->update([
                'status' => WorkstationPendingSelection::STATUS_CONFIRMED,
                'confirmed_at' => now(),
            ]);

            WorkstationRequest::create([
                'workstation_id' => $seat->id,
                'student_id' => $locked->student_id,
                'status' => WorkstationRequest::STATUS_CONFIRMED,
                'requested_at' => now(),
                // PART 4 #3: clicking this link is the acceptance -- there
                // is deliberately no separate checkbox.
                'terms_accepted_at' => now(),
                'terms_edition' => 'First Edition',
            ]);

            WorkstationHistory::record(
                seat: $seat,
                actorType: WorkstationHistory::ACTOR_STUDENT,
                actorName: $locked->student->name,
                action: WorkstationHistory::ACTION_SEAT_CONFIRMED_TERMS,
                beforeState: Workstation::STATUS_PENDING,
                afterState: Workstation::STATUS_OCCUPIED,
                student: $locked->student,
            );

            return ['confirmed' => true, 'already' => null, 'workstation' => $seat, 'student' => $locked->student];
        });

        if ($result['confirmed']) {
            $result['student']->notify(new WorkstationSelectionConfirmed($result['workstation']));
        }

        return $result;
    }

    /**
     * The safety net behind the 5-minute hold: a student who never clicks
     * the link must not keep the seat forever. Called by the scheduled
     * `workstation:release-expired-selections` command (every minute) AND
     * inline whenever seats are listed or a token is used, so an expired
     * hold is cleared immediately rather than waiting for the next minute's
     * run. Idempotent — each row is only ever flipped once, from the
     * lockForUpdate re-read, so running it from two places at once is safe.
     */
    public function releaseExpiredSelections(): int
    {
        $expiredIds = WorkstationPendingSelection::where('status', WorkstationPendingSelection::STATUS_PENDING)
            ->where('expires_at', '<=', now())
            ->pluck('id');

        $released = 0;

        foreach ($expiredIds as $id) {
            DB::transaction(function () use ($id, &$released) {
                $locked = WorkstationPendingSelection::whereKey($id)->lockForUpdate()->first();

                if (! $locked || $locked->status !== WorkstationPendingSelection::STATUS_PENDING || ! $locked->expires_at->isPast()) {
                    return;
                }

                $seat = Workstation::whereKey($locked->workstation_id)->lockForUpdate()->first();

                $locked->update(['status' => WorkstationPendingSelection::STATUS_EXPIRED]);
                $seat->update(['status' => Workstation::STATUS_AVAILABLE]);

                WorkstationHistory::record(
                    seat: $seat,
                    actorType: WorkstationHistory::ACTOR_SYSTEM,
                    actorName: 'System',
                    action: WorkstationHistory::ACTION_PENDING_EXPIRED,
                    beforeState: Workstation::STATUS_PENDING,
                    afterState: Workstation::STATUS_AVAILABLE,
                    student: $locked->student,
                );

                $released++;
            });
        }

        return $released;
    }

    /**
     * The one place a seat's locker key gets handled when its occupant's
     * WorkstationRequest ends (release, reassign-away-from) — every other
     * key status just stays as it is (STEP 4's "Not Requested: nothing").
     * $isAutomaticRelease decides return_due_at's two formulas and whether
     * the separate return reminder is pre-marked sent (the 14/7/3-day
     * notices already covered that case).
     *
     * @return array{kind: ?string, return_due_date: ?string} for the caller's email's key paragraph
     */
    protected function handleKeyOnRelease(
        WorkstationRequest $request,
        Carbon $releaseDate,
        bool $isAutomaticRelease,
        string $actorType,
        string $actorName,
    ): array {
        $lockerKey = LockerKey::where('workstation_request_id', $request->id)
            ->latest('requested_at')
            ->first();

        if (! $lockerKey) {
            return ['kind' => null, 'return_due_date' => null];
        }

        if ($lockerKey->status === LockerKey::STATUS_REQUESTED) {
            $lockerKey->update(['status' => LockerKey::STATUS_CANCELLED]);

            WorkstationHistory::record(
                seat: $request->workstation,
                actorType: $actorType,
                actorName: $actorName,
                action: WorkstationHistory::ACTION_KEY_CANCELLED,
                beforeState: LockerKey::STATUS_REQUESTED,
                afterState: LockerKey::STATUS_CANCELLED,
                student: $request->occupant()?->asUserLike(),
            );

            return ['kind' => 'pending', 'return_due_date' => null];
        }

        if ($lockerKey->status === LockerKey::STATUS_COLLECTED) {
            $returnDue = $isAutomaticRelease
                ? $releaseDate->copy()
                : $releaseDate->copy()->addDays(self::RETURN_PERIOD_DAYS);

            $lockerKey->update([
                'status' => LockerKey::STATUS_RETURN_PENDING,
                'return_due_at' => $returnDue,
                'return_reminder_sent_at' => $isAutomaticRelease ? now() : null,
            ]);

            WorkstationHistory::record(
                seat: $request->workstation,
                actorType: $actorType,
                actorName: $actorName,
                action: WorkstationHistory::ACTION_KEY_RETURN_PENDING,
                beforeState: LockerKey::STATUS_COLLECTED,
                afterState: LockerKey::STATUS_RETURN_PENDING,
                student: $request->occupant()?->asUserLike(),
            );

            return ['kind' => 'collected', 'return_due_date' => $returnDue->format('j M Y')];
        }

        return ['kind' => null, 'return_due_date' => null];
    }

    protected function actorName(string $actorType, ?User $actor): string
    {
        return $actorType === WorkstationHistory::ACTOR_SYSTEM ? 'System' : ($actor?->name ?? 'Unknown');
    }

    /**
     * STEP 2 #1 / PART 3 #3d, #3e, #4. Ends a confirmed request: seat ->
     * Available (or Awaiting Key Return), request -> released, the key
     * handled per STEP 4, History written, any active termination notice
     * on this request closed out (whoever actually ended the loan --
     * student self-Return during the notice period, CGS "Release now",
     * or the daily job's vacate-ended auto-release), the student notified
     * per the STEP 6 table. Email/bell is suppressed for actor System
     * (PART 3 #3d: no email on the automatic vacate-ended release) and for
     * the CGS reason "Student has vacated" (PART 3 #4: telling a student
     * who has already left serves no one).
     *
     * $historyAction lets the daily job write
     * ACTION_SEAT_RELEASED_VACATE_ENDED instead of the generic
     * ACTION_SEAT_RELEASED, so History reads correctly for that one case
     * without every other caller having to know the constant exists.
     */
    public function releaseSeat(
        WorkstationRequest $request,
        string $actorType,
        ?User $actor,
        string $reason,
        ?string $remark = null,
        bool $isAutomaticRelease = false,
        ?string $historyAction = null,
    ): void {
        if ($actorType === WorkstationHistory::ACTOR_STUDENT && ! in_array($reason, self::REASONS_STUDENT_RETURN, true)) {
            throw new InvalidArgumentException("Invalid reason for a student return: {$reason}");
        }
        if ($actorType === WorkstationHistory::ACTOR_CGS
            && ! in_array($reason, self::REASONS_RELEASE_IMMEDIATE, true)
            && ! in_array($reason, self::REASONS_RELEASE_NOW, true)) {
            throw new InvalidArgumentException("Invalid reason for a CGS release: {$reason}");
        }
        $result = DB::transaction(function () use ($request, $actorType, $actor, $reason, $remark, $isAutomaticRelease, $historyAction) {
            $locked = WorkstationRequest::whereKey($request->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== WorkstationRequest::STATUS_CONFIRMED) {
                throw new RuntimeException('This workstation request is not active.');
            }

            $seat = Workstation::whereKey($locked->workstation_id)->lockForUpdate()->first();

            $locked->update(['status' => WorkstationRequest::STATUS_RELEASED, 'released_at' => now()]);

            $actorName = $this->actorName($actorType, $actor);
            $occupant = $locked->occupant();

            // The key's own fate decides the seat's: a Collected key still
            // out there means the seat cannot be offered to anyone until
            // it comes back (PART 1) -- so this has to run BEFORE the
            // seat's own status is set and its History row written, not
            // after.
            $key = $this->handleKeyOnRelease($locked, now(), $isAutomaticRelease, $actorType, $actorName);
            $newStatus = $key['kind'] === 'collected' ? Workstation::STATUS_AWAITING_KEY_RETURN : Workstation::STATUS_AVAILABLE;

            $seat->update(['status' => $newStatus]);

            $this->closeActiveTerminationNotice($locked);

            WorkstationHistory::record(
                seat: $seat,
                actorType: $actorType,
                actorName: $actorName,
                action: $historyAction ?? WorkstationHistory::ACTION_SEAT_RELEASED,
                reason: $reason,
                remark: $remark,
                beforeState: Workstation::STATUS_OCCUPIED,
                afterState: $newStatus,
                student: $occupant?->asUserLike(),
            );

            return ['seat' => $seat, 'occupant' => $occupant, 'key' => $key];
        });

        $suppress = $actorType === WorkstationHistory::ACTOR_SYSTEM || $reason === self::REASON_RELEASE_NO_EMAIL;

        if ($suppress) {
            return;
        }

        $kind = $actorType === WorkstationHistory::ACTOR_STUDENT
            ? WorkstationStatusNotice::RETURNED
            : WorkstationStatusNotice::RELEASED;

        $occupant = $result['occupant'];

        $occupant?->notify(new WorkstationStatusNotice($result['seat'], $kind, [
            'reason' => $reason,
            'key_paragraph' => $result['key']['kind'],
            'return_due_date' => $result['key']['return_due_date'],
        ], $occupant?->name));
    }

    /**
     * PART 3 #3d/#4: whoever ends a loan that was mid-notice ends the
     * notice with it -- the badge and banner must disappear the moment
     * the seat is actually released, not linger until something else
     * notices. Called from inside releaseSeat()'s and reassignSeat()'s
     * own transactions, on the already-locked request.
     */
    protected function closeActiveTerminationNotice(WorkstationRequest $request): void
    {
        WorkstationTerminationNotice::where('workstation_request_id', $request->id)
            ->where('status', WorkstationTerminationNotice::STATUS_ACTIVE)
            ->update(['status' => WorkstationTerminationNotice::STATUS_RELEASED]);
    }

    /**
     * PART 3 #3. Starts the 5-working-day notice -- seat stays Occupied,
     * only History/the notice row/the email+bell happen here. Called by
     * CGS (Release, "Termination" reason group) and by the daily job's
     * trigger detection (Support\WorkstationTerminationTriggers), never
     * directly by a student. Idempotent against a second automatic
     * trigger firing for the same request on a later run: an already-active
     * notice is left alone rather than restarting its 5-day clock.
     */
    public function startTermination(
        WorkstationRequest $request,
        string $actorType,
        ?User $actor,
        string $reason,
        ?string $remark = null,
    ): WorkstationTerminationNotice {
        if (! in_array($reason, self::REASONS_TERMINATION, true) && $reason !== self::REASON_TERMINATION_EXPIRY) {
            throw new InvalidArgumentException("Invalid termination reason: {$reason}");
        }

        $notice = DB::transaction(function () use ($request, $actorType, $actor, $reason, $remark) {
            $locked = WorkstationRequest::whereKey($request->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== WorkstationRequest::STATUS_CONFIRMED) {
                throw new RuntimeException('This workstation request is not active.');
            }

            $existing = WorkstationTerminationNotice::where('workstation_request_id', $locked->id)
                ->where('status', WorkstationTerminationNotice::STATUS_ACTIVE)
                ->first();

            if ($existing) {
                return $existing;
            }

            $noticeDate = Carbon::now('Asia/Kuala_Lumpur')->startOfDay();
            $vacateBy = WorkingDays::add($noticeDate, self::TERMINATION_NOTICE_WORKING_DAYS);

            $notice = WorkstationTerminationNotice::create([
                'workstation_request_id' => $locked->id,
                'reason' => $reason,
                'notice_date' => $noticeDate,
                'vacate_by_date' => $vacateBy,
                'status' => WorkstationTerminationNotice::STATUS_ACTIVE,
            ]);

            $seat = $locked->workstation;
            $actorName = $this->actorName($actorType, $actor);
            $occupant = $locked->occupant();

            WorkstationHistory::record(
                seat: $seat,
                actorType: $actorType,
                actorName: $actorName,
                action: WorkstationHistory::ACTION_TERMINATION_NOTICE_ISSUED,
                reason: $reason,
                remark: trim(($remark ? $remark.' — ' : '').'Vacate by '.$vacateBy->format('j M Y')),
                student: $occupant?->asUserLike(),
            );

            return $notice;
        });

        $occupant = $request->occupant();

        $occupant?->notify(ScheduledDelivery::apply(new WorkstationStatusNotice($request->workstation, WorkstationStatusNotice::TERMINATION_NOTICE, [
            'reason' => $notice->reason,
            'notice_date' => $notice->notice_date->format('j M Y'),
            'vacate_by_date' => $notice->vacate_by_date->format('j M Y'),
            'terms_link' => route('workstation.terms'),
        ], $occupant?->name)));

        return $notice;
    }

    /**
     * PART 3 #4: cancels an active notice -- the seat stays Occupied (it
     * never left), the student is told they can carry on.
     */
    public function withdrawTermination(
        WorkstationTerminationNotice $notice,
        ?User $actor,
        string $reason,
        ?string $remark = null,
    ): void {
        if (! in_array($reason, self::REASONS_WITHDRAW_TERMINATION, true)) {
            throw new InvalidArgumentException("Invalid reason to withdraw a termination: {$reason}");
        }

        $result = DB::transaction(function () use ($notice, $actor, $reason, $remark) {
            $locked = WorkstationTerminationNotice::whereKey($notice->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== WorkstationTerminationNotice::STATUS_ACTIVE) {
                throw new RuntimeException('This termination notice is no longer active.');
            }

            $locked->update([
                'status' => WorkstationTerminationNotice::STATUS_WITHDRAWN,
                'withdrawn_reason' => $reason,
                'withdrawn_at' => now(),
            ]);

            $request = $locked->workstationRequest;
            $seat = $request->workstation;
            $actorName = $this->actorName(WorkstationHistory::ACTOR_CGS, $actor);
            $occupant = $request->occupant();

            WorkstationHistory::record(
                seat: $seat,
                actorType: WorkstationHistory::ACTOR_CGS,
                actorName: $actorName,
                action: WorkstationHistory::ACTION_TERMINATION_NOTICE_WITHDRAWN,
                reason: $reason,
                remark: $remark,
                student: $occupant?->asUserLike(),
            );

            return ['seat' => $seat, 'occupant' => $occupant, 'notice' => $locked];
        });

        $result['occupant']?->notify(new WorkstationStatusNotice($result['seat'], WorkstationStatusNotice::TERMINATION_WITHDRAWN, [
            'notice_date' => $result['notice']->notice_date->format('j M Y'),
        ], $result['occupant']?->name));
    }

    /**
     * STEP 2 #2. Old seat -> Available, new seat -> Occupied, same student,
     * in one transaction. The new seat must be Available and the same
     * gender as the student; any block is fine. The old seat's key is
     * handled exactly like a release (STEP 4) -- reassigning away from a
     * seat is "every other release" for the 7-day return window, never the
     * automatic-release formula.
     */
    public function reassignSeat(
        WorkstationRequest $oldRequest,
        Workstation $newSeat,
        ?User $actor,
        string $reason,
        ?string $remark = null,
    ): WorkstationRequest {
        if (! in_array($reason, self::REASONS_REASSIGN, true)) {
            throw new InvalidArgumentException("Invalid reason for a reassignment: {$reason}");
        }

        $result = DB::transaction(function () use ($oldRequest, $newSeat, $actor, $reason, $remark) {
            $lockedOld = WorkstationRequest::whereKey($oldRequest->id)->lockForUpdate()->first();

            if (! $lockedOld || $lockedOld->status !== WorkstationRequest::STATUS_CONFIRMED) {
                throw new RuntimeException('This workstation request is not active.');
            }

            $oldSeat = Workstation::whereKey($lockedOld->workstation_id)->lockForUpdate()->first();
            $lockedNew = Workstation::whereKey($newSeat->id)->lockForUpdate()->first();

            if (! $lockedNew || $lockedNew->status !== Workstation::STATUS_AVAILABLE) {
                throw new RuntimeException('That seat is not available.');
            }

            $occupant = $lockedOld->occupant();

            if ($lockedNew->location->gender !== $occupant?->gender) {
                throw new RuntimeException('That seat is designated for a different gender.');
            }

            $actorName = $this->actorName(WorkstationHistory::ACTOR_CGS, $actor);
            $previousSeatLabel = $oldSeat->location->room_code.' Seat '.$oldSeat->seat_code;

            // Old seat first -- released, key handling decides whether it
            // can go straight back to Available or has to wait in
            // Awaiting Key Return (PART 1), then History.
            $lockedOld->update(['status' => WorkstationRequest::STATUS_RELEASED, 'released_at' => now()]);

            $key = $this->handleKeyOnRelease($lockedOld, now(), false, WorkstationHistory::ACTOR_CGS, $actorName);
            $oldSeatNewStatus = $key['kind'] === 'collected' ? Workstation::STATUS_AWAITING_KEY_RETURN : Workstation::STATUS_AVAILABLE;

            $oldSeat->update(['status' => $oldSeatNewStatus]);

            WorkstationHistory::record(
                seat: $oldSeat,
                actorType: WorkstationHistory::ACTOR_CGS,
                actorName: $actorName,
                action: WorkstationHistory::ACTION_SEAT_REASSIGNED,
                reason: $reason,
                remark: $remark,
                beforeState: Workstation::STATUS_OCCUPIED,
                afterState: $oldSeatNewStatus,
                student: $occupant?->asUserLike(),
            );

            // New seat -- occupied, new request (same occupant, real or
            // demo, carried over to whichever of the two FK columns it
            // came from), History.
            $lockedNew->update(['status' => Workstation::STATUS_OCCUPIED]);

            $newRequest = WorkstationRequest::create([
                'workstation_id' => $lockedNew->id,
                'student_id' => $lockedOld->student_id,
                'demo_profile_id' => $lockedOld->demo_profile_id,
                'status' => WorkstationRequest::STATUS_CONFIRMED,
                'requested_at' => now(),
            ]);

            WorkstationHistory::record(
                seat: $lockedNew,
                actorType: WorkstationHistory::ACTOR_CGS,
                actorName: $actorName,
                action: WorkstationHistory::ACTION_SEAT_REASSIGNED,
                reason: $reason,
                remark: $remark,
                beforeState: Workstation::STATUS_AVAILABLE,
                afterState: Workstation::STATUS_OCCUPIED,
                student: $occupant?->asUserLike(),
            );

            return [
                'newRequest' => $newRequest, 'newSeat' => $lockedNew, 'occupant' => $occupant,
                'key' => $key, 'previousSeat' => $previousSeatLabel,
            ];
        });

        $newOccupant = $result['occupant'];

        $newOccupant?->notify(new WorkstationStatusNotice($result['newSeat'], WorkstationStatusNotice::CHANGED, [
            'reason' => $reason,
            'previous_seat' => $result['previousSeat'],
            'key_paragraph' => $result['key']['kind'],
            'return_due_date' => $result['key']['return_due_date'],
        ], $newOccupant?->name));

        return $result['newRequest'];
    }

    /**
     * STEP 2 #3. Only for an Available seat. The student must currently
     * hold no seat and match the room's gender -- "a real login account" is
     * automatic here, since $student is always a genuine users row; the
     * seed's dummy occupants are never User models at all (bulk-set
     * Workstation.status with no WorkstationRequest), so there is no path
     * that could call this with one.
     */
    public function assignStudent(
        User $student,
        Workstation $seat,
        ?User $actor,
        string $reason,
        ?string $remark = null,
    ): WorkstationRequest {
        if (! in_array($reason, self::REASONS_ASSIGN, true)) {
            throw new InvalidArgumentException("Invalid reason for an assignment: {$reason}");
        }

        $request = DB::transaction(function () use ($student, $seat, $actor, $reason, $remark) {
            $locked = Workstation::whereKey($seat->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== Workstation::STATUS_AVAILABLE) {
                throw new RuntimeException('That seat is not available.');
            }

            $hasSeat = WorkstationRequest::where('student_id', $student->id)
                ->where('status', WorkstationRequest::STATUS_CONFIRMED)
                ->exists();

            if ($hasSeat) {
                throw new RuntimeException('This student already has a workstation.');
            }

            $studentGender = StudentGender::where('student_id', $student->id)->value('gender');

            if ($locked->location->gender !== $studentGender) {
                throw new RuntimeException('That seat is designated for a different gender.');
            }

            $locked->update(['status' => Workstation::STATUS_OCCUPIED]);

            $newRequest = WorkstationRequest::create([
                'workstation_id' => $locked->id,
                'student_id' => $student->id,
                'status' => WorkstationRequest::STATUS_CONFIRMED,
                'requested_at' => now(),
            ]);

            WorkstationHistory::record(
                seat: $locked,
                actorType: WorkstationHistory::ACTOR_CGS,
                actorName: $this->actorName(WorkstationHistory::ACTOR_CGS, $actor),
                action: WorkstationHistory::ACTION_STUDENT_ASSIGNED,
                reason: $reason,
                remark: $remark,
                beforeState: Workstation::STATUS_AVAILABLE,
                afterState: Workstation::STATUS_OCCUPIED,
                student: $student,
            );

            return $newRequest;
        });

        $student->notify(new WorkstationStatusNotice($seat->fresh(), WorkstationStatusNotice::ASSIGNED));

        return $request;
    }

    /**
     * STEP 2 #4. Only for an Available seat -- Occupied and Pending are
     * both rejected with the same message, since either way someone has
     * to be dealt with first.
     */
    public function setMaintenance(Workstation $seat, ?User $actor, string $reason, ?string $remark = null): void
    {
        if (! in_array($reason, self::REASONS_MAINTENANCE, true)) {
            throw new InvalidArgumentException("Invalid reason for maintenance: {$reason}");
        }

        DB::transaction(function () use ($seat, $actor, $reason, $remark) {
            $locked = Workstation::whereKey($seat->id)->lockForUpdate()->first();

            if ($locked?->status === Workstation::STATUS_AWAITING_KEY_RETURN) {
                throw new RuntimeException('Return the key or use Release Hold first.');
            }

            if (! $locked || $locked->status !== Workstation::STATUS_AVAILABLE) {
                throw new RuntimeException('Reassign or release the student first.');
            }

            $locked->update(['status' => Workstation::STATUS_DISABLED]);

            WorkstationHistory::record(
                seat: $locked,
                actorType: WorkstationHistory::ACTOR_CGS,
                actorName: $this->actorName(WorkstationHistory::ACTOR_CGS, $actor),
                action: WorkstationHistory::ACTION_SET_MAINTENANCE,
                reason: $reason,
                remark: $remark,
                beforeState: Workstation::STATUS_AVAILABLE,
                afterState: Workstation::STATUS_DISABLED,
            );
        });
    }

    /** STEP 2 #5. Maintenance -> Available. No reason required. */
    public function restoreSeat(Workstation $seat, ?User $actor, ?string $remark = null): void
    {
        DB::transaction(function () use ($seat, $actor, $remark) {
            $locked = Workstation::whereKey($seat->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== Workstation::STATUS_DISABLED) {
                throw new RuntimeException('That seat is not under maintenance.');
            }

            $locked->update(['status' => Workstation::STATUS_AVAILABLE]);

            WorkstationHistory::record(
                seat: $locked,
                actorType: WorkstationHistory::ACTOR_CGS,
                actorName: $this->actorName(WorkstationHistory::ACTOR_CGS, $actor),
                action: WorkstationHistory::ACTION_RESTORED,
                remark: $remark,
                beforeState: Workstation::STATUS_DISABLED,
                afterState: Workstation::STATUS_AVAILABLE,
            );
        });
    }

    /**
     * PART 1 #6. The only way out of Awaiting Key Return other than the
     * key actually coming back (LockerKey::markReturned()) -- CGS writing
     * the key off instead. Marks the Return Pending key Lost (PART 5:
     * excluded from the Return Pending list, never blocks a new request)
     * and frees the seat. Silent on purpose -- no email or bell, the
     * student already knows they did not return it.
     */
    public function releaseHold(Workstation $seat, ?User $actor, string $reason, ?string $remark = null): void
    {
        if (! in_array($reason, self::REASONS_RELEASE_HOLD, true)) {
            throw new InvalidArgumentException("Invalid reason for a release hold: {$reason}");
        }

        DB::transaction(function () use ($seat, $actor, $reason, $remark) {
            $locked = Workstation::whereKey($seat->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== Workstation::STATUS_AWAITING_KEY_RETURN) {
                throw new RuntimeException('This seat is not awaiting a key return.');
            }

            $actorName = $this->actorName(WorkstationHistory::ACTOR_CGS, $actor);

            $lockerKey = $locked->lockerKeysHistory()
                ->where('locker_keys.status', LockerKey::STATUS_RETURN_PENDING)
                ->latest('requested_at')
                ->first();

            if ($lockerKey) {
                $occupant = $lockerKey->occupant();

                $lockerKey->update(['status' => LockerKey::STATUS_LOST]);

                WorkstationHistory::record(
                    seat: $locked,
                    actorType: WorkstationHistory::ACTOR_CGS,
                    actorName: $actorName,
                    action: WorkstationHistory::ACTION_KEY_MARKED_LOST,
                    reason: $reason,
                    remark: $remark,
                    beforeState: LockerKey::STATUS_RETURN_PENDING,
                    afterState: LockerKey::STATUS_LOST,
                    student: $occupant?->asUserLike(),
                );
            }

            $locked->update(['status' => Workstation::STATUS_AVAILABLE]);

            WorkstationHistory::record(
                seat: $locked,
                actorType: WorkstationHistory::ACTOR_CGS,
                actorName: $actorName,
                action: WorkstationHistory::ACTION_RELEASE_HOLD,
                reason: $reason,
                remark: $remark,
                beforeState: Workstation::STATUS_AWAITING_KEY_RETURN,
                afterState: Workstation::STATUS_AVAILABLE,
            );
        });
    }

    /**
     * CGS's release override for a seat with no tracked WorkstationRequest —
     * unlike releaseSeat() above, this takes the seat rather than a request,
     * because a seat can be occupied without one: the catalogue was seeded
     * from CGS's existing seat map, and every seat it showed already taken
     * came in pre-occupied with no app history to attach. Any confirmed
     * request that does exist is still marked released so the student's own
     * record stays correct. Used by WorkstationManagementController's
     * Release action only as the fallback for that seeded-with-no-request
     * case; every other release goes through releaseSeat() above.
     */
    public function forceReleaseSeat(Workstation $seat, User $admin, string $reason, ?string $remark = null): void
    {
        DB::transaction(function () use ($seat, $admin, $reason, $remark) {
            $locked = Workstation::whereKey($seat->id)->lockForUpdate()->first();

            WorkstationRequest::where('workstation_id', $seat->id)
                ->where('status', WorkstationRequest::STATUS_CONFIRMED)
                ->update([
                    'status' => WorkstationRequest::STATUS_RELEASED,
                    'released_at' => now(),
                    'is_override' => true,
                    'overridden_by_id' => $admin->id,
                    'override_reason' => $reason,
                ]);

            WorkstationHistory::record(
                seat: $locked,
                actorType: WorkstationHistory::ACTOR_CGS,
                actorName: $admin->name,
                action: WorkstationHistory::ACTION_SEAT_RELEASED,
                reason: $reason,
                remark: $remark,
                beforeState: Workstation::STATUS_OCCUPIED,
                afterState: Workstation::STATUS_AVAILABLE,
            );

            $locked->update(['status' => Workstation::STATUS_AVAILABLE]);
        });

        activity('workstation')
            ->causedBy($admin)
            ->performedOn($seat)
            ->withProperties(['reason' => $reason])
            ->log('Force-released workstation #'.$seat->id.' (CGS override, no tracked occupant)');
    }

    /**
     * CGS's manual-assignment fallback — kept only for the old
     * workstation.cgs.force-assign route, which now delegates to
     * assignStudent() instead of this method wherever it still has a real
     * caller. See WorkstationOperationsController::forceAssign().
     */
    public function forceAssign(User $student, Workstation $seat, User $admin, ?string $reason = null): WorkstationRequest
    {
        return DB::transaction(function () use ($student, $seat, $admin, $reason) {
            $locked = Workstation::whereKey($seat->id)->lockForUpdate()->first();

            if (! $locked || $locked->status === Workstation::STATUS_OCCUPIED) {
                throw new RuntimeException('That seat is occupied — force-release it before reassigning.');
            }

            $locked->update(['status' => Workstation::STATUS_OCCUPIED]);

            $request = WorkstationRequest::create([
                'workstation_id' => $seat->id,
                'student_id' => $student->id,
                'status' => WorkstationRequest::STATUS_CONFIRMED,
                'requested_at' => now(),
                'is_override' => true,
                'overridden_by_id' => $admin->id,
                'override_reason' => $reason,
            ]);

            activity('workstation')
                ->causedBy($admin)
                ->performedOn($request)
                ->withProperties(['reason' => $reason])
                ->log('Force-assigned workstation #'.$seat->id.' to '.$student->name.' (CGS override)');

            return $request;
        });
    }
}
