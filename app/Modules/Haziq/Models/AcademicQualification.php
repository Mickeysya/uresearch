<?php

namespace App\Modules\Haziq\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicQualification extends Model
{
    use HasFactory;

    protected $table = 'gra_academic_qualifications';

    protected $fillable = [
        'profile_id', 'level_of_study', 'institution', 'programme', 'result', 'year_from', 'year_to',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(GraGaStudentProfile::class, 'profile_id');
    }
}
