<?php

namespace App\Modules\Haziq\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Haziq\Models\DocumentCompletenessReminder;
use App\Modules\Haziq\Models\GraApplicationDetail;
use App\Modules\Haziq\Notifications\IncompleteDocumentsReminder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Document completeness never gates the approval chain — an application
 * keeps moving through its stages with documents missing, confirmed
 * explicitly 2026-11-07: "application boleh terus ke approver lain even
 * document tak complete." This is a parallel tracking concern only: who
 * is still missing what, chased by a weekly reminder, same "reporting
 * view, not a workflow" shape as Allowance Eligibility and Delay by Actor
 * — see those classes' own docblocks.
 *
 * Scoped to 'new' applications only, both modules. GRA Extension shares
 * gra_application's module_type but was never gated on these documents at
 * Submit either — GraApplicationController::storeExtension() has no
 * missingRequiredDocuments() call — so there is nothing to chase there.
 *
 * Required document lists mirror GraApplicationController::documentTypes()
 * and GaApplicationController::documentTypes() exactly — duplicated here
 * rather than reached into those controllers, which are request-handling
 * classes, not services, the same reasoning the module already uses for
 * duplicating logic between the GRA and GA controllers themselves. Keep
 * all three lists in sync if any one of them changes.
 */
class DocumentCompleteness
{
    public const MODULES = ['gra_application', 'ga_application'];

    protected const ACTIVE_STATUSES = [
        Application::STATUS_PENDING,
        Application::STATUS_APPROVED,
        Application::STATUS_RETURNED,
    ];

    /** @return array<string, string> doc_type => label */
    public static function requiredDocuments(string $moduleType): array
    {
        return match ($moduleType) {
            'gra_application' => [
                'resume' => 'Resume/CV',
                'academic_transcript' => 'Academic Transcript',
                'visa_or_ic' => 'Visa / IC',
                'admission_letter' => 'UTP Admission Offer Letter',
                'bank_letter' => 'Bank Account Details Letter',
                'study_plan' => 'Study Plan (Gantt Chart)',
                'grant_utilization_approval' => 'Grant Utilization Approval',
                'supervisor_supporting_letter' => 'GRA Supporting Letter from Supervisor',
            ],
            'ga_application' => [
                'resume' => 'Resume/CV',
                'academic_transcript' => 'Academic Transcript',
                'visa_or_ic' => 'Visa / IC',
            ],
            default => [],
        };
    }

    /** @return Collection<int, string> labels of required documents with no file attached yet */
    public static function missingFor(Application $application): Collection
    {
        $attached = ApplicationDocument::where('application_id', $application->id)->pluck('doc_type');

        return collect(self::requiredDocuments($application->module_type))
            ->reject(fn ($label) => $attached->contains($label))
            ->values();
    }

    public static function isComplete(Application $application): bool
    {
        return self::missingFor($application)->isEmpty();
    }

    /**
     * Every 'new' application still worth chasing: submitted, and not dead
     * — pending, approved or returned, never draft (not submitted yet) or
     * rejected (nothing left to chase).
     *
     * @return Collection<int, Application>
     */
    public static function trackedApplications(?string $moduleType = null): Collection
    {
        $modules = $moduleType ? [$moduleType] : self::MODULES;

        $applications = Application::query()
            ->whereIn('module_type', $modules)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->with('student')
            ->get();

        if (! in_array('gra_application', $modules, true)) {
            return $applications;
        }

        // GRA Extension shares the module_type but was never gated on these
        // documents at Submit — drop any application that is actually an
        // extension row rather than a 'new' one.
        $extensionIds = GraApplicationDetail::where('application_type', 'extension')->pluck('application_id');

        return $applications->reject(fn (Application $application) => $application->module_type === 'gra_application'
            && $extensionIds->contains($application->id))->values();
    }

    /**
     * @return Collection<int, array{application: Application, student: ?\App\Modules\Core\Models\User, module: string, missing: Collection<int, string>, complete: bool, lastReminded: ?Carbon}>
     */
    public static function rows(?string $moduleType = null): Collection
    {
        $registry = app(ModuleRegistry::class);
        $applications = self::trackedApplications($moduleType);

        $reminders = DocumentCompletenessReminder::whereIn('application_id', $applications->pluck('id'))
            ->get()
            ->keyBy('application_id');

        return $applications
            ->map(function (Application $application) use ($registry, $reminders) {
                $missing = self::missingFor($application);

                return [
                    'application' => $application,
                    'student' => $application->student,
                    'module' => $registry->labelFor($application->module_type),
                    'missing' => $missing,
                    'complete' => $missing->isEmpty(),
                    'lastReminded' => $reminders->get($application->id)?->last_reminded_at,
                ];
            })
            ->sortBy(fn ($row) => $row['student']?->name ?? '')
            ->values();
    }

    /**
     * Sends the reminder and records it immediately, so the weekly job's
     * own throttle (see remindedRecently()) never fires a second one the
     * same week this ran manually from the "Send Reminder to All" button.
     */
    public static function remind(Application $application): void
    {
        $missing = self::missingFor($application);

        if ($missing->isEmpty()) {
            return;
        }

        $application->student?->notify(new IncompleteDocumentsReminder($application, $missing));

        $reminder = DocumentCompletenessReminder::firstOrNew(['application_id' => $application->id]);
        $reminder->reminder_count = ($reminder->reminder_count ?? 0) + 1;
        $reminder->last_reminded_at = now();
        $reminder->save();
    }

    /** True once this application has already been reminded within the last week. */
    public static function remindedRecently(Application $application): bool
    {
        $lastRemindedAt = DocumentCompletenessReminder::where('application_id', $application->id)->value('last_reminded_at');

        return $lastRemindedAt && Carbon::parse($lastRemindedAt)->gt(now()->subWeek());
    }
}
