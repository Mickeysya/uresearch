<?php

namespace App\Modules\Norhanis\Models;

use App\Modules\Core\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What a student typed into an unfinished Travel, Publication or Claims form. See Support\Drafts. */
class SavedDraft extends Model
{
    protected $table = 'norhanis_drafts';

    protected $fillable = ['application_id', 'payload', 'step'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'step' => 'integer'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
