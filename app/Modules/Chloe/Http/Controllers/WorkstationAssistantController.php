<?php

namespace App\Modules\Chloe\Http\Controllers;

use App\Modules\Chloe\Assistant\AssistantDomains;
use App\Modules\Chloe\Assistant\Pipeline;
use App\Modules\Chloe\Assistant\SuggestedQuestions;
use App\Modules\Core\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * PART 6. Three read-only JSON endpoints -- the chat UI itself is the next
 * task; this is the backend it will call. Every response goes through
 * Assistant\Pipeline, which is also where the PART 7 log row is written,
 * so there is no code path here that answers without logging.
 */
class WorkstationAssistantController extends Controller
{
    /**
     * The domain this request is allowed to use. A request that names a domain must prove it: the widget is rendered with a token that binds (domain, user, page), and the
     * page must be one config('assistant.domain_pages') maps to that domain, and the role must be served there. A request that names no domain is the Workstation widget.
     */
    protected function domainOf(Request $request): string
    {
        $claimed = $request->input('domain', $request->query('domain'));

        if ($claimed === null || $claimed === '') {
            return AssistantDomains::WORKSTATION; // no domain named: the Workstation widget (the pipeline answers an unsupported role with its own message)
        }

        $page = $request->input('page', $request->query('page'));
        $token = $request->input('token', $request->query('token'));

        if (! AssistantDomains::accepts($request->user(), (string) $claimed, is_string($page) ? $page : null, is_string($token) ? $token : null)) {
            abort(response()->json(['error' => 'domain_mismatch', 'message' => 'That assistant is not available from this page.'], 403));
        }

        return (string) $claimed;
    }

    public function suggestions(Request $request)
    {
        $domain = $this->domainOf($request);
        $role = AssistantDomains::effectiveRole($request->user(), $domain);

        if ($role === null) {
            return response()->json(['suggestions' => []]);
        }

        $suggestions = collect(SuggestedQuestions::forDomain($role, $domain))
            ->map(fn (array $s, string $id) => ['id' => $id, 'text' => $s['text']])
            ->values();

        return response()->json(['suggestions' => $suggestions]);
    }

    public function suggestion(Request $request, Pipeline $pipeline)
    {
        $data = $request->validate([
            'id' => ['required', 'string', 'max:60'],
            // Part 2.5: a "Did you mean" chip click carries the router's own (already-validated) params along, e.g. a period for release_forecast -- the curated suggestion ids never send any.
            'params' => ['nullable', 'array'],
            'domain' => ['nullable', 'string', 'max:20'], 'page' => ['nullable', 'string', 'max:120'], 'token' => ['nullable', 'string', 'max:100'],
        ]);

        $pipeline->setDomain($this->domainOf($request));

        return response()->json(Arr::except($pipeline->askSuggestion($request->user(), $data['id'], $data['params'] ?? []), ['meta']));
    }

    public function ask(Request $request, Pipeline $pipeline)
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:'.config('assistant.max_question_length', 300)],
            'recent' => ['nullable', 'array', 'max:5'],
            'recent.*' => ['string', 'max:'.config('assistant.max_question_length', 300)],
            'domain' => ['nullable', 'string', 'max:20'], 'page' => ['nullable', 'string', 'max:120'], 'token' => ['nullable', 'string', 'max:100'],
        ]);

        $pipeline->setDomain($this->domainOf($request));

        // 'meta' (catalogue id, confidence, model) is for the console commands and tests only, never sent to the browser.
        return response()->json(Arr::except($pipeline->ask($request->user(), $data['message'], $data['recent'] ?? []), ['meta']));
    }
}
