<?php

namespace App\Modules\Core\Contracts\Defaults;

use App\Modules\Core\Models\Application;

/**
 * Do-nothing defaults for Contracts\DescribesApplicationDetails, so a module
 * implements only what it has. A method added to the interface later gets
 * its default here too, and no implementer breaks.
 */
trait DescribesApplicationDetailsDefaults
{
    public function details(Application $application): array
    {
        return [];
    }

    public function timelineEvents(Application $application): array
    {
        return [];
    }

    public function issuedDocument(Application $application): ?array
    {
        return null;
    }

    public function stageTargetDays(Application $application, string $stageKey): ?int
    {
        return null;
    }
}
