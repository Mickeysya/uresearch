<?php

namespace App\Modules\Hani\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Hani\Models\ReVivaDetail;
use App\Modules\Hani\Models\VivaDetail;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The student submits a re-viva round: three PDFs, one open round at a time,
 * inside the window worked out from the viva or the level-4 outcome. A
 * submission outside the window is warned about, never refused.
 */
class ReVivaLogging
{
    public const MODULE = 're_viva';

    public const DOC_THESIS = 'Re-corrected Thesis';

    public const DOC_SUMMARY = 'Summary of Corrections';

    public const DOC_CONFIRMATION = 'Confirmation of Correction';

    public const REQUIRED = [self::DOC_CONFIRMATION, self::DOC_SUMMARY, self::DOC_THESIS];

    public function __construct(
        protected WorkflowEngine $engine,
        protected DocumentStore $documents,
        protected PanelSeats $seats,
    ) {}

    /**
     * @param  array<string, UploadedFile>  $files  keyed by the REQUIRED doc types
     * @return array{detail: ReVivaDetail, round: int, warning: ?string}
     */
    public function log(User $student, array $files): array
    {
        $latest = self::latestCycle($student->id);

        if ($reason = self::blockReason($latest)) {
            throw ValidationException::withMessages(['thesis' => $reason]);
        }

        $window = ReVivaWindow::for($student, $latest);

        if (! $window) {
            throw ValidationException::withMessages(['thesis' => 'There is no re-viva resubmission expected from you at the moment.']);
        }

        $now = now();
        $warning = $window->warningFor($now);
        $round = $latest ? $latest->cycle_number + 1 : 1;

        $detail = DB::transaction(function () use ($student, $files, $latest, $window, $now, $warning, $round) {
            $application = Application::create([
                'student_id' => $student->id,
                'submitted_by_id' => $student->id,
                'module_type' => self::MODULE,
                'status' => Application::STATUS_DRAFT,
            ]);

            $detail = ReVivaDetail::create([
                'application_id' => $application->id,
                'cycle_number' => $round,
                'previous_cycle_id' => $latest?->id,
                'viva_application_id' => $window->viva?->application_id ?? $latest?->viva_application_id,
                'resubmission_at' => $now,
                'resubmission_opens_at' => $window->opens,
                'resubmission_closes_at' => $window->closes,
                'logged_outside_window' => $warning !== null,
            ]);

            foreach ($files as $type => $file) {
                $this->documents->attach($application, $file, $type);
            }

            $this->holdReexaminers($window->viva, $now->copy()->addMonthsNoOverflow((int) config('hani.reviva.hold_months', 12)), $student);
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
