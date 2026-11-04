<?php

namespace App\Modules\Nureen\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\ApplicationDetails;
use App\Modules\Nureen\Support\StaffApplicationAccess;
use Illuminate\Http\Request;

/**
 * A read-only view of one of this module's applications for the staff who
 * have a part in it -- what the supervisor's outcome copy, the Senior
 * Director's information email and the revocation email link to. Built from
 * Core's ApplicationDetails (the same progress, timeline and details the
 * student sees), without the student's own prompts.
 */
class StaffApplicationController extends Controller
{
    public function show(Request $request, Application $application, ApplicationDetails $details)
    {
        abort_unless(StaffApplicationAccess::allows($request->user(), $application), 403);

        return view('nureen::staff_application.show', $details->for($application, []) + [
            'viewer' => $request->user(),
        ]);
    }
}
