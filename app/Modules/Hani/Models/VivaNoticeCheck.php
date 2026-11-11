<?php

namespace App\Modules\Hani\Models;

use App\Modules\Core\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The automatic document checks on one Notice of Thesis Submission. See Support\NoticeChecks. */
class VivaNoticeCheck extends Model
{
    public const QUEUED = 'queued';

    public const DONE = 'done';

    public const FAILED = 'failed';

    protected $fillable = ['application_id', 'status', 'results', 'papers', 'engine', 'checked_at'];

    protected function casts(): array
    {
        return ['results' => 'array', 'papers' => 'array', 'checked_at' => 'datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** not_met if any line failed outright, warning if any warned, else pass. */
    public function overall(): string
    {
        $statuses = collect($this->results ?? [])->pluck('status');

        return match (true) {
            $statuses->contains('not_met') => 'not_met',
            $statuses->contains('warning') => 'warning',
            default => 'pass',
        };
    }
}
