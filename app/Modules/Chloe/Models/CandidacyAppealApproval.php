<?php

namespace App\Modules\Chloe\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One stage decision of one round, with snapshots of the approver's name, designation and signature image. */
class CandidacyAppealApproval extends Model
{
    protected $table = 'candidacy_appeal_approvals';

    protected $fillable = [
        'candidacy_appeal_id', 'round', 'stage', 'profile_id', 'user_id', 'full_name', 'designation',
        'signature_snapshot_path', 'signature_snapshot_sha256', 'decision', 'documents_complete', 'remark', 'decided_at', 'verification_code',
    ];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime', 'documents_complete' => 'boolean'];
    }

    public function appeal(): BelongsTo
    {
        return $this->belongsTo(CandidacyAppeal::class, 'candidacy_appeal_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(CandidacyStaffProfile::class, 'profile_id');
    }
}
