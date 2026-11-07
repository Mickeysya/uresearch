<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Saved drafts of GA Extension and GA/GRA Certification requests.
 *
 * A draft is Core's own: an `applications` row with status 'draft' and
 * current_stage NULL, plus the module's detail row. It is never on a
 * stage, so it never reaches a queue (WorkflowEngine::queue() reads
 * pending rows only), a reminder or the processing-time analytics, and
 * the Client Charter has no clock for it.
 *
 * Only the student who owns a draft may open, change, submit or delete it
 * -- ownedBy() is the one check every draft action goes through.
 */
class Drafts
{
    public const MODULES = ['ga_extension', 'ga_certification'];

    /**
     * Other modules' saved drafts, listed on My Drafts beside these:
     * module_type => a scope narrowing that module's draft rows to the ones
     * that are saved drafts (or null for all of them). Each module registers
     * itself from its own ModuleProvider -- Norhanis' Travel, Publication and
     * Claims do -- and links its rows through <module-type>.draft.edit and
     * .draft.destroy routes, the same names this module's routes use.
     *
     * @var array<string, \Closure|null>
     */
    protected static array $listed = [];

    public static function list(string $module, ?\Closure $scope = null): void
    {
        static::$listed[$module] = $scope;
    }

    /**
     * The draft, if it is one of $module's and belongs to $student --
     * otherwise abort: 403 for someone else's, 404 for anything that is
     * not (or is no longer) a draft of this module.
     */
    public static function ownedBy(User $student, Application $application, string $module): Application
    {
        abort_unless($application->module_type === $module, 404);
        abort_unless($application->student_id === $student->id, 403, 'This draft is not yours.');
        abort_unless($application->status === Application::STATUS_DRAFT, 404, 'This application has already been submitted.');

        return $application;
    }

    /** @return Collection<int, Application> the student's drafts, most recently saved first */
    public static function of(User $student, ?string $module = null): Collection
    {
        return Application::query()
            ->where('student_id', $student->id)
            ->where('status', Application::STATUS_DRAFT)
            ->whereIn('module_type', $module ? [$module] : [...self::MODULES, ...array_keys(static::$listed)])
            ->where(function ($query) {
                foreach (array_filter(static::$listed) as $listed => $scope) {
                    $query->where(fn ($q) => $q->where('module_type', '!=', $listed)->orWhere(fn ($own) => $scope($own)));
                }
            })
            ->with('documents')
            ->latest('updated_at')
            ->get();
    }

    /** When the student last saved it: the draft row is touched on every save. */
    public static function lastSaved(Application $application): CarbonInterface
    {
        return $application->updated_at ?? $application->created_at;
    }

    /**
     * Files already attached to the draft, per document type, leaving out
     * any the student has ticked to remove.
     *
     * @param  array<int, int|string>  $removing
     * @return Collection<string, Collection<int, ApplicationDocument>>
     */
    public static function attached(Application $application, array $removing = []): Collection
    {
        return $application->documents()
            ->whereNotIn('id', array_map('intval', $removing))
            ->orderBy('id')
            ->get()
            ->groupBy('doc_type');
    }

    /**
     * Remove the given files from the draft: the stored file and its row.
     * Only this application's own documents -- an id belonging to anything
     * else is ignored.
     *
     * @param  array<int, int|string>  $ids
     */
    public static function removeDocuments(Application $application, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $application->documents()
            ->whereIn('id', array_map('intval', $ids))
            ->get()
            ->each(function (ApplicationDocument $document) {
                Storage::disk('local')->delete($document->path);
                $document->delete();
            });
    }

    /** Delete a draft completely: its stored files, its document rows, its detail row and itself. */
    public static function delete(Application $application, string $detailModel): void
    {
        DB::transaction(function () use ($application, $detailModel) {
            self::removeDocuments($application, $application->documents()->pluck('id')->all());
            $detailModel::where('application_id', $application->id)->delete();
            $application->delete();
        });
    }
}
