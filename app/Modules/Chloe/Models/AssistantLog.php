<?php

namespace App\Modules\Chloe\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PART 7: metadata only. Deliberately no `question`/`answer` column at
 * all, not even one left unused -- there is nothing in this class that
 * could ever be filled with the question or answer text, a name, a
 * student ID or an email, because the column does not exist to fill.
 * `lookup` holds only a lookup's own name, "guidance:<topic>", or a
 * refusal-type string (see Assistant\Pipeline), never anything a user
 * typed.
 *
 * Append-only, like WorkstationHistory: no route, no screen, and no
 * method anywhere in this codebase calls ->update() or ->delete() on a
 * row here. One row is written per request (see AssistantLog::record()),
 * even for asked_back/refused/unavailable -- nothing is skipped.
 */
class AssistantLog extends Model
{
    public const SOURCE_SUGGESTION = 'suggestion';

    public const SOURCE_TYPED = 'typed';

    public const OUTCOME_ANSWERED = 'answered';

    public const OUTCOME_ASKED_BACK = 'asked_back';

    public const OUTCOME_REFUSED = 'refused';

    public const OUTCOME_UNAVAILABLE = 'unavailable';

    public const OUTCOME_ERROR = 'error';

    public const ANSWERED_BY_RULE = 'rule';

    public const ANSWERED_BY_MODEL = 'model';

    public const ANSWERED_BY_DIRECT = 'direct';

    /** PART B.7: the single model call's own guidance prose passed validation and was used (vs. ANSWERED_BY_DIRECT, meaning it fell back to raw knowledge-section text). */
    public const ANSWERED_BY_MODEL_GUIDANCE = 'model_guidance';

    /** PART B.4: the SECOND, aggregate-composing model call's prose passed validation and was used for a lookup answer (vs. ANSWERED_BY_MODEL, meaning only the lookup CHOICE came from the model and the answer text is the template). */
    public const ANSWERED_BY_MODEL_COMPOSE = 'model_compose';

    /** The new router call (Part 2) picked the catalogue id this answer came from. */
    public const ANSWERED_BY_MODEL_ROUTER = 'model_router';

    /** Part 5: the router was unavailable or returned invalid output, and the existing deterministic layer (keyword rules, period extractor) answered instead. */
    public const ANSWERED_BY_RULE_FALLBACK = 'rule_fallback';

    public $timestamps = false;

    protected $fillable = [
        'domain',
        'user_id', 'role', 'occurred_at', 'source', 'lookup',
        'outcome', 'refusal_reason', 'result_count', 'answered_by',
        'model_used', 'error_code', 'catalogue_kind', 'route', 'calls_primary', 'calls_fallback',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $log) {
            $log->occurred_at ??= now();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The one place a row is written -- see this class's own doc for what it deliberately never stores. */
    public static function record(
        int $userId,
        string $role,
        string $source,
        string $lookup,
        string $outcome,
        ?string $refusalReason = null,
        ?int $resultCount = null,
        string $answeredBy = self::ANSWERED_BY_DIRECT,
        ?string $modelUsed = null,
        ?string $errorCode = null,
        ?string $catalogueKind = null,
        string $domain = 'workstation',
        ?string $route = null,
        int $callsPrimary = 0,
        int $callsFallback = 0,
    ): self {
        return self::create([
            'user_id' => $userId,
            'role' => $role,
            'source' => $source,
            'lookup' => $lookup,
            'outcome' => $outcome,
            'refusal_reason' => $refusalReason,
            'result_count' => $resultCount,
            'answered_by' => $answeredBy,
            'model_used' => $modelUsed,
            'error_code' => $errorCode,
            'catalogue_kind' => $catalogueKind,
            'domain' => $domain,
            'route' => $route,
            'calls_primary' => $callsPrimary,
            'calls_fallback' => $callsFallback,
        ]);
    }
}
