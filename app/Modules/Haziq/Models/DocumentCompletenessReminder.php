<?php

namespace App\Modules\Haziq\Models;

use App\Modules\Core\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per application being chased for missing documents — throttles
 * Support\DocumentCompleteness::remind() to once a week per application
 * and feeds the "last reminded" column on the Document Completeness report.
 */
class DocumentCompletenessReminder extends Model
{
    protected $fillable = ['application_id', 'reminder_count', 'last_reminded_at'];

    protected function casts(): array
    {
        return [
            'reminder_count' => 'integer',
            'last_reminded_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
