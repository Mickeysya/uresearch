<?php

namespace App\Modules\Nureen\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One automatic attendance early-warning email, as sent. */
class AttendanceAlert extends Model
{
    protected $fillable = [
        'student_id', 'reason', 'current_percentage', 'projected_percentage', 'supervisor_copied', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'current_percentage' => 'float',
            'projected_percentage' => 'float',
            'supervisor_copied' => 'boolean',
            'sent_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}
