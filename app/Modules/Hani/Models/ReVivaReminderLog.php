<?php

namespace App\Modules\Hani\Models;

use Illuminate\Database\Eloquent\Model;

/** What `re-viva:monitor` has already sent. See the 2026_11_06_000300 migration. */
class ReVivaReminderLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['re_viva_detail_id', 'kind', 'reference', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
