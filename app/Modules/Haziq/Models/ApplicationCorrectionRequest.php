<?php

namespace App\Modules\Haziq\Models;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Any approver flags either one document, one whole section, or (for
 * Section B/C, which have a fixed field list) one specific field as wrong,
 * without rejecting the application. target_type is 'document' or
 * 'section_b'..'section_f'; target_field is the raw field key
 * (e.g. 'project_title') when one specific field was flagged, null when the
 * whole section or a document was. target_label stays the human-readable
 * version for display; target_field is what the student's edit form uses to
 * highlight the exact input. Never touches applications.status/current_stage
 * — see the migration's docblock.
 */
class ApplicationCorrectionRequest extends Model
{
    protected $fillable = [
        'application_id', 'target_type', 'target_field', 'target_label', 'remarks', 'requested_by', 'requested_at',
        'resolved_at', 'resolved_document_id',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function resolvedDocument(): BelongsTo
    {
        return $this->belongsTo(ApplicationDocument::class, 'resolved_document_id');
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }

    public function isDocument(): bool
    {
        return $this->target_type === 'document';
    }
}
