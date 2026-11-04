<?php

namespace App\Modules\Haziq\Http\Controllers\Concerns;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Support\Role;
use App\Modules\Haziq\Models\ApplicationCorrectionRequest;
use App\Modules\Haziq\Notifications\ApplicationCorrectionRequested;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * "Request Correction" — any approver (Admin, CGS Staff, Supervisor, Senior
 * Director) can flag a section or a document as wrong on any application,
 * at any time, not only Admin and not only on their own current stage —
 * confirmed explicitly 2026-10-11 after an earlier round had this as
 * Admin-only. It is shared between GRA and GA since the flagging action is
 * identical either way; resolving a request (document re-upload, or — GRA
 * only — editing a section) differs enough between the two that each
 * controller implements its own showCorrection()/storeCorrection() rather
 * than this trait trying to cover both.
 *
 * approve/reject stays stage-scoped as before (WorkflowEngine::decide() only
 * lets the role whose turn it is act) — this is additive to that, not a
 * replacement: any approver can flag an issue on any application, whether
 * or not it has reached their stage yet, because it never touches
 * status/current_stage. See the migration's docblock for why no new
 * Application status was needed for this.
 */
trait HandlesApplicationCorrections
{
    abstract protected function moduleKey(): string;

    /** @return array<string, string> doc field => label, e.g. ['resume' => 'Resume/CV'] */
    abstract protected function documentTypes(): array;

    /** @return array<string, string> section key => display label. GA returns []. */
    abstract protected function sectionLabels(): array;

    /**
     * Which individual fields can be flagged within a section, for the
     * sections that have a fixed, named set of them — a single-row section
     * like Project Details can say "Project Title is wrong" instead of just
     * "Project Details is wrong". A repeatable section (Academic
     * Qualification, with an unknown number of rows per student) has no
     * fixed field list, so it's simply absent here and falls back to a
     * whole-section flag. GA returns [].
     *
     * @return array<string, array<string, string>> section key => [field key => field label]
     */
    protected function sectionFieldLabels(): array
    {
        return [];
    }

    /**
     * Whether this user can still flag anything on this application.
     * Flagging is only open before a role's own checkpoint — once an
     * approver has approved (or rejected) their own stage and moved the
     * application on, they're done with it and shouldn't be able to pull it
     * back for a correction; same for anyone once the whole application is
     * no longer pending (approved or rejected — there's nothing left to
     * gate a correction against). Implemented per controller since which
     * stage(s) a role owns is module-specific.
     */
    abstract protected function canStillFlag(User $user, Application $application): bool;

    /**
     * One submission, many flags: tick as many fields, sections and
     * documents as are wrong, write one reason, send one notification
     * listing all of them. Each ticked item still gets its own
     * ApplicationCorrectionRequest row, tagged with the section it belongs
     * to — storeCorrection() on the student's side resolves a whole
     * section at once regardless of how many of its fields were
     * individually flagged, so ticking three fields in Section B still
     * just means one edit form for the student, with three precise reasons
     * attached to it.
     */
    public function requestCorrection(Request $request, Application $application): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->isCgs() || $user->role === Role::SUPERVISOR, 403);
        abort_unless(
            $this->canStillFlag($user, $application),
            403,
            'You have already decided this application — no further corrections can be flagged from your stage.'
        );

        $fieldLabels = $this->sectionFieldLabels();

        $validTargets = array_merge(
            array_map(fn ($label) => 'document:'.$label, $this->documentTypes()),
            collect($this->sectionLabels())->keys()->flatMap(
                fn ($key) => isset($fieldLabels[$key])
                    ? collect(array_keys($fieldLabels[$key]))->map(fn ($field) => "{$key}:{$field}")
                    : [$key]
            )->all()
        );

        $data = $request->validate([
            'targets' => ['required', 'array', 'min:1'],
            'targets.*' => ['string', 'in:'.implode(',', $validTargets)],
            'remarks' => ['required', 'string', 'max:1000'],
        ]);

        $flaggedLabels = [];

        foreach ($data['targets'] as $target) {
            [$targetType, $targetField, $targetLabel] = $this->describeTarget($target, $fieldLabels);

            ApplicationCorrectionRequest::create([
                'application_id' => $application->id,
                'target_type' => $targetType,
                'target_field' => $targetField,
                'target_label' => $targetLabel,
                'remarks' => $data['remarks'],
                'requested_by' => $user->id,
                'requested_at' => now(),
            ]);

            $flaggedLabels[] = $targetLabel;
        }

        // Which role flagged it, not just that it was flagged — now that
        // any of the four approvers can do this, the student should know
        // whether it's Admin, CGS, their Supervisor or Senior Director.
        $application->student?->notify(new ApplicationCorrectionRequested($application, $flaggedLabels, $data['remarks'], $user->roleLabel()));

        $count = count($flaggedLabels);

        return back()->with('status', "{$count} ".Str::plural('item', $count)." flagged for correction.");
    }

    /**
     * @param  array<string, array<string, string>>  $fieldLabels
     * @return array{0: string, 1: ?string, 2: string} [target_type, target_field, target_label]
     */
    protected function describeTarget(string $target, array $fieldLabels): array
    {
        if (str_starts_with($target, 'document:')) {
            return ['document', null, substr($target, 9)];
        }

        if (str_contains($target, ':')) {
            [$sectionKey, $fieldKey] = explode(':', $target, 2);
            $fieldLabel = $fieldLabels[$sectionKey][$fieldKey] ?? $fieldKey;

            return [$sectionKey, $fieldKey, $this->sectionLabels()[$sectionKey].' — '.$fieldLabel];
        }

        return [$target, null, $this->sectionLabels()[$target]];
    }

    /**
     * Re-uploading a document type used to just insert another
     * ApplicationDocument row on top of whatever was already there —
     * `DocumentStore::attach()` (Core, shared by every module) has always
     * been a plain create(), never a replace. Every GRA/GA document field
     * only ever means one file per type, so this deletes whatever
     * same-doc_type row(s) already exist first — the old file off disk,
     * then its row — before attaching the new one. Fixed here rather than
     * in DocumentStore itself: that service is shared by every other
     * module's uploads too, and changing its default behaviour for
     * everyone wasn't something to do without asking; this stays local to
     * how GRA/GA specifically use it.
     */
    protected function replaceDocument(Application $application, UploadedFile $file, string $docType, DocumentStore $documents): void
    {
        ApplicationDocument::where('application_id', $application->id)
            ->where('doc_type', $docType)
            ->get()
            ->each(function (ApplicationDocument $old) {
                Storage::disk('local')->delete($old->path);
                $old->delete();
            });

        $documents->attach($application, $file, $docType);
    }
}
