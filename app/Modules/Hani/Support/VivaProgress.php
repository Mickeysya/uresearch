<?php

namespace App\Modules\Hani\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\ExaminerNomination;
use App\Modules\Hani\Models\VivaDetail;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

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
        public readonly ?VivaDetail $detail = null,
    ) {}

    public static function for(User $student): self
    {
        $latest = fn (string $module) => Application::query()
            ->where('module_type', $module)
            ->where('student_id', $student->id)
            ->latest('id')
            ->first();

        $notice = VivaChecklist::openNotice($student);

        return new self(
            $notice,
            $latest(self::SOFTBOUND),
            $latest(self::NOMINATION),
            $notice ? VivaDetail::where('application_id', $notice->id)->first() : null,
        );
    }

    /**
     * Progress for many notices at once, in four queries however many there
     * are, for the AE's and the supervisor's lists.
     *
     * @param  Collection<int, Application>  $notices
     * @return Collection<int, self> keyed by notice id
     */
    public static function forNotices(Collection $notices): Collection
    {
        $studentIds = $notices->pluck('student_id')->unique();

        $softbounds = self::latestPerStudent(self::SOFTBOUND, $studentIds);
        $nominations = self::latestPerStudent(self::NOMINATION, $studentIds);
        $details = VivaDetail::whereIn('application_id', $notices->pluck('id'))->get()->keyBy('application_id');

        return $notices->mapWithKeys(fn (Application $notice) => [$notice->id => new self(
            $notice,
            $softbounds->get($notice->student_id),
            $nominations->get($notice->student_id),
            $details->get($notice->id),
        )]);
    }

    /**
     * Why this student cannot file a softbound thesis right now, or null if
     * they can. A returned (rejected) attempt does not block a new one.
     */
    public function softboundBlockReason(): ?string
    {
        return match (true) {
            $this->notice === null => 'Submit your Notice of Thesis Submission first.',
            $this->notice->status === Application::STATUS_PENDING && $this->notice->current_stage === \App\Modules\Hani\Workflows\VivaVoceWorkflow::SUPERVISOR_STAGE => 'Your notice is still with your supervisor to endorse.',
            $this->notice->status === Application::STATUS_PENDING => 'Your notice is still waiting for your Academic Executive to verify it.',
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

    /**
     * What still stands between this viva and a date. Empty means the AE may
     * schedule it: the guideline needs BOTH the nomination approved and the
     * softbound submitted, and the softbound verified is the AE's own check.
     *
     * @return array<int, string>
     */
    public function waitingFor(): array
    {
        return array_values(array_filter([
            $this->notice?->status !== Application::STATUS_APPROVED ? 'Notice not verified yet' : null,
            $this->softbound?->status !== Application::STATUS_APPROVED ? 'Softbound thesis not verified' : null,
            $this->nomination?->status !== Application::STATUS_APPROVED ? 'Examiner nomination '.lcfirst($this->nominationLabel()) : null,
        ]));
    }

    public function isReadyToSchedule(): bool
    {
        return $this->waitingFor() === [];
    }

    /** The main internal and external examiners, once the nomination is approved. */
    public function panel(): ?ExaminerNomination
    {
        if ($this->nomination?->status !== Application::STATUS_APPROVED) {
            return null;
        }

        return ExaminerNomination::with('internalMain', 'externalMain')
            ->where('application_id', $this->nomination->id)
            ->first();
    }

    public function softboundLabel(): string
    {
        return match ($this->softbound?->status) {
            null => 'Not submitted yet',
            Application::STATUS_PENDING => 'With the Academic Executive for checking',
            Application::STATUS_APPROVED => 'Verified',
            default => 'Returned for corrections',
        };
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
        return self::latestPerStudent(self::NOMINATION, $studentIds);
    }

    /**
     * The Academic Executives who own this student's viva: the AEs of the
     * student's department, the same scoping the engine applies to their queues.
     *
     * @return Collection<int, User>
     */
    public static function academicExecsFor(User $student): Collection
    {
        return User::where('role', Role::ACADEMIC_EXEC)
            ->where('department', $student->department)
            ->orderBy('name')
            ->get();
    }

    /**
     * Best effort: tell those AEs that new work is in their queue. After the
     * commit, and never failing the student's own submission.
     */
    public static function notifyAcademicExecs(User $student, object $notification): void
    {
        try {
            Notification::send(self::academicExecsFor($student), $notification);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @return Collection<int, Application> keyed by student_id */
    protected static function latestPerStudent(string $module, iterable $studentIds): Collection
    {
        // Ascending, so keyBy() keeps the highest id per student.
        return Application::query()
            ->where('module_type', $module)
            ->whereIn('student_id', collect($studentIds)->all())
            ->orderBy('id')
            ->get()
            ->keyBy('student_id');
    }
}
