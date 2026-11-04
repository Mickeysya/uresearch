<?php

namespace App\Modules\Chloe\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The Supervisor / Programme Manager a student was enrolled with (see the migration for why this table exists). */
class CandidacyStudentSupervisor extends Model
{
    public const KIND_SUPERVISOR = 'supervisor';

    public const KIND_PROGRAMME_MANAGER = 'programme_manager';

    protected $table = 'candidacy_student_supervisors';

    protected $fillable = ['student_id', 'demo_profile_id', 'staff_profile_id', 'kind', 'is_demo'];

    protected function casts(): array
    {
        return ['is_demo' => 'boolean'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(CandidacyStaffProfile::class, 'staff_profile_id');
    }
}
