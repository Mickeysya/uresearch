<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Models\Department;
use App\Modules\Core\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        return view('core::admin.departments.index', [
            'departments' => Department::orderBy('name')->get(),
            'counts' => $counts,
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
        ]);

        $old = $department->name;
        $new = $data['name'];

        if ($old !== $new) {
            DB::transaction(function () use ($department, $old, $new) {
                $department->update(['name' => $new]);
                User::where('department', $old)->update(['department' => $new]);
            });
        }

        return redirect()->route('admin.departments.index')
            ->with('status', "Department renamed to \"{$new}\". Every account under the old name now carries the new one.");
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
