<?php

namespace App\Modules\Haziq\Models;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** GA's own allowance ledger -- see this table's migration for why it is separate from AllowancePaymentLog. */
class GaAllowancePaymentLog extends Model
{
    protected $fillable = [
        'application_id', 'student_id', 'payment_month', 'amount', 'confirmed_amount',
        'proof_document_id', 'confirmed_by', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'payment_month' => 'date',
            'amount' => 'decimal:2',
            'confirmed_amount' => 'decimal:2',
            'confirmed_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function proofDocument(): BelongsTo
    {
        return $this->belongsTo(ApplicationDocument::class, 'proof_document_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }
}
