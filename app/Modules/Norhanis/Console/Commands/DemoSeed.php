<?php

namespace App\Modules\Norhanis\Console\Commands;

use App\Modules\Norhanis\Support\Demo\DemoDataBuilder;
use App\Modules\Norhanis\Support\Demo\DemoManifest;
use App\Modules\Norhanis\Support\Demo\DemoReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Demo data for Travel, Publication and Student Claims: 30 / 70 / 35
 * applications from the students already in the database, spread across
 * every department, at every status and stage, so the CGS, Supervisor and
 * Chair dashboards, queues and reports look like a working term. Creates
 * no users and changes none.
 *
 *   php artisan norhanis:demo-seed           seed (refuses if already seeded)
 *   php artisan norhanis:demo-seed --fresh   clear the last run first, then seed
 *   php artisan norhanis:demo-clear          remove exactly what it created
 *
 * Local and development only. Sends no email: mail goes to the array
 * mailer and every notification runs in-process (sync queue), so the
 * in-app notifications are written but nothing leaves the machine. What
 * it creates is listed in storage/app/demo-seed-manifest.json. Named
 * norhanis:* because demo:seed is the team's own command.
 */
class DemoSeed extends Command
{
    protected $signature = 'norhanis:demo-seed
        {--fresh : Remove the previous demo run first}
        {--seed=20261112 : Random seed, for a different but repeatable mix}';

    protected $description = 'Seed realistic Travel, Publication and Student Claims demo data from existing users (local/dev only)';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run: APP_ENV is production.');

            return self::FAILURE;
        }

        if (DemoManifest::exists()) {
            if (! $this->option('fresh')) {
                $this->error('Demo data is already seeded ('.DemoManifest::path().'). Run norhanis:demo-clear first, or use --fresh.');

                return self::FAILURE;
            }

            $removed = DemoManifest::clear();
            $this->line('Cleared the previous run: '.json_encode($removed));
        }

        // No email leaves the machine; notifications still write their
        // in-app rows, in-process.
        config(['mail.default' => 'array', 'queue.default' => 'sync']);

        $before = DB::table('notifications')->pluck('id')->flip();
        $builder = app(DemoDataBuilder::class, ['seed' => (int) $this->option('seed')]);

        $this->info('Seeding... (generates the sign-off PDFs, so this takes a few minutes)');
        $started = microtime(true);

        try {
            $builder->build();
        } finally {
            // Whatever was made is recorded, so demo-clear can undo a partial run.
            $notifications = DB::table('notifications')->pluck('id')->reject(fn ($id) => $before->has($id))->values()->all();
            DemoManifest::write($builder->created, $notifications);
        }

        $this->info(sprintf('Done in %ds: %d travel, %d publication, %d claims, %d notifications. Manifest: %s',
            microtime(true) - $started, count($builder->created['travel']), count($builder->created['publication']),
            count($builder->created['claims_student']), count($notifications), DemoManifest::path()));

        foreach ($builder->notes as $note) {
            $this->warn($note);
        }

        $this->newLine();
        foreach (DemoReport::lines() as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
