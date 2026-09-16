<?php

namespace App\Console\Commands;

use Database\Seeders\DemoDataSeeder;
use Illuminate\Console\Command;

/**
 * php artisan demo:seed [scenario]
 *
 * A thin wrapper around DemoDataSeeder so running a scenario, or listing
 * what's available, doesn't require remembering --class=DemoDataSeeder or
 * reading the seeder's source to find the names. The actual data lives in
 * DemoDataSeeder::SCENARIOS -- add a version there, not here.
 */
class SeedDemoData extends Command
{
    protected $signature = 'demo:seed {scenario? : One of the keys in DemoDataSeeder::SCENARIOS} {--list : List available scenarios and exit}';

    protected $description = 'Populate all three dashboards with demo data (students, applications, attendance)';

    public function handle(): int
    {
        if ($this->option('list')) {
            $this->table(['Scenario', 'What it does'], collect(DemoDataSeeder::SCENARIOS)
                ->map(fn ($s, $name) => [$name, $s['label']])
                ->values()
                ->all());

            return self::SUCCESS;
        }

        // No scenario check here -- run() already does it, and doing it twice
        // is how the two messages drift apart.
        (new DemoDataSeeder())->setContainer(app())->setCommand($this)->run($this->argument('scenario'));

        return self::SUCCESS;
    }
}
