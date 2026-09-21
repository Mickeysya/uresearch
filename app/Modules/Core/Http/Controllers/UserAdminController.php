<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Models\Department;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Create and edit staff accounts, and assign their role and department.
 *
 * Students are deliberately not here: they carry matric_no, programme and a
 * supervisor link this screen has no reason to touch, and every one of the
 * six modules already has its own idea of what a "new student" needs. This
 * is the screen that exists because a department can now have more than one
 * Chair or Academic Executive (see Role::isDepartmentScoped()) and, until
 * this, the only way to add one was the seeder or phpMyAdmin.
 */
class UserAdminController extends Controller
{
    protected const PER_PAGE = 20;

    public function index(Request $request)
    {
        $role = (string) $request->input('role', '');
        $search = mb_strtolower((string) $request->input('search', ''));

        $users = User::query()
            ->whereIn('role', Role::staffRoles())
            ->when($role !== '', fn ($q) => $q->where('role', $role))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereRaw('LOWER(name) like ?', ['%'.$search.'%'])
                ->orWhereRaw('LOWER(email) like ?', ['%'.$search.'%'])))
            ->orderBy('name')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('core::admin.users.index', [
            'users' => $users,
            'roles' => Role::staffRoles(),
            'filters' => ['role' => $role, 'search' => $search],
        ]);
    }

    public function create()
    {
        return view('core::admin.users.form', [
            'user' => new User(),
            'roles' => Role::staffRoles(),
            'departments' => Department::where('is_active', true)->orderBy('name')->pluck('name'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'in:'.implode(',', Role::staffRoles())],
            'department' => ['nullable', 'string', 'max:150'],
            'faculty' => ['nullable', 'string', 'max:100'],
        ]);

        User::create([
            ...$data,
            'password' => Hash::make($data['password']),
        ]);

        return redirect()->route('admin.users.index')
            ->with('status', "{$data['name']} added as ".Role::label($data['role']).'.');
    }

    public function edit(User $user)
    {
        abort_if($user->isStudent(), 404);

        $departments = Department::where('is_active', true)->orderBy('name')->pluck('name');

        // A department retired since this account was placed under it must
        // still appear as an option, or saving the form with nothing else
        // changed would silently clear it.
        if ($user->department && ! $departments->contains($user->department)) {
            $departments = $departments->push($user->department)->sort()->values();
        }

        return view('core::admin.users.form', [
            'user' => $user,
            'roles' => Role::staffRoles(),
            'departments' => $departments,
        ]);
    }

    public function update(Request $request, User $user)
    {
        abort_if($user->isStudent(), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email,'.$user->id],
            'role' => ['required', 'in:'.implode(',', Role::staffRoles())],
            'department' => ['nullable', 'string', 'max:150'],
            'faculty' => ['nullable', 'string', 'max:100'],
        ]);

        $user->update($data);

        return redirect()->route('admin.users.index')->with('status', "{$user->name} updated.");
    }
}
