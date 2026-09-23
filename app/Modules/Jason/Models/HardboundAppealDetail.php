<?php

namespace App\Modules\Jason\Models;

use App\Modules\Core\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An appeal for an extension of the hardbound thesis submission deadline:
 * what the candidate's memo says.
 */
class HardboundAppealDetail extends Model
{
    protected $fillable = [
        'application_id', 'reason', 'original_deadline', 'requested_until',
    ];

    protected function casts(): array
    {
        return ['original_deadline' => 'date', 'requested_until' => 'date'];
    }

    /** The extension request itself. */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
