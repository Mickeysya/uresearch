<?php

namespace App\Modules\Hani\Models;

use App\Modules\Hani\Support\Import\PoolRow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** A parsed pool file awaiting confirmation. See the migration. */
class ExaminerImportBatch extends Model
{
    protected $fillable = ['user_id', 'layout', 'filename', 'rows', 'messages', 'summary', 'confirmed_at'];

    protected function casts(): array
    {
        return [
            'rows' => 'array',
            'messages' => 'array',
            'summary' => 'array',
            'confirmed_at' => 'datetime',
        ];
    }

    /** @return array<int, PoolRow> */
    public function poolRows(): array
    {
        return array_map(fn (array $row) => PoolRow::fromArray($row), $this->rows ?? []);
    }

    /** Unconfirmed previews older than the configured lifetime. */
    public function scopeStale(Builder $query): Builder
    {
        return $query->whereNull('confirmed_at')
            ->where('created_at', '<', now()->subHours((int) config('hani.import.batch_ttl_hours', 24)));
    }

    public static function purgeStale(): int
    {
        return static::stale()->delete();
    }
}
