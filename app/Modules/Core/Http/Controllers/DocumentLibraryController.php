<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Support\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The Documents page: every file this user may see, grouped by application.
 *
 * Nothing new is stored. Every row already exists in `application_documents`;
 * this is the same data, gathered under the application it belongs to.
 *
 * Visibility is NOT re-derived here. `ApplicationDocument::scopeVisibleTo()`
 * is the exact query form of `visibleTo()`, the method the download route
 * calls, so the list can never name a file `documents.show` would refuse.
 * Every query below starts from that scope -- the one that picks which
 * applications match, the one that pages them, and the one that loads their
 * files -- so grouping changes the shape of the page, never who sees what.
 *
 * Paging is by application, in SQL: one grouped query picks this page's 20
 * applications, and only their files are loaded.
 */
class DocumentLibraryController extends Controller
{
    /** Applications per page. Each one is a collapsed row. */
    public const PER_PAGE = 20;

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

        // The files that decide whether an application is listed: everything
        // except the module tab, so each tab's count says what clicking it
        // would bring back under the current search and window.
        $base = fn (): Builder => ApplicationDocument::query()
            ->visibleTo($user)
            ->when($windowed, fn (Builder $q) => $q->where(fn (Builder $recent) => $recent
                ->where('application_documents.created_at', '>=', now()->subDays(self::RECENT_DAYS))
                ->orWhereHas('application', fn (Builder $a) => $a->where('status', Application::STATUS_PENDING))))
            ->when($applicationId, fn (Builder $q) => $q->where('application_documents.application_id', $applicationId))
            ->when($search !== '', fn (Builder $q) => $this->search($q, $search));

        // Each tab counts applications, not files.
        $byModule = $base()
            ->join('applications', 'applications.id', '=', 'application_documents.application_id')
            ->groupBy('applications.module_type')
            ->selectRaw('applications.module_type, count(distinct application_documents.application_id) as total')
            ->orderByDesc('total')
            ->pluck('total', 'module_type');

        $matched = $base()
            ->when($module !== 'all', fn (Builder $q) => $q
                ->whereHas('application', fn (Builder $a) => $a->where('module_type', $module)))
            ->select('application_documents.application_id');

        // A matched application shows every file on it this user may see --
        // a search for the student's name should not hide the rest of their
        // letters. Still the same visibility scope, file by file.
        $files = fn (): Builder => ApplicationDocument::query()
            ->visibleTo($user)
            ->whereIn('application_documents.application_id', $matched);

        $groups = $files()
            ->groupBy('application_documents.application_id')
            ->selectRaw('application_documents.application_id, max(application_documents.created_at) as latest_upload, count(*) as file_count')
            ->orderByDesc('latest_upload')
            ->orderByDesc('application_documents.application_id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $pageIds = $groups->getCollection()->pluck('application_id')->all();
        $applications = Application::with('student')->findMany($pageIds)->keyBy('id');
        $documents = $files()
            ->whereIn('application_documents.application_id', $pageIds)
            ->latest('application_documents.created_at')
            ->latest('application_documents.id')
            ->get()
            ->groupBy('application_id');

        // Paginator of groups, in page order: the application, its files, and
        // the latest upload across them.
        $groups->setCollection($groups->getCollection()
            ->filter(fn ($g) => $applications->has($g->application_id))
            ->map(fn ($g) => (object) [
                'application' => $applications[$g->application_id],
                'documents' => $documents->get($g->application_id, collect()),
                'latestUpload' => $documents->get($g->application_id, collect())->max('created_at'),
            ])
            ->values());

        return view('core::documents.index', [
            'groups' => $groups,
            'fileTotal' => $files()->count(),
            'totalBytes' => (int) $files()->sum('size_bytes'),
            'byModule' => $byModule,
            'moduleLabels' => $byModule->keys()
                ->merge($applications->pluck('module_type'))->unique()
                ->mapWithKeys(fn ($key) => [$key => $registry->labelFor($key)]),
            'module' => $module,
            'search' => $search,
            'windowed' => $windowed,
            'recentDays' => self::RECENT_DAYS,
            'applicationId' => $applicationId,
            // Only a reference, derived from id + module key -- nothing the
            // URL did not already say. The list itself stays scoped.
            'applicationRef' => $applicationId ? Application::find($applicationId)?->reference() : null,
            'isOwnList' => $user->hasRole(Role::STUDENT),
        ]);
    }

    /**
     * Does this file itself match the search (by name or type)? A match on
     * the student or the reference matches the whole application instead.
     */
    public static function fileMatches(ApplicationDocument $document, string $search): bool
    {
        return $search !== ''
            && (mb_stripos($document->original_name, $search) !== false
                || mb_stripos((string) $document->doc_type, $search) !== false);
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
