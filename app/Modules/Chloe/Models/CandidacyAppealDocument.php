<?php

namespace App\Modules\Chloe\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidacyAppealDocument extends Model
{
    public const KIND_OFFICIAL_FORM = 'official_form';

    public const KIND_SUPPORTING = 'supporting';

    public $timestamps = false;

    protected $table = 'candidacy_appeal_documents';

    protected $fillable = ['candidacy_appeal_id', 'kind', 'original_name', 'stored_path', 'mime', 'size', 'sha256', 'round_uploaded', 'uploaded_at'];

    protected function casts(): array
    {
        return ['uploaded_at' => 'datetime'];
    }

    public function appeal(): BelongsTo
    {
        return $this->belongsTo(CandidacyAppeal::class, 'candidacy_appeal_id');
    }
}
