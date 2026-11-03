<?php

namespace App\Modules\Nureen\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per `attendance:sync` run -- what source, when, and the
 * read/added/updated/rejected counts the upload page's status panel and
 * the "Sync Now" button both read back.
 */
class AttendanceSyncRun extends Model
{
    protected $fillable = [
        'source', 'started_at', 'finished_at',
        'rows_read', 'rows_added', 'rows_updated', 'rows_rejected', 'rows_newly_at_risk',
        'rejection_reasons',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'rejection_reasons' => 'array',
        ];
    }
}
