<?php

namespace App\Modules\Nureen\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Provisional stand-in for Haziq's (not yet built) GRA/GA appointment data
 * -- see Support\AppointmentVerifier and the migration that creates this
 * table.
 */
class GaAppointmentRecord extends Model
{
    protected $fillable = [
        'student_id', 'appointment_type', 'period_start', 'period_end',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}
