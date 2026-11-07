<?php

namespace App\Modules\Norhanis\Http\Controllers\Concerns;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Norhanis\Models\SavedDraft;
use App\Modules\Norhanis\Support\Drafts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

/**
 * "Save draft" for a Travel, Publication or Claims form: save, continue,
 * delete, and finish by submitting. See Support\Drafts for what a draft is.
 *
 * A draft keeps what was typed exactly as posted (SavedDraft::payload) and
 * hands it back through old() when continued, so every field refills the
 * way it already does after a failed submit -- no form needs to know about
 * drafts beyond the few lines in the draft partials.
 */
trait SavesDrafts
{
    abstract protected function moduleKey(): string;

    /** @return array<int, string> the inputs a draft keeps */
    abstract protected function draftFields(): array;

    /** @return array<string, string> file field => document type */
    abstract protected function draftDocuments(): array;

    /** The form page: blank, or continuing $draft. */
    abstract protected function form(Request $request, ?Application $draft);

    /**
     * Document fields that take one file only, as a plain file input.
     * Every other document field takes several, through the same
     * multi-file picker as Nureen's forms.
     *
     * @return array<int, string>
     */
    protected function singleFileDocuments(): array
    {
        return [];
    }

    /** @return array<int, string> document fields the student may leave empty */
    protected function optionalDocuments(): array
    {
        return [];
    }

    /** Extensions a multi-file field accepts, if narrower than Core's own list. */
    protected function documentExtensions(): ?array
    {
        return null;
    }

    /**
     * Rules for every document field. On submit a required field must end up
     * with at least one file, counting those the draft already holds; on a
     * draft save nothing is required. Either way the files already attached
     * count towards the limit.
     *
     * @return array{0: array<string, array>, 1: array<string, string>} rules, messages
     */
    protected function documentRules(?Application $draft, array $removing, bool $submitting): array
    {
        $attached = Drafts::attached($draft, $removing);
        $rules = $messages = [];

        foreach ($this->draftDocuments() as $field => $label) {
            $already = $attached->get($label)?->count() ?? 0;
            $required = $submitting && $already === 0 && ! in_array($field, $this->optionalDocuments(), true);

            if (in_array($field, $this->singleFileDocuments(), true)) {
                $rules[$field] = DocumentStore::rules($required);

                continue;
            }

            $rules += DocumentStore::manyRules($field, $required);
            $rules[$field][] = function (string $attribute, $value, \Closure $fail) use ($already) {
                if ($already + count((array) $value) > DocumentStore::MULTI_MAX_FILES) {
                    $fail('You may attach at most '.DocumentStore::MULTI_MAX_FILES." files of this type ({$already} already attached).");
                }
            };

            if ($extensions = $this->documentExtensions()) {
                $rules["{$field}.*"] = ['file', 'mimes:'.implode(',', $extensions), 'max:'.DocumentStore::MULTI_MAX_FILE_KB];
            }

            $messages += DocumentStore::manyMessages($field);
            $messages["{$field}.required"] = "Attach the {$label}.";

            if ($extensions) {
                $messages["{$field}.*.mimes"] = 'Each file must be one of: '.strtoupper(implode(', ', $extensions)).'.';
            }
        }

        return [$rules, $messages];
    }

    /** Validate a submit: the module's own rules plus its document rules, in one pass. */
    protected function validateWithDocuments(Request $request, ?Application $draft, array $removing, array $rules, array $messages = []): array
    {
        $this->normaliseDocumentFiles($request);
        [$fileRules, $fileMessages] = $this->documentRules($draft, $removing, submitting: true);

        return $request->validate($rules + $fileRules, $messages + $fileMessages);
    }

    /**
     * A multi-file field posted as a single file (an older page, a script,
     * a test) is treated as a list of one, so both shapes validate alike.
     */
    protected function normaliseDocumentFiles(Request $request): void
    {
        foreach (array_keys($this->draftDocuments()) as $field) {
            // Read the raw bag: $request->file() would cache the converted
            // files before this change could reach validation.
            $file = $request->files->get($field);

            if ($file !== null && ! is_array($file) && ! in_array($field, $this->singleFileDocuments(), true)) {
                $request->files->set($field, [$file]);
            }
        }
    }

    public function editDraft(Request $request, Application $application)
    {
        $draft = Drafts::ownedBy($request->user(), $application, $this->moduleKey());

        // For this request only, so it never leaks into the next form the
        // student opens. A page coming back from a failed submit keeps its
        // own input instead.
        if (! $request->session()->hasOldInput()) {
            $request->session()->now('_old_input', Drafts::saved($draft)->payload ?? []);
        }

        return $this->form($request, $draft);
    }

    /**
     * Creates the draft on the first save, updates it after. Light checks
     * only -- file type and size, never "required" -- so a half-finished
     * form always saves. Files are attached straight away so they survive
     * between sessions; ticked "Remove" boxes take files off.
     */
    public function saveDraft(Request $request, DocumentStore $documents, ?Application $application = null): RedirectResponse
    {
        if ($application) {
            Drafts::ownedBy($request->user(), $application, $this->moduleKey());
        }

        $removing = $this->draftRemoving($request, $application);
        $this->normaliseDocumentFiles($request);
        [$fileRules, $fileMessages] = $this->documentRules($application, $removing, submitting: false);

        $data = $request->validate(['draft_step' => ['nullable', 'integer', 'min:0', 'max:20']] + $fileRules, $fileMessages);
        $payload = $request->only($this->draftFields());

        if (strlen((string) json_encode($payload)) > 60000) {
            throw ValidationException::withMessages(['draft' => 'This draft is too large to save. Shorten the longest answers and try again.']);
        }

        $application = DB::transaction(function () use ($request, $data, $payload, $application, $removing, $documents) {
            $application ??= Application::create([
                'student_id' => $request->user()->id,
                'module_type' => $this->moduleKey(),
                'status' => Application::STATUS_DRAFT,
            ]);

            SavedDraft::updateOrCreate(['application_id' => $application->id], [
                'payload' => $payload,
                'step' => $data['draft_step'] ?? null,
            ]);

            Drafts::removeDocuments($application, $removing);
            $this->attachDraftFiles($request, $application, $documents);

            // "Last saved" is the draft row's updated_at.
            $application->touch();

            return $application;
        });

        return redirect()
            ->route($this->draftRoute('edit'), $application)
            ->with('status', 'Draft saved at '.now()->format('g:ia').'. Continue it any time from My Drafts.');
    }

    /** Delete a draft: its files, its saved form and the application row. */
    public function destroyDraft(Request $request, Application $application): RedirectResponse
    {
        Drafts::delete(Drafts::ownedBy($request->user(), $application, $this->moduleKey()));

        $back = Route::has('nureen.drafts')
            ? route('nureen.drafts')
            : route(app(ModuleRegistry::class)->get($this->moduleKey())->createRoute());

        return redirect($back)->with('status', 'Draft deleted.');
    }

    // ---- Used by the module's own create() and store() ----------------------

    /** What the draft partials need, for a blank form or a draft being continued. */
    protected function draftViewData(Request $request, ?Application $draft): array
    {
        return [
            'draft' => $draft,
            'draftStep' => $draft ? (Drafts::saved($draft)?->step ?? 0) : 0,
            'attached' => Drafts::attached($draft),
            // On a blank form, offer any draft already in progress.
            'otherDrafts' => $draft ? collect() : Drafts::of($request->user(), $this->moduleKey()),
            'draftSaveUrl' => $draft ? route($this->draftRoute('update'), $draft) : route($this->draftRoute('save')),
            'draftEditRoute' => $this->draftRoute('edit'),
        ];
    }

    /** The saved draft a submit is finishing, if any -- the student's own, still a draft. */
    protected function submittingDraft(Request $request): ?Application
    {
        return $request->filled('draft_id')
            ? Drafts::ownedBy($request->user(), Application::findOrFail((int) $request->input('draft_id')), $this->moduleKey())
            : null;
    }

    /**
     * Inside the submit transaction: the draft's own row -- locked, so a
     * double click cannot submit it twice -- with its saved form cleared; or
     * a new application when there is no draft.
     */
    protected function draftOrNewApplication(Request $request, ?Application $draft, array $removing): Application
    {
        if (! $draft) {
            return Application::create([
                'student_id' => $request->user()->id,
                'module_type' => $this->moduleKey(),
                'status' => Application::STATUS_DRAFT,
            ]);
        }

        $application = Application::whereKey($draft->id)->lockForUpdate()->first();
        abort_unless($application?->status === Application::STATUS_DRAFT, 409, 'This draft has already been submitted.');

        SavedDraft::where('application_id', $application->id)->delete();
        Drafts::removeDocuments($application, $removing);

        return $application;
    }

    /**
     * Attach the newly picked files. A multi-file field adds to what the
     * draft already holds (the student removes files with the tick boxes);
     * a single-file field replaces its earlier file.
     */
    protected function attachDraftFiles(Request $request, Application $application, DocumentStore $documents): void
    {
        foreach ($this->draftDocuments() as $field => $label) {
            if (! $request->hasFile($field)) {
                continue;
            }

            if (in_array($field, $this->singleFileDocuments(), true)) {
                Drafts::removeDocuments($application, $application->documents()->where('doc_type', $label)->pluck('id')->all());
                $documents->attach($application, $request->file($field), $label);

                continue;
            }

            $files = $request->file($field);
            $documents->attachMany($application, array_filter(is_array($files) ? $files : [$files]), $label);
        }
    }

    /** The document ids ticked "Remove" on the form -- only meaningful on a draft. @return array<int, int> */
    protected function draftRemoving(Request $request, ?Application $draft): array
    {
        if (! $draft) {
            return [];
        }

        $ids = $request->validate(['remove_documents' => ['nullable', 'array'], 'remove_documents.*' => ['integer']])['remove_documents'] ?? [];

        return array_map('intval', $ids);
    }

    protected function draftRoute(string $action): string
    {
        return str_replace('_', '-', $this->moduleKey()).".draft.{$action}";
    }
}
