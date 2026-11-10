<?php

namespace App\Modules\Haziq\Models;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class GaApplicationDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'application_id', 'profile_id', 'candidature_status', 'department_unit', 'purpose',
        'proposed_hours_per_week',
        'service_start_date', 'service_end_date', 'proposed_allowance', 'eligibility_notes',
        'interview_result', 'interview_date', 'interview_ends_at', 'interview_scheduled_at', 'interview_notes', 'interviewed_by',
    ];

    protected function casts(): array
    {
        return [
            'service_start_date' => 'date',
            'service_end_date' => 'date',
            'proposed_allowance' => 'decimal:2',
            'interview_date' => 'datetime',
            'interview_ends_at' => 'datetime',
            'interview_scheduled_at' => 'datetime',
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

    /**
     * Same display-only concept as GraApplicationDetail::serviceStatus() --
     * see that method's own docblock. GA has no application_type split of
     * its own (a GA extension, through Nureen's ga_extension module,
     * updates this SAME row's service_end_date in place via
     * ApprovedGaApplicationProvider::extendEndDate() rather than creating a
     * new Application row the way a GRA extension does), so re-reading
     * service_end_date fresh here is all a GA extension ever needs to flip
     * this back to "active".
     */
    public function serviceStatus(): string
    {
        if ($this->isTerminated()) {
            return 'terminated';
        }

        $today = Carbon::today();

        if ($today->lt($this->service_start_date)) {
            return 'approved';
        }

        if ($today->gt($this->service_end_date)) {
            return 'inactive';
        }

        return 'active';
    }
}
