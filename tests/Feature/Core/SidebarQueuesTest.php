<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The sidebar's queue list, grouped by module.
 *
 * A module contributes one queue per stage it owns for a role, and the nav
 * printed the MODULE's label for each. Hani's re-viva has four consecutive
 * Academic Executive stages, so that sidebar read "Re-viva Monitoring" four
 * times with nothing to tell them apart — four links nobody could choose
 * between, and a bug no test could see because every one of them resolved.
 */
class SidebarQueuesTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    public function test_a_module_owning_several_stages_is_one_group_not_repeated_links(): void
    {
        $registry = app(ModuleRegistry::class);

        // The condition this is really about: more than one stage of one
        // module on a single role. If re-viva is ever restaged, this test
        // should start failing rather than quietly proving nothing.
        $reViva = collect($registry->queuesForRole(Role::ACADEMIC_EXEC))
            ->filter(fn ($q) => $q['module']->key() === 're_viva');

        $this->assertGreaterThan(1, $reViva->count(), 'Re-viva no longer has several AE stages.');

        $html = $this->actingAs($this->academicExec())
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        // The module's name appears once, as the group; the stages appear by
        // their own names underneath it.
        $sidebar = substr(
            $html,
            strpos($html, 'id="app-sidebar"'),
            strpos($html, 'sidebar-footer') - strpos($html, 'id="app-sidebar"')
        );

        $this->assertSame(
            1,
            substr_count($sidebar, '>Re-viva Monitoring<'),
            'The module name should label the group once, not once per stage.'
        );

        // The label alone, not '>label<': Blade renders the anchor's text on
        // its own indented line, so the tags are not adjacent to it.
        foreach ($reViva as $queue) {
            $this->assertStringContainsString(
                $queue['stage']->label,
                $sidebar,
                "The '{$queue['stage']->label}' stage needs its own named link."
            );
        }
    }

    /** A module with one stage for this role is untouched: a plain link. */
    public function test_a_single_stage_module_stays_a_plain_link(): void
    {
        $sidebar = $this->actingAs($this->chair())
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('>Travel<', $sidebar);
        // No group wrapper, because nothing the Chair owns repeats.
        $this->assertStringNotContainsString('nav-tree-nested', $sidebar);
    }

    /**
     * Flat-or-collapsed counts MODULES, not stages: the Academic Executive
     * owns six stages across three modules, which reads as three items.
     */
    public function test_the_flat_or_tree_decision_counts_modules(): void
    {
        $modules = collect(app(ModuleRegistry::class)->queuesForRole(Role::ACADEMIC_EXEC))
            ->unique(fn ($q) => $q['module']->key());

        $this->assertLessThan(4, $modules->count(), 'The AE owns fewer than four modules.');

        $this->actingAs($this->academicExec())
            ->get(route('dashboard'))
            ->assertOk()
            // Fewer than four modules, so the flat branch, not the outer tree.
            ->assertSee('nav-flat-queues', false);
    }
}
