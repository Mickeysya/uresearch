<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Support\Stage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The shared spine of every module. One row per submission, whatever its type.
 *
 * Your module owns a *detail* table keyed by application_id and never touches
 * status or current_stage directly -- the WorkflowEngine does that, so every
 * module advances the same way.
 */
class Application extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'student_id', 'submitted_by_id', 'module_type', 'status', 'current_stage', 'submitted_at',
    ];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(ApprovalHistory::class)->orderBy('created_at');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ApplicationDocument::class);
    }

    public function module(): WorkflowModule
    {
        return app(ModuleRegistry::class)->get($this->module_type);
    }

    public function currentStage(): ?Stage
    {
        return app(WorkflowEngine::class)->currentStage($this);
    }

    /** @return array<int, array{stage: Stage, state: string}> */
    public function progress(): array
    {
        return app(WorkflowEngine::class)->progress($this);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** The decision that closed this application, if it was rejected. */
    public function rejection(): ?ApprovalHistory
    {
        if ($this->status !== self::STATUS_REJECTED) {
            return null;
        }

        return $this->history()->where('decision', 'rejected')->latest('created_at')->first();
    }

    public function scopeFor($query, string $moduleKey)
    {
        return $query->where('module_type', $moduleKey);
    }
}
