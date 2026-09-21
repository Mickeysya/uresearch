<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Models\User;

/**
 * Optional companion to WorkflowModule, for a module to say "this person
 * cannot do the thing they are about to try".
 *
 * The case this exists for: a Chair opens a Hardbound Submission, clicks
 * Approve, and is bounced to an upload page because they have no signature on
 * file. Nothing before that moment says so. That is a module's rule, but the
 * only screen that can warn about it in time is the dashboard, which is
 * Core's.
 *
 * Core must not name a module -- `HardboundSignature` lives in Jason's folder
 * and Core cannot import it -- so the module declares the alert and Core lays
 * it out, exactly as ProvidesLinks does for the sidebar. A module with no
 * blocking rule implements nothing and costs nothing.
 *
 * Only for things that BLOCK someone. A count, a reminder or a nice-to-know
 * belongs in a panel, not here: an alert that is usually on is one nobody
 * reads by the second week.
 */
interface ProvidesDashboardAlerts
{
    /**
     * Blocking conditions for this user right now, or [] when there is
     * nothing in their way.
     *
     * `tone` is one of the status tones: critical, warn, info.
     *
     * @return array<int, array{
     *     tone: string,
     *     title: string,
     *     body: string,
     *     action?: array{label: string, route: string, params?: array}
     * }>
     */
    public function alerts(User $user): array;
}
