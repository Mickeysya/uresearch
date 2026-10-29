<?php

namespace App\Modules\Hani\Models;

use App\Modules\Core\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VivaDetail extends Model
{
    protected $fillable = [
        'application_id', 'programme_level', 'thesis_title', 'expected_softbound_date',
    ];

    protected function casts(): array
    {
        return ['expected_softbound_date' => 'date'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
