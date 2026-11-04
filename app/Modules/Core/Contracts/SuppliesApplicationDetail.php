<?php

namespace App\Modules\Core\Contracts;

/**
 * Optional companion to WorkflowModule, for a module whose application has
 * more to show than the one-line summary() carries.
 *
 * "Track My Application" (ApplicationTrackingController::show, one screen
 * for every module) used to be the summary line, the stepper, the documents
 * and the history trail -- never the actual form data the student filled
 * in, because Core cannot name a teammate's detail model any more than it
 * can name their attendance or calendar rows. A module that wants its own
 * fields on that same screen, instead of a second page duplicating the
 * stepper and history, implements this and hands over a view name.
 *
 * Discovered exactly like SuppliesCalendarEvents: implement it on a workflow
 * class already registered with ModuleRegistry. No new wiring, and every
 * module that does not implement it renders exactly as before.
 */
interface SuppliesApplicationDetail
{
    /**
     * A Blade view name, included with ['application' => $application] --
     * the same convention core::partials.queue already uses for
     * $detailView, so one partial can serve both screens (see
     * Chloe's candidacy.appeal._detail).
     */
    public function detailView(): string;
}
