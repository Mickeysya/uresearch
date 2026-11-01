<?php

namespace App\Modules\Chloe\Console\Commands;

use App\Modules\Chloe\Assistant\Catalogue;
use App\Modules\Chloe\Assistant\GeminiModel;
use App\Modules\Chloe\Assistant\Pipeline;
use App\Modules\Chloe\Models\AssistantLog;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * PART 8 / Part 4. Measures the semantic ROUTER against the REAL Gemini
 * provider -- always a fresh GeminiModel, independent of
 * ASSISTANT_PROVIDER. It runs only when someone starts it; it spends real
 * free-tier quota.
 *
 * What a run measures, honestly: the router alone. The primary model
 * only (unless --allow-fallback), no compose calls (Pipeline::
 * disableCompose()), and --model-only semantics (a router failure is
 * NOT RUN, never quietly rescued by the keyword fallback). A question the
 * pipeline answers BEFORE the router (an exact suggested-question text)
 * costs no call and is reported separately, never counted as a router
 * success.
 *
 * Pacing: one router call per question, spaced to the primary model's
 * own configured per-minute limit. Stops cleanly at the first
 * unavailable answer or 429 -- never loops or retries -- saves progress,
 * and prints the exact --resume command.
 *
 * Questions: app/Modules/Chloe/Assistant/evaluation_questions.php, or
 * --heldout for docs/assistant-ml/heldout-labelled.jsonl. Results live in
 * storage/app/assistant-evaluate-progress[-heldout].json; --report only
 * prints them again, with no calls.
 */
class AssistantEvaluate extends Command
{
    /** The date the relative periods ("next month") in the question set were labelled for. */
    public const LABELLED_FOR = '2026-10-30';

    protected $signature = 'assistant:evaluate
        {--resume : Continue the saved run, skipping questions already answered}
        {--only= : "failed" reruns only the previous failures; any other text reruns questions containing it}
        {--group= : Comma list of groups to run: original,contrast,messy,standard,followup,candidacy}
        {--set= : Comma list of sets to run: workstation, candidacy, cross (default: all)}
        {--domain= : Only questions asked in this domain (workstation or candidacy)}
        {--sample= : Run only this many questions of the workstation set, evenly spread (the other sets run whole)}
        {--limit= : Stop after this many router calls in this run}
        {--heldout : Use docs/assistant-ml/heldout-labelled.jsonl instead of the built-in set}
        {--report : Print the saved results only -- no calls}
        {--allow-fallback : Also allow the fallback model}
        {--model-only : Kept for compatibility; a run is always router-only}';

    protected $description = 'Measure the assistant router against the real provider and report accuracy';

    protected function progressPath(): string
    {
        return storage_path('app/assistant-evaluate-progress'.($this->option('heldout') ? '-heldout' : '').'.json');
    }

    public function handle(): int
    {
        $questions = $this->loadQuestions();

        if ($questions === null) {
            return self::FAILURE;
        }

        $saved = $this->loadProgress();

        if ($this->option('report')) {
            $this->report($saved, $questions, 'Saved results');

            return self::SUCCESS;
        }

        $indices = $this->selectIndices($questions, $saved);

        if ($indices === []) {
            $this->warn('Nothing to run for these options.');
            $this->report($saved, $questions, 'Saved results');

            return self::SUCCESS;
        }

        if (Carbon::now('Asia/Kuala_Lumpur')->format('Y-m') !== substr(self::LABELLED_FOR, 0, 7)) {
            $this->warn('The question set\'s relative periods were labelled for '.self::LABELLED_FOR.' -- period checks for "next month"-style questions may not hold today.');
        }

        $studentUser = User::where('role', Role::STUDENT)->first();
        // a plain CGS user (not also an approver), so a CGS question is judged against the CGS catalogue alone
        $cgsUser = User::whereIn('role', Role::cgsTeam())->get()->first(fn (User $u) => \App\Modules\Chloe\Assistant\AssistantRoles::roleFor($u) === 'cgs') ?? User::whereIn('role', Role::cgsTeam())->first();
        $approverUser = User::whereIn('role', [Role::SUPERVISOR, Role::CHAIR, Role::DEAN_PGR])->first();

        if (! $studentUser || ! $cgsUser) {
            $this->error('Need at least one student-role user and one CGS-role user in the database to evaluate against.');

            return self::FAILURE;
        }

        $pipeline = new Pipeline(new GeminiModel);
        $pipeline->disableCompose();
        $allowFallback = (bool) $this->option('allow-fallback');
        $primaryModel = (string) config('assistant.gemini.model');
        $perMinute = max(1, (int) (config('assistant.rate_limits', [])[$primaryModel]['requests_per_minute'] ?? 12));
        $delaySeconds = (int) ceil(60 / $perMinute);
        $limit = $this->option('limit') !== null ? max(0, (int) $this->option('limit')) : null;
        $isRerun = $this->option('only') !== null;

        $callsBefore = $this->callsToday($primaryModel);
        $thisRun = [];
        $routerCalls = 0;
        $stopped = null;
        $runStart = microtime(true);

        $this->info(sprintf('Running %d question(s), one router call each, paced every %ds (%s).', count($indices), $delaySeconds, $primaryModel));

        foreach ($indices as $n => $i) {
            if ($limit !== null && $routerCalls >= $limit) {
                $stopped = "reached --limit={$limit}";

                break;
            }

            $q = $questions[$i];
            $user = match ($q['role']) { 'student' => $studentUser, 'approver' => $approverUser ?? $cgsUser, default => $cgsUser };
            $this->line(sprintf('[%d/%d] (%s, %s) %s', $n + 1, count($indices), $q['role'], $q['group'], $q['q']));

            $start = microtime(true);
            $result = $pipeline->setDomain($q['domain'] ?? 'workstation')->ask($user, $q['q'], $q['recent'] ?? [], true, $allowFallback);
            $elapsedMs = (int) round((microtime(true) - $start) * 1000);
            $meta = $result['meta'] ?? [];

            // a question the pipeline refuses before the router (a person's name) is labelled refused_before_router
            if (($q['id'] ?? null) === 'refused_before_router' && ($result['outcome'] ?? null) === AssistantLog::OUTCOME_REFUSED) {
                $meta['catalogue_id'] = 'refused_before_router';
            }
            $errorCode = AssistantLog::where('user_id', $user->id)->latest('id')->value('error_code');

            if ($result['outcome'] === AssistantLog::OUTCOME_UNAVAILABLE) {
                $stopped = 'provider unavailable (error '.($errorCode ?? 'unknown').')';
                $this->warn("{$stopped} -- stopping cleanly; this question was NOT RUN.");

                break;
            }

            $usedRouter = ($meta['answered_by'] ?? null) === AssistantLog::ANSWERED_BY_MODEL_ROUTER;
            $routerCalls += $usedRouter ? 1 : 0;

            $thisRun[$i] = $saved[$i] = $this->score($q, $meta, $result, $usedRouter, $elapsedMs);
            $this->line('    -> '.($saved[$i]['actual'] ?? '(none)').' conf '.($saved[$i]['confidence'] ?? '-').($saved[$i]['pass_accept'] ? '  ok' : '  FAIL').($usedRouter ? '' : '  [no router call]'));
            $this->saveProgress($saved);

            if ($errorCode === '429') {
                $stopped = 'HTTP 429';

                break;
            }

            if ($usedRouter && $n < count($indices) - 1) {
                sleep($delaySeconds);
            }
        }

        $wall = (int) round(microtime(true) - $runStart);
        $callsUsed = $this->callsToday($primaryModel) - $callsBefore;

        if ($isRerun) {
            $this->report($thisRun, $questions, 'This rerun (only the questions just re-asked)');
        }
        $this->report($saved, $questions, 'All saved results');

        $this->info("Router calls counted this run: {$callsUsed} (app day counter for {$primaryModel}); questions asked: ".count($thisRun)."; wall time: {$wall}s.");

        if ($stopped !== null) {
            $remaining = count(array_diff(array_keys($questions), array_keys($saved)));
            $this->warn("Stopped: {$stopped}. {$remaining} question(s) not yet run.");
            $this->warn('Continue with: docker exec uresearch-app php artisan assistant:evaluate --resume'.($this->option('heldout') ? ' --heldout' : '').($this->option('group') ? ' --group='.$this->option('group') : ''));
        }

        return self::SUCCESS;
    }

    /** @return array<int, array<string, mixed>>|null */
    protected function loadQuestions(): ?array
    {
        if (! $this->option('heldout')) {
            return require base_path('app/Modules/Chloe/Assistant/evaluation_questions.php');
        }

        $path = base_path('docs/assistant-ml/heldout-labelled.jsonl');

        if (! File::exists($path)) {
            $this->error('docs/assistant-ml/heldout-labelled.jsonl does not exist.');

            return null;
        }

        return collect(preg_split('/\R/', trim(File::get($path))))
            ->filter()
            ->map(fn ($line) => json_decode($line, true) + ['group' => 'heldout'])
            ->values()
            ->all();
    }

    /** @return array<int, int> */
    protected function selectIndices(array $questions, array $saved): array
    {
        $indices = array_keys($questions);

        if ($groups = $this->option('group')) {
            $wanted = array_map('trim', explode(',', $groups));
            $indices = array_values(array_filter($indices, fn ($i) => in_array($questions[$i]['group'], $wanted, true)));
        }

        if ($sets = $this->option('set')) {
            $wanted = array_map('trim', explode(',', $sets));
            $indices = array_values(array_filter($indices, fn ($i) => in_array($questions[$i]['set'] ?? 'workstation', $wanted, true)));
        }

        if ($domain = $this->option('domain')) {
            $indices = array_values(array_filter($indices, fn ($i) => ($questions[$i]['domain'] ?? 'workstation') === $domain));
        }

        if ($sample = (int) $this->option('sample')) {
            $existing = array_values(array_filter($indices, fn ($i) => ($questions[$i]['set'] ?? 'workstation') === 'workstation'));
            $step = max(1, (int) floor(count($existing) / $sample));
            $picked = array_slice(array_values(array_filter($existing, fn ($i, $n) => $n % $step === 0, ARRAY_FILTER_USE_BOTH)), 0, $sample);
            $indices = array_values(array_filter($indices, fn ($i) => ($questions[$i]['set'] ?? 'workstation') !== 'workstation' || in_array($i, $picked, true)));
        }

        $only = $this->option('only');

        if ($only !== null && mb_strtolower(trim($only)) === 'failed') {
            // A failure: the wrong id, or the right id with the wrong parameters.
            return array_values(array_filter($indices, fn ($i) => isset($saved[$i]) && (! $saved[$i]['pass_accept'] || ($saved[$i]['params_ok'] ?? null) === false)));
        }
        if ($only !== null) {
            return array_values(array_filter($indices, fn ($i) => str_contains(mb_strtolower($questions[$i]['q']), mb_strtolower($only))));
        }

        if ($this->option('resume')) {
            return array_values(array_filter($indices, fn ($i) => ! isset($saved[$i])));
        }

        return $indices;
    }

    protected function score(array $q, array $meta, array $result, bool $usedRouter, int $elapsedMs): array
    {
        $actual = $meta['catalogue_id'] ?? null;

        // A student asking a CGS-only question is labelled out_of_scope: a polite refusal is the correct outcome there.
        // cross-domain labels: a redirect is the expected answer to another domain's question; an ambiguous question passes when it is NOT redirected
        $passStrict = $q['id'] === 'stay_in_domain' ? $actual !== 'wrong_domain' : $actual === $q['id'];
        $passAccept = $passStrict || in_array($actual, $q['accept'] ?? [], true);

        $expectedParams = $q['params'] ?? [];
        $actualParams = $meta['router_params'] ?? [];
        $paramsOk = null;

        if ($expectedParams !== [] && $passAccept) {
            $paramsOk = true;
            foreach ($expectedParams as $k => $v) {
                if (! array_key_exists($k, $actualParams) || mb_strtolower((string) $actualParams[$k]) !== mb_strtolower((string) $v)) {
                    $paramsOk = false;
                }
            }
        }

        return [
            'actual' => $actual,
            'actual_kind' => $actual !== null ? (Catalogue::find($actual)['kind'] ?? null) : null,
            'confidence' => isset($meta['confidence']) ? round((float) $meta['confidence'], 2) : null,
            'alternatives' => $meta['alternatives'] ?? [],
            'router_params' => $actualParams,
            'pass_strict' => $passStrict,
            'pass_accept' => $passAccept,
            'params_ok' => $paramsOk,
            'router' => $usedRouter,
            'outcome' => $result['outcome'],
            'model_used' => $meta['model_used'] ?? null,
            'ms' => $elapsedMs,
        ];
    }

    /**
     * Per role: id accuracy (strict / with accepted alternatives), kind
     * accuracy, accuracy by expected kind, the kind confusion matrix,
     * confidence calibration, the threshold table, params accuracy,
     * every failure, average response time. Router-answered rows only;
     * pre-router rows are counted separately.
     */
    protected function report(array $results, array $questions, string $title): void
    {
        $rows = collect($results)->map(fn ($r, $i) => $r + ['q' => $questions[$i]['q'], 'role' => $questions[$i]['role'], 'group' => $questions[$i]['group'], 'expected' => $questions[$i]['id'], 'expected_kind' => Catalogue::find($questions[$i]['id'])['kind'] ?? '?', 'accept' => $questions[$i]['accept'] ?? []]);
        $router = $rows->where('router', true);
        $preRouter = $rows->where('router', false)->count();

        $this->newLine();
        $this->info("=== {$title}: {$router->count()} router-answered question(s)".($preRouter ? ", {$preRouter} answered before the router (no call, not counted)" : '').' ===');

        // per set: all rows (router-answered or not). Cross-domain rows also say how many were redirected WITHOUT a model call.
        $setRows = [];
        foreach ($results as $i => $r) {
            $setRows[$questions[$i]['set'] ?? 'workstation'][] = $r + ['expected_id' => $questions[$i]['id']];
        }
        foreach (['workstation', 'candidacy', 'cross'] as $set) {
            $rs = collect($setRows[$set] ?? []);

            if ($rs->isEmpty()) {
                continue;
            }

            $line = sprintf('Set %-11s %d question(s): strict %s, with accepted alternatives %s', $set, $rs->count(), round($rs->where('pass_strict', true)->count() / $rs->count() * 100, 1).'%', round($rs->where('pass_accept', true)->count() / $rs->count() * 100, 1).'%');

            if ($set === 'cross') {
                $redirects = $rs->where('expected_id', 'wrong_domain');
                $line .= sprintf('; redirects: %d of %d passed, %d without a model call', $redirects->where('pass_accept', true)->count(), $redirects->count(), $redirects->where('pass_accept', true)->where('router', false)->count());
            }

            $this->line($line);
        }

        if ($router->isEmpty()) {
            return;
        }

        $pct = fn ($n, $d) => $d > 0 ? round($n / $d * 100, 1).'%' : '-';
        $kindOk = fn ($r) => $r['actual_kind'] === $r['expected_kind'] || ($r['pass_accept'] && $r['actual_kind'] !== null);

        $summary = [];
        foreach ([...['student', 'approver', 'cgs'], 'all'] as $role) {
            $set = $role === 'all' ? $router : $router->where('role', $role);
            if ($set->isEmpty()) {
                continue;
            }
            $withParams = $set->filter(fn ($r) => $r['params_ok'] !== null);
            $withParams = $withParams->values();
            $summary[] = [
                $role, $set->count(),
                $pct($set->where('pass_strict', true)->count(), $set->count()),
                $pct($set->where('pass_accept', true)->count(), $set->count()),
                $pct($set->filter($kindOk)->count(), $set->count()),
                $withParams->isEmpty() ? '-' : $pct($withParams->filter(fn ($r) => $r['params_ok'] === true)->count(), $withParams->count()).' of '.$withParams->count(),
                (int) round($set->avg('ms')).'ms',
            ];
        }
        $this->table(['Role', 'N', 'Id strict', 'Id + accepted', 'Kind', 'Params', 'Avg time'], $summary);

        $byGroup = $router->groupBy('group')->map(fn ($set, $g) => [$g, $set->count(), $pct($set->where('pass_strict', true)->count(), $set->count()), $pct($set->where('pass_accept', true)->count(), $set->count()), $pct($set->filter($kindOk)->count(), $set->count())])->values()->all();
        $this->table(['Group', 'N', 'Id strict', 'Id + accepted', 'Kind'], $byGroup);

        $kinds = ['lookup', 'guidance', 'action', 'out_of_scope'];
        $byKind = collect($kinds)->map(function ($k) use ($router, $pct, $kindOk) {
            $set = $router->where('expected_kind', $k);

            return [$k, $set->count(), $pct($set->where('pass_accept', true)->count(), $set->count()), $pct($set->filter($kindOk)->count(), $set->count())];
        })->all();
        $this->table(['Expected kind', 'N', 'Id + accepted', 'Kind correct'], $byKind);

        $matrix = collect($kinds)->map(fn ($e) => [$e, ...collect([...$kinds, null])->map(fn ($a) => $router->where('expected_kind', $e)->where('actual_kind', $a)->count())->all()])->all();
        $this->line('Kind confusion matrix (rows = expected, columns = actual):');
        $this->table(['', ...$kinds, '(none)'], $matrix);

        $bands = [[0.0, 0.5], [0.5, 0.6], [0.6, 0.7], [0.7, 0.8], [0.8, 0.9], [0.9, 1.01]];
        $calibration = collect($bands)->map(function ($b) use ($router, $pct) {
            $set = $router->filter(fn ($r) => $r['confidence'] !== null && $r['confidence'] >= $b[0] && $r['confidence'] < $b[1]);

            return [sprintf('%.1f-%.1f', $b[0], min(1.0, $b[1])), $set->count(), $pct($set->where('pass_accept', true)->count(), $set->count())];
        })->all();
        $this->line('Confidence calibration:');
        $this->table(['Band', 'N', 'Accuracy'], $calibration);

        $thresholds = collect([0.5, 0.6, 0.7, 0.75, 0.8, 0.85, 0.9, 0.95])->map(function ($t) use ($router, $pct) {
            $wrongAbove = $router->filter(fn ($r) => ! $r['pass_accept'] && ($r['confidence'] ?? 0) >= $t)->count();
            $chips = $router->filter(fn ($r) => ($r['confidence'] ?? 0) < $t || ($r['alternatives'] ?? []) !== [])->count();

            return [$t, $pct($wrongAbove, $router->count()), $pct($chips, $router->count())];
        })->all();
        $this->line('Threshold choice (wrong answers shown with no "Did you mean" / share of answers that show chips):');
        $this->table(['router_min_confidence', 'Wrong & confident', 'Shows chips'], $thresholds);

        $failures = $router->where('pass_accept', false)->map(fn ($r) => [$r['role'], $r['group'], mb_strimwidth($r['q'], 0, 60, '...'), $r['expected'], $r['actual'] ?? '(none)', $r['confidence']])->values()->all();
        $this->line('Failures ('.count($failures).'):');
        if ($failures !== []) {
            $this->table(['Role', 'Group', 'Question', 'Expected', 'Actual', 'Conf'], $failures);
        }

        // Strict: where('params_ok', false) would also match null (no parameters expected).
        $paramFails = $router->filter(fn ($r) => $r['params_ok'] === false)->map(fn ($r) => [mb_strimwidth($r['q'], 0, 60, '...'), json_encode($questions[array_search($r['q'], array_column($questions, 'q'))]['params'] ?? []), json_encode($r['router_params'])])->values()->all();
        if ($paramFails !== []) {
            $this->line('Parameter mismatches ('.count($paramFails).'):');
            $this->table(['Question', 'Expected params', 'Router params (masked)'], $paramFails);
        }
    }

    protected function callsToday(string $model): int
    {
        return (int) Cache::get("assistant:calls:day:{$model}:".now(config('assistant.day_timezone', 'America/Los_Angeles'))->format('Ymd'), 0);
    }

    protected function loadProgress(): array
    {
        if (! File::exists($this->progressPath())) {
            return [];
        }

        $decoded = json_decode(File::get($this->progressPath()), true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function saveProgress(array $results): void
    {
        File::ensureDirectoryExists(dirname($this->progressPath()));
        ksort($results);
        File::put($this->progressPath(), json_encode($results, JSON_PRETTY_PRINT));
    }
}
