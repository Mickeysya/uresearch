<?php

namespace App\Modules\Norhanis\Models;

use App\Modules\Core\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClaimsDetail extends Model
{
    protected $fillable = [
        'application_id', 'publication_application_id', 'bank_name', 'bank_account_no', 'total_claims',
    ];

    protected function casts(): array
    {
        return [
            'total_claims' => 'decimal:2',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** The publication this claim pays for: its detail row, by application id. */
    public function publication(): BelongsTo
    {
        return $this->belongsTo(PublicationDetail::class, 'publication_application_id', 'application_id');
    }
}