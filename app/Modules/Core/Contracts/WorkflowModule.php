<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Stage;

/**
 * Implement this once per application type you own, put it in your own
 * module folder, and the shared Core gives you for free:
 *
 *   - routing between approvers (no more hand-written next-stage if/else)
 *   - the approval queue query for every stage
 *   - authorisation (only the stage's role can act on it)
 *   - the student-facing progress stepper
 *   - the "Track My Applications" row
 *   - the email that fires on every decision
 *   - the sidebar links, for students and approvers alike
 *
 * Register it in your module's ServiceProvider. Nothing in Core needs editing.
 */
interface WorkflowModule
{
    /**
     * Stable identifier stored in applications.module_type.
     * Lowercase snake_case, unique across the whole team. e.g. 'travel'.
     */
    public function key(): string;

    /** Human name used in headings, emails and the sidebar. e.g. 'Travel'. */
    public function label(): string;

    /**
     * The approval chain, in order.
     *
     * This is a function of the application, not a constant, which is how
     * conditional routing is expressed: Norhanis' travel chain stops at the
     * Chair for local travel but continues to CGS and the Dean when
     * `is_international` is set. Return the chain for THIS application and
     * the engine handles the rest.
     *
     * @return array<int, Stage>
     */
    public function stages(Application $application): array;

    /**
     * One-line description shown on the student's tracking page,
     * e.g. "Data collection — Kuala Lumpur". Keep it short.
     */
    public function summary(Application $application): string;

    /**
     * Route name a student visits to start a new application,
     * e.g. 'travel.create'. Return null if students cannot self-submit.
     */
    public function createRoute(): ?string;

    /**
     * Route name for a given stage's approval queue, e.g. 'travel.queue'.
     * The engine passes ?stage=<key>, so one route serves every stage.
     */
    public function queueRoute(): string;
}
