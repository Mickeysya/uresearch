<?php

namespace App\Modules\Haziq\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Replaces gra_terms_acceptances and ga_terms_acceptances (identical
 * columns, same usage) -- docs/table-audit.md finding A, agreed with
 * Sharvin 2026-11-08. One row per student per scheme, not per
 * application: accepting once covers every application of that scheme
 * the student ever submits afterwards. The two schemes stay genuinely
 * separate acceptances (confirmed explicitly 2026-11-03: "ga is
 * different from gra" -- different documents, accepting one is never
 * accepting the other), kept apart here by `scheme` rather than by two
 * tables.
 */
class TermsAcceptance extends Model
{
    use HasFactory;

    public const GRA = 'gra';

    public const GA = 'ga';

    protected $fillable = ['user_id', 'scheme', 'accepted_at'];

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
