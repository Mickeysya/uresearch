<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit trail. Written solely by the WorkflowEngine.
 *
 * Stores both stage_key (machine, for querying) and stage_label (human, as it
 * read at the time) so relabelling a stage later does not rewrite history.
 */
class ApprovalHistory extends Model
{
    protected $table = 'approval_history';

    protected $fillable = [
        'application_id', 'approver_id', 'stage_key', 'stage_label', 'decision', 'remarks',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
