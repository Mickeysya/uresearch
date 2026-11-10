<?php

namespace App\Modules\Hani\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One time the Senior Executive returned a student's documents. */
class ReVivaDocumentReturn extends Model
{
    protected $fillable = ['re_viva_detail_id', 'documents', 'reason', 'returned_by_id', 'resubmitted_at'];

    protected function casts(): array
    {
        return ['documents' => 'array', 'resubmitted_at' => 'datetime'];
    }

    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by_id');
    }
}
