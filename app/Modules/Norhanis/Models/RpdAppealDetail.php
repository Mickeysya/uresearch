<?php

namespace App\Modules\Norhanis\Models;

use App\Modules\Core\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * requested_extension_months (1-6) is what the student asked for; on the
 * Dean's approval the candidacy's deadline is extended by that many months.
 * See RpdAppealController::extendCandidacy().
 */
class RpdAppealDetail extends Model
{
    protected $fillable = ['application_id', 'candidacy_id', 'reason', 'requested_extension_months'];

    protected function casts(): array
    {
        return ['requested_extension_months' => 'integer'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function candidacy(): BelongsTo
    {
        return $this->belongsTo(Candidacy::class);
    }
}
