<?php

namespace App\Modules\Norhanis\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicationAuthor extends Model
{
    protected $fillable = [
        'publication_detail_id', 'author_name', 'designation', 'organisation', 'role_contribution',
    ];

    public function publicationDetail(): BelongsTo
    {
        return $this->belongsTo(PublicationDetail::class);
    }
}
