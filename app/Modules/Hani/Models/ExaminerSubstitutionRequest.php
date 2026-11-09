<?php

namespace App\Modules\Hani\Models;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A department asked to replace one seat on a pending list. See the migration. */
class ExaminerSubstitutionRequest extends Model
{
    public const OPEN = 'open';

    public const DONE = 'done';

    public const CLOSED = 'closed';

    /** The stages whose holder can ask: Senior Executive, Senior Director, Dean. */
    public const ASKING_STAGES = ['cgs_compile', 'senior_director', 'dean'];

    /**
     * Where the seat may still be changed. Further up the chain people are
     * signing this exact list, so the Senior Director or the Dean has to send
     * it back first, which brings it to academic_exec again.
     */
    public const REPLACEABLE_STAGES = ['academic_exec', 'cgs_compile'];

    protected $fillable = [
        'nomination_id', 'seat', 'examiner_id', 'requested_at_stage', 'reason', 'status',
        'requested_by_id', 'replacement_examiner_id', 'resolved_by_id', 'resolved_at',
    ];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function nomination(): BelongsTo
    {
        return $this->belongsTo(ExaminerNomination::class, 'nomination_id');
    }

    public function examiner(): BelongsTo
    {
        return $this->belongsTo(Examiner::class);
    }

    public function replacement(): BelongsTo
    {
        return $this->belongsTo(Examiner::class, 'replacement_examiner_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::OPEN);
    }

    /** May the person holding this application's current stage ask for a substitution? */
    public static function canAsk(User $user, Application $application): bool
    {
        return $application->status === Application::STATUS_PENDING
            && in_array($application->current_stage, self::ASKING_STAGES, true)
            && $application->currentStage()?->role === $user->role;
    }

    /** Can the seat be changed right now? */
    public function isReplaceable(): bool
    {
        $application = $this->nomination?->application;

        return $this->status === self::OPEN
            && $application?->status === Application::STATUS_PENDING
            && in_array($application->current_stage, self::REPLACEABLE_STAGES, true);
    }

    public function seatLabel(): string
    {
        return ExaminerNomination::SLOTS[$this->seat] ?? $this->seat;
    }
}
