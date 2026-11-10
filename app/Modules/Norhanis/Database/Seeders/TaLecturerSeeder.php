<?php

namespace App\Modules\Norhanis\Database\Seeders;

use App\Modules\Core\Models\User;
use App\Modules\Norhanis\Models\TaCourseLecturer;
use App\Modules\Norhanis\Support\Roles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Course lecturers for Daily Log TA Hours: the people a GA/GRA picks as
 * "Course Lecturer", who then approve those hours. Fictional names; the
 * staff codes are placeholders until CGS confirms UTP's real format.
 *
 * Matched on email, so running it twice adds nothing.
 *
 *     php artisan db:seed --class="App\Modules\Norhanis\Database\Seeders\TaLecturerSeeder"
 *
 * Not called from Core's DatabaseSeeder: that file belongs to the whole team.
 */
class TaLecturerSeeder extends Seeder
{
    /** [name, email, staff code, department, faculty] */
    protected const LECTURERS = [
        ['Dr. Hafiz Rahman', 'lecturer@utp.edu.my', 'UTP1001', 'Management & Humanities', 'FSMC'],
        ['Dr. Siti Aminah Yusof', 'siti.aminah@utp.edu.my', 'UTP1002', 'Computing', 'FSMC'],
        ['Ir. Dr. Kumar Selvam', 'kumar.selvam@utp.edu.my', 'UTP1003', 'Mechanical Engineering', 'FOE'],
        ['Dr. Tan Mei Ling', 'tan.meiling@utp.edu.my', 'UTP1004', 'Chemical Engineering', 'FOE'],
        ['Dr. Farid Ismail', 'farid.ismail@utp.edu.my', 'UTP1005', 'Petroleum Engineering', 'FOE'],
    ];

    public function run(): void
    {
        $hash = Hash::make('password');

        foreach (self::LECTURERS as [$name, $email, $code, $department, $faculty]) {
            $user = User::updateOrCreate(['email' => $email], [
                'name' => $name,
                'role' => Roles::LECTURER,
                'password' => $hash,
                'department' => $department,
                'faculty' => $faculty,
            ]);

            TaCourseLecturer::updateOrCreate(['user_id' => $user->id], ['staff_code' => $code]);
        }
    }
}
