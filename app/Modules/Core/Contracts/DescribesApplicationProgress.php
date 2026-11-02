<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Models\Application;

/**
 * OPTIONAL companion to WorkflowModule: what Track My Applications says
 * about one of this module's applications, beyond what Core can work out.
 *
 * Without it a card still renders fully: Core writes a generic sentence
 * ("Now with CGS Staff. No action needed from you.") from the stage chain
 * and approval_history, and offers View details. Implement it on your
 * workflow class (discovered with instanceof, no wiring) to add any of:
 *
 *   sentence               the footer line, in plain words
 *   actions                extra buttons, each ['label' => 'Download letter (PDF)',
 *                          'url' => ..., 'primary' => true]; View details is
 *                          always added by Core, so do not repeat it
 *   reference              a reference to show instead of the application's
 *                          own, e.g. an issued letter's real reference
 *   needs_student          true when the student genuinely has to act now
 *                          (counted under the "Needs you" filter); make the
 *                          action itself the primary button
 *   resubmission_expected  a REJECTED application counts as "Needs you"
 *                          only when this is true
 *   next                   the details page's "What happens next", one or
 *                          two sentences ending with whether the student
 *                          must act (otherwise Core writes it)
 *
 * Every key is optional; return [] for "nothing to add". Scope your own
 * reads to $application -- it is always the signed-in student's own.
 */
interface DescribesApplicationProgress
{
    /**
     * @return array{
     *     sentence?: string,
     *     actions?: array<int, array{label: string, url: string, primary?: bool}>,
     *     reference?: string,
     *     needs_student?: bool,
     *     resubmission_expected?: bool,
     *     next?: string,
     * }
     */
    public function describeProgress(Application $application): array;
}
