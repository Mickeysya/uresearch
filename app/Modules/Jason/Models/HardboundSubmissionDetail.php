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
        'country', 'intake', 'passport_no', 'date_of_birth',
        'supervisor_name',
        'internal_examiner_id', 'internal_examiner_name', 'internal_examiner_email',
        'external_examiner_id', 'external_examiner_name', 'external_examiner_email',
        'viva_date', 'co_supervisor_name', 'resubmission_of_id', 'response_to_comments',
    ];

    protected function casts(): array
    {
        return ['viva_date' => 'date', 'date_of_birth' => 'date'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** The submission this one replaces, when CGS returned an earlier attempt. */
    public function resubmissionOf(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'resubmission_of_id');
    }

    /**
     * The examiners the candidate named, as [slot => [name, email]].
     *
     * A slot left empty is simply not named: the Confirmation has one
     * examiner block and either of them signing fills it, so a submission
     * naming only one is complete.
     *
     * @return array<string, array{name: string, email: string}>
     */
    public function namedExaminers(): array
    {
        $named = [];

        foreach (['internal', 'external'] as $slot) {
            $email = $this->{$slot.'_examiner_email'};

            if (filled($email)) {
                $named[$slot] = [
                    'name' => (string) $this->{$slot.'_examiner_name'},
                    'email' => (string) $email,
                ];
            }
        }

        return $named;
    }

    public function isResubmission(): bool
    {
        return $this->resubmission_of_id !== null;
    }

    /**
     * The submissions a student may currently resubmit: any that was
     * returned -- a rejection at any stage of this chain is a return -- and
     * has not yet been replaced. Once, per submission.
     *
     * The single source of that rule -- the sidebar link, the Hardbound
     * Submission page, the tracking summary and the controller's guard all
     * ask this.
     *
     * @return Builder<Application>
     */
    public static function resubmittableFor(int $studentId): Builder
    {
        $replaced = static::whereNotNull('resubmission_of_id')->select('resubmission_of_id');

        return Application::query()
            ->where('module_type', 'hardbound_submission')
            ->where('student_id', $studentId)
            ->where('status', Application::STATUS_REJECTED)
            ->whereNotIn('id', $replaced)
            ->orderByDesc('id');
    }
}
