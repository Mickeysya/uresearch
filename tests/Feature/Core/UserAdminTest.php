<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Models\Department;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Creating and editing staff accounts. This is what replaced
 * AdminController::users()'s placeholder, and the direct answer to "I need
 * to create more AE" -- until this, a second Chair or Academic Executive for
 * a department could only be added in the seeder or phpMyAdmin.
 */
class UserAdminTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    public function test_admin_and_cgs_can_both_reach_the_user_list(): void
    {
        foreach ([$this->admin(), $this->cgs()] as $user) {
            $this->actingAs($user)
                ->get(route('admin.users.index'))
                ->assertOk()
                ->assertSee('Users and Roles');
        }
    }

    public function test_nobody_else_can_reach_it(): void
    {
        foreach ([$this->chair(), $this->academicExec(), $this->student()] as $user) {
            $this->actingAs($user)
                ->get(route('admin.users.index'))
                ->assertForbidden();
        }
    }

    public function test_the_list_never_includes_students(): void
    {
        $student = $this->student();

        $this->actingAs($this->admin())
            ->get(route('admin.users.index'))
            ->assertDontSee($student->email);
    }

    /**
     * The scenario the whole feature exists for: a second Academic
     * Executive for a department that already has one.
     */
    public function test_cgs_can_add_a_second_academic_exec_for_a_department(): void
    {
        Department::create(['name' => 'Petroleum Engineering', 'is_active' => true]);
        $this->user(Role::ACADEMIC_EXEC, [
            'name' => 'First AE', 'email' => 'first-ae@test.my', 'department' => 'Petroleum Engineering',
        ]);

        $this->actingAs($this->cgs())
            ->post(route('admin.users.store'), [
                'name' => 'Second AE',
                'email' => 'second-ae@test.my',
                'password' => 'password123',
                'role' => Role::ACADEMIC_EXEC,
                'department' => 'Petroleum Engineering',
            ])
            ->assertRedirect(route('admin.users.index'));

        $second = User::where('email', 'second-ae@test.my')->firstOrFail();
        $this->assertSame(Role::ACADEMIC_EXEC, $second->role);
        $this->assertSame('Petroleum Engineering', $second->department);
        $this->assertSame(
            2,
            User::where('role', Role::ACADEMIC_EXEC)->where('department', 'Petroleum Engineering')->count()
        );
    }

    public function test_the_new_account_can_sign_in_with_the_password_it_was_given(): void
    {
        $this->actingAs($this->admin())->post(route('admin.users.store'), [
            'name' => 'New Chair', 'email' => 'new-chair@test.my', 'password' => 'a-strong-password',
            'role' => Role::CHAIR, 'department' => 'Mechanical Engineering',
        ]);

        $this->post(route('login.store'), ['email' => 'new-chair@test.my', 'password' => 'a-strong-password'])
            ->assertRedirect(route('dashboard'));
    }

    public function test_an_email_already_in_use_is_rejected(): void
    {
        $existing = $this->chair();

        $this->actingAs($this->admin())
            ->post(route('admin.users.store'), [
                'name' => 'Someone Else', 'email' => $existing->email, 'password' => 'password123',
                'role' => Role::ACADEMIC_EXEC,
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_editing_changes_role_and_department_without_touching_the_password(): void
    {
        $user = $this->user(Role::ACADEMIC_EXEC, [
            'name' => 'Grows Into Chair', 'email' => 'grows@test.my', 'department' => 'Science',
        ]);
        $originalPassword = $user->password;

        $this->actingAs($this->admin())
            ->put(route('admin.users.update', $user), [
                'name' => 'Grows Into Chair', 'email' => 'grows@test.my',
                'role' => Role::CHAIR, 'department' => 'Applied Science',
            ])
            ->assertRedirect(route('admin.users.index'));

        $fresh = $user->fresh();
        $this->assertSame(Role::CHAIR, $fresh->role);
        $this->assertSame('Applied Science', $fresh->department);
        $this->assertSame($originalPassword, $fresh->password);
    }

    public function test_a_student_cannot_be_reached_through_the_edit_route(): void
    {
        $student = $this->student();

        $this->actingAs($this->admin())
            ->get(route('admin.users.edit', $student))
            ->assertNotFound();
    }
}
