<?php

namespace App\Modules\Haziq\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkExperience extends Model
{
    use HasFactory;

    protected $table = 'gra_work_experiences';

    protected $fillable = [
        'profile_id', 'company_name', 'designation', 'job_description', 'date_from', 'date_to', 'reason_for_leaving',
    ];

    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(GraGaStudentProfile::class, 'profile_id');
    }
}
