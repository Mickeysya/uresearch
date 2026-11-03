<?php

namespace App\Modules\Chloe\Console\Commands;

use App\Modules\Chloe\Services\DismissalGenerator;
use Illuminate\Console\Command;

/**
 * Daily refresh of the dismissal candidate list. See DismissalGenerator for
 * the eligibility rule — also called on demand from CGS's "Refresh List"
 * button, so the rule lives in exactly one place.
 */
class GenerateCandidacyDismissals extends Command
{
    protected $signature = 'candidacy:generate-dismissals';

    protected $description = 'Generate the study-candidacy dismissal candidate list for CGS to review';

    public function handle(DismissalGenerator $generator): int
    {
        $created = $generator->generate();

        $this->info("Added {$created} new dismissal candidate(s).");

        return self::SUCCESS;
    }
}
