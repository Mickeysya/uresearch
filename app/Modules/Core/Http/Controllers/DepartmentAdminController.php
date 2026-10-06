<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Models\Department;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Faculty;
use App\Modules\Core\Support\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The department picker admin and CGS use everywhere else: on the "add a
 * user" form here, and wherever a module offers a department filter.
 *
 * Renaming is the one operation worth pausing on. `users.department` is a
 * free-text string, not a foreign key: the hard rule bars new columns on
 * `users`, so this table constrains and renames that text rather than
 * replacing it. A rename therefore has to reach every account already filed
 * under the old name in the same breath as the department row itself, or an
 * account is left under a name nothing lists any more, including the Chair
 * and Academic Executive queues that filter on it (see
 * Role::isDepartmentScoped()).
 */
class DepartmentAdminController extends Controller
{
    public function index()
    {
        $counts = User::whereNotNull('department')
            ->selectRaw('department, count(*) as aggregate')
            ->groupBy('department')
            ->pluck('aggregate', 'department');

        // Who covers each department's examiner-nomination queue. A department
        // with nobody here has a queue nothing can clear, which is the one
        // thing this screen is worth showing at a glance.
        $execs = User::where('role', Role::ACADEMIC_EXEC)
            ->whereNotNull('department')
            ->orderBy('name')
            ->get(['id', 'name', 'department'])
            ->groupBy('department');

        $departments = Department::orderBy('name')->get();

        // Faculty order is the university's own (Faculty::all()), not
        // alphabetical, with anything unfiled gathered at the end under ''.
        $groups = collect(Faculty::all())->push('')
            ->map(fn ($code) => [
                'code' => $code,
                'label' => $code === '' ? 'Not under a faculty' : Faculty::label($code),
                'note' => $code === '' ? 'Added by hand, or left over from before the list was settled.' : Faculty::note($code),
                'departments' => $departments->where('faculty', $code === '' ? null : $code)->values(),
            ])
            ->filter(fn ($group) => $group['departments']->isNotEmpty())
            ->values();

        return view('core::admin.departments.index', [
            'groups' => $groups,
            'counts' => $counts,
            'execs' => $execs,
        ]);
    }

    public function create()
    {
        return view('core::admin.departments.form', ['department' => new Department()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', 'unique:departments,name'],
            'faculty' => ['nullable', Rule::in(Faculty::all())],
        ]);

        Department::create($data + ['is_active' => true]);

        return redirect()->route('admin.departments.index')
            ->with('status', "Department \"{$data['name']}\" added.");
    }

    public function edit(Department $department)
    {
        return view('core::admin.departments.form', ['department' => $department]);
    }

    public function update(Request $request, Department $department)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', 'unique:departments,name,'.$department->id],
            'faculty' => ['nullable', Rule::in(Faculty::all())],
        ]);

        $old = $department->name;
        $new = $data['name'];

        // The faculty is this table's own business -- no account is filed
        // under it -- so it saves either way; only the name has to reach
        // `users` too, and only when it actually changed.
        if ($old === $new) {
            $department->update(['faculty' => $data['faculty'] ?? null]);
        } else {
            DB::transaction(function () use ($department, $data, $old, $new) {
                $department->update(['name' => $new, 'faculty' => $data['faculty'] ?? null]);
                User::where('department', $old)->update(['department' => $new]);
            });
        }

        $status = $old === $new
            ? "\"{$new}\" saved."
            : "Department renamed to \"{$new}\". Every account under the old name now carries the new one.";

        return redirect()->route('admin.departments.index')->with('status', $status);
    }

    /**
     * Retired rather than deleted: existing accounts keep the name on their
     * record, so the row has to stay valid history even once it stops being
     * offered on the "add a user" form.
     */
    public function toggleActive(Department $department)
    {
        $department->update(['is_active' => ! $department->is_active]);

        $status = $department->is_active
            ? "\"{$department->name}\" reactivated."
            : "\"{$department->name}\" retired. Existing accounts keep it; it is no longer offered for a new one.";

        return redirect()->route('admin.departments.index')->with('status', $status);
    }
}
