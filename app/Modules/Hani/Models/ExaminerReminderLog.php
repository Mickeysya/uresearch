<?php

namespace App\Modules\Hani\Models;

use Illuminate\Database\Eloquent\Model;

/** A reminder `examiners:sweep` has sent. See the migration. */
class ExaminerReminderLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['nomination_id', 'kind', 'sequence', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
