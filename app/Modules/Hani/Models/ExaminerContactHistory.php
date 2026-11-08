<?php

namespace App\Modules\Hani\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of an examiner's email history. Written only by Support\ExaminerContact. */
class ExaminerContactHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'examiner_contact_history';

    public const SOURCES = [
        'manual' => 'Edited on the examiner form',
        'email_form' => 'Email updated',
        'import' => 'Filled in by a pool import',
        'proposal' => 'Confirmed from a nomination',
        'reported' => 'Reported not working',
        'confirmed' => 'Confirmed still valid',
        'merge' => 'Moved by a record merge',
    ];

    protected $fillable = ['examiner_id', 'old_email', 'new_email', 'source', 'reason', 'changed_by_id'];

    public function examiner(): BelongsTo
    {
        return $this->belongsTo(Examiner::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_id');
    }

    public function sourceLabel(): string
    {
        return self::SOURCES[$this->source] ?? ucfirst($this->source);
    }

    /** Whether this line changed the address, rather than reporting or confirming it. */
    public function isChange(): bool
    {
        return $this->old_email !== $this->new_email;
    }
}
