<?php

namespace App\Modules\Core\Services;

/**
 * The dashboard for an approving role with no screen of its own.
 *
 * The Dean of PGR, the Academic Executive, the Registry, the Faculty office
 * and the Senior Executive all land here. None of them needs a bespoke
 * service: what each owns is a set of stages, and ApproverDashboard already
 * derives everything from that.
 *
 * This exists as a named class rather than the controller instantiating
 * ApproverDashboard directly because ApproverDashboard is abstract on
 * purpose -- it is the shared half of a dashboard, not a whole one, and
 * "which concrete screen am I looking at" should be answerable from the
 * class name in a stack trace.
 *
 * WHY THESE ROLES DO NOT GET THEIR OWN. A Chair files examiner panels and a
 * Supervisor is accountable for named students, so each has a panel Core can
 * build for nobody else. The Dean and the Academic Executive have no
 * equivalent: the AE's examiner conflicts and pending evaluations live in
 * `app/Modules/Hani`, which Core cannot read, and they are already one click
 * away because that module declares them through ProvidesLinks -- they appear
 * in Quick actions on this very screen. Inventing a service to duplicate them
 * in Core would be the wrong arrow.
 */
class GeneralApproverDashboard extends ApproverDashboard
{
    //
}
