<?php

namespace Tests\Feature;

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
}
