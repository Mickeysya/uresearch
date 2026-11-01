<?php

namespace App\Modules\Chloe\Console\Commands;

use App\Modules\Chloe\Assistant\Pipeline;
use App\Modules\Chloe\Models\AssistantLog;
use App\Modules\Core\Models\User;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * PART 8. Runs the exact same Assistant\Pipeline::ask() the HTTP endpoint
 * uses, as the given user, and prints the result -- a quick way to
 * demonstrate or debug one question from the terminal without a UI.
 * --recent passes earlier questions as follow-up context, the same way
 * the browser does (never stored anywhere). Each call writes its own
 * metadata log row like any other.
 *
 * --trace prints the masked question, the router's JSON (ids and
 * parameters only, plus the clarify text), each validation step and the
 * decision. Never the key, never a real name or ID (everything traced is
 * the MASKED form), and never written to the log table.
 */
class AssistantAsk extends Command
{
    protected $signature = 'assistant:ask {email : The user\'s email} {question : The question to ask} {--domain=workstation : workstation or candidacy} {--recent=* : An earlier question, oldest first (repeatable)} {--trace : Print the router decision trace}';

    protected $description = 'Run the assistant pipeline as the given user and print the result';

    public function handle(Pipeline $pipeline): int
    {
        $email = $this->argument('email');
        $question = $this->argument('question');

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No user found with email {$email}.");

            return self::FAILURE;
        }

        $pipeline->setDomain((string) $this->option('domain'));

        if ($this->option('trace')) {
            $pipeline->enableTrace();
        }

        $start = microtime(true);
        $result = $pipeline->ask($user, $question, (array) $this->option('recent'));
        $elapsedMs = (int) round((microtime(true) - $start) * 1000);

        if ($this->option('trace')) {
            $this->line('--- trace ---');
            foreach ($pipeline->trace() as $line) {
                // Escaped: "<ID1>" would otherwise be read as a console style tag.
                $this->line('  '.OutputFormatter::escape($line));
            }
            $this->line('-------------');
        }

        $this->line('Answer: '.OutputFormatter::escape($result['answer']));
        $this->line('Interpreted as: '.($result['interpreted_as'] ?? '(none)'));

        if ($result['links'] !== []) {
            foreach ($result['links'] as $link) {
                $this->line("Link: {$link['label']} -> {$link['url']}");
            }
        } else {
            $this->line('Links: (none)');
        }

        foreach ($result['chips'] ?? [] as $chip) {
            $this->line("Did you mean: {$chip['text']} [{$chip['id']}]");
        }
        if (! empty($result['suggestions'])) {
            $this->line('Suggested-question chips: '.count($result['suggestions']));
        }

        $meta = $result['meta'] ?? [];
        $log = AssistantLog::where('user_id', $user->id)->latest('id')->first();

        $this->line('Outcome: '.$result['outcome']);
        $this->line('Catalogue id: '.($meta['catalogue_id'] ?? '(none)').' ('.($meta['kind'] ?? $log?->catalogue_kind ?? '-').')');
        $this->line('Confidence: '.(isset($meta['confidence']) ? number_format($meta['confidence'], 2) : '-'));
        $this->line('Answered by: '.($log?->answered_by ?? '(unknown)'));
        $this->line('Model used: '.($log?->model_used ?? '-').($log?->error_code ? " (error {$log->error_code})" : ''));
        $this->line("Response time: {$elapsedMs}ms");

        return self::SUCCESS;
    }
}
