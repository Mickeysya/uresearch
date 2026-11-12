<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Contracts\DescribesApplicationDetails;
use App\Modules\Core\Contracts\ProvidesStaffView;
use App\Modules\Core\Contracts\SuppliesApprovedGaApplication;
use App\Modules\Core\Contracts\SuppliesAttendanceOverview;
use App\Modules\Core\Contracts\SuppliesCandidacyStatus;
use App\Modules\Core\Contracts\SuppliesSupervisionHistory;
use App\Modules\Core\Contracts\SuppliesTeachingAssistantStatus;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Services\StudentDirectory;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The Students page: a read-only directory of postgraduates (/students) and
 * one record per student (/students/{student}) -- "the holistic view of a
 * student's administrative status" the scope document asks for.
 *
 * Every module's data arrives through a Core contract, never a module class:
 * attendance (SuppliesAttendanceOverview), supervision history
 * (SuppliesSupervisionHistory), candidacy (SuppliesCandidacyStatus), GA/GRA
 * (SuppliesTeachingAssistantStatus, SuppliesApprovedGaApplication), letters
 * (DescribesApplicationDetails) and staff links (ProvidesStaffView). Each is
 * optional: a module that is not installed only leaves its field empty.
 *
 * Who sees whom is Services\StudentDirectory. IC and bank numbers are never
 * read here. The projected attendance figure is passed to the view only for
 * the CGS team.
 */
class StudentDirectoryController extends Controller
{
    public const PER_PAGE = 20;

    public function __construct(protected StudentDirectory $directory) {}

    public function index(Request $request)
    {
        $viewer = $request->user();

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:150'],
            'programme' => ['nullable', 'string', 'max:150'],
            'supervisor' => ['nullable', 'integer'],
            'at_risk' => ['nullable', 'boolean'],
            'pending' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:name,-name,attendance,-attendance'],
        ]) + ['sort' => 'name'];
        $filters['sort'] ??= 'name';

        $base = $this->directory->visibleTo($viewer);

        // The filter dropdowns list only what this viewer can actually see.
        $options = [
            'departments' => (clone $base)->whereNotNull('users.department')->distinct()->orderBy('users.department')->pluck('users.department'),
            'programmes' => (clone $base)->whereNotNull('users.programme')->distinct()->orderBy('users.programme')->pluck('users.programme'),
            'supervisors' => User::whereIn('id', (clone $base)->whereNotNull('users.supervisor_id')->select('users.supervisor_id'))->orderBy('name')->get(['id', 'name']),
        ];

        $query = (clone $base)
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('users.name', 'like', '%'.$term.'%')
                ->orWhere('users.matric_no', 'like', '%'.$term.'%')))
            ->when($filters['department'] ?? null, fn ($q, $v) => $q->where('users.department', $v))
            ->when($filters['programme'] ?? null, fn ($q, $v) => $q->where('users.programme', $v))
            ->when($filters['supervisor'] ?? null, fn ($q, $v) => $q->where('users.supervisor_id', $v))
            ->when($filters['pending'] ?? false, fn ($q) => $q->whereHas('applications', fn ($a) => $a->where('status', Application::STATUS_PENDING)));

        // Every matching id, in name order, in one query. Attendance for all
        // of them in one more -- so "at risk" and the attendance sort work
        // across the whole result, not only the page being shown.
        $ids = $query->orderBy('users.name')->pluck('users.id');
        $attendance = app()->bound(SuppliesAttendanceOverview::class)
            ? app(SuppliesAttendanceOverview::class)->overviewFor($ids)
            : null;

        if (($filters['at_risk'] ?? false) && $attendance !== null) {
            $ids = $ids->filter(fn ($id) => $attendance[$id]['at_risk'] ?? false)->values();
        }

        $ids = match ($filters['sort']) {
            '-name' => $ids->reverse()->values(),
            // No figure on record sorts last, either way.
            'attendance' => $ids->sortBy(fn ($id) => $attendance[$id]['percentage'] ?? INF)->values(),
            '-attendance' => $ids->sortByDesc(fn ($id) => $attendance[$id]['percentage'] ?? -INF)->values(),
            default => $ids,
        };

        $page = LengthAwarePaginator::resolveCurrentPage();
        $slice = $ids->forPage($page, self::PER_PAGE)->values();

        // The page's rows in one query, supervisor and pending count included.
        $rows = User::whereIn('id', $slice)
            ->with('supervisor:id,name')
            ->withCount(['applications as pending_count' => fn ($q) => $q->where('status', Application::STATUS_PENDING)])
            ->get()
            ->sortBy(fn (User $u) => $slice->search($u->id))
            ->values();

        // Candidacy is answered one student at a time (Chloe's contract has
        // no bulk method yet -- see SHARED_CHANGES), bounded by the page size.
        $candidacy = app()->bound(SuppliesCandidacyStatus::class)
            ? $rows->mapWithKeys(fn (User $u) => [$u->id => app(SuppliesCandidacyStatus::class)->candidacyStatusFor($u)])->all()
            : null;

        $students = new LengthAwarePaginator($rows, $ids->count(), self::PER_PAGE, $page, [
            'path' => $request->url(),
            'query' => array_filter($request->query(), fn ($v) => $v !== null && $v !== ''),
        ]);

        return view('core::students.index', [
            'students' => $students,
            'attendance' => $attendance,
            'candidacy' => $candidacy,
            'filters' => $filters,
            'options' => $options,
            'filtered' => collect($filters)->except('sort')->filter(fn ($v) => $v !== null && $v !== '' && $v !== false)->isNotEmpty(),
        ]);
    }

    public function show(Request $request, User $student, ModuleRegistry $registry, WorkflowEngine $engine)
    {
        $viewer = $request->user();

        abort_unless($student->role === Role::STUDENT, 404);
        abort_unless($this->directory->canView($viewer, $student), 403, 'This student is not one you can view.');

        $student->loadMissing('supervisor');

        $history = app()->bound(SuppliesSupervisionHistory::class)
            ? app(SuppliesSupervisionHistory::class)->supervisorsFor($student)
            : null;

        $attendance = app()->bound(SuppliesAttendanceOverview::class)
            ? (app(SuppliesAttendanceOverview::class)->overviewFor([$student->id])[$student->id] ?? null)
            : false;

        if (is_array($attendance) && ! StudentDirectory::seesProjection($viewer)) {
            unset($attendance['projected_percentage']);
        }

        $appointment = app()->bound(SuppliesTeachingAssistantStatus::class)
            ? app(SuppliesTeachingAssistantStatus::class)->currentAppointmentFor($student)
            : null;
        $gaApplication = app()->bound(SuppliesApprovedGaApplication::class)
            ? app(SuppliesApprovedGaApplication::class)->approvedFor($student)
            : null;

        $applications = [];
        $letters = [];

        $rows = Application::where('student_id', $student->id)
            ->where('status', '!=', Application::STATUS_DRAFT)
            ->orderByDesc('submitted_at')->orderByDesc('id')
            ->get();

        foreach ($rows as $application) {
            $module = $registry->has($application->module_type) ? $registry->get($application->module_type) : null;
            $stage = $module ? collect($engine->stagesFor($application))->firstWhere('key', $application->current_stage) : null;

            $applications[] = [
                'module' => $module?->label() ?? $application->module_type,
                'reference' => $application->reference(),
                'status' => $application->status,
                'stage' => $stage?->label,
                'date' => $application->submitted_at,
                'url' => $module instanceof ProvidesStaffView ? $module->staffViewUrl($application, $viewer) : null,
            ];

            // issuedDocument() can return just a label for a letter that is
            // not issued yet; only one with a reference has been issued.
            if ($module instanceof DescribesApplicationDetails && ($issued = $module->issuedDocument($application)) && ! empty($issued['reference'])) {
                $letters[] = [
                    'label' => $issued['label'],
                    'reference' => $issued['reference'] ?? null,
                    'verify_url' => $issued['verify_url'] ?? null,
                    'revoked_at' => $issued['revoked_at'] ?? null,
                    'module' => $module->label(),
                ];
            }
        }

        return view('core::students.show', [
            'student' => $student,
            'history' => $history,
            'candidacy' => app()->bound(SuppliesCandidacyStatus::class) ? app(SuppliesCandidacyStatus::class)->candidacyStatusFor($student) : false,
            'appointment' => $appointment,
            'gaEnds' => $gaApplication['service_end_date'] ?? null,
            'gaInstalled' => app()->bound(SuppliesTeachingAssistantStatus::class),
            'attendance' => $attendance,
            'seesProjection' => StudentDirectory::seesProjection($viewer),
            'applications' => $applications,
            'letters' => $letters,
        ]);
    }
}
