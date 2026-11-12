<?php

namespace App\Modules\Nureen\Workflows\Concerns;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Nureen\Support\StaffApplicationAccess;

/**
 * Core\Contracts\ProvidesStaffView for Nureen's four modules: the read-only
 * staff page (nureen.application), only for staff StaffApplicationAccess
 * lets in -- the same rule the page itself enforces.
 */
trait LinksToStaffView
{
    public function staffViewUrl(Application $application, User $viewer): ?string
    {
        return StaffApplicationAccess::allows($viewer, $application)
            ? route('nureen.application', $application)
            : null;
    }
}
