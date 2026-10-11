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
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Fills a development database with example students who have each applied
 * for Travel, Claims and Publication, so the overview list and the queues can
 * be looked at with a realistic number of rows instead of two.
 *
 * Every demo student has an address at DEMO_DOMAIN, which is how --clear
 * finds them again and why it can never touch a real account.
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
        {--count=100 : How many demo students to create}
        {--clear : Remove every demo student and their applications instead}';

    protected $description = 'Create (or remove) demo students with Travel, Claims and Publication applications';

    public const DEMO_DOMAIN = '@demo.utp.edu.my';

    protected const FIRST_NAMES = [
        'Aina', 'Aiman', 'Amirul', 'Aisyah', 'Daniel', 'Farah', 'Hakim', 'Hui Min', 'Irfan', 'Jia Hui',
        'Kavitha', 'Lim Wei', 'Mei Ling', 'Nabila', 'Nurul', 'Priya', 'Rahul', 'Siti', 'Syafiq', 'Zulaikha',
    ];

    protected const LAST_NAMES = [
        'Abdullah', 'Ahmad', 'Chong', 'Hassan', 'Ibrahim', 'Ismail', 'Kumar', 'Lee', 'Mohamad', 'Ng',
        'Osman', 'Rahman', 'Raj', 'Salleh', 'Tan', 'Wong', 'Yusof', 'Zainal', 'Zakaria', 'Zulkifli',
    ];

    protected const DEPARTMENTS = [
        'Computer & Information Sciences', 'Electrical & Electronic Engineering', 'Chemical Engineering',
        'Mechanical Engineering', 'Petroleum Engineering', 'Civil & Environmental Engineering',
    ];

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
            $email = sprintf('demo%03d%s', $n, self::DEMO_DOMAIN);

            if (User::where('email', $email)->exists()) {
                continue;
            }

            DB::transaction(function () use ($n, $email, $supervisor, $engine) {
                $student = User::create([
                    'name' => self::FIRST_NAMES[mt_rand(0, 19)].' '.self::LAST_NAMES[mt_rand(0, 19)],
                    'email' => $email,
                    'password' => 'password',
                    'role' => Role::STUDENT,
                    'matric_no' => sprintf('D26%05d', $n),
                    'programme' => mt_rand(0, 1) ? 'PhD' : 'MSc by Research',
                    'department' => self::DEPARTMENTS[mt_rand(0, 5)],
                    'supervisor_id' => $supervisor->id,
                ]);

                $this->progress($engine, $this->travel($student));
                $this->progress($engine, $this->claims($student));
                $this->progress($engine, $this->publication($student));
            });

            $created++;
        }

        $this->info("Created {$created} demo students, each with a Travel, Claims and Publication application.");
        $this->line('Remove them again with: php artisan norhanis:demo-applications --clear');

        return self::SUCCESS;
    }

    protected function clear(): int
    {
        $ids = User::where('email', 'like', '%'.self::DEMO_DOMAIN)->pluck('id');

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
            'contact_person_email' => 'contact'.self::DEMO_DOMAIN,
            'is_ga_gra' => false,
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
            'cost_centre' => 'CGS(015BD1-001)',
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
            $engine->decide($application, $this->approver($application), 'approve');
        }

        if ($rejects) {
            $engine->decide($application, $this->approver($application), 'reject', 'Demo: incomplete supporting documents.');
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

    /** Whoever holds the stage the application is on now. */
    protected function approver(Application $application): User
    {
        $role = $application->refresh()->currentStage()->role;

        return $this->approvers[$role] ??= $role === Role::SUPERVISOR
            ? $application->student->supervisor
            : User::where('role', $role)->orderBy('id')->firstOrFail();
    }
}
