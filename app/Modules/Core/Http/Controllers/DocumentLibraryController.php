<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Services\ModuleRegistry;
use Illuminate\Http\Request;

/**
 * The Documents page — every file this user may see, in one list.
 *
 * Nothing new is stored. Every row already exists in `application_documents`;
 * until now the only way to reach one was to remember which application it was
 * attached to and open that application's tracking page. This is the same data
 * with the application as a column rather than as the only route to it.
 *
 * Visibility is NOT re-derived here. `ApplicationDocument::scopeVisibleTo()`
 * narrows the query and `visibleTo()` makes the final per-row decision — the
 * same method the download route calls, so the list can never name a file that
 * `documents.show` would then refuse.
 */
class DocumentLibraryController extends Controller
{
    public function index(Request $request, ModuleRegistry $registry)
    {
        $user = $request->user();

        $documents = ApplicationDocument::query()
            ->visibleTo($user)
            ->with(['application.student'])
            ->latest()
            ->get()
            // The scope is deliberately slightly wide (a stage maps to a role
            // through the module's chain, which is PHP and not SQL), so the
            // authoritative check runs here, per row.
            ->filter(fn (ApplicationDocument $doc) => $doc->visibleTo($user));

        $search = trim((string) $request->query('q', ''));
        $module = $request->query('module', 'all');

        // Counts come from the unfiltered set, so a filter chip still shows
        // how much it would bring back rather than always reading 0.
        $byModule = $documents
            ->groupBy(fn (ApplicationDocument $doc) => $doc->application->module_type)
            ->map->count()
            ->sortDesc();

        if ($module !== 'all') {
            $documents = $documents->filter(
                fn (ApplicationDocument $doc) => $doc->application->module_type === $module
            );
        }

        if ($search !== '') {
            $needle = mb_strtolower($search);

            $documents = $documents->filter(function (ApplicationDocument $doc) use ($needle) {
                return str_contains(mb_strtolower($doc->original_name), $needle)
                    || str_contains(mb_strtolower($doc->doc_type), $needle)
                    || str_contains(mb_strtolower((string) $doc->application?->reference()), $needle)
                    || str_contains(mb_strtolower((string) $doc->application?->student?->name), $needle);
            });
        }

        return view('core::documents.index', [
            'documents' => $documents->values(),
            'groups' => $documents->groupBy(fn ($doc) => $doc->created_at->format('F Y')),
            'byModule' => $byModule,
            'moduleLabels' => collect($byModule->keys())
                ->mapWithKeys(fn ($key) => [$key => $registry->labelFor($key)]),
            'module' => $module,
            'search' => $search,
            'totalBytes' => $documents->sum('size_bytes'),
            'isOwnList' => $user->hasRole(\App\Modules\Core\Support\Role::STUDENT),
        ]);
    }
}
