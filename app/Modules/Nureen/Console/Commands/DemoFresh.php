<?php

namespace App\Modules\Nureen\Console\Commands;

use App\Modules\Nureen\Models\DemoRecord;
use App\Modules\Nureen\Support\Demo\DemoFreshBuilder;
use App\Modules\Nureen\Support\Demo\DemoFreshReset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Fresh demo data for Nureen's four modules, made by driving the real
 * screens -- see Support\Demo\DemoFreshBuilder for what is created and how,
 * and Support\Demo\DemoFreshReset for what --reset removes.
 *
 *     php artisan nureen:demo-fresh           # create
 *     php artisan nureen:demo-fresh --reset   # remove only what it created
 *
 * Adds data only. It uses student accounts that have nothing on file yet,
 * so no existing record is changed, and it refuses to run a second time
 * until the first run's data has been reset. If a step fails, everything
 * the run had created so far is removed again.
 *
 * Sends real emails (to Mailpit on a dev machine), exactly as using the
 * screens would.
 */
class DemoFresh extends Command
{
    protected $signature = 'nureen:demo-fresh
        {--reset : Remove only the data an earlier run of this command created}';

    protected $description = 'Create fresh demo applications and attendance for Nureen\'s modules through the real workflow (or --reset to remove them)';

    public function handle(DemoFreshBuilder $builder, DemoFreshReset $reset): int
    {
        if (! Schema::hasTable('nureen_demo_records')) {
            $this->error('The nureen_demo_records table is missing. Run ./sync.sh (or php artisan migrate) first.');

            return self::FAILURE;
        }

        if ($this->option('reset')) {
            $deleted = $reset->run();
            $this->info($deleted === [] ? 'There was no demo data to remove.' : 'Removed the demo data this command created:');

            if ($deleted !== []) {
                $this->table(['Table', 'Rows'], collect($deleted)->map(fn ($n, $t) => [$t, $n])->values()->all());
                $this->line('Emails already in Mailpit are not touched.');
            }

            return self::SUCCESS;
        }

        if (DemoRecord::exists()) {
            $this->error('Demo data from an earlier run is still there. Run `php artisan nureen:demo-fresh --reset` first.');

            return self::FAILURE;
        }

        $this->line('Creating demo data through the real screens. This sends real emails and takes a minute...');

        try {
            $result = $builder->build();
        } catch (\Throwable $e) {
            $reset->run();
            $this->error('Stopped: '.$e->getMessage());
            $this->warn('Everything this run had created has been removed again. Nothing else was changed.');

            return self::FAILURE;
        }

        $this->table(['Module', 'Reference', 'Student', 'Current stage', 'Log in next as'], $result['rows']);
        $this->line('Every password is "password". Remove all of this with: php artisan nureen:demo-fresh --reset');

        foreach ($result['warnings'] as $warning) {
            $this->warn($warning);
        }

        return self::SUCCESS;
    }
}
