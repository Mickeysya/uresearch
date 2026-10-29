<?php

namespace App\Modules\Chloe\Models;

use App\Modules\Chloe\Notifications\LockerKeyNotice;
use App\Modules\Chloe\Support\StudentDirectory;
use App\Modules\Chloe\Support\StudentIdentity;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Deliberately its own record rather than a column on WorkstationRequest — a
 * student can hold a workstation and never touch this at all, and the two
 * are tracked (and reminded about) on separate clocks.
 */
class LockerKey extends Model
{
    public const STATUS_REQUESTED = 'requested';

    public const STATUS_COLLECTED = 'collected';

    public const STATUS_RETURNED = 'returned';

    // A request the student cancelled, or Console\Commands\
    // RemindOverdueLockerKeys cancelled automatically at COLLECTION_PERIOD_DAYS
    // — a status, not a deleted row, so the request history (how many times,
    // when) survives the same way a released WorkstationRequest does. The UI
    // treats it identically to "no request at all".
    public const STATUS_CANCELLED = 'cancelled';

    // The key was Collected, then the seat it belongs to was released or
    // reassigned away from -- the key itself is still out with the student
    // and has to come back before the next occupant can request one. See
    // WorkstationAllocator's key-on-release handling and
    // markReturned()/isOverdueForReturn() below. Unlike STATUS_REQUESTED,
    // this is NEVER cancelled automatically (rule: a key already in
    // someone's hand doesn't just stop being owed back).
    public const STATUS_RETURN_PENDING = 'return_pending';

    // Set only by WorkstationAllocator::releaseHold() -- CGS writing the
    // key off rather than it ever coming back. Terminal, like Returned:
    // excluded from the Return Pending list, and does not block the
    // former holder from a new key request (unlike Return Pending).
    public const STATUS_LOST = 'lost';

    /** Days uncollected before Console\Commands\RemindOverdueLockerKeys nags the student. */
    public const GRACE_DAYS = 3;

    /** Days uncollected before the request is cancelled automatically. */
    public const COLLECTION_PERIOD_DAYS = 7;

    /** Days before return_due_at the one return reminder goes out. */
    public const RETURN_REMINDER_DAYS_BEFORE = 3;

    protected $fillable = [
        'workstation_request_id', 'student_id', 'demo_profile_id', 'status', 'requested_at',
        'collected_at', 'returned_at', 'return_due_at', 'return_reminder_sent_at',
        'reminder_sent_at', 'reminder_count', 'is_override', 'overridden_by_id', 'override_reason',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'collected_at' => 'datetime',
            'returned_at' => 'datetime',
            'return_due_at' => 'datetime',
            'return_reminder_sent_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'is_override' => 'boolean',
        ];
    }

    public function workstationRequest(): BelongsTo
    {
        return $this->belongsTo(WorkstationRequest::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function demoProfile(): BelongsTo
    {
        return $this->belongsTo(DemoStudentProfile::class, 'demo_profile_id');
    }

    /** Whichever of student_id/demo_profile_id is set, resolved to one shape -- see Support\StudentDirectory. */
    public function occupant(): ?StudentIdentity
    {
        return StudentDirectory::forOccupant($this->student_id, $this->demo_profile_id);
    }

    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_REQUESTED
            && $this->requested_at->lt(now()->subDays(self::GRACE_DAYS));
    }

    /** 7 days from the request — past this, Console\Commands\RemindOverdueLockerKeys cancels it automatically. */
    public function collectByDate(): Carbon
    {
        return $this->requested_at->copy()->addDays(self::COLLECTION_PERIOD_DAYS);
    }

    public function isPastCollectionWindow(): bool
    {
        return $this->status === self::STATUS_REQUESTED && now()->gt($this->collectByDate());
    }

    /**
     * The one piece of logic behind both the daily sweep
     * (Console\Commands\RemindOverdueLockerKeys) and the inline check on the
     * student's own page load (rule 11) — same method, same two thresholds,
     * called on one row at a time so either caller can run it on whatever
     * scope it has (the whole table, or just the one row a student is
     * looking at). Returns what happened, so a caller tallying totals
     * doesn't need its own copy of the thresholds to know.
     *
     * @return 'cancelled'|'reminded'|null
     */
    public function processIfOverdue(): ?string
    {
        if ($this->status !== self::STATUS_REQUESTED || ! $this->student) {
            return null;
        }

        if ($this->isPastCollectionWindow()) {
            $this->update(['status' => self::STATUS_CANCELLED]);
            $this->student->notify(new LockerKeyNotice($this, LockerKeyNotice::CANCELLED));

            WorkstationHistory::record(
                seat: $this->workstationRequest->workstation,
                actorType: WorkstationHistory::ACTOR_SYSTEM,
                actorName: 'System',
                action: WorkstationHistory::ACTION_KEY_AUTO_CANCELLED,
                beforeState: self::STATUS_REQUESTED,
                afterState: self::STATUS_CANCELLED,
                student: $this->student,
            );

            return 'cancelled';
        }

        $reminderDue = $this->requested_at->copy()->addDays(self::GRACE_DAYS)->isPast();

        if ($reminderDue && ! $this->reminder_sent_at) {
            $this->update(['reminder_sent_at' => now(), 'reminder_count' => $this->reminder_count + 1]);
            $this->student->notify(new LockerKeyNotice($this, LockerKeyNotice::REMINDER));

            return 'reminded';
        }

        return null;
    }

    /**
     * Derived, not stored — rule: "Overdue is derived... do not store it."
     * A Return Pending key is never auto-cancelled, so this is purely a
     * display flag, not something any job acts on.
     */
    public function isOverdueForReturn(): bool
    {
        return $this->status === self::STATUS_RETURN_PENDING
            && $this->return_due_at
            && now()->gt($this->return_due_at);
    }

    public function isReturnReminderDue(): bool
    {
        return $this->status === self::STATUS_RETURN_PENDING
            && $this->return_due_at
            && ! $this->return_reminder_sent_at
            && now()->gte($this->return_due_at->copy()->subDays(self::RETURN_REMINDER_DAYS_BEFORE));
    }

    /**
     * Only from Return Pending — rule 6's equivalent for the return leg.
     * No notification to the student (spec is explicit: silent).
     */
    /**
     * PART 1 #4: the key coming back is what frees an Awaiting Key Return
     * seat again -- automatically, silently (no notification either way,
     * same as before this rule existed), and in the same transaction so
     * the two can never disagree about whether the seat is free yet.
     */
    public function markReturned(string $actorType, string $actorName): void
    {
        if ($this->status !== self::STATUS_RETURN_PENDING) {
            throw new \RuntimeException('This key is not awaiting return.');
        }

        $seat = $this->workstationRequest->workstation;

        $this->update(['status' => self::STATUS_RETURNED, 'returned_at' => now()]);

        WorkstationHistory::record(
            seat: $seat,
            actorType: $actorType,
            actorName: $actorName,
            action: WorkstationHistory::ACTION_KEY_RETURNED,
            beforeState: self::STATUS_RETURN_PENDING,
            afterState: self::STATUS_RETURNED,
            student: $this->occupant()?->asUserLike(),
        );

        if ($seat->status === Workstation::STATUS_AWAITING_KEY_RETURN) {
            $seat->update(['status' => Workstation::STATUS_AVAILABLE]);

            WorkstationHistory::record(
                seat: $seat,
                actorType: $actorType,
                actorName: $actorName,
                action: WorkstationHistory::ACTION_SEAT_AVAILABLE_AGAIN,
                beforeState: Workstation::STATUS_AWAITING_KEY_RETURN,
                afterState: Workstation::STATUS_AVAILABLE,
            );
        }
    }

    /**
     * The real collection logic, on the row itself rather than re-derived
     * from a User -- works identically for a demo profile's key, which has
     * no User to look up by student_id at all. markAsCollected() below is
     * kept as the real-student convenience it always was.
     *
     * @throws \RuntimeException if this key is not awaiting collection
     */
    public function collect(string $actorType = WorkstationHistory::ACTOR_CGS, string $actorName = 'CGS'): void
    {
        if ($this->status !== self::STATUS_REQUESTED) {
            throw new \RuntimeException('This locker key is not awaiting collection.');
        }

        $this->update(['status' => self::STATUS_COLLECTED, 'collected_at' => now()]);

        $identity = $this->occupant();
        $identity?->notify(new LockerKeyNotice($this, LockerKeyNotice::COLLECTED));

        WorkstationHistory::record(
            seat: $this->workstationRequest->workstation,
            actorType: $actorType,
            actorName: $actorName,
            action: WorkstationHistory::ACTION_KEY_COLLECTED,
            beforeState: self::STATUS_REQUESTED,
            afterState: self::STATUS_COLLECTED,
            student: $identity?->asUserLike(),
        );
    }

    /**
     * The backend half of the CGS "Mark as Collected" button, for a real
     * student found by their User -- see collect() above for the
     * demo-capable version WorkstationManagementController actually calls,
     * which this now delegates to for the state change itself.
     *
     * @throws \RuntimeException if the student has no key in Pending Collection
     */
    public static function markAsCollected(User $student, string $actorType = WorkstationHistory::ACTOR_CGS, string $actorName = 'CGS'): self
    {
        $lockerKey = self::where('student_id', $student->id)
            ->where('status', self::STATUS_REQUESTED)
            ->latest('requested_at')
            ->first();

        if (! $lockerKey) {
            throw new \RuntimeException('This student has no locker key request awaiting collection.');
        }

        $lockerKey->collect($actorType, $actorName);

        return $lockerKey;
    }

    /**
     * The backend half of the future CGS "Pending Locker Key Collection"
     * list — one row per student currently in Pending Collection, with
     * everything that list needs to display and search by student ID or
     * seat number. No staff screen exists yet; call this directly (tinker,
     * a console command, a future controller) once one does.
     *
     * @return Collection<int, array{
     *     student_name: string, matric_no: ?string, block: string,
     *     room_code: string, seat_number: string, requested_at: Carbon,
     *     collect_by: Carbon, reminder_sent: bool,
     * }>
     */
    public static function pendingCollectionList(?string $search = null): Collection
    {
        return self::query()
            ->where('status', self::STATUS_REQUESTED)
            ->with(['student', 'workstationRequest.workstation.location'])
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->whereHas('student', fn ($sq) => $sq->where('matric_no', 'like', "%{$search}%"))
                        ->orWhereHas('workstationRequest.workstation', fn ($sq) => $sq->where('seat_code', 'like', "%{$search}%"));
                });
            })
            ->oldest('requested_at')
            ->get()
            ->map(function (self $lockerKey) {
                $location = $lockerKey->workstationRequest->workstation->location;

                return [
                    'student_name' => $lockerKey->student->name,
                    'matric_no' => $lockerKey->student->matric_no,
                    'block' => $location->block,
                    'room_code' => $location->room_code,
                    'seat_number' => $lockerKey->workstationRequest->workstation->seat_code,
                    'requested_at' => $lockerKey->requested_at,
                    'collect_by' => $lockerKey->collectByDate(),
                    'reminder_sent' => (bool) $lockerKey->reminder_sent_at,
                ];
            });
    }

    /**
     * The Return Pending counterpart to pendingCollectionList() — same
     * shape, same search, for the future CGS "Pending Key Returns" list.
     *
     * @return Collection<int, array{
     *     student_name: string, matric_no: ?string, block: string,
     *     room_code: string, seat_number: string, released_at: ?Carbon,
     *     return_due_at: ?Carbon, overdue: bool,
     * }>
     */
    public static function returnPendingList(?string $search = null): Collection
    {
        return self::query()
            ->where('status', self::STATUS_RETURN_PENDING)
            ->with(['student', 'workstationRequest.workstation.location'])
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->whereHas('student', fn ($sq) => $sq->where('matric_no', 'like', "%{$search}%"))
                        ->orWhereHas('workstationRequest.workstation', fn ($sq) => $sq->where('seat_code', 'like', "%{$search}%"));
                });
            })
            ->oldest('return_due_at')
            ->get()
            ->map(function (self $lockerKey) {
                $location = $lockerKey->workstationRequest->workstation->location;

                return [
                    'student_name' => $lockerKey->student->name,
                    'matric_no' => $lockerKey->student->matric_no,
                    'block' => $location->block,
                    'room_code' => $location->room_code,
                    'seat_number' => $lockerKey->workstationRequest->workstation->seat_code,
                    'released_at' => $lockerKey->workstationRequest->released_at,
                    'return_due_at' => $lockerKey->return_due_at,
                    'overdue' => $lockerKey->isOverdueForReturn(),
                ];
            });
    }
}
