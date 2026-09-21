<?php

namespace Database\Seeders;

use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Norhanis\Models\Candidacy;
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
        $this->user('Registry Officer', 'registry@utp.edu.my', Role::REGISTRY, ['department' => 'Registry']);
        $this->user('Assoc. Prof. Dr. Kamarul Ariffin', 'faculty@utp.edu.my', Role::FACULTY, ['department' => 'FSMC']);
        $this->user('System Admin', 'admin@utp.edu.my', Role::ADMIN);

        // Students, all attached to the supervisor above so the supervisee
        // relationship can actually be exercised.
        $student = $this->user('Ahmad Danial', 'student@utp.edu.my', Role::STUDENT, [
            'matric_no' => '22001001',
            'programme' => 'MSc Full-Time',
            'department' => 'Computer & Information Sciences',
            'faculty' => 'FSMC',
            'supervisor_id' => $supervisor->id,
        ]);

        $student2 = $this->user('Nur Farah Adilah', 'student2@utp.edu.my', Role::STUDENT, [
            'matric_no' => '22001002',
            'programme' => 'PhD Part-Time',
            'department' => 'Computer & Information Sciences',
            'faculty' => 'FSMC',
            'supervisor_id' => $supervisor->id,
        ]);

        $this->examiners();
        $this->candidacies($student, $student2);

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

    /** One examiner in each of the four states, so the rules are visible. */
    protected function examiners(): void
    {
        $rows = [
            // Available: never examined, no assignment.
            ['Prof. Dr. Rosli Hamid', 'rosli@utp.edu.my', 'Petroleum Engineering', 'FOE', 'internal', true, null, null],
            // Available: last examined well beyond the 90-day gap.
            ['Dr. Chandra Segaran', 'chandra@utp.edu.my', 'Civil Engineering', 'FOE', 'internal', true, '-200 days', null],
            // On gap: examined 30 days ago.
            ['Prof. Madya Dr. Nabila Yusof', 'nabila@um.edu.my', 'Computer Science', null, 'external', true, '-30 days', null],
            // Assigned: tied to an active case.
            ['Dr. Tan Boon Keat', 'tan@usm.edu.my', 'Software Engineering', null, 'external', true, null, '+45 days'],
            // Unavailable: retired.
            ['Prof. Dr. Ismail Bakar', 'ismail@utp.edu.my', 'Chemical Engineering', 'FOE', 'internal', false, null, null],
        ];

        foreach ($rows as [$name, $email, $dept, $faculty, $type, $active, $lastExam, $assigned]) {
            Examiner::updateOrCreate(['email' => $email], [
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

    /**
     * One candidacy nearing its RPD deadline (exercises the reminder command
     * and the appeal chain) and one already overdue (exercises the CGS
     * dismissal-initiation screen), so both of Norhanis' RPD flows have real
     * data to click through on a fresh database.
     */
    protected function candidacies(User $fullTimeStudent, User $partTimeStudent): void
    {
        // MSc Full-Time, 8-month deadline: started 7 months ago, so the
        // deadline is about a month out -- within the reminder command's
        // 1-month window and still appeal-eligible.
        $start = now()->subMonths(7);
        Candidacy::updateOrCreate(
            ['student_id' => $fullTimeStudent->id],
            [
                'study_mode' => Candidacy::STUDY_MODE_FULL_TIME,
                'programme' => Candidacy::PROGRAMME_MASTERS,
                'start_date' => $start,
                'deadline' => Candidacy::computeDeadline($start, Candidacy::STUDY_MODE_FULL_TIME),
                'status' => Candidacy::STATUS_ACTIVE,
            ]
        );

        // PhD Part-Time, 12-month deadline: started 14 months ago, so the
        // deadline already passed with no appeal filed -- eligible for CGS
        // to initiate dismissal.
        $start = now()->subMonths(14);
        Candidacy::updateOrCreate(
            ['student_id' => $partTimeStudent->id],
            [
                'study_mode' => Candidacy::STUDY_MODE_PART_TIME,
                'programme' => Candidacy::PROGRAMME_PHD,
                'start_date' => $start,
                'deadline' => Candidacy::computeDeadline($start, Candidacy::STUDY_MODE_PART_TIME),
                'status' => Candidacy::STATUS_ACTIVE,
            ]
        );
    }
}
