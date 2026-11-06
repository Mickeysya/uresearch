<?php

namespace App\Modules\Haziq\Models;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Admin or CGS confirming they've actually disbursed an application's
 * accumulated allowance, with proof — see this table's migration for why
 * it's distinct from Stage Gate completion.
 */
class AllowancePaymentConfirmation extends Model
{
    protected $fillable = [
        'application_id', 'confirmed_month', 'amount', 'proof_document_id', 'confirmed_by', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'confirmed_month' => 'date',
            'amount' => 'decimal:2',
            'confirmed_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function proofDocument(): BelongsTo
    {
        return $this->belongsTo(ApplicationDocument::class, 'proof_document_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
