<?php

namespace App\Modules\Jason\Models;

use App\Modules\Core\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HardboundSubmissionDetail extends Model
{
    protected $fillable = [
        'application_id', 'thesis_title', 'matric_no', 'programme',
        'supervisor_name', 'resubmission_of_id', 'response_to_comments',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** The submission this one replaces, when CGS returned an earlier attempt. */
    public function resubmissionOf(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'resubmission_of_id');
    }

    public function isResubmission(): bool
    {
        return $this->resubmission_of_id !== null;
    }
}
