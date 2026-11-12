<?php

namespace App\Modules\Norhanis\Console\Commands;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Norhanis\Models\ClaimsDetail;
use App\Modules\Norhanis\Models\PublicationDetail;
use App\Modules\Norhanis\Models\TravelDetail;
use App\Modules\Norhanis\Support\CgsOfficer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Fills a development database with example students who have each made one
 * application -- Travel, Claims or Publication in turn -- so the overview list
 * and the queues can be looked at with a realistic number of rows instead of
 * two, without one row per module for every student.
 *
 * The students differ the way real ones do: UTP's own PhD and Master's
 * programmes across its nine departments, full- or part-time, some on a
 * GA/GRA post, some travelling abroad, some publishing at a conference and
 * some in a journal.
 *
 * Each has a UTP-style address, name_studentid@utp.edu.my, and a student ID
 * from a block real IDs do not use: a third digit of 9 (24900001). That is
 * how --clear finds them again -- the ID in the block AND the address built
 * from it, so a real account is never matched by either alone.
 *
 * Applications are moved along their chains by WorkflowEngine, the same as
 * real ones, so their status, stage and approval history are genuine. Two
 * things are skipped because this is display data: no notifications are sent,
 * and a demo publication is submitted without collecting its authors' and
 * reviewers' sign-offs.
 */
class SeedDemoApplications extends Command
{
    protected $signature = 'norhanis:demo-applications
        {--count=100 : How many demo students to create, one application each}
        {--clear : Remove every demo student and their applications instead}';

    protected $description = 'Create (or remove) demo students, each with one Travel, Claims or Publication application';

    public const DOMAIN = '@utp.edu.my';

    /** Where the demo students used to live, so --clear still removes them. */
    protected const OLD_DEMO_DOMAIN = '@demo.utp.edu.my';

    protected const FIRST_NAMES = [
        'Aina', 'Aiman', 'Amirul', 'Aisyah', 'Daniel', 'Farah', 'Hakim', 'Hui Min', 'Irfan', 'Jia Hui',
        'Kavitha', 'Lim Wei', 'Mei Ling', 'Nabila', 'Nurul', 'Priya', 'Rahul', 'Siti', 'Syafiq', 'Zulaikha',
    ];

    protected const LAST_NAMES = [
        'Abdullah', 'Ahmad', 'Chong', 'Hassan', 'Ibrahim', 'Ismail', 'Kumar', 'Lee', 'Mohamad', 'Ng',
        'Osman', 'Rahman', 'Raj', 'Salleh', 'Tan', 'Wong', 'Yusof', 'Zainal', 'Zakaria', 'Zulkifli',
    ];

    /**
     * UTP's departments, each with programmes it runs: PhD by research,
     * and Master's by coursework or research.
     */
    protected const DEPARTMENTS = [
        'Chemical Engineering Department' => [
            'PhD in Chemical Engineering', 'Master of Science in Process Safety', 'Master of Science in Process Integration',
        ],
        'Civil & Environmental Engineering Department' => [
            'PhD in Civil Engineering', 'Master of Science in Industrial Environmental Engineering', 'Master of Science in Offshore Engineering',
        ],
        'Electrical & Electronic Engineering Department' => [
            'PhD in Electrical and Electronics Engineering', 'Master of Science in Electronic Systems Engineering',
        ],
        'Mechanical Engineering Department' => [
            'PhD in Mechanical Engineering', 'Master of Science in Asset Management & Maintenance', 'Master of Science in Corrosion Engineering',
        ],
        'Petroleum Engineering Department' => [
            'PhD in Petroleum Engineering', 'Master of Science in Petroleum Engineering', 'Master of Science in Drilling Engineering',
        ],
        'Computing' => [
            'PhD in Information Technology', 'PhD in Information Systems', 'Master of Science in Data Science', 'Master of Science in Applied Computing',
        ],
        'Geosciences Department' => [
            'PhD in Petroleum Geoscience', 'Master of Science in Petroleum Geoscience',
        ],
        'Management & Humanities Department' => [
            'PhD in Management', 'PhD in Social Science & Humanities', 'MBA in Energy Management',
        ],
        'Fundamental & Applied Sciences Department' => [
            'PhD in Applied Science', 'Master of Science in Predictive Analytics',
        ],
    ];

    /** Each student files one of these, in turn, so the three lists come out even. */
    protected const MODULES = ['travel', 'claims', 'publication'];

    protected const PAPER_TOPICS = [
        'Graph Networks for Reservoir Simulation', 'Corrosion Forecasting in Subsea Pipelines',
        'Low-Power Sensing for Offshore Platforms', 'Catalyst Design for CO2 Conversion',
        'Seismic Facies Classification', 'Fault Detection in Smart Grids',
    ];

    /** One approver per role, looked up once. */
    protected array $approvers = [];

    public function handle(WorkflowEngine $engine): int
    {
        if (app()->environment('production')) {
            $this->error('This creates example data and must not be run in production.');

            return self::FAILURE;
        }

        if ($this->option('clear')) {
            return $this->clear();
        }

        // Display data: nobody should be emailed about it.
        Notification::fake();

        $supervisor = User::where('role', Role::SUPERVISOR)->orderBy('id')->first();

        if (! $supervisor) {
            $this->error('No supervisor account exists yet. Run the database seeder first.');

            return self::FAILURE;
        }

        // Seeded, so the same command gives the same picture every time.
        mt_srand(2026);

        $count = max(1, (int) $this->option('count'));
        $created = 0;

        for ($n = 1; $n <= $count; $n++) {
            // Drawn every time, skipped or not, so student N is always the
            // same person however many already exist.
            $first = self::FIRST_NAMES[mt_rand(0, 19)];
            $last = self::LAST_NAMES[mt_rand(0, 19)];
            $department = array_keys(self::DEPARTMENTS)[mt_rand(0, count(self::DEPARTMENTS) - 1)];
            $programmes = self::DEPARTMENTS[$department];
            $programme = $programmes[mt_rand(0, count($programmes) - 1)].(mt_rand(0, 2) === 0 ? ' (Part-Time)' : ' (Full-Time)');
            $matric = self::matric($n, intake: 24 + mt_rand(0, 2));

            if (User::where('matric_no', $matric)->exists()) {
                continue;
            }

            DB::transaction(function () use ($n, $first, $last, $department, $programme, $matric, $supervisor, $engine) {
                $student = User::create([
                    'name' => $first.' '.$last,
                    'email' => self::email($first, $matric),
                    'password' => 'password',
                    'role' => Role::STUDENT,
                    'matric_no' => $matric,
                    'programme' => $programme,
                    'department' => $department,
                    'supervisor_id' => $supervisor->id,
                ]);

                $this->progress($engine, match (self::MODULES[($n - 1) % count(self::MODULES)]) {
                    'travel' => $this->travel($student),
                    'claims' => $this->claims($student),
                    'publication' => $this->publication($student),
                });
            });

            $created++;
        }

        $this->info("Created {$created} demo students, each with one Travel, Claims or Publication application.");
        $this->line('Remove them again with: php artisan norhanis:demo-applications --clear');

        return self::SUCCESS;
    }

    /** Student N's ID: the intake year, then 9, then N (24900001). */
    public static function matric(int $n, int $intake): string
    {
        return sprintf('%02d9%05d', $intake, $n);
    }

    /** name_studentid@utp.edu.my, the name as one lowercase word. */
    public static function email(string $firstName, string $matric): string
    {
        return strtolower(preg_replace('/[^A-Za-z]/', '', $firstName)).'_'.$matric.self::DOMAIN;
    }

    /** Is this a student the command made? See the class comment. */
    public static function isDemo(User $user): bool
    {
        if (str_ends_with($user->email, self::OLD_DEMO_DOMAIN)) {
            return true;
        }

        return $user->role === Role::STUDENT
            && preg_match('/^\d{2}9\d{5}$/', (string) $user->matric_no)
            && str_ends_with($user->email, '_'.$user->matric_no.self::DOMAIN);
    }

    protected function clear(): int
    {
        $ids = User::where('role', Role::STUDENT)
            ->where(fn ($q) => $q->where('matric_no', 'like', '__9_____')->orWhere('email', 'like', '%'.self::OLD_DEMO_DOMAIN))
            ->get()
            ->filter(fn (User $user) => self::isDemo($user))
            ->pluck('id');

        DB::transaction(function () use ($ids) {
            Application::whereIn('student_id', $ids)->get()->each->delete();
            User::whereIn('id', $ids)->delete();
        });

        $this->info("Removed {$ids->count()} demo students and their applications.");

        return self::SUCCESS;
    }

    protected function travel(User $student): Application
    {
        $application = $this->draft($student, 'travel');
        $start = now()->addDays(mt_rand(10, 90));
        $days = mt_rand(2, 6);

        TravelDetail::create([
            'application_id' => $application->id,
            'type_of_request' => array_rand(TravelDetail::requestTypes()),
            'travel_start_date' => $start,
            'travel_end_date' => $start->copy()->addDays($days - 1),
            'duration_days' => $days,
            'reason_for_travel' => 'Demo: '.['Conference presentation', 'Data collection', 'Research attachment', 'Workshop'][mt_rand(0, 3)],
            'destination_address' => ['Kuala Lumpur', 'Penang', 'Johor Bahru', 'Singapore', 'Bandung', 'Tokyo'][mt_rand(0, 5)],
            'is_international' => mt_rand(1, 10) <= 3,
            'contact_person_email' => 'host.contact'.self::DOMAIN,
            // About one in three holds a GA or GRA post.
            'is_ga_gra' => mt_rand(1, 3) === 1,
        ]);

        return $application;
    }

    protected function claims(User $student): Application
    {
        $application = $this->draft($student, 'claims_student');

        ClaimsDetail::create([
            'application_id' => $application->id,
            'bank_name' => ['Maybank', 'CIMB', 'Bank Islam', 'RHB'][mt_rand(0, 3)],
            'bank_account_no' => (string) mt_rand(1000000000, 1999999999),
            'total_claims' => mt_rand(300, 3000),
        ]);

        return $application;
    }

    protected function publication(User $student): Application
    {
        $application = $this->draft($student, 'publication');
        $conference = mt_rand(0, 1) === 1;
        $start = now()->addDays(mt_rand(20, 120));

        $detail = PublicationDetail::create([
            'application_id' => $application->id,
            'type_of_request' => $conference ? 'publication_conference' : 'publication_journal',
            'conference_scope' => $conference ? ['local', 'international'][mt_rand(0, 1)] : null,
            'title_of_paper' => 'Demo: '.self::PAPER_TOPICS[mt_rand(0, 5)],
            'title_of_conference_journal' => $conference ? 'Demo Conference on Engineering Research' : 'Demo Journal of Applied Science',
            'organizer_publisher' => 'Demo Publisher',
            'conference_journal_fee' => mt_rand(8, 30) * 100,
            'currency_type' => 'MYR',
            // A conference may use "Others" only; a journal any of them.
            'cost_centre' => $conference ? 'others' : ['cgs_pay_and_claims', 'cgs_letter_of_undertaking', 'split_memo'][mt_rand(0, 2)],
            'cost_centre_other' => $conference ? 'Demo research grant' : null,
            'conference_start_date' => $start,
            'conference_end_date' => $start->copy()->addDays(2),
        ]);

        $detail->authors()->create(['author_name' => $student->name, 'email' => $student->email]);

        return $application;
    }

    protected function draft(User $student, string $module): Application
    {
        return Application::create([
            'student_id' => $student->id,
            'module_type' => $module,
            'status' => Application::STATUS_DRAFT,
        ]);
    }

    /**
     * Submit the application and walk it part of the way down its chain, so
     * the list shows a mix: some waiting at each stage, some approved, a few
     * rejected.
     */
    protected function progress(WorkflowEngine $engine, Application $application): void
    {
        $engine->submit($application);

        // Spread the submissions over the last two months. The engine has
        // just stamped "now"; only the date is moved, never status or stage.
        $application->forceFill(['submitted_at' => now()->subDays(mt_rand(0, 60))->subMinutes(mt_rand(0, 1440))])->save();

        $stages = $engine->stagesFor($application);
        $roll = mt_rand(1, 100);

        // 25% approved, 10% rejected somewhere, the rest still on a stage.
        $approvals = match (true) {
            $roll <= 25 => count($stages),
            default => mt_rand(0, count($stages) - 1),
        };
        $rejects = $roll > 25 && $roll <= 35;

        for ($i = 0; $i < $approvals; $i++) {
            if (! $approver = $this->approver($application)) {
                // Nobody can act on this stage (no Chair in the student's
                // department, say): the application waits there, as it would.
                $rejects = false;
                break;
            }
            $engine->decide($application, $approver, 'approve');
        }

        if ($rejects && $approver = $this->approver($application)) {
            $engine->decide($application, $approver, 'reject', 'Demo: incomplete supporting documents.');
        }

        $this->spreadDecisions($application);
    }

    /**
     * The engine dated every decision "now", all in the same second. Move
     * each one to a believable day between submission and today, so the
     * report's time-per-stage figures have something to show. The later
     * stages are made the slower ones, as a queue further up a chain is.
     */
    protected function spreadDecisions(Application $application): void
    {
        $at = $application->submitted_at->copy();

        foreach (ApprovalHistory::where('application_id', $application->id)->orderBy('id')->get() as $step => $entry) {
            $at = $at->copy()->addHours(mt_rand(6, 48) * ($step + 1));

            // Never in the future, and never out of order.
            $at = $at->min(now()->subMinutes(5 - min($step, 4)));

            ApprovalHistory::whereKey($entry->id)->update(['created_at' => $at, 'updated_at' => $at]);
        }
    }

    /** Whoever may decide the current stage, or null when nobody can. */
    protected function approver(Application $application): ?User
    {
        $role = $application->refresh()->currentStage()->role;

        if ($role === Role::SUPERVISOR) {
            return $application->student->supervisor;
        }

        // Travel, Claims and Publication have their own CGS officer, so the
        // demo history names her rather than whichever officer came first.
        if ($role === Role::NON_EXEC_CGS && $officer = app(CgsOfficer::class)->user()) {
            return $officer;
        }

        // A Chair or AE may only act on their own department's students
        // (WorkflowEngine refuses anyone else), so they are looked up per
        // department.
        $department = Role::isDepartmentScoped($role) ? $application->student->department : null;

        return $this->approvers[$role.'|'.$department] ??= User::where('role', $role)
            ->when($department !== null, fn ($q) => $q->where('department', $department))
            ->orderBy('id')
            ->first();
    }
}
