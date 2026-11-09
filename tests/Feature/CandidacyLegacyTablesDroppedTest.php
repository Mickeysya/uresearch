<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyAppealPublication;
use App\Modules\Core\Models\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsCandidacyWorld;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/** The four leftover tables are gone (docs/proposals/chloe-legacy-tables.md) and nothing that still runs depended on them. */
class CandidacyLegacyTablesDroppedTest extends TestCase
{
    use BuildsCandidacyWorld;
    use MakesUsers;
    use RefreshDatabase;

    public function test_the_four_tables_and_the_legacy_publication_column_are_gone(): void
    {
        foreach (['study_candidacy_reminders', 'candidacy_dismissals', 'candidacy_appeal_details', 'workstation_pending_selections'] as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }

        $this->assertFalse(Schema::hasColumn('candidacy_appeal_publications', 'candidacy_appeal_detail_id'));
        $this->assertTrue(Schema::hasColumn('candidacy_appeal_publications', 'description'), 'the free-text of a backfilled legacy row stays');
    }

    public function test_the_three_commands_the_shared_scheduler_still_calls_are_harmless_no_ops(): void
    {
        foreach (['candidacy:remind', 'candidacy:generate-dismissals', 'workstation:release-expired-selections'] as $command) {
            $this->artisan($command)->assertSuccessful();
        }
    }

    public function test_a_legacy_engine_appeal_still_shows_its_details_from_candidacy_appeals(): void
    {
        $application = Application::create(['student_id' => $this->student->id, 'module_type' => 'candidacy_appeal', 'status' => Application::STATUS_APPROVED]);
        $appeal = CandidacyAppeal::create([
            'student_id' => $this->student->id, 'study_candidacy_id' => $this->candidacy->id, 'appeal_no' => 'SCA-99999999-01', 'sequence_no' => 1,
            'status' => CandidacyAppeal::STATUS_APPROVED, 'final_outcome' => 'approved', 'round' => 1, 'requested_months' => 6, 'approved_months' => 6,
            'reason' => 'Needs more time for the experiment.', 'phase' => 'simulation', 'percent_complete' => 75, 'rcs_status' => 'pending',
            'new_expiry_date' => '2027-07-31', 'legacy_application_id' => $application->id,
            'department_id' => $this->profile($this->lecturer)->department_id, 'supervisor_profile_id' => $this->profile($this->lecturer)->id,
        ]);
        CandidacyAppealPublication::create(['candidacy_appeal_id' => $appeal->id, 'description' => 'A paper on drilling fluids, 2026.']);

        $html = view('chloe::candidacy.appeal._detail', ['application' => $application->load('history')])->render();

        $this->assertStringContainsString('Needs more time for the experiment.', $html);
        $this->assertStringContainsString('Simulation', $html);
        $this->assertStringContainsString('75% complete', $html);
        $this->assertStringContainsString('6 month(s)', $html);
        $this->assertStringContainsString('A paper on drilling fluids, 2026.', $html);
        $this->assertStringContainsString('31 Jul 2027', $html);
        $this->assertSame('Extension request: 6 month(s)', app(\App\Modules\Chloe\Workflows\CandidacyAppealWorkflow::class)->summary($application));
    }

    public function test_an_old_confirmation_link_lands_on_the_invalid_page_not_an_error(): void
    {
        $this->get(route('workstation.confirm', 'a-token-from-an-old-email'))->assertOk();
    }
}
