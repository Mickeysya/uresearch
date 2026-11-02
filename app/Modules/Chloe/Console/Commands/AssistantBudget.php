<?php

namespace App\Modules\Chloe\Console\Commands;

use App\Modules\Chloe\Models\AssistantLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Prints the assistant's own request counters next to the requests the log shows for the same Pacific-time day (Google's quota day) and Google's free-tier
 * limits, and warns when they disagree. --reset (local only) sets each counter to the number of real calls logged today. Never prints the API key.
 */
class AssistantBudget extends Command
{
    protected $signature = 'assistant:budget {--reset : APP_ENV=local only: set each day counter to the number of real calls logged today}';

    protected $description = 'Show the assistant request counters, the calls logged today and the Google free-tier limits';

    /** Google AI Studio free tier for these models (requests per minute, per day). */
    protected const GOOGLE = [
        'gemini-3.5-flash-lite' => ['rpm' => 15, 'rpd' => 500],
        'gemini-3.8-flash' => ['rpm' => 5, 'rpd' => 20],
    ];

    public function handle(): int
    {
        $tz = (string) config('assistant.day_timezone', 'America/Los_Angeles');
        $dayStart = now($tz)->startOfDay();
        $date = $dayStart->format('Ymd');
        $reset = $dayStart->copy()->addDay()->setTimezone(config('app.timezone'));

        $this->line('Cache store: '.config('cache.default').'  ·  quota day '.$dayStart->format('Y-m-d')." ({$tz}), resets ".$reset->format('Y-m-d H:i T').' ('.config('app.timezone').')');

        $rows = [];
        $warn = false;

        foreach ([(string) config('assistant.gemini.model') => 'primary', (string) config('assistant.fallback_model') => 'fallback'] as $model => $role) {
            $limits = (array) (config('assistant.rate_limits', [])[$model] ?? []);
            $google = self::GOOGLE[$model] ?? ['rpm' => '?', 'rpd' => '?'];
            $counter = (int) Cache::get("assistant:calls:day:{$model}:{$date}", 0);
            // requests sent: the per-question call counts (every attempt, failed ones too) plus the older rows that only recorded the answering model
            $since = $dayStart->copy()->setTimezone(config('app.timezone'));
            $column = $role === 'primary' ? 'calls_primary' : 'calls_fallback';
            $logged = (int) AssistantLog::query()->where('occurred_at', '>=', $since)->sum($column)
                + AssistantLog::query()->where('occurred_at', '>=', $since)->where('model_used', $model)->where('calls_primary', 0)->where('calls_fallback', 0)->count();
            $cap = (int) ($limits['requests_per_day'] ?? 0);

            if ($this->option('reset')) {
                if (! app()->environment('local')) {
                    $this->error('--reset only runs with APP_ENV=local.');

                    return self::FAILURE;
                }

                Cache::put("assistant:calls:day:{$model}:{$date}", $logged, 90000);
                $counter = $logged;
            }

            $note = '';

            // the log now records every request sent per question (calls_primary / calls_fallback); rows from before that only knew the answering model, so a few failed attempts can still be missing there
            if ($counter < $logged) {
                $note = 'counter BELOW logged calls';
                $warn = true;
            } elseif ($counter - $logged > max(5, (int) ceil($logged * 0.1))) {
                $note = 'counter above logged calls (retries or failed attempts)';
                $warn = true;
            }

            if ($cap > (int) $google['rpd'] && is_int($google['rpd'])) {
                $note .= ' cap above Google limit';
                $warn = true;
            }

            $reserve = (int) config('assistant.reserve_per_day', 60);
            $rows[] = [$role, $model, "{$counter} / {$cap}", $reserve, max(0, $cap - $counter), max(0, $cap - $reserve - $counter), $logged, ($limits['requests_per_minute'] ?? '?').' / min', $google['rpm'].' / min, '.$google['rpd'].' / day', trim($note) ?: 'ok'];
        }

        $this->table(['Role', 'Model', 'Used today / cap', 'Reserve', 'Left for people', 'Left for evaluation', 'Logged today', 'Per-minute cap', 'Google free tier', 'Check'], $rows);

        $routes = AssistantLog::query()->where('occurred_at', '>=', $dayStart->copy()->setTimezone(config('app.timezone')))->whereNotNull('route')->selectRaw('route, count(*) c')->groupBy('route')->pluck('c', 'route');
        $this->line('Routes logged today (Pacific-time day): '.collect(['static', 'refused', 'cache', 'model', 'fallback_error', 'fallback_budget'])->map(fn ($r) => $r.' '.($routes[$r] ?? 0))->implode(', '));
        $this->line('Per-user limit: '.(int) config('assistant.rate_limits.user_requests_per_minute', 10).' questions per minute; routing cache '.(int) config('assistant.route_cache_ttl', 86400).' s; next reset '.$reset->format('H:i').' '.config('app.timezone').'.');

        if ($this->option('reset')) {
            $this->info('Counters set to the number of real calls logged today.');
        }

        if ($warn) {
            $this->warn('Counter and log disagree (or a cap is above Google\'s limit): compare with the numbers in Google AI Studio.');
        }

        return self::SUCCESS;
    }
}
