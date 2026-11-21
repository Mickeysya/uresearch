<?php

namespace App\Modules\Nureen\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One record `nureen:demo-fresh` created -- see that command and the
 * nureen_demo_records migration. `--reset` deletes what these point at,
 * then these.
 */
class DemoRecord extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'nureen_demo_records';

    protected $fillable = ['record_table', 'record_id'];

    public static function remember(string $table, int|iterable $ids): void
    {
        foreach (is_iterable($ids) ? $ids : [$ids] as $id) {
            static::firstOrCreate(['record_table' => $table, 'record_id' => (int) $id]);
        }
    }

    /** @return array<int, int> */
    public static function idsFor(string $table): array
    {
        return static::where('record_table', $table)->pluck('record_id')->map(fn ($id) => (int) $id)->all();
    }
}
