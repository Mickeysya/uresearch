<?php

namespace App\Modules\Chloe\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** DEMO DATA: the stand-in student status for one of our demo students (see Support\StudentStatusReader). */
class CandidacyDemoStudentStatus extends Model
{
    protected $table = 'candidacy_demo_student_statuses';

    protected $fillable = ['demo_profile_id', 'softbound_submitted_at', 'completed', 'inactive', 'label'];

    protected function casts(): array
    {
        return ['softbound_submitted_at' => 'date', 'completed' => 'boolean', 'inactive' => 'boolean'];
    }

    public function demoProfile(): BelongsTo
    {
        return $this->belongsTo(DemoStudentProfile::class, 'demo_profile_id');
    }
}
