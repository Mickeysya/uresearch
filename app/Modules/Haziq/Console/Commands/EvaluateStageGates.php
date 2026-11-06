<?php

namespace App\Modules\Haziq\Console\Commands;

use App\Modules\Haziq\Support\StageGateEngine;
use Illuminate\Console\Command;

/**
 * Runs daily (registered from Haziq\ModuleProvider, not routes/console.php —
 * this module never edits a shared file). Recomputes every open stage
 * gate's timeline against today's date, sends grace-period reminders, and
 * applies the withhold/forfeit-and-trigger consequence the moment a grace
 * period lapses.
 */
class EvaluateStageGates extends Command
{
    protected $signature = 'stage-gate:evaluate';

    protected $description = 'Evaluate every open Stage Gate against today\'s date and apply consequences.';

    public function handle(StageGateEngine $engine): int
    {
        $engine->evaluateAll();

        $this->info('Stage Gate evaluation complete.');

        return self::SUCCESS;
    }
}
