<?php

namespace App\Modules\Chloe\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A person who signs an appeal. user_id is null for staff without a login (demo data). The signature is a file of
 * ours; a decision COPIES it (CandidacyAppealApproval), so changing it later never changes an existing memo.
 */
class CandidacyStaffProfile extends Model
{
    protected $table = 'candidacy_staff_profiles';

    protected $fillable = ['user_id', 'full_name', 'designation', 'signature_path', 'signature_sha256', 'signature_updated_at', 'is_demo', 'department_id'];

    protected function casts(): array
    {
        return ['signature_updated_at' => 'datetime', 'is_demo' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(CandidacyDepartment::class, 'department_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(CandidacyStaffAssignment::class, 'staff_profile_id');
    }

    public function hasSignature(): bool
    {
        return $this->signature_path !== null;
    }
}
