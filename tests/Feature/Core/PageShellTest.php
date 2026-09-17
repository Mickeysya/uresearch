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
