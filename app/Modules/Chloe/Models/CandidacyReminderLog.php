<?php

namespace App\Modules\Chloe\Models;

use Illuminate\Database\Eloquent\Model;

class CandidacyReminderLog extends Model
{
    public const SENT = 'sent';

    public const SKIPPED = 'skipped';

    protected $table = 'candidacy_reminder_log';

    protected $fillable = ['candidacy_id', 'demo_profile_id', 'student_id', 'expiry_date', 'milestone', 'variant', 'status', 'sent_at'];

    protected function casts(): array
    {
        return ['expiry_date' => 'date', 'sent_at' => 'datetime'];
    }
}
