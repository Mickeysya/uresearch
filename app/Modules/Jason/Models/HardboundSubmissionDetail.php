<?php

namespace App\Modules\Jason\Models;

use App\Modules\Core\Models\Application;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * The submissions a student may currently resubmit. Two things qualify
     * and nothing else: CGS returned it at the review stage, or the Senior
     * Executive rejected it and an appeal against that rejection has been
     * upheld. Either way, only until it has been replaced once.
     *
     * The single source of that rule -- the sidebar link, the tracking
     * summary and the controller's guard all ask this.
     *
     * @return Builder<Application>
     */
    public static function resubmittableFor(int $studentId): Builder
    {
        $replaced = static::whereNotNull('resubmission_of_id')->select('resubmission_of_id');

        $upheld = HardboundAppealDetail::query()
            ->whereHas('application', fn ($q) => $q->where('status', Application::STATUS_APPROVED))
            ->select('hardbound_application_id');

        return Application::query()
            ->where('module_type', 'hardbound_submission')
            ->where('student_id', $studentId)
            ->where('status', Application::STATUS_REJECTED)
            ->whereNotIn('id', $replaced)
            ->where(fn ($q) => $q
                ->where('current_stage', 'cgs_review')
                ->orWhereIn('id', $upheld))
            ->orderByDesc('id');
    }
}
