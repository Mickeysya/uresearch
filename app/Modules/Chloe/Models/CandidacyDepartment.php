<?php

namespace App\Modules\Chloe\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A department an appeal is routed by: its lecturers, its chair and (optionally) its dean come from the assignments. */
class CandidacyDepartment extends Model
{
    protected $table = 'candidacy_departments';

    protected $fillable = ['code', 'name', 'active', 'is_demo'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(CandidacyStaffAssignment::class, 'department_id');
    }
}
