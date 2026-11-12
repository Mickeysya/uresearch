<?php

namespace App\Modules\Norhanis\Console\Commands;

use App\Modules\Norhanis\Support\Demo\DemoManifest;
use Illuminate\Console\Command;

/** Removes exactly what norhanis:demo-seed created, as its manifest lists. */
class DemoClear extends Command
{
    protected $signature = 'norhanis:demo-clear';

    protected $description = 'Delete the Travel, Publication and Claims demo data that norhanis:demo-seed created';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run: APP_ENV is production.');

            return self::FAILURE;
        }

        if (! DemoManifest::exists()) {
            $this->info('Nothing to clear: no '.DemoManifest::path().'.');

            return self::SUCCESS;
        }

        $removed = DemoManifest::clear();
        $this->info('Removed: '.json_encode($removed));

        return self::SUCCESS;
    }
}
