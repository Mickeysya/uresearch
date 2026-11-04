<?php

namespace App\Modules\Haziq\Models;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Supervisor/Senior Director's internal note on CGS's recommended
 * proposed_allowance — visible to Admin/CGS/Supervisor/Senior Director,
 * never to the student. See this table's migration for why it isn't just
 * another ApplicationCorrectionRequest.
 */
class AllowanceRecommendationNote extends Model
{
    protected $fillable = ['application_id', 'author_id', 'reason'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
