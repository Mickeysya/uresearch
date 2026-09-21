<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Models\Department;
use App\Modules\Core\Support\Faculty;
use App\Modules\Core\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The department picker admin and Non-Executive CGS (Puan Waheeda) manage
 * together, added so a department name can change without an ALTER and
 * without abandoning every account already filed under the old one -- see
 * the migration and DepartmentAdminController's doc comment.
 */
class DepartmentAdminTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    public function test_admin_and_cgs_can_both_reach_the_department_list(): void
    {
        Department::create(['name' => 'Civil Engineering', 'is_active' => true]);

        foreach ([$this->admin(), $this->cgs()] as $user) {
            $this->actingAs($user)
                ->get(route('admin.departments.index'))
                ->assertOk()
                ->assertSee('Civil Engineering');
        }
    }

    public function test_nobody_else_can_reach_it(): void
    {
        foreach ([$this->chair(), $this->academicExec(), $this->student()] as $user) {
            $this->actingAs($user)
                ->get(route('admin.departments.index'))
                ->assertForbidden();
        }
    }

    public function test_adding_a_department(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.departments.store'), ['name' => 'Geoscience'])
            ->assertRedirect(route('admin.departments.index'));

        $this->assertDatabaseHas('departments', ['name' => 'Geoscience', 'is_active' => true]);
    }

    public function test_a_department_name_must_be_unique(): void
    {
        Department::create(['name' => 'Geoscience', 'is_active' => true]);

        $this->actingAs($this->admin())
            ->post(route('admin.departments.store'), ['name' => 'Geoscience'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Department::where('name', 'Geoscience')->count());
    }

    /**
     * The whole reason this table exists rather than just validating
     * `users.department` against a hardcoded list: a rename has to reach
     * every account already carrying the old name, in the same request,
     * since `users.department` is free text rather than a foreign key.
     */
    public function test_renaming_a_department_moves_every_account_under_the_old_name(): void
    {
        $department = Department::create(['name' => 'Computing', 'is_active' => true]);

        $chair = $this->user(Role::CHAIR, ['name' => 'A Chair', 'email' => 'a-chair@test.my', 'department' => 'Computing']);
        $ae = $this->user(Role::ACADEMIC_EXEC, ['name' => 'An AE', 'email' => 'an-ae@test.my', 'department' => 'Computing']);
        $unrelated = $this->user(Role::CHAIR, ['name' => 'Other Chair', 'email' => 'other-chair@test.my', 'department' => 'Geoscience']);

        $this->actingAs($this->cgs())
            ->put(route('admin.departments.update', $department), ['name' => 'Computing and Information Technology'])
            ->assertRedirect(route('admin.departments.index'));

        $this->assertSame('Computing and Information Technology', $department->fresh()->name);
        $this->assertSame('Computing and Information Technology', $chair->fresh()->department);
        $this->assertSame('Computing and Information Technology', $ae->fresh()->department);
        $this->assertSame('Geoscience', $unrelated->fresh()->department, 'A different department must not move.');
    }

    public function test_a_department_is_added_under_its_faculty_and_listed_below_it(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.departments.store'), ['name' => 'Petroleum Engineering', 'faculty' => Faculty::FOE])
            ->assertRedirect(route('admin.departments.index'));

        $this->assertDatabaseHas('departments', ['name' => 'Petroleum Engineering', 'faculty' => Faculty::FOE]);

        $this->actingAs($admin)
            ->get(route('admin.departments.index'))
            ->assertOk()
            ->assertSeeInOrder([Faculty::label(Faculty::FOE), 'Petroleum Engineering']);
    }

    public function test_a_faculty_nobody_has_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.departments.store'), ['name' => 'School of Wizardry', 'faculty' => 'HOGWARTS'])
            ->assertSessionHasErrors('faculty');

        $this->assertDatabaseMissing('departments', ['name' => 'School of Wizardry']);
    }

    /**
     * The faculty is this table's own business -- no account is filed under
     * it -- so saving one must not touch the accounts the way a rename does.
     */
    public function test_moving_a_department_to_another_faculty_leaves_its_accounts_alone(): void
    {
        $department = Department::create(['name' => 'Computing', 'faculty' => Faculty::FOE, 'is_active' => true]);
        $chair = $this->user(Role::CHAIR, ['name' => 'A Chair', 'email' => 'a-chair@test.my', 'department' => 'Computing']);

        $this->actingAs($this->admin())
            ->put(route('admin.departments.update', $department), ['name' => 'Computing', 'faculty' => Faculty::FSMC])
            ->assertRedirect(route('admin.departments.index'));

        $this->assertSame(Faculty::FSMC, $department->fresh()->faculty);
        $this->assertSame('Computing', $chair->fresh()->department);
    }

    public function test_retiring_and_reactivating_a_department(): void
    {
        $department = Department::create(['name' => 'Management', 'is_active' => true]);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->patch(route('admin.departments.toggle', $department))
            ->assertRedirect(route('admin.departments.index'));

        $this->assertFalse($department->fresh()->is_active);

        $this->actingAs($admin)
            ->patch(route('admin.departments.toggle', $department))
            ->assertRedirect(route('admin.departments.index'));

        $this->assertTrue($department->fresh()->is_active);
    }

    /** Retiring is not deleting: the account it was set on keeps the name. */
    public function test_retiring_a_department_does_not_touch_accounts_already_on_it(): void
    {
        $department = Department::create(['name' => 'Management', 'is_active' => true]);
        $chair = $this->user(Role::CHAIR, ['name' => 'A Chair', 'email' => 'a-chair@test.my', 'department' => 'Management']);

        $this->actingAs($this->admin())->patch(route('admin.departments.toggle', $department));

        $this->assertSame('Management', $chair->fresh()->department);
    }
}
