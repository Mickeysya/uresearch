<?php

namespace App\Modules\Chloe\Console\Commands;

use App\Modules\Chloe\Assistant\AssistantModel;
use App\Modules\Chloe\Assistant\GeminiModel;
use Illuminate\Console\Command;

/**
 * PART 8 / Round 3 PART A.4. One trivial request, through whichever
 * provider is actually configured -- prints provider, model name,
 * OK/FAILED, HTTP status (where meaningful) and response time. PRIMARY
 * MODEL ONLY by default, so a result actually measures that model; pass
 * --allow-fallback to also try the fallback model if the primary fails.
 * Never prints GEMINI_API_KEY: the key never leaves config()/GeminiModel's
 * own request, and this command never reads it directly.
 */
class AssistantCheck extends Command
{
    protected $signature = 'assistant:check {--allow-fallback}';

    protected $description = 'Send one trivial request to the configured assistant provider and report whether it works';

    public function handle(AssistantModel $model): int
    {
        $provider = config('assistant.provider');
        $primaryModel = config('assistant.gemini.model');

        $this->line("Provider: {$provider}");
        $this->line('Model: '.($primaryModel ?: '(not set)'));

        if ($provider === 'gemini' && ! $primaryModel) {
            $this->error('ASSISTANT_GEMINI_MODEL is empty -- set it in .env before this can work.');

            return self::FAILURE;
        }

        if ($provider === 'gemini' && $model instanceof GeminiModel && ! config('assistant.gemini.api_key')) {
            $this->error('GEMINI_API_KEY is empty -- set it in .env before this can work.');

            return self::FAILURE;
        }

        $primaryResult = $this->attempt($model, (string) $primaryModel);

        if ($primaryResult['result']['outcome'] === 'ok') {
            $this->info('OK (primary model)');

            return self::SUCCESS;
        }

        if (! $this->option('allow-fallback')) {
            $this->error('FAILED (primary model, error_code: '.($primaryResult['result']['error_code'] ?? '(none)').')');

            return self::FAILURE;
        }

        $fallbackModel = (string) config('assistant.fallback_model');
        $this->line('Primary failed -- trying the fallback model: '.$fallbackModel);
        $fallbackResult = $this->attempt($model, $fallbackModel);

        if ($fallbackResult['result']['outcome'] === 'ok') {
            $this->info('OK (fallback model)');

            return self::SUCCESS;
        }

        $this->error('FAILED (both primary and fallback, last error_code: '.($fallbackResult['result']['error_code'] ?? '(none)').')');

        return self::FAILURE;
    }

    /** @return array{result: array, elapsedMs: int} */
    protected function attempt(AssistantModel $model, string $modelName): array
    {
        // microtime(true), not Carbon's diffInMilliseconds() -- found live:
        // that returned a negative value here, a sign-direction quirk in
        // the installed Carbon version's non-absolute default. A plain
        // float subtraction has no such ambiguity.
        $start = microtime(true);
        $result = $model->answer('How many seats are available?', [], '### overall_stats_demo
Demo knowledge text for assistant:check only.', [
            'overall_stats' => ['description' => 'Total seat counts.', 'parameters' => []],
        ], $modelName, 15);
        $elapsedMs = (int) round((microtime(true) - $start) * 1000);
        $status = $model instanceof GeminiModel ? $model->lastHttpStatus() : null;

        $this->line('HTTP status: '.($status ?? '(not applicable)'));
        $this->line("Response time: {$elapsedMs}ms");

        return ['result' => $result, 'elapsedMs' => $elapsedMs];
    }
}
