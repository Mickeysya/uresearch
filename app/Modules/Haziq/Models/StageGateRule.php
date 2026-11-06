<?php

namespace App\Modules\Haziq\Models;

use Illuminate\Database\Eloquent\Model;

class StageGateRule extends Model
{
    protected $fillable = [
        'level_of_study', 'stage_order', 'stage_name', 'requirement_description',
        'deadline_month', 'grace_end_month', 'recoverable',
    ];

    protected function casts(): array
    {
        return ['recoverable' => 'boolean'];
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, self> */
    public static function forLevel(string $level)
    {
        return static::where('level_of_study', $level)->orderBy('stage_order')->get();
    }
}
