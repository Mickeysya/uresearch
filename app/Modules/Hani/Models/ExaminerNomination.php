<?php

namespace App\Modules\Hani\Models;

use App\Modules\Core\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExaminerNomination extends Model
{
    protected $fillable = [
        'application_id', 'main_examiner_id', 'backup_examiner_id', 'thesis_title', 'notes',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function mainExaminer(): BelongsTo
    {
        return $this->belongsTo(Examiner::class, 'main_examiner_id');
    }

    public function backupExaminer(): BelongsTo
    {
        return $this->belongsTo(Examiner::class, 'backup_examiner_id');
    }
}
