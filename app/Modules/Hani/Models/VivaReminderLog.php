<?php

namespace App\Modules\Hani\Models;

use Illuminate\Database\Eloquent\Model;

/** What `viva:reminders` has already sent. See the 2026_11_04_000400 migration. */
class VivaReminderLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['viva_application_id', 'kind', 'reference', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
