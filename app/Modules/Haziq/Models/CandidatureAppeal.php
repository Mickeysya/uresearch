<?php

namespace App\Modules\Haziq\Models;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's appeal against their own Stage Gate termination on one
 * application, reinstating that same appointment — see this table's
 * migration for why this is the one and only way back, not a new
 * application submission. The actual letter is an ApplicationDocument on
 * $application, doc_type 'Candidature Appeal Letter' — this row is only
 * ever the decision record.
 */
class CandidatureAppeal extends Model
{
    protected $fillable = [
        'application_id', 'student_id', 'status', 'decided_by', 'decided_at', 'decision_notes',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
