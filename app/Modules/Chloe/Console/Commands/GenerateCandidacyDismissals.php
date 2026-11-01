<?php

namespace App\Modules\Chloe\Console\Commands;

use Illuminate\Console\Command;

/**
 * DEPRECATED, does nothing. The Dismissal List is now live (Support\CandidacyDismissalList): students whose candidacy has expired, with no list to
 * generate or review. The shared routes/console.php still schedules this name at 07:15, so it stays as a harmless no-op until that line is removed.
 */
class GenerateCandidacyDismissals extends Command
{
    protected $signature = 'candidacy:generate-dismissals';

    protected $description = 'DEPRECATED (does nothing): the Dismissal List is now live; see Candidacy Management > Dismissal List';

    public function handle(): int
    {
        $this->line('candidacy:generate-dismissals is deprecated and does nothing. The Dismissal List is live in Candidacy Management.');

        return self::SUCCESS;
    }
}
