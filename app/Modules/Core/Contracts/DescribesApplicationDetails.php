<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use Carbon\CarbonInterface;

/**
 * OPTIONAL companion to WorkflowModule, for the student's application
 * details page ("View details"). Without it the page still shows the
 * header, stepper, timeline (submission and every decision), the student's
 * uploads and the help box; the "Your application" section is simply left
 * out.
 *
 * Its own interface, not methods added to DescribesApplicationProgress: a
 * method added to an existing interface would break every implementer.
 * Implement it with `use Defaults\DescribesApplicationDetailsDefaults;`
 * and override only what you need -- every method has a do-nothing
 * default there, so a method added here later never breaks you either.
 *
 * NEVER return internal-only data: CGS notes, check results, anything the
 * student should not see. Everything here is shown to the student.
 */
interface DescribesApplicationDetails
{
    /**
     * The submitted details, label => value, in display order.
     *
     * @return array<string, string>
     */
    public function details(Application $application): array;

    /**
     * Events Core cannot see in approval_history -- a reviewer reminded, a
     * letter issued or revoked. Never name staff.
     *
     * @return array<int, array{date: CarbonInterface, title: string, detail?: string, quote?: string, tone?: string}>
     *         tone: info | good | warn | critical
     */
    public function timelineEvents(Application $application): array;

    /**
     * The document this module issues (a letter), or null if it issues
     * none. Before it exists, return ['label' => ...] only and the page
     * shows "Your letter will appear here once it is issued."
     *
     * @return array{label: string, reference?: string, document?: ApplicationDocument, verify_url?: string, revoked_at?: CarbonInterface, revoked_reason?: string}|null
     */
    public function issuedDocument(Application $application): ?array;

    /** The working days a stage is promised (a service charter), or null when none. */
    public function stageTargetDays(Application $application, string $stageKey): ?int;
}
