<?php

namespace App\Modules\Chloe\Console\Commands;

use App\Modules\Chloe\Assistant\Pipeline;
use App\Modules\Chloe\Models\AssistantLog;
use App\Modules\Core\Models\User;
use Illuminate\Console\Command;

/**
 * PART 8. Runs the exact same Assistant\Pipeline::ask() the HTTP endpoint
 * uses, as the given user, and prints the result -- a quick way to
 * demonstrate or debug one question from the terminal without a UI. No
 * conversation history is passed (console use is always a fresh
 * question); each call writes its own PART 7 log row like any other.
 */
class AssistantAsk extends Command
{
    protected $signature = 'assistant:ask {email : The user\'s email} {question : The question to ask}';

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

        $result = $pipeline->ask($user, $question, []);

        $this->line('Answer: '.$result['answer']);
        $this->line('Interpreted as: '.($result['interpreted_as'] ?? '(none)'));

        if ($result['links'] !== []) {
            foreach ($result['links'] as $link) {
                $this->line("Link: {$link['label']} -> {$link['url']}");
            }
        } else {
            $this->line('Links: (none)');
        }

        $this->line('Outcome: '.$result['outcome']);

        $answeredBy = AssistantLog::where('user_id', $user->id)->latest('occurred_at')->value('answered_by');
        $this->line('Answered by: '.($answeredBy ?? '(unknown)'));

        return self::SUCCESS;
    }
}
