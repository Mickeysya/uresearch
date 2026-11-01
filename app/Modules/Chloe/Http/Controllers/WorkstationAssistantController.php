<?php

namespace App\Modules\Chloe\Http\Controllers;

use App\Modules\Chloe\Assistant\AssistantRoles;
use App\Modules\Chloe\Assistant\CandidacyAssistant;
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
    public function suggestions(Request $request)
    {
        $role = AssistantRoles::roleFor($request->user());

        if ($role === null) {
            return response()->json(['suggestions' => []]);
        }

        // the candidacy pages ask for their own six questions (answered by catalogue id, no model call); everywhere else the Workstation ones
        $suggestions = $request->query('domain') === 'candidacy'
            ? collect(CandidacyAssistant::suggestions($role))->map(fn (string $text, string $id) => ['id' => $id, 'text' => $text])->values()
            : collect(SuggestedQuestions::forRole($role))->map(fn (array $s, string $id) => ['id' => $id, 'text' => $s['text']])->values();

        return response()->json(['suggestions' => $suggestions]);
    }

    public function suggestion(Request $request, Pipeline $pipeline)
    {
        $data = $request->validate([
            'id' => ['required', 'string', 'max:60'],
            // Part 2.5: a "Did you mean" chip click carries the router's
            // own (already-validated) params along, e.g. a period for
            // release_forecast -- the 12 curated suggestion ids never
            // send any.
            'params' => ['nullable', 'array'],
        ]);

        return response()->json(Arr::except($pipeline->askSuggestion($request->user(), $data['id'], $data['params'] ?? []), ['meta']));
    }

    public function ask(Request $request, Pipeline $pipeline)
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:'.config('assistant.max_question_length', 300)],
            'recent' => ['nullable', 'array', 'max:5'],
            'recent.*' => ['string', 'max:'.config('assistant.max_question_length', 300)],
            'domain' => ['nullable', 'in:workstation,candidacy'],
        ]);

        $pipeline->setDomain($data['domain'] ?? null);

        // 'meta' (catalogue id, confidence, model) is for the console commands and tests only, never sent to the browser.
        return response()->json(Arr::except($pipeline->ask($request->user(), $data['message'], $data['recent'] ?? []), ['meta']));
    }
}
