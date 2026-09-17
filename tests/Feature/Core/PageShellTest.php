<?php

namespace Tests\Feature\Core;

use Tests\TestCase;

/**
 * One page shape, kept that way.
 *
 * Every signed-in screen opens with `x-core::page-header` inside a
 * `.card-container-inline`, which is the page shell: full width, fluid at
 * clamp(880px, 92vw, 1320px). Before this, a screen declared its own heading
 * inside its own card and there were five different page widths, so two tabs
 * in a row looked like two different products.
 *
 * A repo-wide scan for the same reason `ContentSecurityPolicyTest` scans:
 * the screens a test happens to render are not all the screens there are, and
 * a page that quietly reverts to its own heading renders perfectly.
 */
class PageShellTest extends TestCase
{
    /**
     * Screens that are deliberately not on the shell.
     *
     * Login is the only one, and it is on `core::layouts.guest`: a sign-in
     * box is a centred card on an empty page, not a full-width admin screen.
     */
    protected const EXEMPT = [
        // core::layouts.guest: a sign-in box is a centred card on an empty
        // page, not a full-width admin screen.
        'app/Modules/Core/Resources/views/auth/login.blade.php',

        // The dashboards open with the welcome banner, which is a hero and
        // carries the person's name and role. A page header above it would
        // be the title said twice.
        'app/Modules/Core/Resources/views/dashboard/admin.blade.php',
        'app/Modules/Core/Resources/views/dashboard/chair.blade.php',
        'app/Modules/Core/Resources/views/dashboard/approver.blade.php',
        'app/Modules/Core/Resources/views/dashboard/cgs.blade.php',
        'app/Modules/Core/Resources/views/dashboard/student.blade.php',
        'app/Modules/Core/Resources/views/dashboard/supervisor.blade.php',
    ];

    /** @return array<int, string> */
    protected function signedInViews(): array
    {
        $views = [];

        foreach (glob(base_path('app/Modules/*/Resources/views/**/*.blade.php'), GLOB_BRACE) as $view) {
            $name = str_replace(base_path().'/', '', $view);

            if (in_array($name, self::EXEMPT, true)) {
                continue;
            }

            if (str_contains(file_get_contents($view), 'core::layouts.app')) {
                $views[$name] = $view;
            }
        }

        return $views;
    }

    public function test_every_signed_in_screen_opens_with_the_shared_page_header(): void
    {
        $offenders = [];

        foreach ($this->signedInViews() as $name => $view) {
            $source = file_get_contents($view);

            // Either it uses the component, or it is a queue and gets one
            // from core::partials.queue.
            $onShell = str_contains($source, 'x-core::page-header')
                || str_contains($source, 'core::partials.queue');

            if (! $onShell) {
                $offenders[] = $name;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These screens declare their own heading instead of opening with "
            ."<x-core::page-header ... /> inside a .card-container-inline:\n  "
            .implode("\n  ", $offenders)
        );
    }

    /**
     * The heading belongs above the card, not inside it. A card that still
     * opens with <h2> + .card-divider is the old shape, and on a stepper form
     * it also changes what the wizard does with the title.
     */
    public function test_no_screen_still_puts_its_heading_inside_the_card(): void
    {
        $offenders = [];

        foreach ($this->signedInViews() as $name => $view) {
            if (preg_match('/<h2>.*?<\/h2>\s*\n\s*<div class="card-divider">/s', file_get_contents($view))) {
                $offenders[] = $name;
            }
        }

        $this->assertSame([], $offenders, "Old in-card heading:\n  ".implode("\n  ", $offenders));
    }

    /**
     * The shell is one element in the layout, so a view cannot forget it.
     * Before this, a queue page wrapped itself in nothing and ran the full
     * width of the content area while every card page sat centred at
     * --page-max: two different pages side by side in the same app.
     */
    public function test_the_layout_wraps_every_screen_in_the_page_shell(): void
    {
        $this->assertStringContainsString(
            'class="page-shell"',
            file_get_contents(base_path('app/Modules/Core/Resources/views/layouts/app.blade.php')),
            'layouts/app.blade.php must wrap @yield(\'content\') in the page shell.'
        );
    }

    /**
     * A queue view that prints its own guidance above the include lands it
     * ABOVE the page title, and the screen reads as though it has no
     * heading. The partial takes an `intro` for exactly this.
     */
    public function test_no_queue_view_prints_anything_above_the_page_header(): void
    {
        $offenders = [];

        foreach (glob(base_path('app/Modules/*/Resources/views/**/queue.blade.php'), GLOB_BRACE) as $view) {
            $source = file_get_contents($view);

            if (! str_contains($source, 'core::partials.queue')) {
                continue;
            }

            $between = substr(
                $source,
                strpos($source, "@section('content')"),
                strpos($source, 'core::partials.queue') - strpos($source, "@section('content')")
            );

            // Blade comments and @php blocks emit nothing; markup does.
            $between = preg_replace('/\{\{--.*?--\}\}/s', '', $between);
            $between = preg_replace('/@php\b.*?@endphp/s', '', $between);

            if (preg_match('/<(p|div|h[1-6]|section|table|ul)\b/', $between)) {
                $offenders[] = str_replace(base_path().'/', '', $view);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These queue views render markup above the page header. Pass it to "
            ."the partial as 'intro' instead:\n  ".implode("\n  ", $offenders)
        );
    }

    /**
     * A dashboard takes the whole screen; a page of prose does not.
     *
     * The release is `.page-shell:has(> .sdash)` in layout.css, so what makes
     * a dashboard full width is having `.sdash` as its root. A dashboard that
     * loses that class silently goes back to being capped at 1320px, which is
     * a third of a monitor thrown away and nothing in the page to explain it.
     */
    public function test_every_dashboard_roots_in_sdash_so_it_gets_the_full_screen(): void
    {
        $offenders = [];

        // The approver dashboard is the one screen still on the pre-.sdash
        // markup; it is tracked in TODO.md and stays capped until rebuilt.
        $pending = ['approver'];

        foreach (glob(base_path('app/Modules/Core/Resources/views/dashboard/*.blade.php')) as $view) {
            $name = basename($view, '.blade.php');

            if (in_array($name, $pending, true)) {
                continue;
            }

            if (! preg_match('/<div class="sdash[ "]/', file_get_contents($view))) {
                $offenders[] = $name;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These dashboards do not root in .sdash, so layout.css cannot release "
            ."the width cap for them:\n  ".implode("\n  ", $offenders)
        );
    }

    /**
     * The Chair and Supervisor screens are locked to one viewport on a
     * desktop, so a panel has to have decided what it does when that squeezes
     * it. There are exactly two right answers and a panel declares one:
     *
     *   approver-scroll  a list of unknown length; it scrolls internally, and
     *                    the *-scroll name is what dashboard-states.css hides
     *                    the bar on -- a visible scrollbar inside a one-screen
     *                    layout is what that rule exists to stop.
     *   approver-fit     a known, fixed amount of content (a chart and a short
     *                    legend); it is sized to hold it and never scrolls.
     *
     * A panel declaring neither is one that will overflow the layout.
     */
    public function test_every_approver_panel_body_declares_how_it_handles_being_squeezed(): void
    {
        $offenders = [];

        foreach (glob(base_path('app/Modules/Core/Resources/views/dashboard/partials/{approver,supervisor,chair}-*.blade.php'), GLOB_BRACE) as $view) {
            $source = file_get_contents($view);
            $name = basename($view);

            // Only panels; the stat cards and the alert strip do not scroll.
            if (! str_contains($source, 'approver-panel')) {
                continue;
            }

            // Matched inside a class attribute, not anywhere in the file:
            // both names appear in these partials' own comments explaining
            // the choice, and a guard a comment can satisfy is not a guard.
            if (! preg_match('/class="[^"]*\bapprover-(scroll|fit)\b/', $source)) {
                $offenders[] = $name;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These panels declare neither approver-scroll nor approver-fit, so "
            ."they will overflow the one-screen layout:\n  "
            .implode("\n  ", $offenders)
        );
    }

    /**
     * layout.css is linked BEFORE the dashboard sheets, so a rule here that
     * modifies a `.sdash-*` component loses at equal specificity and does
     * nothing. It cost two rounds of "the panels are overlapping": the
     * container kept `display: grid` from dashboard-student.css because the
     * override was written with one class.
     *
     * So a `.sdash-*` modifier in layout.css must name both classes.
     */
    public function test_layout_css_overrides_dashboard_components_with_two_classes(): void
    {
        $offenders = [];

        foreach (file(public_path('css/layout.css')) as $i => $line) {
            $line = trim($line);

            // A selector line (ends in { or ,) that starts with a single
            // .sdash-something and never qualifies it.
            if (! preg_match('/^\.sdash-[a-z0-9-]+\s*[,{]/', $line)) {
                continue;
            }

            $offenders[] = 'layout.css:'.($i + 1).'  '.$line;
        }

        $this->assertSame(
            [],
            $offenders,
            "These rules modify a .sdash-* component from layout.css, which is "
            ."linked first and so loses at equal specificity. Write them as "
            ."`.sdash.sdash-name` or `.sdash-component.your-class`:\n  "
            .implode("\n  ", $offenders)
        );
    }

    /**
     * The widths that used to exist. A module writing its own page cap is how
     * the five widths happened in the first place; --page-max is the only one.
     */
    public function test_no_module_declares_a_page_width_of_its_own(): void
    {
        $offenders = [];

        foreach ($this->signedInViews() as $name => $view) {
            foreach (explode("\n", file_get_contents($view)) as $i => $line) {
                // A max-width in px on something that is clearly a page
                // wrapper. Component-level caps (a table cell, a modal) are
                // fine and are not what this is looking for.
                if (preg_match('/\.\w[\w-]*(page|container|wrap)\b[^{]*\{[^}]*max-width:\s*\d+px/i', $line)) {
                    $offenders[] = $name.':'.($i + 1).'  '.trim($line);
                }
            }
        }

        $this->assertSame([], $offenders, "Page width declared locally:\n  ".implode("\n  ", $offenders));
    }
}
