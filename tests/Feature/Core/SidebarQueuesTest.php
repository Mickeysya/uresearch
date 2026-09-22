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
     * CGS's loose tools collapse into one tree, grouped by the module that
     * declared them. Eight links from five modules -- the examiner pool, the
     * appointment list, a signature, the RPD masterlist -- were stacked flat
     * under one label, which is a list nobody reads to the bottom of. Same
     * rule as the queue tree: one link stays a link, two or more become a
     * sub-tree named after the module.
     */
    public function test_the_cgs_actions_list_is_a_tree_grouped_by_module(): void
    {
        $cgs = $this->cgs();

        $actions = collect(app(ModuleRegistry::class)->linksFor($cgs))
            ->reject(fn ($l) => in_array($l['route'], ['attendance.upload.form', 'attendance.at-risk'], true));

        $this->assertGreaterThan(3, $actions->count(), 'This is a tree because CGS owns more than a handful.');

        $html = $this->actingAs($cgs)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('class="nav-item nav-tree-trigger" title="Actions"', $html);
        $this->assertStringNotContainsString('nav-item-flat', $html, 'CGS should have no flat action links left.');

        // Just the Actions tree: it is the last thing the CGS nav renders,
        // so everything between its trigger and the divider is its own.
        $panel = substr($html, strpos($html, 'title="Actions"'));
        $panel = substr($panel, 0, strpos($panel, 'nav-divider'));

        $groups = $actions->groupBy(fn ($l) => $l['module']->key());

        $this->assertSame(
            $groups->filter(fn ($g) => $g->count() > 1)->count(),
            substr_count($panel, 'nav-tree-nested'),
            'One sub-tree per module with more than one tool, and none for the rest.'
        );

        foreach ($groups as $group) {
            if ($group->count() > 1) {
                $this->assertStringContainsString(
                    '>'.$group->first()['module']->label().'<',
                    $panel,
                    'A module with several tools is named once, as the group.'
                );
            }

            // Collapsed, not dropped.
            foreach ($group as $link) {
                $this->assertStringContainsString($link['label'], $panel, "'{$link['label']}' must still be reachable.");
            }
        }
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
