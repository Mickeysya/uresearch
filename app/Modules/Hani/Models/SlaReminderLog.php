<?php

namespace App\Modules\Hani\Models;

use Illuminate\Database\Eloquent\Model;

/** What `hani:sla-reminders` has already sent. See the 2026_11_07_000100 migration. */
class SlaReminderLog extends Model
{
    public $timestamps = false;

    protected $table = 'hani_sla_reminder_logs';

    protected $fillable = ['application_id', 'stage_key', 'entered_at', 'threshold', 'days', 'sent', 'sent_at'];

    protected function casts(): array
    {
        return ['entered_at' => 'datetime', 'sent_at' => 'datetime', 'sent' => 'boolean'];
    }
}
