<?php

namespace App\Modules\Norhanis\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicationAuthor extends Model
{
    // The certification columns (signature_png, signed_at, ...) are left out
    // on purpose: only PublicationSignOffs::sign() writes them.
    protected $fillable = [
        'publication_detail_id', 'author_name', 'email', 'designation', 'organisation', 'role_contribution',
    ];

    protected $hidden = ['signature_png'];

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
            'invited_at' => 'datetime',
        ];
    }

    public function publicationDetail(): BelongsTo
    {
        return $this->belongsTo(PublicationDetail::class);
    }

    public function hasSigned(): bool
    {
        return $this->signed_at !== null;
    }
}
