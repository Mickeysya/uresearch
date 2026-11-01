<?php

namespace App\Modules\Chloe\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One confirmed upload of the Registry's result. */
class CandidacyDismissalImport extends Model
{
    protected $fillable = ['uploaded_by', 'file_name', 'uploaded_at', 'rows_total', 'dismissed', 'skipped', 'summary', 'is_demo'];

    protected function casts(): array
    {
        return ['uploaded_at' => 'datetime', 'is_demo' => 'boolean'];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(CandidacyDismissalImportRow::class, 'import_id');
    }
}
