<?php

namespace App\Modules\Hani\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One email, or refusal, of a correction letter. See the 2026_11_06_000800 migration. */
class ReVivaLetterSend extends Model
{
    public const FIRST = 'first';

    public const RESEND = 'resend';

    protected $fillable = ['re_viva_staff_document_id', 'sent_by_id', 'kind', 'emailed', 'skipped_reason', 'link_expires_at'];

    protected function casts(): array
    {
        return ['emailed' => 'boolean', 'link_expires_at' => 'datetime'];
    }

    public function letter(): BelongsTo
    {
        return $this->belongsTo(ReVivaStaffDocument::class, 're_viva_staff_document_id');
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_id');
    }
}
