<?php

namespace App\Modules\Chloe\Console\Commands;

use App\Modules\Chloe\Assistant\GeminiModel;
use App\Modules\Chloe\Assistant\Pipeline;
use App\Modules\Chloe\Models\AssistantLog;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * PART 8. Runs app/Modules/Chloe/Assistant/evaluation_questions.php against
 * the REAL Gemini provider -- always a fresh GeminiModel, independent of
 * ASSISTANT_PROVIDER, since the whole point of this command is to measure
 * that provider specifically. "It runs only when I start it": nothing
 * else in this codebase calls it, and the agent building this module
 * never runs it either -- it spends real free-tier quota.
 *
 * Paces its own calls to the PRIMARY model's own config('assistant.
 * rate_limits.<model>.requests_per_minute') -- Round 4 PART A.5 says 12/min
 * -- and stops cleanly (not a crash) the moment the app's own daily/minute
 * budget trips, which Assistant\Pipeline already enforces -- this command
 * just recognises that outcome and saves progress instead of continuing.
 */
class AssistantEvaluate extends Command
{
    protected $signature = 'assistant:evaluate {--resume} {--model-only} {--only=} {--allow-fallback}';

    protected $description = 'Run the evaluation question set against the real provider and report accuracy';

    protected function progressPath(): string
    {
        return storage_path('app/assistant-evaluate-progress.json');
    }

    public function handle(): int
    {
        $questions = require base_path('app/Modules/Chloe/Assistant/evaluation_questions.php');

        $only = $this->option('only');

        if ($only !== null) {
            return $this->runOnly($questions, $only);
        }

        $total = count($questions);

        $results = $this->option('resume') ? $this->loadProgress() : [];

        $studentUser = User::where('role', Role::STUDENT)->first();
        $cgsUser = User::whereIn('role', Role::cgsTeam())->first();

        if (! $studentUser || ! $cgsUser) {
            $this->error('Need at least one student-role user and one CGS-role user in the database to evaluate against.');

            return self::FAILURE;
        }

        $pipeline = new Pipeline(new GeminiModel);
        $modelOnly = (bool) $this->option('model-only');
        $allowFallback = (bool) $this->option('allow-fallback');
        // Round 4 PART A.5: paces at the PRIMARY model's own real per-minute limit (12), never the old flat placeholder.
        $primaryModel = (string) config('assistant.gemini.model');
        $perMinute = max(1, (int) (config('assistant.rate_limits', [])[$primaryModel]['requests_per_minute'] ?? 12));
        $delaySeconds = (int) ceil(60 / $perMinute);

        $callsThisRun = 0;
        $responseTimesMs = [];
        $stoppedEarly = false;

        foreach ($questions as $i => $q) {
            if (isset($results[$i])) {
                continue;
            }

            $user = $q['role'] === 'student' ? $studentUser : $cgsUser;

            $this->line(sprintf('[%d/%d] (%s) %s', $i + 1, $total, $q['role'], $q['question']));

            $start = microtime(true);
            $result = $pipeline->ask($user, $q['question'], [], $modelOnly, $allowFallback);
            $elapsedMs = (int) round((microtime(true) - $start) * 1000);

            $logRow = AssistantLog::where('user_id', $user->id)->latest('occurred_at')->first();
            $chosen = $logRow?->lookup;
            $answeredBy = $logRow?->answered_by;
            $modelUsed = $logRow?->model_used;
            $errorCode = $logRow?->error_code;

            // Any "unavailable" outcome -- our own budget cap, a 429, or
            // another error from the provider itself -- is NOT RUN, not a
            // failure: stop at the first one, never loop or retry, same
            // as the rule requires for --only below.
            if ($result['outcome'] === AssistantLog::OUTCOME_UNAVAILABLE) {
                $this->warn("Provider unavailable (budget reached or an error, code: {$errorCode}) -- stopping cleanly, this question was NOT RUN. Re-run with --resume to continue from here.");
                $this->saveProgress($results);
                $stoppedEarly = true;

                break;
            }

            if ($this->isModelAnswered($answeredBy)) {
                $callsThisRun++;
                $responseTimesMs[] = $elapsedMs;
            }

            $m = $this->matches($q, $chosen, $result['outcome'], $answeredBy);

            $results[$i] = [
                'pass' => $m['strict'],
                'pass_acceptable' => $m['acceptable'],
                'chosen' => $chosen,
                'answered_by' => $answeredBy,
                'outcome' => $result['outcome'],
                'model_used' => $modelUsed,
                'error_code' => $errorCode,
            ];

            $this->saveProgress($results);

            if ($this->isModelAnswered($answeredBy) && $i < $total - 1) {
                sleep($delaySeconds);
            }
        }

        $this->report($results, $questions, $callsThisRun, $responseTimesMs);

        if (! $stoppedEarly && count($results) === $total) {
            $this->clearProgress();
        }

        return self::SUCCESS;
    }

    /**
     * PART 1 #6: a scoped, non-resuming run over just the questions whose
     * text contains --only's text, PLUS any question that failed on the
     * last full/resumed run (read from the same progress file, never
     * written back here) -- the fast loop for checking a fix without
     * spending the whole day's budget on the other ~40 questions.
     * Deliberately its own progress-free path, not a filtered version of
     * handle()'s loop, so an --only run can never corrupt the full run's
     * own resumable progress file.
     */
    protected function runOnly(array $questions, string $only): int
    {
        $previousFailures = $this->previouslyFailedIndices();

        // Round 4 PART B.3: --only=failed reruns ONLY the previous
        // (strict) failures -- no text matching at all, unlike a normal
        // --only=<text> run, which merges a text match with the
        // previous failures as before.
        if (mb_strtolower(trim($only)) === 'failed') {
            $indices = $previousFailures;

            if ($indices === []) {
                $this->warn('No previously-failed questions to re-run.');

                return self::SUCCESS;
            }
        } else {
            $needle = mb_strtolower($only);

            $indices = array_values(array_filter(
                array_keys($questions),
                fn (int $i) => str_contains(mb_strtolower($questions[$i]['question']), $needle) || in_array($i, $previousFailures, true)
            ));

            if ($indices === []) {
                $this->warn('Nothing matches --only, and there are no previously-failed questions to re-run.');

                return self::SUCCESS;
            }
        }

        $studentUser = User::where('role', Role::STUDENT)->first();
        $cgsUser = User::whereIn('role', Role::cgsTeam())->first();

        if (! $studentUser || ! $cgsUser) {
            $this->error('Need at least one student-role user and one CGS-role user in the database to evaluate against.');

            return self::FAILURE;
        }

        $pipeline = new Pipeline(new GeminiModel);
        $modelOnly = (bool) $this->option('model-only');
        $allowFallback = (bool) $this->option('allow-fallback');
        // Round 4 PART A.5: paces at the PRIMARY model's own real per-minute limit (12), never the old flat placeholder.
        $primaryModel = (string) config('assistant.gemini.model');
        $perMinute = max(1, (int) (config('assistant.rate_limits', [])[$primaryModel]['requests_per_minute'] ?? 12));
        $delaySeconds = (int) ceil(60 / $perMinute);

        $results = [];
        $callsThisRun = 0;
        $responseTimesMs = [];
        $total = count($indices);

        foreach ($indices as $n => $i) {
            $q = $questions[$i];
            $user = $q['role'] === 'student' ? $studentUser : $cgsUser;

            $this->line(sprintf('[%d/%d] (%s) %s', $n + 1, $total, $q['role'], $q['question']));

            $start = microtime(true);
            $result = $pipeline->ask($user, $q['question'], [], $modelOnly, $allowFallback);
            $elapsedMs = (int) round((microtime(true) - $start) * 1000);

            $logRow = AssistantLog::where('user_id', $user->id)->latest('occurred_at')->first();
            $chosen = $logRow?->lookup;
            $answeredBy = $logRow?->answered_by;
            $modelUsed = $logRow?->model_used;
            $errorCode = $logRow?->error_code;

            if ($result['outcome'] === AssistantLog::OUTCOME_UNAVAILABLE) {
                $this->warn("Provider unavailable (budget reached or an error, code: {$errorCode}) -- stopping cleanly, this question was NOT RUN. --only never resumes; just run it again.");

                break;
            }

            if ($this->isModelAnswered($answeredBy)) {
                $callsThisRun++;
                $responseTimesMs[] = $elapsedMs;
            }

            $m = $this->matches($q, $chosen, $result['outcome'], $answeredBy);

            $results[$i] = [
                'pass' => $m['strict'],
                'pass_acceptable' => $m['acceptable'],
                'chosen' => $chosen,
                'answered_by' => $answeredBy,
                'outcome' => $result['outcome'],
                'model_used' => $modelUsed,
                'error_code' => $errorCode,
            ];

            if ($this->isModelAnswered($answeredBy) && $n < $total - 1) {
                sleep($delaySeconds);
            }
        }

        $this->report($results, $questions, $callsThisRun, $responseTimesMs);

        return self::SUCCESS;
    }

    /** @return array<int, int> indices (into evaluation_questions.php) that failed last time, from whatever the shared progress file holds -- empty if there is none. */
    protected function previouslyFailedIndices(): array
    {
        if (! File::exists($this->progressPath())) {
            return [];
        }

        $decoded = json_decode(File::get($this->progressPath()), true);

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_map(
            'intval',
            array_keys(array_filter($decoded, fn ($row) => is_array($row) && ($row['pass'] ?? true) === false))
        ));
    }

    /** PART B: a real provider call was used -- not just the old ANSWERED_BY_MODEL (a lookup CHOICE), but either of the two new guidance/aggregate-compose values too. Undercounts a call that failed validation and fell back to ANSWERED_BY_DIRECT, which cannot be told apart from "budget unavailable, no call attempted at all" from the log alone -- an acceptable, documented approximation for this report-only number. */
    protected function isModelAnswered(?string $answeredBy): bool
    {
        return in_array($answeredBy, [
            AssistantLog::ANSWERED_BY_MODEL,
            AssistantLog::ANSWERED_BY_MODEL_GUIDANCE,
            AssistantLog::ANSWERED_BY_MODEL_COMPOSE,
        ], true);
    }

    /**
     * $expectedAnsweredBy (STEP 2): present only for questions the
     * deterministic extraction layer is specifically meant to catch --
     * e.g. "J2 free seats?" must now be 'rule', never 'model'. Omitted
     * (null) everywhere that distinction either already held before STEP
     * 2, or genuinely still needs the model, so it isn't asserted there.
     *
     * Round 4 PART B.3: returns BOTH a strict pass (expected only) and
     * an acceptable pass (expected OR any of the question's own
     * 'acceptable' list) -- never merged into one number, so a CGS
     * topic-counterpart question can legitimately count as correct
     * either way without hiding which of the two it actually was.
     *
     * @return array{strict: bool, acceptable: bool}
     */
    protected function matches(array $question, ?string $chosen, string $outcome, ?string $answeredBy): array
    {
        $expectedAnsweredBy = $question['expected_answered_by'] ?? null;
        $answeredByOk = $expectedAnsweredBy === null || $answeredBy === $expectedAnsweredBy;

        $strict = $answeredByOk && $this->oneExpectationMatches($question['expected'], $chosen, $outcome);
        $acceptable = $strict;

        if (! $acceptable && $answeredByOk) {
            foreach ($question['acceptable'] ?? [] as $alternative) {
                if ($this->oneExpectationMatches($alternative, $chosen, $outcome)) {
                    $acceptable = true;

                    break;
                }
            }
        }

        return ['strict' => $strict, 'acceptable' => $acceptable];
    }

    protected function oneExpectationMatches(string $expected, ?string $chosen, string $outcome): bool
    {
        return match ($expected) {
            'refused' => $outcome === AssistantLog::OUTCOME_REFUSED,
            'asked_back' => $outcome === AssistantLog::OUTCOME_ASKED_BACK,
            default => $chosen === $expected,
        };
    }

    /**
     * Round 4 PART B.3: reports STRICT accuracy (expected only) and
     * ACCEPTABLE accuracy (expected OR the question's own 'acceptable'
     * list) as two separate numbers -- a question answered only via its
     * 'acceptable' alternative shows as ACCEPTABLE in the table, never
     * silently counted as a plain PASS.
     */
    protected function report(array $results, array $questions, int $callsThisRun, array $responseTimesMs): void
    {
        $rows = [];
        $passCount = 0;
        $acceptableCount = 0;

        foreach ($results as $i => $r) {
            $q = $questions[$i];
            // Backward-compatible with a progress file saved before this round, which has no 'pass_acceptable' key at all.
            $passAcceptable = $r['pass_acceptable'] ?? $r['pass'];

            $rows[] = [
                $q['question'], $q['role'], $q['expected'],
                $r['chosen'] ?? '(none)', $r['answered_by'] ?? '-',
                $r['model_used'] ?? '-', $r['error_code'] ?? '-',
                $r['pass'] ? 'PASS' : ($passAcceptable ? 'ACCEPTABLE' : 'FAIL'),
            ];

            if ($r['pass']) {
                $passCount++;
            }
            if ($passAcceptable) {
                $acceptableCount++;
            }
        }

        $this->table(['Question', 'Role', 'Expected', 'Chosen', 'Answered By', 'Model Used', 'Error', 'Result'], $rows);

        $answered = count($results);
        $strictAccuracy = $answered > 0 ? round($passCount / $answered * 100, 1) : 0.0;
        $acceptableAccuracy = $answered > 0 ? round($acceptableCount / $answered * 100, 1) : 0.0;
        $avgMs = $responseTimesMs === [] ? 0 : (int) round(array_sum($responseTimesMs) / count($responseTimesMs));

        $this->info("Strict accuracy: {$strictAccuracy}% ({$passCount}/{$answered})");
        $this->info("Acceptable accuracy: {$acceptableAccuracy}% ({$acceptableCount}/{$answered})");
        $this->info("Provider calls used this run: {$callsThisRun}");
        $this->info("Average response time: {$avgMs}ms");
    }

    /** @return array<int, array{pass: bool, chosen: ?string, answered_by: ?string, outcome: string}> */
    protected function loadProgress(): array
    {
        if (! File::exists($this->progressPath())) {
            return [];
        }

        $decoded = json_decode(File::get($this->progressPath()), true);

        return is_array($decoded) ? array_map(fn ($row) => $row, $decoded) : [];
    }

    protected function saveProgress(array $results): void
    {
        File::ensureDirectoryExists(dirname($this->progressPath()));
        File::put($this->progressPath(), json_encode($results, JSON_PRETTY_PRINT));
    }

    protected function clearProgress(): void
    {
        if (File::exists($this->progressPath())) {
            File::delete($this->progressPath());
        }
    }
}
