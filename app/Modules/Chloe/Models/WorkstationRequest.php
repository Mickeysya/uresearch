<?php

namespace App\Modules\Chloe\Models;

use App\Modules\Chloe\Support\StudentDirectory;
use App\Modules\Chloe\Support\StudentIdentity;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One student's claim on one seat. Not an Application row — this module has
 * no approval chain, so nothing here ever touches WorkflowEngine. See
 * Services\WorkstationAllocator, which is the only code that changes these
 * rows and the parent Workstation's status together.
 */
class WorkstationRequest extends Model
{
    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_RELEASED = 'released';

    protected $fillable = [
        'workstation_id', 'student_id', 'demo_profile_id', 'status', 'requested_at', 'released_at',
        'is_override', 'overridden_by_id', 'override_reason',
        // The old 14/7/3-day pipeline's columns -- superseded by PART 3's
        // termination flow (see WorkstationTerminationNotice), kept
        // un-dropped so no historical data is lost; nothing writes to
        // them any more.
        'scheduled_release_date', 'scheduled_release_source',
        'release_notice_14_sent_at', 'release_notice_7_sent_at', 'release_notice_3_sent_at',
        // PART 4 #3.
        'terms_accepted_at', 'terms_edition',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'released_at' => 'datetime',
            'is_override' => 'boolean',
            'scheduled_release_date' => 'date',
            'release_notice_14_sent_at' => 'datetime',
            'release_notice_7_sent_at' => 'datetime',
            'release_notice_3_sent_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
        ];
    }

    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class)->withTrashed();
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function demoProfile(): BelongsTo
    {
        return $this->belongsTo(DemoStudentProfile::class, 'demo_profile_id');
    }

    public function overriddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overridden_by_id');
    }

    /** Whichever of student_id/demo_profile_id is set, resolved to one shape -- see Support\StudentDirectory. */
    public function occupant(): ?StudentIdentity
    {
        return StudentDirectory::forOccupant($this->student_id, $this->demo_profile_id);
    }

    /**
     * The most recent one, not just "a" one — a student can request, cancel,
     * and request again, so more than one LockerKey row can belong to the
     * same confirmed seat over its lifetime (see LockerKey::STATUS_CANCELLED).
     * Without latestOfMany() this would arbitrarily pick whichever row the
     * database happens to return first.
     */
    public function lockerKey(): HasOne
    {
        return $this->hasOne(LockerKey::class)->latestOfMany('requested_at');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    public function terminationNotices(): HasMany
    {
        return $this->hasMany(WorkstationTerminationNotice::class);
    }

    /** At most one at a time in practice (WorkstationAllocator::startTermination() enforces it); latestOfMany is just the defensive read. */
    public function activeTerminationNotice(): HasOne
    {
        return $this->hasOne(WorkstationTerminationNotice::class)
            ->where('status', WorkstationTerminationNotice::STATUS_ACTIVE)
            ->latestOfMany('notice_date');
    }
}
