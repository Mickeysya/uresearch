<?php

namespace App\Modules\Chloe\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only. Every seat/key lifecycle action writes one row here and
 * nothing — no controller, no console command, no CGS screen now or
 * later — ever updates or deletes one. There is deliberately no edit or
 * delete route/method/button anywhere in this module; if a future screen
 * needs one, that is a decision for someone to make explicitly, not a
 * capability to leave lying around on a model called "history".
 */
class WorkstationHistory extends Model
{
    public const ACTOR_STUDENT = 'student';

    public const ACTOR_CGS = 'cgs';

    public const ACTOR_SYSTEM = 'system';

    public const ACTION_SEAT_SELECTED = 'Seat selected';

    public const ACTION_SEAT_CONFIRMED = 'Seat confirmed';

    public const ACTION_PENDING_EXPIRED = 'Pending expired';

    public const ACTION_SEAT_RELEASED = 'Seat released';

    public const ACTION_SEAT_REASSIGNED = 'Seat reassigned';

    public const ACTION_STUDENT_ASSIGNED = 'Student assigned';

    public const ACTION_SET_MAINTENANCE = 'Set to Maintenance';

    public const ACTION_RESTORED = 'Restored';

    public const ACTION_KEY_REQUESTED = 'Key requested';

    public const ACTION_KEY_CANCELLED = 'Key cancelled';

    public const ACTION_KEY_AUTO_CANCELLED = 'Key auto-cancelled';

    public const ACTION_KEY_COLLECTED = 'Key collected';

    public const ACTION_KEY_RETURN_PENDING = 'Key set to Return Pending';

    public const ACTION_KEY_RETURNED = 'Key returned';

    /** Superseded by ACTION_TERMINATION_NOTICE_ISSUED (the Term of Use termination flow replaced the old 14/7/3-day pipeline) -- kept only so pre-existing rows still have a label. */
    public const ACTION_RELEASE_NOTICE_SENT = 'Release notice sent';

    /** PART 3: Term of Use clause 9/16 -- the seat stays Occupied, the student has 5 working days. */
    public const ACTION_TERMINATION_NOTICE_ISSUED = 'Termination notice issued';

    public const ACTION_TERMINATION_NOTICE_WITHDRAWN = 'Termination notice withdrawn';

    /** The daily job's auto-release once the vacate-by day has ended -- no email, no bell. */
    public const ACTION_SEAT_RELEASED_VACATE_ENDED = 'Seat released (vacate period ended)';

    /** PART 4 #3: written only on email-link confirmation, never a checkbox. */
    public const ACTION_SEAT_CONFIRMED_TERMS = 'Seat confirmed (Term of Use accepted)';

    /** PART 1 #4: the key coming back is what frees the seat again. */
    public const ACTION_SEAT_AVAILABLE_AGAIN = 'Seat available again (key returned)';

    /** PART 1 #6: CGS writing the key off instead of waiting for it. */
    public const ACTION_RELEASE_HOLD = 'Release hold';

    /** PART 5: the key status releaseHold() sets. */
    public const ACTION_KEY_MARKED_LOST = 'Locker key marked as Lost';

    /**
     * Exact remark text the pre-PART-3 seeder wrote, carrying no real
     * information for CGS staff -- hidden in the display layer only (see
     * remarkForDisplay()); the stored rows themselves are untouched,
     * because History never gets rewritten after the fact.
     */
    protected const HIDDEN_REMARK = 'Demo data';

    public $timestamps = false;

    protected $fillable = [
        'block', 'room_code', 'seat_number', 'student_name', 'student_matric_no',
        'student_email', 'student_programme',
        'actor_type', 'actor_name', 'action', 'reason', 'remark', 'before_state', 'after_state',
        'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $history) {
            $history->created_at ??= now();
        });
    }

    /**
     * The one place a History row gets built, so "block/room/seat/student
     * name/matric are a snapshot off the Workstation and its current
     * occupant at the moment of the action" is written once, not
     * re-derived slightly differently at each of WorkstationAllocator's
     * dozen call sites.
     */
    public static function record(
        Workstation $seat,
        string $actorType,
        string $actorName,
        string $action,
        ?string $reason = null,
        ?string $remark = null,
        ?string $beforeState = null,
        ?string $afterState = null,
        ?User $student = null,
    ): self {
        return self::create([
            'block' => $seat->location->block,
            'room_code' => $seat->location->room_code,
            'seat_number' => $seat->seat_code,
            'student_name' => $student?->name,
            'student_matric_no' => $student?->matric_no,
            'student_email' => $student?->email,
            'student_programme' => $student?->programme,
            'actor_type' => $actorType,
            'actor_name' => $actorName,
            'action' => $action,
            'reason' => $reason,
            'remark' => $remark,
            'before_state' => $beforeState,
            'after_state' => $afterState,
        ]);
    }

    /**
     * "System (System)" / "Cgs (Puan Waheeda)" collapsed into one label,
     * never the actor type and name both spelled out separately -- PART 3
     * #3. System has no name worth repeating; CGS/Student do.
     */
    public function actorLabel(): string
    {
        return match ($this->actor_type) {
            self::ACTOR_SYSTEM => 'System',
            self::ACTOR_CGS => 'CGS · '.$this->actor_name,
            self::ACTOR_STUDENT => 'Student · '.$this->actor_name,
            default => $this->actor_name,
        };
    }

    /**
     * PART 3 #6: the row's own stored action text, mapped to the clean
     * wording CGS actually reads -- covers both this model's current
     * ACTION_* values and the slightly different wording a handful of
     * already-written rows carry (e.g. the pre-PART-3 "Key set to Return
     * Pending"), so old and new rows read the same way. Never changes
     * what is stored; only how this one row renders.
     */
    public function actionLabel(): string
    {
        return match ($this->action) {
            self::ACTION_KEY_REQUESTED => 'Locker key requested',
            self::ACTION_KEY_CANCELLED => 'Locker key cancelled',
            self::ACTION_KEY_AUTO_CANCELLED => 'Locker key cancelled automatically',
            self::ACTION_KEY_COLLECTED => 'Locker key collected',
            self::ACTION_KEY_RETURN_PENDING, 'Key set to Return Pending' => 'Locker key set to Return Pending',
            self::ACTION_KEY_RETURNED => 'Locker key returned',
            default => $this->action,
        };
    }

    /**
     * PART 3 #4: before_state/after_state are internal codes
     * ("return_pending", "awaiting_key_return", ...) -- this turns one
     * into the label CGS sees elsewhere on the same screen. Null-safe:
     * most rows only ever set one side.
     */
    public static function stateLabel(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return match ($code) {
            Workstation::STATUS_AVAILABLE => 'Available',
            Workstation::STATUS_PENDING => 'Pending',
            Workstation::STATUS_OCCUPIED => 'Occupied',
            Workstation::STATUS_RESERVED => 'Reserved',
            Workstation::STATUS_DISABLED => 'Maintenance',
            Workstation::STATUS_AWAITING_KEY_RETURN => 'Awaiting Key Return',
            LockerKey::STATUS_REQUESTED => 'Pending Collection',
            LockerKey::STATUS_COLLECTED => 'Collected',
            LockerKey::STATUS_RETURN_PENDING => 'Return Pending',
            LockerKey::STATUS_RETURNED => 'Returned',
            LockerKey::STATUS_CANCELLED => 'Cancelled',
            LockerKey::STATUS_LOST => 'Lost',
            default => ucfirst(str_replace('_', ' ', $code)),
        };
    }

    public function beforeLabel(): ?string
    {
        return self::stateLabel($this->before_state);
    }

    public function afterLabel(): ?string
    {
        return self::stateLabel($this->after_state);
    }

    /** PART 3 #5: the stored remark is never changed; only hidden from view. */
    public function remarkForDisplay(): ?string
    {
        return $this->remark === self::HIDDEN_REMARK ? null : $this->remark;
    }
}
