<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;

/**
 * OPTIONAL companion to WorkflowModule: where a member of staff reads one of
 * this module's applications, if $viewer may.
 *
 * Core lists applications from every module (the Students page) but has no
 * staff detail page of its own -- each module has its own (or none), with
 * its own access rule. Implement this on the workflow and those lists link
 * to it; return null when $viewer may not open it. Checked with instanceof;
 * a module without it is listed without a link.
 */
interface ProvidesStaffView
{
    public function staffViewUrl(Application $application, User $viewer): ?string;
}
