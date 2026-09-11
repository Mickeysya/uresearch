<?php

namespace App\Modules\Norhanis\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClaimsItem extends Model
{
    protected $fillable = [
        'claims_detail_id', 'item_date', 'travel_from', 'travel_to',
        'flight_train_amount', 'meal_allowance', 'lodging_amount', 'misc_amount',
    ];

    protected function casts(): array
    {
        return [
            'item_date' => 'date',
            'flight_train_amount' => 'decimal:2',
            'meal_allowance' => 'decimal:2',
            'lodging_amount' => 'decimal:2',
            'misc_amount' => 'decimal:2',
        ];
    }

    public function claimsDetail(): BelongsTo
    {
        return $this->belongsTo(ClaimsDetail::class);
    }
}