<?php

namespace Tests\Feature\Norhanis\Concerns;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Norhanis\Models\PublicationDetail;

/**
 * An already-approved publication, for a claim to be linked to.
 *
 * Written straight to the table rather than walked through the engine: the
 * claim tests are about the claim, and four publication decisions would add
 * their own emails to every Notification::assertCount() that follows.
 */
trait HasApprovedPublication
{
    /** @param  array<string, mixed>  $detail  overrides for the publication_details row */
    protected function approvedPublication(User $student, string $status = Application::STATUS_APPROVED, array $detail = []): Application
    {
        $application = Application::create([
            'student_id' => $student->id,
            'module_type' => 'publication',
            'status' => $status,
            'submitted_at' => now()->subDays(20),
        ]);

        PublicationDetail::create($detail + [
            'application_id' => $application->id,
            'type_of_request' => 'publication_journal',
            'title_of_paper' => 'Reservoir Simulation with Graph Networks',
            'title_of_conference_journal' => 'Journal of Petroleum Science',
            'organizer_publisher' => 'Elsevier',
            'conference_journal_fee' => 2500,
            'currency_type' => 'MYR',
            'cost_centre' => 'cgs_pay_and_claims',
        ]);

        return $application;
    }
}
