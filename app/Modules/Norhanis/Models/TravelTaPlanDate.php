<?php

namespace App\Modules\Norhanis\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TravelTaPlanDate extends Model
{
    protected $fillable = ['ta_plan_id', 'date'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function taPlan(): BelongsTo
    {
        return $this->belongsTo(TravelTaPlan::class, 'ta_plan_id');
    }
}
