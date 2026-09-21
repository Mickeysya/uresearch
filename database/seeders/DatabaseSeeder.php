<?php

namespace Database\Seeders;

use App\Modules\Chloe\Models\LockerKey;
use App\Modules\Chloe\Models\StudentGender;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationLocation;
use App\Modules\Chloe\Models\WorkstationRequest;
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
        // Rules on a hardbound appeal after the Non-Executive compiles the
        // Dean PFR report -- the last stage of HardboundAppealWorkflow, which
        // had nobody able to act on it until this account existed.
        $this->user('Puan Hasnah', 'seniorexec@utp.edu.my', Role::SENIOR_EXEC_CGS, ['department' => 'CGS']);
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
        $this->workstations();
        $this->studyCandidacies();

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

    /**
     * The real CGS seat catalogue (see roomDefinitions()), with every seat
     * the reference PDF itself showed occupied or under maintenance seeded
     * to match — plus one of those occupied seats given a real
     * WorkstationRequest and an overdue locker key, so the CGS operations
     * screen and `workstation:remind-locker-keys` have something to show
     * immediately. The other ~147 occupied seats have no request behind
     * them, because the reference recorded occupancy, not who — matching
     * that is more honest than inventing occupants. See
     * Workstation::activeRequest().
     */
    protected function workstations(): void
    {
        foreach ($this->roomDefinitions() as $definition) {
            $location = WorkstationLocation::updateOrCreate(
                ['room_code' => $definition['room_code']],
                [
                    'block' => $definition['block'],
                    'gender' => $definition['gender'],
                    'name' => $definition['name'],
                    'description' => $definition['description'] ?? null,
                ]
            );

            $position = 0;

            foreach ($definition['clusters'] as $clusterLabel => $seatNumbers) {
                foreach ($seatNumbers as $seatNumber) {
                    $position++;

                    Workstation::updateOrCreate(
                        ['workstation_location_id' => $location->id, 'seat_code' => (string) $seatNumber],
                        ['cluster' => $clusterLabel, 'position' => $position]
                        // Deliberately no 'status' here: updateOrCreate only
                        // sets it on first insert (default 'available'), so
                        // re-running the seeder never clobbers a seat a demo
                        // run has since allocated, force-assigned or
                        // disabled. The two passes below set status
                        // explicitly, every run, to match the reference.
                    );
                }
            }
        }

        foreach ($this->occupiedSeatNumbers() as $roomCode => $seatNumbers) {
            if (empty($seatNumbers)) {
                continue;
            }

            Workstation::whereHas('location', fn ($q) => $q->where('room_code', $roomCode))
                ->whereIn('seat_code', array_map('strval', $seatNumbers))
                ->update(['status' => Workstation::STATUS_OCCUPIED]);
        }

        foreach ($this->maintenanceSeatNumbers() as $roomCode => $seatNumbers) {
            Workstation::whereHas('location', fn ($q) => $q->where('room_code', $roomCode))
                ->whereIn('seat_code', array_map('strval', $seatNumbers))
                ->update(['status' => Workstation::STATUS_DISABLED]);
        }

        // Seat 11 (N2-03-01-02) is already marked occupied by the pass
        // above; this attaches the one demo WorkstationRequest + overdue
        // LockerKey so there's a concrete "known occupant" example too.
        $demoStudent = User::where('email', 'student2@utp.edu.my')->first();
        $demoSeat = Workstation::whereHas('location', fn ($q) => $q->where('room_code', 'N2-03-01-02'))
            ->where('seat_code', '11')
            ->first();

        if ($demoStudent && $demoSeat && ! WorkstationRequest::where('workstation_id', $demoSeat->id)
                ->where('status', WorkstationRequest::STATUS_CONFIRMED)->exists()) {
            $request = WorkstationRequest::create([
                'workstation_id' => $demoSeat->id,
                'student_id' => $demoStudent->id,
                'status' => WorkstationRequest::STATUS_CONFIRMED,
                'requested_at' => now()->subDays(6),
            ]);

            LockerKey::create([
                'workstation_request_id' => $request->id,
                'student_id' => $demoStudent->id,
                'status' => LockerKey::STATUS_REQUESTED,
                'requested_at' => now()->subDays(5),
            ]);
        }

        // Demo gender assignments — `users` has no gender column (Hard Rule
        // 2), so this module tracks it itself. See StudentGender.
        $student = User::where('email', 'student@utp.edu.my')->first();

        if ($student) {
            StudentGender::updateOrCreate(['student_id' => $student->id], ['gender' => 'male']);
        }

        if ($demoStudent) {
            StudentGender::updateOrCreate(['student_id' => $demoStudent->id], ['gender' => 'female']);
        }
    }

    /**
     * Every seat the reference PDF itself showed red ("NOT AVAILABLE"),
     * keyed by room_code, transcribed slide by slide alongside
     * roomDefinitions(). Seats not listed here stay at the 'available'
     * default.
     *
     * @return array<string, array<int, int>>
     */
    protected function occupiedSeatNumbers(): array
    {
        return [
            'N2-03-01-02' => [16, 15, 14, 13, 12, 11, 6, 7, 8, 9, 10, 5, 4, 3, 1],
            'N2-03-03-04' => [28, 29, 30, 32, 33, 34, 27, 25, 23, 19, 20, 21, 22],
            'N2-03-05-06' => [51, 50, 49, 46, 45, 40, 41, 42, 44, 39, 38, 37, 35],
            'N2-03-07-08' => [61, 60, 53, 54, 55, 56],
            'N2-03-10-11' => [81, 82],
            'N2-03-12-13' => [119, 92, 93, 96],
            'N2-03-14-15' => [],
            'J2-1.9' => [
                179, 180, 176, 181, 177, 182, 171, 170, 172, 169, 173, 168, 174, 167,
                155, 156, 157, 192, 193, 190, 189, 184, 185, 187, 186, 166, 165, 163,
                159, 160, 161, 162,
            ],
            'J2-1.2' => [
                248, 247, 249, 250, 245, 251, 244, 231, 220, 219, 218, 222, 217, 223,
                216, 204, 203, 205, 202, 207, 243, 237, 238, 239, 227, 224, 215, 208,
                209, 210, 212, 211, 194, 196, 195,
            ],
            'J2-1.1' => [
                260, 257, 256, 252, 262, 263, 265, 273, 274, 279, 275, 276, 277, 283,
                288, 284, 287, 285, 291, 296, 292, 295, 293, 294, 309, 310, 311, 315,
            ],
        ];
    }

    /**
     * Every seat the reference PDF showed yellow ("MAINTAINANCE"), keyed by
     * room_code. Mapped onto Workstation::STATUS_DISABLED — see
     * Workstation::statuses().
     *
     * @return array<string, array<int, int>>
     */
    protected function maintenanceSeatNumbers(): array
    {
        return [
            'J2-1.1' => [258, 286, 303],
        ];
    }

    /**
     * The real CGS workstation catalogue — every block, room, gender
     * designation, desk cluster and seat number, transcribed from
     * "Workstation seat map.pdf" (docs/scope/, 13 slides). Seat numbers run
     * 1-315 continuously across both blocks, not restarted per room; cluster
     * order and seat order within each cluster match the reference exactly,
     * since neither can be derived from the seat numbers alone (several
     * rows count down, not up).
     *
     * @return array<int, array{block: string, room_code: string, gender: string, name: string, description?: string, clusters: array<string, array<int, int>>}>
     */
    protected function roomDefinitions(): array
    {
        return [
            // --- Block N2 -----------------------------------------------
            [
                'block' => 'N2', 'room_code' => 'N2-03-01-02', 'gender' => 'male', 'name' => 'N2-03-01-02',
                'clusters' => [
                    'Cluster 1' => [17, 16, 15, 14, 13, 12, 11],
                    'Cluster 2' => [6, 7, 8, 9, 10],
                    'Cluster 3' => [5, 4, 3, 2, 1],
                ],
            ],
            [
                'block' => 'N2', 'room_code' => 'N2-03-03-04', 'gender' => 'male', 'name' => 'N2-03-03-04',
                'clusters' => [
                    'Cluster 1' => [28, 29, 30, 31, 32, 33, 34],
                    'Cluster 2' => [27, 26, 25, 24, 23],
                    'Cluster 3' => [18, 19, 20, 21, 22],
                ],
            ],
            [
                'block' => 'N2', 'room_code' => 'N2-03-05-06', 'gender' => 'female', 'name' => 'N2-03-05-06',
                'clusters' => [
                    'Cluster 1' => [51, 50, 49, 48, 47, 46, 45],
                    'Cluster 2' => [40, 41, 42, 43, 44],
                    'Cluster 3' => [39, 38, 37, 36, 35],
                ],
            ],
            [
                'block' => 'N2', 'room_code' => 'N2-03-07-08', 'gender' => 'female', 'name' => 'N2-03-07-08',
                'clusters' => [
                    'Cluster 1' => [66, 67, 68, 69, 70, 71, 72, 73, 74],
                    'Cluster 2' => [65, 64, 63, 62, 61, 60, 59],
                    'Cluster 3' => [52, 53, 54, 55, 56, 57, 58],
                ],
            ],
            [
                'block' => 'N2', 'room_code' => 'N2-03-10-11', 'gender' => 'female', 'name' => 'N2-03-10-11',
                'clusters' => [
                    'Cluster 1' => [91, 90, 89, 88, 87, 86, 85],
                    'Cluster 2' => [80, 81, 82, 83, 84],
                    'Cluster 3' => [79, 78, 77, 76, 75],
                ],
            ],
            [
                'block' => 'N2', 'room_code' => 'N2-03-12-13', 'gender' => 'male', 'name' => 'N2-03-12-13',
                'clusters' => [
                    'Cluster 1' => [110, 111, 112, 113, 114, 115, 116, 117, 118, 119],
                    'Cluster 2' => [109, 108, 107, 106, 105, 104, 103, 102, 101],
                    'Cluster 3' => [92, 93, 94, 95, 96, 97, 98, 99, 100],
                ],
            ],
            [
                'block' => 'N2', 'room_code' => 'N2-03-14-15', 'gender' => 'male', 'name' => 'N2-03-14-15',
                'clusters' => [
                    'Cluster 1' => [142, 143, 144, 145, 146, 147, 148, 149, 150, 151, 152, 153, 154],
                    'Cluster 2' => [141, 140, 139, 138, 137, 136, 135, 134, 133, 132, 131],
                    'Cluster 3' => [120, 121, 122, 123, 124, 125, 126, 127, 128, 129, 130],
                ],
            ],

            // --- Block J2 -----------------------------------------------
            [
                'block' => 'J2', 'room_code' => 'J2-1.9', 'gender' => 'male', 'name' => 'PG LAB MALE 1.9',
                'description' => 'Table and pantry along the west wall.',
                'clusters' => [
                    'Cluster 1' => [179, 175, 180, 176, 181, 177, 182, 178],
                    'Cluster 2' => [171, 170, 172, 169, 173, 168, 174, 167],
                    'Cluster 3' => [155, 156, 157, 158],
                    'Cluster 4' => [191, 192, 193],
                    'Cluster 5' => [190, 183, 189, 184, 188, 185, 187, 186],
                    'Cluster 6' => [166, 165, 164, 163],
                    'Cluster 7' => [159, 160, 161, 162],
                ],
            ],
            [
                'block' => 'J2', 'room_code' => 'J2-1.2', 'gender' => 'male', 'name' => 'PG LAB MALE 1.2',
                'description' => 'Prayer room, table, pantry and stairs along this room\'s walls.',
                'clusters' => [
                    'Cluster 1' => [248, 247, 249, 246, 250, 245, 251, 244],
                    'Cluster 2' => [232, 231, 233, 230, 234, 229, 235, 228],
                    'Cluster 3' => [220, 219, 221, 218, 222, 217, 223, 216],
                    'Cluster 4' => [204, 203, 205, 202, 206, 201, 207, 200],
                    'Cluster 5' => [243, 236, 242, 237, 241, 238, 240, 239],
                    'Cluster 6' => [227, 224, 226, 225],
                    'Cluster 7' => [215, 208, 214, 209, 213, 210, 212, 211],
                    'Cluster 8' => [199, 198, 197, 194, 196, 195],
                ],
            ],
            [
                'block' => 'J2', 'room_code' => 'J2-1.1', 'gender' => 'female', 'name' => 'PG LAB FEMALE 1.1',
                'description' => 'Two discussion tables and a discussion room along the south wall.',
                'clusters' => [
                    'Cluster 1' => [271],
                    'Cluster 2' => [260, 259, 258, 257, 256, 255, 254, 253, 252],
                    'Cluster 3' => [261, 270, 262, 269, 263, 268, 264, 267, 265, 266],
                    'Cluster 4' => [272, 281, 273, 280, 274, 279, 275, 278, 276, 277],
                    'Cluster 5' => [282, 289, 283, 288, 284, 287, 285, 286],
                    'Cluster 6' => [290, 297, 291, 296, 292, 295, 293, 294],
                    'Cluster 7' => [298, 309, 299, 308, 300, 307, 301, 306, 302, 305, 303, 304],
                    'Cluster 8' => [310, 311, 312, 313, 314, 315],
                ],
            ],
        ];
    }

    /**
     * Two candidacies, each set up so the matching scheduled command has
     * real work to do rather than something pre-faked into place — run
     * `candidacy:remind` and `candidacy:generate-dismissals` to see them
     * fire. Demo path: Ahmad submits an appeal from the reminder, walks it
     * through Supervisor -> Chair -> CGS -> Dean; Nur Farah's already-expired,
     * never-appealed candidacy shows up on the dismissal list untouched.
     */
    protected function studyCandidacies(): void
    {
        $ahmad = User::where('email', 'student@utp.edu.my')->first();
        $nurFarah = User::where('email', 'student2@utp.edu.my')->first();

        if ($ahmad) {
            StudyCandidacy::updateOrCreate(
                ['student_id' => $ahmad->id],
                [
                    'programme_start_date' => now()->subYears(2),
                    // Inside the 90-day reminder window (see
                    // SendCandidacyReminders) the moment the command runs.
                    'candidacy_expiry_date' => now()->addDays(60),
                    'status' => StudyCandidacy::STATUS_ACTIVE,
                ]
            );
        }

        if ($nurFarah) {
            StudyCandidacy::updateOrCreate(
                ['student_id' => $nurFarah->id],
                [
                    'programme_start_date' => now()->subYears(4),
                    'candidacy_expiry_date' => now()->subDays(10),
                    'status' => StudyCandidacy::STATUS_ACTIVE,
                ]
            );
        }
    }
}
