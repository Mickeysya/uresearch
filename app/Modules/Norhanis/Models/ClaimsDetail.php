<?php

namespace App\Modules\Norhanis\Models;

use App\Modules\Core\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClaimsDetail extends Model
{
    protected $fillable = [
        'application_id', 'purpose_of_claim', 'bank_account_no',
        'total_claim_amount', 'less_cash_advance', 'claim_balance',
    ];

    protected function casts(): array
    {
        return [
            'total_claim_amount' => 'decimal:2',
            'less_cash_advance' => 'decimal:2',
            'claim_balance' => 'decimal:2',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ClaimsItem::class);
    }
}