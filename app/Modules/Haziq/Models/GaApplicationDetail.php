<?php

namespace App\Modules\Haziq\Models;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GaApplicationDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'application_id', 'profile_id', 'candidature_status', 'department_unit', 'purpose',
        'proposed_hours_per_week',
        'service_start_date', 'service_end_date', 'proposed_allowance', 'eligibility_notes',
        'interview_result', 'interview_date', 'interview_notes', 'interviewed_by',
    ];

    protected function casts(): array
    {
        return [
            'service_start_date' => 'date',
            'service_end_date' => 'date',
            'proposed_allowance' => 'decimal:2',
            'interview_date' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(GraGaStudentProfile::class, 'profile_id');
    }

    public function interviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'interviewed_by');
    }

    public function isTerminated(): bool
    {
        return $this->candidature_status === 'terminated';
    }
}
