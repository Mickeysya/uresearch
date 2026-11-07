<?php

namespace App\Modules\Haziq\Console\Commands;

use App\Modules\Haziq\Support\DocumentCompleteness;
use Illuminate\Console\Command;

/**
 * Runs weekly (registered from Haziq\ModuleProvider, not routes/console.php
 * — this module never edits a shared file). Chases every 'new' GRA/GA
 * application still missing a required document, skipping any already
 * reminded within the last week — see
 * Support\DocumentCompleteness::remindedRecently().
 */
class RemindIncompleteDocuments extends Command
{
    protected $signature = 'document-completeness:remind';

    protected $description = 'Remind every student whose GRA/GA application is still missing a required document.';

    public function handle(): int
    {
        $sent = 0;

        foreach (DocumentCompleteness::trackedApplications() as $application) {
            if (DocumentCompleteness::remindedRecently($application)) {
                continue;
            }

            if (DocumentCompleteness::missingFor($application)->isEmpty()) {
                continue;
            }

            DocumentCompleteness::remind($application);
            $sent++;
        }

        $this->info("Sent {$sent} incomplete-document reminder(s).");

        return self::SUCCESS;
    }
}
