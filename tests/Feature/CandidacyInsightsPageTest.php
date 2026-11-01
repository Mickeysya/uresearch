<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsCandidacyWorld;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/** Candidacy Insights: role access, the embedded data, the live endpoints and the menu entry. */
class CandidacyInsightsPageTest extends TestCase
{
    use BuildsCandidacyWorld;
    use MakesUsers;
    use RefreshDatabase;

    public function test_only_the_cgs_roles_may_open_the_page_and_its_live_endpoints(): void
    {
        $registry = User::create(['name' => 'R', 'email' => 'r@x.my', 'password' => 'password', 'role' => Role::REGISTRY]);

        foreach (['candidacy.cgs.insights', 'candidacy.cgs.insights.live.version', 'candidacy.cgs.insights.live.stats'] as $route) {
            foreach ([$this->student, $this->lecturer, $this->chairUser, $this->deanUser, $registry] as $u) {
                $this->actingAs($u)->get(route($route))->assertForbidden();
            }

            foreach (Role::cgsTeam() as $i => $role) {
                $u = User::where('role', $role)->first() ?? User::create(['name' => "C{$i}", 'email' => "c{$i}@x.my", 'password' => 'password', 'role' => $role]);
                $this->actingAs($u)->get(route($route))->assertOk();
            }
        }

        auth()->logout();
        $this->get(route('candidacy.cgs.insights'))->assertRedirect();
    }

    public function test_the_page_embeds_the_statistics_rows_and_the_endpoints_return_no_store_json(): void
    {
        $this->candidacy->update(['candidacy_expiry_date' => now()->addMonths(2)->toDateString()]);

        $page = $this->actingAs($this->cgsUser)->get(route('candidacy.cgs.insights'))->assertOk()->assertSee('Candidacy Insights')->assertSee('wci-data', false)->assertSee($this->student->matric_no, false);
        $this->assertStringContainsString('no-store', $page->headers->get('Cache-Control'));

        $stats = $this->actingAs($this->cgsUser)->get(route('candidacy.cgs.insights.live.stats'))->assertOk();
        $this->assertStringContainsString('no-store', $stats->headers->get('Cache-Control'));
        $this->assertSame($stats->json('version'), $this->actingAs($this->cgsUser)->get(route('candidacy.cgs.insights.live.version'))->json('version'));
        $this->assertContains($this->student->matric_no, array_column($stats->json('data.rows'), 'studentId'));
    }

    public function test_the_version_changes_when_a_number_changes_and_not_otherwise(): void
    {
        $this->candidacy->update(['candidacy_expiry_date' => now()->addMonths(2)->toDateString()]);
        $a = \App\Modules\Chloe\Support\CandidacyStatistics::version();
        $this->assertSame($a, \App\Modules\Chloe\Support\CandidacyStatistics::version());

        $this->candidacy->update(['candidacy_expiry_date' => now()->addMonths(5)->toDateString()]);
        $this->assertNotSame($a, \App\Modules\Chloe\Support\CandidacyStatistics::version());
    }

    public function test_the_workflow_links_offer_insights_to_cgs_only(): void
    {
        $links = fn ($u) => collect(app(\App\Modules\Chloe\Workflows\CandidacyAppealWorkflow::class)->links($u))->pluck('route')->all();

        $this->assertContains('candidacy.cgs.insights', $links($this->cgsUser));
        $this->assertNotContains('candidacy.cgs.insights', $links($this->student));
        $this->assertNotContains('candidacy.cgs.insights', $links($this->lecturer));
    }

    public function test_the_operations_header_has_an_insights_button(): void
    {
        $this->actingAs($this->cgsUser)->get(route('candidacy.cgs.operations'))->assertOk()->assertSee(route('candidacy.cgs.insights'), false);
    }
}
