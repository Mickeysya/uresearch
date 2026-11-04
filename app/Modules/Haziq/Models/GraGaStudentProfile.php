<?php

namespace App\Modules\Haziq\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GraGaStudentProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'level_of_study', 'programme', 'mode_of_study', 'admission_date',
        'thesis_title', 'co_supervisor_id', 'ic_passport_no', 'visa_expiry_date',
        'bank_name', 'bank_account_no', 'previous_sponsorship_name', 'previous_sponsorship_duration',
        'scholarship_name', 'scholarship_duration',
    ];

    protected function casts(): array
    {
        return [
            'admission_date' => 'date',
            'visa_expiry_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function coSupervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'co_supervisor_id');
    }

    public function academicQualifications(): HasMany
    {
        return $this->hasMany(AcademicQualification::class, 'profile_id');
    }

    public function workExperiences(): HasMany
    {
        return $this->hasMany(WorkExperience::class, 'profile_id');
    }

    public function publications(): HasMany
    {
        return $this->hasMany(Publication::class, 'profile_id');
    }

    /** Months elapsed since admission — the clock every Stage Gate deadline is measured against. */
    public function candidatureMonth(): int
    {
        return (int) $this->admission_date->diffInMonths(now()->startOfDay());
    }
}
