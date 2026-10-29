<?php

namespace App\Modules\Hani\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use Illuminate\Support\Collection;

/**
 * Where one student is in the viva, read across the three applications it
 * spans: the Notice of Thesis Submission (viva_voce), their softbound
 * attempts (viva_softbound) and the examiner nomination
 * (examiner_nomination, its own chain, only ever read here).
 */
final class VivaProgress
{
    public const NOTICE = 'viva_voce';

    public const SOFTBOUND = 'viva_softbound';

    public const NOMINATION = 'examiner_nomination';

    public function __construct(
        public readonly ?Application $notice,
        public readonly ?Application $softbound,
        public readonly ?Application $nomination,
    ) {}

    public static function for(User $student): self
    {
        $latest = fn (string $module) => Application::query()
            ->where('module_type', $module)
            ->where('student_id', $student->id)
            ->latest('id')
            ->first();

        return new self(VivaChecklist::openNotice($student), $latest(self::SOFTBOUND), $latest(self::NOMINATION));
    }

    /**
     * Why this student cannot file a softbound thesis right now, or null if
     * they can. A returned (rejected) attempt does not block a new one.
     */
    public function softboundBlockReason(): ?string
    {
        return match (true) {
            $this->notice === null => 'Submit your Notice of Thesis Submission first.',
            $this->notice->status === Application::STATUS_PENDING => 'Your notice is still waiting for your Academic Executive to acknowledge it.',
            $this->softbound?->status === Application::STATUS_PENDING => 'Your softbound thesis is already with your Academic Executive.',
            $this->softbound?->status === Application::STATUS_APPROVED => 'Your softbound thesis has already been verified.',
            default => null,
        };
    }

    /** The AE's reason, when the latest softbound attempt was returned. */
    public function softboundReturnReason(): ?string
    {
        return $this->softbound?->status === Application::STATUS_REJECTED
            ? ($this->softbound->rejection()?->remarks ?: 'No reason was recorded.')
            : null;
    }

    public function nominationLabel(): string
    {
        return self::describeNomination($this->nomination);
    }

    public static function describeNomination(?Application $nomination): string
    {
        return match ($nomination?->status) {
            null => 'Not started',
            Application::STATUS_APPROVED => 'Approved',
            Application::STATUS_REJECTED => 'Not approved',
            default => 'In progress: '.($nomination->currentStage()?->label ?? 'pending'),
        };
    }

    /**
     * The latest examiner nomination for each of these students, in one
     * query, for the AE's queue.
     *
     * @param  iterable<int>  $studentIds
     * @return Collection<int, Application> keyed by student_id
     */
    public static function nominationsFor(iterable $studentIds): Collection
    {
        // Ascending, so keyBy() keeps the highest id per student.
        return Application::query()
            ->where('module_type', self::NOMINATION)
            ->whereIn('student_id', collect($studentIds)->all())
            ->orderBy('id')
            ->get()
            ->keyBy('student_id');
    }
}
