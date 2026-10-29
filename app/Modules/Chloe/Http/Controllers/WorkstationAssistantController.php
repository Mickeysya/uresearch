<?php

namespace App\Modules\Chloe\Http\Controllers;

use App\Modules\Chloe\Assistant\Pipeline;
use App\Modules\Chloe\Assistant\SuggestedQuestions;
use App\Modules\Core\Http\Controllers\Controller;
use Illuminate\Http\Request;

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
        $role = Pipeline::normalizeRole($request->user()->role);

        if ($role === null) {
            return response()->json(['suggestions' => []]);
        }

        $suggestions = collect(SuggestedQuestions::forRole($role))
            ->map(fn (array $s, string $id) => ['id' => $id, 'text' => $s['text']])
            ->values();

        return response()->json(['suggestions' => $suggestions]);
    }

    public function suggestion(Request $request, Pipeline $pipeline)
    {
        $data = $request->validate(['id' => ['required', 'string', 'max:60']]);

        return response()->json($pipeline->askSuggestion($request->user(), $data['id']));
    }

    public function ask(Request $request, Pipeline $pipeline)
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:'.config('assistant.max_question_length', 300)],
            'recent' => ['nullable', 'array', 'max:5'],
            'recent.*' => ['string', 'max:'.config('assistant.max_question_length', 300)],
        ]);

        return response()->json($pipeline->ask($request->user(), $data['message'], $data['recent'] ?? []));
    }
}
