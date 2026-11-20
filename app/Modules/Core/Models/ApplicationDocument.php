<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Support\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Every upload, for every module, lands here.
 *
 * The legacy app declared this table and then never used it -- GA Extension
 * stashed a path on its own detail row and wrote the file under the webroot
 * with its original name and no validation, which made a .php upload remote
 * code execution. Files now go to the private disk under storage/app and are
 * only ever served back through a controller.
 */
class ApplicationDocument extends Model
{
    protected $fillable = [
        'application_id', 'doc_type', 'original_name', 'path', 'mime_type', 'size_bytes',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function url(): string
    {
        return route('documents.show', $this);
    }

    /** Inline, for the in-app viewer; url() is the download. */
    public function viewUrl(): string
    {
        return route('documents.view', $this);
    }

    public function exists(): bool
    {
        return Storage::disk('local')->exists($this->path);
    }

    /**
     * May this user see this document?
     *
     * The student who owns the application, an admin, whoever holds the stage
     * it is currently sitting on, or anyone who has already decided on it.
     *
     * This lived only inside DocumentController::show(). The Documents page
     * needed the same rule to decide what to list, and two copies of an
     * access rule is how a list ends up naming files its download route then
     * refuses -- or worse, the other way round. One definition, two callers.
     */
    public function visibleTo(User $user): bool
    {
        $application = $this->application;

        if (! $application) {
            return false;
        }

        return $application->student_id === $user->id
            || $user->hasRole(Role::ADMIN)
            || $application->currentStage()?->role === $user->role
            || $application->history()->where('approver_id', $user->id)->exists();
    }

    /**
     * The same rule as a query, for listing rather than checking.
     *
     * Kept beside visibleTo() deliberately: they must agree, and they are
     * easiest to keep in step when they are readable together. The stage
     * check resolves the user's role to (module_type, stage key) pairs in PHP
     * first -- ModuleRegistry::queuesForRole() over each chain's superset --
     * so the query is exact and the Documents page can paginate in SQL.
     * visibleTo() still guards the download itself.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasRole(Role::ADMIN)) {
            return $query;
        }

        $stages = app(ModuleRegistry::class)->queuesForRole((string) $user->role);

        return $query->whereHas('application', function (Builder $q) use ($user, $stages) {
            $q->where('student_id', $user->id)
                ->orWhereIn('id', ApprovalHistory::where('approver_id', $user->id)->select('application_id'));

            if ($stages !== []) {
                $q->orWhere(function (Builder $held) use ($stages) {
                    $held->where('status', Application::STATUS_PENDING)
                        ->where(function (Builder $pairs) use ($stages) {
                            foreach ($stages as $queue) {
                                $pairs->orWhere(fn (Builder $pair) => $pair
                                    ->where('module_type', $queue['module']->key())
                                    ->where('current_stage', $queue['stage']->key));
                            }
                        });
                });
            }
        });
    }
}
