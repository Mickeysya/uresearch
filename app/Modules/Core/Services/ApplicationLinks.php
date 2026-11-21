<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Contracts\ProvidesStaffView;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;

/**
 * "Open application": the one page $viewer's role may open for an
 * application, or null when there is none -- so a list shows no link
 * rather than a link that 403s.
 *
 * Grants nothing. Every branch names a page whose own rule already lets
 * this viewer in:
 *  1. the student who owns it -> applications.show (role:student, owner only);
 *  2. staff, on a module implementing ProvidesStaffView -> whatever the
 *     module says, which it returns only when its own page would allow it;
 *  3. staff holding the stage the application is waiting at -> that
 *     module's queue for the stage (the role is the queue's own gate);
 *  4. anyone else -> null.
 */
class ApplicationLinks
{
    public function __construct(protected ModuleRegistry $registry) {}

    public function openUrl(Application $application, User $viewer): ?string
    {
        if ($viewer->isStudent()) {
            return $application->student_id === $viewer->id ? route('applications.show', $application) : null;
        }

        if (! $this->registry->has($application->module_type)) {
            return null;
        }

        $module = $this->registry->get($application->module_type);

        if ($module instanceof ProvidesStaffView) {
            return $module->staffViewUrl($application, $viewer);
        }

        if ($application->status === Application::STATUS_PENDING
            && $application->currentStage()?->role === $viewer->role) {
            return route($module->queueRoute(), ['stage' => $application->current_stage]);
        }

        return null;
    }
}
