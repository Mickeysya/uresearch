<?php

namespace App\Modules\Chloe\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidacyStaffAssignment extends Model
{
    public const ROLE_LECTURER = 'lecturer';

    public const ROLE_CHAIR = 'chair';

    public const ROLE_DEAN = 'dean';

    protected $table = 'candidacy_staff_assignments';

    protected $fillable = ['department_id', 'staff_profile_id', 'role'];

    public function department(): BelongsTo
    {
        return $this->belongsTo(CandidacyDepartment::class, 'department_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(CandidacyStaffProfile::class, 'staff_profile_id');
    }
}
