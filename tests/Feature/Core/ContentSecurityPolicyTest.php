<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Content-Security-Policy, and the thing about it that breaks quietly.
 *
 * An inline <script> written without @cspNonce is not an error the server
 * reports -- the page still returns 200, the browser silently refuses to run
 * that block, and whatever it powered just stops working. That is exactly the
 * kind of regression nobody notices until a demo, so it is asserted here.
 */
class ContentSecurityPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function signIn(string $role = Role::STUDENT): User
    {
        $user = User::create([
            'name' => 'Test Person',
            'email' => $role.'@test.my',
            'password' => 'password',
            'role' => $role,
        ]);

        return tap($user, fn ($u) => $this->actingAs($u));
    }

    public function test_the_policy_is_sent_on_html_responses(): void
    {
        $this->signIn();

        $policy = $this->get('/dashboard')->assertOk()->headers->get('Content-Security-Policy');

        $this->assertNotNull($policy, 'Every HTML response must carry a CSP.');
        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
    }

    /**
     * The directive the browser warning was about. Nothing in the portal
     * evaluates strings as code, and neither charting library does either --
     * so if this assertion ever fails, something was added that should have
     * been replaced instead.
     */
    public function test_the_policy_never_allows_string_evaluation(): void
    {
        $this->signIn();

        $policy = $this->get('/dashboard')->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString('unsafe-eval', $policy);
    }

    /** Inline script is allowed by nonce only, never blanket 'unsafe-inline'. */
    public function test_script_src_does_not_allow_blanket_inline(): void
    {
        $this->signIn();

        $policy = $this->get('/dashboard')->headers->get('Content-Security-Policy');

        preg_match('/script-src ([^;]+)/', $policy, $matches);

        $this->assertNotEmpty($matches, 'The policy must declare a script-src.');
        $this->assertStringNotContainsString("'unsafe-inline'", $matches[1]);
        $this->assertMatchesRegularExpression("/'nonce-[A-Za-z0-9]+'/", $matches[1]);
    }

    /**
     * A strict CSP names no script origin at all. An allow-listed CDN is a
     * standing permission to run anything that CDN serves, and the two
     * charting libraries are vendored into public/js precisely so the
     * allow-list can stay empty.
     */
    public function test_the_policy_allow_lists_no_external_script_host(): void
    {
        $this->signIn();

        $policy = $this->get('/dashboard')->headers->get('Content-Security-Policy');

        preg_match('/script-src ([^;]+)/', $policy, $matches);

        $this->assertStringNotContainsString('http', $matches[1],
            'script-src should name no origin — vendor the library into public/js instead.');
    }

    /**
     * And the charts must actually be served locally, or the page renders
     * with no Chart global and every dashboard panel silently draws nothing.
     */
    public function test_the_charting_libraries_are_served_from_this_app(): void
    {
        foreach (['chart.umd.min.js', 'chartjs-plugin-datalabels.min.js'] as $file) {
            $this->assertFileExists(public_path('js/'.$file));
        }

        // A live source-map directive would have the browser request a map
        // that connect-src refuses — the warning this vendoring ended. The
        // `//#` form is what the browser acts on; prose mentioning the word
        // is fine, and the file carries a note explaining the removal.
        $this->assertStringNotContainsString(
            '//# sourceMappingURL=',
            file_get_contents(public_path('js/chart.umd.min.js')),
            'Strip the source-map directive, or ship the .map file beside it.'
        );

        $this->signIn();

        $html = $this->get('/dashboard')->getContent();

        $this->assertStringContainsString('js/chart.umd.min.js', $html);
        $this->assertStringNotContainsString('cdn.jsdelivr.net', $html);
    }

    public function test_the_nonce_is_different_on_every_response(): void
    {
        $this->signIn();

        $first = $this->get('/dashboard')->headers->get('Content-Security-Policy');
        $second = $this->get('/dashboard')->headers->get('Content-Security-Policy');

        $this->assertNotSame($first, $second, 'A reused nonce is no better than unsafe-inline.');
    }

    /**
     * The regression that fails silently: every inline block on every screen
     * must carry the nonce this response actually sent, and no element may
     * use an inline event handler, which a nonce cannot allow-list.
     */
    public function test_every_inline_script_on_every_screen_carries_the_nonce(): void
    {
        $screens = [
            Role::STUDENT => ['/dashboard', '/applications', '/notifications'],
            Role::NON_EXEC_CGS => ['/dashboard'],
            Role::ADMIN => ['/dashboard', '/admin/audit-logs'],
            Role::SUPERVISOR => ['/dashboard'],
        ];

        foreach ($screens as $role => $paths) {
            $this->signIn($role);

            foreach ($paths as $path) {
                $response = $this->get($path)->assertOk();
                $html = $response->getContent();

                preg_match('/nonce-([A-Za-z0-9]+)/', $response->headers->get('Content-Security-Policy'), $m);
                $nonce = $m[1];

                preg_match_all('/<script\b(?![^>]*\bsrc=)[^>]*>/', $html, $tags);

                foreach ($tags[0] as $tag) {
                    $this->assertStringContainsString(
                        $nonce,
                        $tag,
                        "An inline <script> on {$path} has no CSP nonce, so the browser will "
                        ."refuse to run it. Write it as `<script @cspNonce>`. Tag: {$tag}"
                    );
                }

                $this->assertDoesNotMatchRegularExpression(
                    '/\son(click|change|submit|input|load)\s*=/i',
                    $html,
                    "An inline event handler on {$path} cannot be allow-listed by a nonce. "
                    .'Bind it with addEventListener inside a nonced <script> instead.'
                );
            }
        }
    }

    /**
     * The same two rules, read off the Blade source instead of a rendered
     * page.
     *
     * The test above can only check screens it knows how to reach, and it
     * knows four. Jason's appointment-letter views were never among them, so
     * both of these shipped unnoticed: an un-nonced inline script behind the
     * "Add another examiner" button, and Chart.js pulled from jsdelivr on the
     * queue. Neither is an error anywhere -- the page returns 200 and the
     * browser quietly refuses to run the script -- so the only reliable check
     * is over every view in the repo, whether or not a test renders it.
     */
    public function test_no_blade_view_writes_a_script_the_policy_would_refuse(): void
    {
        $views = glob(base_path('app/Modules/*/Resources/views/**/*.blade.php'), GLOB_BRACE)
            + glob(base_path('app/Modules/*/Resources/views/*.blade.php'));

        $this->assertNotEmpty($views, 'Found no Blade views to scan — the glob is wrong.');

        foreach ($views as $view) {
            $source = file_get_contents($view);
            $name = str_replace(base_path().'/', '', $view);

            // Inline blocks: everything without a src= needs the nonce.
            preg_match_all('/<script\b(?![^>]*\bsrc=)[^>]*>/', $source, $inline);

            foreach ($inline[0] as $tag) {
                $this->assertStringContainsString(
                    '@cspNonce',
                    $tag,
                    "{$name} has an inline <script> without @cspNonce. script-src is "
                    ."'self' plus a nonce, with no unsafe-inline, so the browser will "
                    ."refuse to run it and whatever it powers will silently do nothing."
                );
            }

            // Loaded blocks: script-src names no external origin at all, so a
            // CDN tag is refused. Self-host it in public/js instead.
            preg_match_all('/<script\b[^>]*\bsrc=["\']([^"\']+)["\']/', $source, $loaded);

            foreach ($loaded[1] as $src) {
                $this->assertDoesNotMatchRegularExpression(
                    '#^(https?:)?//#',
                    $src,
                    "{$name} loads a script from an external origin ({$src}). script-src "
                    .'allow-lists no host, so it is blocked — serve it from public/js, the '
                    .'way core::dashboard.partials.chartjs serves Chart.js.'
                );
            }

            // A nonce cannot cover a handler attribute, so these are refused
            // however the page is served. Hani's examiner pool shipped five:
            // the two filters did not auto-submit and the modal never opened.
            $this->assertDoesNotMatchRegularExpression(
                '/\son(click|change|submit|input|load)\s*=/i',
                $source,
                "{$name} binds an event with an inline handler attribute. A nonce cannot "
                .'allow-list one, so it never runs — bind it with addEventListener inside '
                .'a nonced <script> instead.'
            );
        }
    }
}
