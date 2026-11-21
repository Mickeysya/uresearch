<?php

namespace App\Modules\Nureen\Support\Demo;

use App\Modules\Core\Models\User;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends a real request through the HTTP kernel as a given user -- the same
 * routes, middleware (`auth`, `role:`), validation, controllers, engine,
 * notifications and PDFs a browser would reach. This is how
 * `nureen:demo-fresh` moves its applications forward without writing a
 * status itself.
 *
 * Two things differ from a browser, both only inside the artisan process
 * that runs the command:
 *  - the CSRF check is skipped (there is no browser session to hold a
 *    token; the command is already trusted code on the server), and
 *  - sessions use the in-memory 'array' driver, so no `sessions` rows are
 *    left behind.
 *
 * A step that does not succeed -- an error page, a validation error, or a
 * controller's own `->with('error', ...)` -- throws DemoStepFailed with
 * what the user would have seen, so a half-built demo is never reported
 * as done.
 */
class DemoHttp
{
    protected bool $prepared = false;

    public function __construct(protected HttpKernel $kernel) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $files  field => UploadedFile|array<UploadedFile>
     */
    public function post(User $as, string $url, array $data = [], array $files = []): Response
    {
        return $this->send($as, 'POST', $url, $data, $files);
    }

    public function get(?User $as, string $url): Response
    {
        return $this->send($as, 'GET', $url);
    }

    public function send(?User $as, string $method, string $url, array $data = [], array $files = []): Response
    {
        $this->prepare();

        $request = Request::create($url, $method, $data, [], $files, [
            'HTTP_ACCEPT' => 'text/html',
            // Where back() returns to; never followed.
            'HTTP_REFERER' => url('/dashboard'),
        ]);

        Auth::forgetGuards();

        if ($as) {
            Auth::guard('web')->setUser($as);
        }

        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);

        $this->assertSucceeded($as, $method, $url, $request, $response);

        return $response;
    }

    protected function prepare(): void
    {
        if ($this->prepared) {
            return;
        }

        config(['session.driver' => 'array']);

        app()->instance(ValidateCsrfToken::class, new class
        {
            public function handle($request, \Closure $next)
            {
                return $next($request);
            }
        });

        $this->prepared = true;
    }

    protected function assertSucceeded(?User $as, string $method, string $url, Request $request, Response $response): void
    {
        $session = $request->hasSession() ? $request->session() : null;
        $errors = $session?->get('errors')?->all() ?? [];
        $flashError = $session?->get('error');
        $status = $response->getStatusCode();

        if ($status < 400 && $errors === [] && ! $flashError) {
            return;
        }

        $who = $as ? "{$as->email} ({$as->role})" : 'a visitor';
        $path = parse_url($url, PHP_URL_PATH);
        $why = match (true) {
            $errors !== [] => implode(' ', $errors),
            (bool) $flashError => (string) $flashError,
            isset($response->exception) => $response->exception->getMessage(),
            default => Str::limit(strip_tags((string) $response->getContent()), 200),
        };

        throw new DemoStepFailed("{$method} {$path} as {$who} failed (HTTP {$status}): {$why}");
    }
}
