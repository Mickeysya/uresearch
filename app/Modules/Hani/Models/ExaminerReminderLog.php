<?php

namespace App\Modules\Hani\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A notification that must go out once and only once, keyed by the unique
 * (nomination_id, kind, sequence). See the migration.
 *
 *   evaluation_overdue  examiners:sweep; sequence = the reminder window
 *   compile_review      NominationsNeedCompileReview; sequence = the arrival
 *                       at cgs_compile (how many returns came before it)
 */
class ExaminerReminderLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['nomination_id', 'kind', 'sequence', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
