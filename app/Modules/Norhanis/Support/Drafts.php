<?php

namespace App\Modules\Norhanis\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Models\User;
use App\Modules\Norhanis\Models\SavedDraft;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Saved drafts of Travel, Publication and Claims forms -- the same idea as
 * Nureen's (Core's draft application row, never on a stage), so they are
 * listed on her My Drafts page with hers.
 *
 * A saved draft is an application with status 'draft' AND a SavedDraft row.
 * The second half matters: a Publication waiting for its co-authors and
 * reviewers to sign is a draft as well, and is not something to "continue".
 *
 * Only the student who owns a draft may open, change, submit or delete it;
 * ownedBy() is the one check every draft action goes through.
 */
class Drafts
{
    public const MODULES = ['travel', 'publication', 'claims_student'];

    /**
     * The draft, if it is one of $module's saved drafts and belongs to
     * $student -- otherwise abort: 403 for someone else's, 404 for anything
     * that is not (or is no longer) a saved draft of this module.
     */
    public static function ownedBy(User $student, Application $application, string $module): Application
    {
        abort_unless($application->module_type === $module, 404);
        abort_unless($application->student_id === $student->id, 403, 'This draft is not yours.');
        abort_unless($application->status === Application::STATUS_DRAFT && self::saved($application), 404, 'This application has already been submitted.');

        return $application;
    }

    /** Narrows an applications query to saved drafts: what My Drafts lists. */
    public static function scope(Builder $query): Builder
    {
        return $query->whereIn('id', SavedDraft::select('application_id'));
    }

    /** @return Collection<int, Application> the student's saved drafts of $module, most recently saved first */
    public static function of(User $student, string $module): Collection
    {
        return self::scope(Application::query())
            ->where('student_id', $student->id)
            ->where('status', Application::STATUS_DRAFT)
            ->where('module_type', $module)
            ->latest('updated_at')
            ->get();
    }

    public static function saved(Application $application): ?SavedDraft
    {
        return SavedDraft::where('application_id', $application->id)->first();
    }

    /**
     * Files already attached to the draft, by document type, leaving out any
     * the student has ticked to remove.
     *
     * @param  array<int, int|string>  $removing
     * @return Collection<string, Collection<int, ApplicationDocument>>
     */
    public static function attached(?Application $application, array $removing = []): Collection
    {
        if (! $application) {
            return collect();
        }

        return $application->documents()
            ->whereNotIn('id', array_map('intval', $removing))
            ->orderBy('id')
            ->get()
            ->groupBy('doc_type');
    }

    /**
     * Remove files from the draft: the stored file and its row. Only this
     * application's own documents -- an id belonging to anything else is ignored.
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

    /** Delete a draft completely: its files, its saved form and the application row. */
    public static function delete(Application $application): void
    {
        DB::transaction(function () use ($application) {
            self::removeDocuments($application, $application->documents()->pluck('id')->all());
            SavedDraft::where('application_id', $application->id)->delete();
            $application->delete();
        });
    }

    /** A line for My Drafts, from what was typed so far. */
    public static function summary(Application $application, string $fallback, string ...$fields): string
    {
        $payload = self::saved($application)?->payload ?? [];
        $parts = array_filter(array_map(fn (string $field) => is_string($payload[$field] ?? null) ? trim($payload[$field]) : '', $fields));

        return $parts ? implode(', ', $parts) : $fallback;
    }
}
