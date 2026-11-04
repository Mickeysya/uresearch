<?php

namespace App\Modules\Hani\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Hani\Models\ReVivaDetail;
use App\Modules\Hani\Models\VivaDetail;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The Senior Executive logs a re-viva round: the corrected thesis and the
 * Summary of Corrections reach CGS, and the round starts at Report Sent.
 * Used by ReVivaController::store() and the demo seeder.
 *
 *   - one open round per student; a new one only after a level-4 outcome
 *   - the window: the category-4 viva's (round 1), the level-4 outcome's
 *     (round N+1), or one worked out from a viva result date (legacy)
 *   - outside the window: logged, with a warning
 *   - the same main examiners stay held for the round
 */
class ReVivaLogging
{
    public const MODULE = 're_viva';

    public const DOC_THESIS = 'Re-corrected Thesis';

    public const DOC_SUMMARY = 'Summary of Corrections';

    public function __construct(
        protected WorkflowEngine $engine,
        protected DocumentStore $documents,
        protected PanelSeats $seats,
    ) {}

    /**
     * @return array{detail: ReVivaDetail, round: int, warning: ?string}
     */
    public function log(User $student, UploadedFile $thesis, UploadedFile $summary, ?string $vivaResultDate, User $actor): array
    {
        $latest = self::latestCycle($student->id);

        if ($reason = self::blockReason($latest)) {
            throw ValidationException::withMessages(['student_id' => $reason]);
        }

        // A student with no viva result in UResearch (decided on paper
        // before the portal) needs the result date from CGS.
        $window = ReVivaWindow::for($student, $latest);

        if (! $window) {
            if (blank($vivaResultDate)) {
                throw ValidationException::withMessages([
                    'viva_result_date' => 'No category 4 viva result is recorded in UResearch for this student. Enter the date of the viva result so the resubmission window can be worked out.',
                ]);
            }
            $window = ReVivaWindow::fromResultDate(Carbon::parse($vivaResultDate));
        }

        $resubmittedAt = now();
        $warning = $window->warningFor($resubmittedAt);
        $round = $latest ? $latest->cycle_number + 1 : 1;

        $detail = DB::transaction(function () use ($student, $thesis, $summary, $vivaResultDate, $actor, $latest, $window, $resubmittedAt, $warning, $round) {
            $application = Application::create([
                'student_id' => $student->id,
                // The CGS staff member logging it, not the student.
                'submitted_by_id' => $actor->id,
                'module_type' => self::MODULE,
                'status' => Application::STATUS_DRAFT,
            ]);

            // correction_deadline is set by the outcome (levels 1-3) and
            // hardbound_deadline is no longer used; see the 2026_11_06
            // migration for how the original columns map.
            $detail = ReVivaDetail::create([
                'application_id' => $application->id,
                'cycle_number' => $round,
                'previous_cycle_id' => $latest?->id,
                'viva_application_id' => $window->viva?->application_id ?? $latest?->viva_application_id,
                'resubmission_at' => $resubmittedAt,
                'resubmission_opens_at' => $window->opens,
                'resubmission_closes_at' => $window->closes,
                'viva_result_date' => $window->source === ReVivaWindow::LEGACY ? $vivaResultDate : null,
                'logged_outside_window' => $warning !== null,
            ]);

            // Both are the student's own documents, so DocumentStore is right
            // here: the student may see them. Nothing examiner-side is.
            $this->documents->attach($application, $thesis, self::DOC_THESIS);
            $this->documents->attach($application, $summary, self::DOC_SUMMARY);

            $this->holdReexaminers($window->viva, $resubmittedAt->copy()->addMonthsNoOverflow((int) config('hani.reviva.hold_months', 12)), $actor);

            $this->engine->submit($application);

            return $detail;
        });

        return ['detail' => $detail, 'round' => $round, 'warning' => $warning];
    }

    /**
     * The student's most recent round across all their re-viva applications,
     * whatever its outcome: used both to gate a new submission and, for a
     * loop-back, to link the new round to the one that produced it.
     */
    public static function latestCycle(int $studentId): ?ReVivaDetail
    {
        return ReVivaDetail::whereHas('application', fn ($q) => $q
            ->where('module_type', self::MODULE)
            ->where('student_id', $studentId))
            ->latest('cycle_number')
            ->first();
    }

    /**
     * Why $latest blocks a new submission, or null if one may be filed.
     * A round still open always blocks; a resolved round blocks unless its
     * outcome was level 4 (another round).
     */
    public static function blockReason(?ReVivaDetail $latest): ?string
    {
        if (! $latest) {
            return null;
        }

        if (! $latest->hasOutcome()) {
            return 'A re-viva cycle is already in progress; wait for it to reach an outcome first.';
        }

        if (! $latest->requiresLoopBack()) {
            return 'Your re-viva has already reached a final outcome; a new cycle is not open.';
        }

        return null;
    }

    /**
     * The same main examiners re-examine, so keep them Assigned for the round
     * (until $until at most); the outcome releases them (ReVivaOutcome).
     * Nothing to do with no linked viva, or once the window-closed sweep freed them.
     */
    protected function holdReexaminers(?VivaDetail $viva, \DateTimeInterface $until, User $actor): void
    {
        if (! $viva || $viva->examiners_freed_at !== null) {
            return;
        }

        $panel = VivaProgress::forNotices(collect([$viva->application]))->first()?->panel();

        if ($panel) {
            $this->seats->holdForReexamination($panel, $until, $actor);
        }
    }
}
