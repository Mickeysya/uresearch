<?php

namespace App\Modules\Norhanis\Models;

use App\Modules\Core\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RpdDismissalDetail extends Model
{
    protected $fillable = ['application_id', 'candidacy_id', 'reason'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function candidacy(): BelongsTo
    {
        return $this->belongsTo(Candidacy::class);
    }
}
