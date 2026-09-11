<?php

namespace App\Modules\Norhanis\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicationAuthor extends Model
{
    protected $fillable = [
        'publication_detail_id', 'name', 'is_corresponding_author',
    ];

    protected function casts(): array
    {
        return [
            'is_corresponding_author' => 'boolean',
        ];
    }

    public function publicationDetail(): BelongsTo
    {
        return $this->belongsTo(PublicationDetail::class);
    }
}
