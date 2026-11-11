<?php

namespace Tests\Feature\Hani\Concerns;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Decide an examiner nomination as the screens now do (UAT 11 Nov 2026):
 * at the department stage (academic_exec) through DAC Compilation, with a
 * DAC meeting date; at every other stage through the decide route.
 */
trait DecidesNominations
{
    protected function decideNomination(User $actor, Application $application, array $data): TestResponse
    {
        $application = $application->fresh();

        if ($application->status === Application::STATUS_PENDING && $application->current_stage === 'academic_exec') {
            return ($data['decision'] ?? 'approve') === 'approve'
                ? $this->actingAs($actor)->post(route('examiner-nomination.dac.endorse'), [
                    'ids' => [$application->id], 'meeting_date' => now()->toDateString(), 'reference' => 'DAC/TEST/1',
                ])
                : $this->actingAs($actor)->post(route('examiner-nomination.dac.not-endorsed'), [
                    'ids' => [$application->id], 'reason' => $data['remarks'] ?? 'Not endorsed.',
                ]);
        }

        return $this->actingAs($actor)->post(route('examiner-nomination.decide', $application), $data);
    }
}
