<?php

namespace App\Modules\Haziq\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Haziq\Assistant\AssistantContext;
use App\Modules\Haziq\Assistant\GeminiClient;
use App\Modules\Haziq\Models\AssistantLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Student-only chatbot over this module's own GRA/GA/Stage Gate/Allowance
 * records -- confirmed explicitly 2026-11-06. See GeminiClient's own
 * docblock for why this calls its own API key rather than reusing
 * Chloe's existing assistant pipeline.
 */
class AssistantController extends Controller
{
    /** App-side safety cap, independent of whatever the API key's own free-tier limit turns out to be. */
    public const DAILY_CAP = 20;

    public function ask(Request $request, GeminiClient $client): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isStudent(), 403);

        $data = $request->validate([
            'question' => ['required', 'string', 'max:500'],
        ]);

        $askedToday = AssistantLog::where('student_id', $user->id)
            ->where('created_at', '>=', Carbon::today())
            ->count();

        if ($askedToday >= self::DAILY_CAP) {
            return response()->json([
                'answer' => "You've reached today's question limit (".self::DAILY_CAP.'). Please try again tomorrow, or contact CGS directly.',
            ], 429);
        }

        $context = AssistantContext::forStudent($user);
        $result = $client->ask($data['question'], $context);

        $answer = $result['outcome'] === 'ok'
            ? $result['answer']
            : "Sorry, I couldn't reach the assistant service right now. Please try again later or contact CGS.";

        AssistantLog::create([
            'student_id' => $user->id,
            'question' => $data['question'],
            'answer' => $answer,
            'outcome' => $result['outcome'],
            'error_code' => $result['error_code'],
        ]);

        return response()->json(['answer' => $answer]);
    }
}
