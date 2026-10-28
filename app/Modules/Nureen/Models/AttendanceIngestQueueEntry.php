<?php

namespace App\Modules\Nureen\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One staged row from AttendanceIngestController -- written on POST,
 * consumed and marked processed by PushEndpointSource on the next
 * `attendance:sync` run. Keeps the HTTP endpoint thin (check the key,
 * validate the shape, stage it) and all the actual ingestion logic in one
 * place shared with every other source.
 */
class AttendanceIngestQueueEntry extends Model
{
    protected $table = 'attendance_ingest_queue';

    protected $fillable = ['payload', 'received_at', 'processed_at'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
