<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Support\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The Documents page — every file this user may see, in one list.
 *
 * Nothing new is stored. Every row already exists in `application_documents`;
 * this is the same data with the application as a column rather than as the
 * only route to it.
 *
 * Visibility is NOT re-derived here. `ApplicationDocument::scopeVisibleTo()`
 * is the exact query form of `visibleTo()`, the method the download route
 * calls, so the list can never name a file `documents.show` would refuse --
 * and because the scope is exact, filtering, counting and paging all happen
 * in SQL. The page holds 50 rows whether CGS can see 50 files or 50,000.
 */
class DocumentLibraryController extends Controller
{
    public const PER_PAGE = 50;

    /** The default window. Search and the application filter ignore it. */
    public const RECENT_DAYS = 90;

    public function index(Request $request, ModuleRegistry $registry)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'module' => ['nullable', 'string', 'max:50'],
            'range' => ['nullable', 'in:all'],
            'application' => ['nullable', 'integer', 'min:1'],
        ]);

        $user = $request->user();
        $search = trim((string) ($filters['q'] ?? ''));
        $module = $filters['module'] ?? 'all';
        $applicationId = isset($filters['application']) ? (int) $filters['application'] : null;

        // Looking for something specific (a search, one application) means
        // looking across all time; only plain browsing is windowed.
        $windowed = ($filters['range'] ?? null) !== 'all' && $search === '' && $applicationId === null;

        // Everything except the module tab, so each tab's count says what
        // clicking it would bring back under the current search and window.
        $base = fn (): Builder => ApplicationDocument::query()
            ->visibleTo($user)
            ->when($windowed, fn (Builder $q) => $q->where(fn (Builder $recent) => $recent
                ->where('application_documents.created_at', '>=', now()->subDays(self::RECENT_DAYS))
                ->orWhereHas('application', fn (Builder $a) => $a->where('status', Application::STATUS_PENDING))))
            ->when($applicationId, fn (Builder $q) => $q->where('application_documents.application_id', $applicationId))
            ->when($search !== '', fn (Builder $q) => $this->search($q, $search));

        $byModule = $base()
            ->join('applications', 'applications.id', '=', 'application_documents.application_id')
            ->groupBy('applications.module_type')
            ->selectRaw('applications.module_type, count(*) as total')
            ->orderByDesc('total')
            ->pluck('total', 'module_type');

        $filtered = fn (): Builder => $base()->when($module !== 'all', fn (Builder $q) => $q
            ->whereHas('application', fn (Builder $a) => $a->where('module_type', $module)));

        $documents = $filtered()
            ->with(['application.student'])
            ->latest('application_documents.created_at')
            ->latest('application_documents.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('core::documents.index', [
            'documents' => $documents,
            'byModule' => $byModule,
            'moduleLabels' => $byModule->keys()->mapWithKeys(fn ($key) => [$key => $registry->labelFor($key)]),
            'module' => $module,
            'search' => $search,
            'windowed' => $windowed,
            'recentDays' => self::RECENT_DAYS,
            'applicationId' => $applicationId,
            // Only a reference, derived from id + module key -- nothing the
            // URL did not already say. The list itself stays scoped.
            'applicationRef' => $applicationId ? Application::find($applicationId)?->reference() : null,
            'totalBytes' => (int) $filtered()->sum('size_bytes'),
            'isOwnList' => $user->hasRole(Role::STUDENT),
        ]);
    }

    /** File name, doc type, student name, or a full reference like GAEX-2026-00202. */
    private function search(Builder $query, string $search): Builder
    {
        $like = '%'.addcslashes($search, '%_\\').'%';

        return $query->where(function (Builder $q) use ($like, $search) {
            $q->where('application_documents.original_name', 'like', $like)
                ->orWhere('application_documents.doc_type', 'like', $like)
                ->orWhereHas('application.student', fn (Builder $s) => $s->where('name', 'like', $like));

            // The reference is derived, not stored, so match its id part.
            if (preg_match('/^[a-z]{2,4}-\d{4}-0*(\d+)$/i', $search, $m)) {
                $q->orWhere('application_documents.application_id', (int) $m[1]);
            }
        });
    }
}
