<?php

namespace Database\Seeders;

use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\Examiner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Test accounts for every role, so anybody can exercise a full approval chain
 * on a fresh database without touching phpMyAdmin.
 *
 * Every account uses the password below. This seeder only ever runs locally --
 * it replaces the legacy fix_test_passwords.php, which was a web-reachable
 * script that reset EVERY user's password with an unfiltered UPDATE.
 */
class DatabaseSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public function run(): void
    {
        $supervisor = $this->user('Dr. Aisyah Rahman', 'supervisor@utp.edu.my', Role::SUPERVISOR, [
            'department' => 'Computer & Information Sciences',
            'faculty' => 'FSMC',
        ]);

        $this->user('Dr. Lim Wei Chun', 'chair@utp.edu.my', Role::CHAIR, [
            'department' => 'Computer & Information Sciences',
            'faculty' => 'FSMC',
        ]);

        $this->user('Puan Waheeda', 'cgs@utp.edu.my', Role::NON_EXEC_CGS, ['department' => 'CGS']);
        $this->user('Norshahirah', 'manager@utp.edu.my', Role::MANAGER_CGS, ['department' => 'CGS']);
        $this->user('En Zulkifly', 'director@utp.edu.my', Role::SENIOR_DIRECTOR_CGS, ['department' => 'CGS']);
        $this->user('Prof. Dr. Hafiz Osman', 'dean@utp.edu.my', Role::DEAN_PGR, ['department' => 'PGR']);
        $this->user('Siti Academic Exec', 'ae@utp.edu.my', Role::ACADEMIC_EXEC, [
            'department' => 'Computer & Information Sciences',
            'faculty' => 'FSMC',
        ]);
        // Faculty signs off an RPD dismissal between the Dean's endorsement
        // and the Registry's termination email -- see RpdDismissalWorkflow.
        $this->user('Faculty Office', 'faculty@utp.edu.my', Role::FACULTY, [
            'department' => 'Faculty of Engineering', 'faculty' => 'FOE',
        ]);
        $this->user('Registry Officer', 'registry@utp.edu.my', Role::REGISTRY, ['department' => 'Registry']);
        $this->user('System Admin', 'admin@utp.edu.my', Role::ADMIN);

        // Students, all attached to the supervisor above so the supervisee
        // relationship can actually be exercised.
        $this->user('Ahmad Danial', 'student@utp.edu.my', Role::STUDENT, [
            'matric_no' => '22001001',
            'programme' => 'MSc Full-Time',
            'department' => 'Computer & Information Sciences',
            'faculty' => 'FSMC',
            'supervisor_id' => $supervisor->id,
        ]);

        $this->user('Nur Farah Adilah', 'student2@utp.edu.my', Role::STUDENT, [
            'matric_no' => '22001002',
            'programme' => 'PhD Part-Time',
            'department' => 'Computer & Information Sciences',
            'faculty' => 'FSMC',
            'supervisor_id' => $supervisor->id,
        ]);

        $this->examiners();

        $this->command->newLine();
        $this->command->info('Seeded. Every account uses the password: '.self::PASSWORD);
        $this->command->table(
            ['Role', 'Email'],
            User::orderBy('id')->get(['role', 'email'])->map(fn ($u) => [Role::label($u->role), $u->email])->all()
        );
    }

    protected function user(string $name, string $email, string $role, array $extra = []): User
    {
        return User::updateOrCreate(['email' => $email], array_merge([
            'name' => $name,
            'role' => $role,
            'password' => Hash::make(self::PASSWORD),
        ], $extra));
    }

    /**
     * One examiner in each of the four states, so the rules are visible --
     * and both lists, so the internal/external split on the pool screen has
     * something to show. The external rows carry the extra record CGS keeps
     * for anyone from outside UTP; the internal ones deliberately do not.
     */
    protected function examiners(): void
    {
        $rows = [
            // Available: never examined, no assignment.
            ['Prof. Dr. Rosli Hamid', 'rosli@utp.edu.my', 'Petroleum Engineering', 'FOE', 'internal', true, null, null, []],
            // Available: last examined well beyond the 90-day gap.
            ['Dr. Chandra Segaran', 'chandra@utp.edu.my', 'Civil Engineering', 'FOE', 'internal', true, '-200 days', null, []],
            // On gap: examined 30 days ago.
            ['Prof. Madya Dr. Nabila Yusof', 'nabila@um.edu.my', 'Computer & Information Sciences', null, 'external', true, '-30 days', null, [
                'institution' => 'Universiti Malaya (UM)',
                'sector' => 'research',
                'faculty_approval' => '2.2023',
                'utp_cluster' => 'Intelligent Systems',
                'expertise' => '1. Machine Learning'."\n".'2. Computer Vision',
                'years_experience' => 18,
                'msc_graduated' => 12,
                'phd_graduated' => 6,
                'first_examination_date' => '-2 years',
            ]],
            // Assigned: tied to an active case.
            ['Dr. Tan Boon Keat', 'tan@usm.edu.my', 'Computer & Information Sciences', null, 'external', true, null, '+45 days', [
                'institution' => 'Universiti Sains Malaysia (USM)',
                'sector' => 'technical',
                'faculty_approval' => '1.2024',
                'utp_cluster' => 'Software Engineering',
                'expertise' => '1. Software Verification',
                'years_experience' => 11,
                'msc_graduated' => 5,
                'phd_graduated' => 5,
                'first_examination_date' => '-14 months',
            ]],
            // Unavailable: retired.
            ['Prof. Dr. Ismail Bakar', 'ismail@utp.edu.my', 'Chemical Engineering', 'FOE', 'internal', false, null, null, []],
        ];

        foreach ($rows as [$name, $email, $dept, $faculty, $type, $active, $lastExam, $assigned, $external]) {
            if (isset($external['first_examination_date'])) {
                $external['first_examination_date'] = now()->modify($external['first_examination_date']);
            }

            Examiner::updateOrCreate(['email' => $email], $external + [
                'name' => $name,
                'department' => $dept,
                'faculty' => $faculty,
                'type' => $type,
                'is_active' => $active,
                'last_examination_date' => $lastExam ? now()->modify($lastExam) : null,
                'assigned_until' => $assigned ? now()->modify($assigned) : null,
            ]);
        }
    }
}
