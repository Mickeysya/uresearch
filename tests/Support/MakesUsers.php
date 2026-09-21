<?php

namespace Tests\Support;

use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;

/**
 * The seeded cast, for tests.
 *
 * Every feature test needs two or three users in specific roles before it can
 * do anything, and each file was building them by hand. The names and emails
 * match DatabaseSeeder's, so a failure message names the same person you would
 * see logging into the real app.
 *
 * Only roles that tests actually act as live here. Add one when a test needs
 * it, not before.
 */
trait MakesUsers
{
    /** Any role, with defaults for the fields every user has. */
    protected function user(string $role, array $attributes = []): User
    {
        return User::create($attributes + [
            'name' => Role::label($role),
            'email' => str_replace('_', '-', $role).'@test.my',
            'password' => 'password',
            'role' => $role,
        ]);
    }

    protected function student(string $matric = '22001001', string $email = 'student@test.my', array $attributes = []): User
    {
        return $this->user(Role::STUDENT, $attributes + [
            'name' => 'Ahmad Danial',
            'email' => $email,
            'matric_no' => $matric,
        ]);
    }

    protected function supervisor(string $email = 'sup@test.my'): User
    {
        return $this->user(Role::SUPERVISOR, ['name' => 'Dr. Aisyah Rahman', 'email' => $email]);
    }

    protected function chair(): User
    {
        return $this->user(Role::CHAIR, ['name' => 'Dr. Lim Wei Chun', 'email' => 'chair@test.my']);
    }

    protected function cgs(): User
    {
        return $this->user(Role::NON_EXEC_CGS, ['name' => 'Puan Waheeda', 'email' => 'cgs@test.my']);
    }

    protected function academicExec(): User
    {
        return $this->user(Role::ACADEMIC_EXEC, ['name' => 'Siti Academic Exec', 'email' => 'ae@test.my']);
    }

    protected function seniorDirector(): User
    {
        return $this->user(Role::SENIOR_DIRECTOR_CGS, ['name' => 'En Zulkifly', 'email' => 'director@test.my']);
    }

    protected function dean(): User
    {
        return $this->user(Role::DEAN_PGR, ['name' => 'Prof. Dr. Hafiz Osman', 'email' => 'dean@test.my']);
    }

    /** Final approver on an RPD dismissal, between the Dean and the Registry. */
    protected function faculty(): User
    {
        return $this->user(Role::FACULTY, ['name' => 'Faculty Office', 'email' => 'faculty@test.my']);
    }

    protected function registry(): User
    {
        return $this->user(Role::REGISTRY, ['name' => 'Registry Officer', 'email' => 'registry@test.my']);
    }
}
