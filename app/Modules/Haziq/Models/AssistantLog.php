<?php

namespace App\Modules\Haziq\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantLog extends Model
{
    protected $table = 'haziq_assistant_logs';

    protected $fillable = ['student_id', 'question', 'answer', 'outcome', 'error_code'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}
