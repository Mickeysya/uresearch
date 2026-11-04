<?php

namespace App\Modules\Chloe\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A "student" that exists only for demo/testing purposes -- no `users` row,
 * no login, no student_genders row. Every row in this table IS a demo
 * student; there is no is_demo flag anywhere (the table itself is the
 * marker). See Support\StudentDirectory, the one place that reads this
 * table alongside `users` so the rest of the module never has to care
 * which kind of student it is looking at.
 *
 * programme_start_date / candidacy_expiry_date / status mirror
 * StudyCandidacy's own field names for a future feature to read; nothing
 * creates an actual StudyCandidacy row for a demo profile.
 */
class DemoStudentProfile extends Model
{
    public const STATUS_ACTIVE = 'active';

    protected $fillable = [
        'matric_no', 'name', 'email', 'gender', 'programme',
        'programme_start_date', 'candidacy_expiry_date', 'status', 'candidacy_only', 'dismissed_at', 'dismissed_by_id',
    ];

    /**
     * Demo students made only for the Study Candidacy demo (candidacy_only) are invisible to everything that does not ask for them: Workstation
     * lists, searches, the demo seeder and the statistics never see them. Candidacy code asks with withoutGlobalScopes() (or the relations below).
     */
    protected static function booted(): void
    {
        static::addGlobalScope('workstation_visible', fn ($query) => $query->where($query->getModel()->getTable().'.candidacy_only', false));
    }

    protected function casts(): array
    {
        return [
            'programme_start_date' => 'date',
            'candidacy_expiry_date' => 'date',
            'candidacy_only' => 'boolean',
            'dismissed_at' => 'datetime',
        ];
    }

    public function workstationRequests(): HasMany
    {
        return $this->hasMany(WorkstationRequest::class, 'demo_profile_id');
    }

    public function lockerKeys(): HasMany
    {
        return $this->hasMany(LockerKey::class, 'demo_profile_id');
    }
}
