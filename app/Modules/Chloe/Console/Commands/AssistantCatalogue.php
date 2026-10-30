<?php

namespace App\Modules\Chloe\Console\Commands;

use App\Modules\Chloe\Assistant\Catalogue;
use Illuminate\Console\Command;

/** Part 1.3: prints the canonical question catalogue for one role -- id, kind, canonical question, parameters. Never touches the provider. */
class AssistantCatalogue extends Command
{
    protected $signature = 'assistant:catalogue {role : student or cgs}';

    protected $description = 'Print the canonical question catalogue for a role (id, kind, canonical question, parameters)';

    public function handle(): int
    {
        $role = $this->argument('role');

        if (! in_array($role, ['student', 'cgs'], true)) {
            $this->error('Role must be "student" or "cgs".');

            return self::FAILURE;
        }

        $entries = Catalogue::forRole($role);

        $rows = collect($entries)->map(fn ($e) => [
            $e['id'],
            $e['kind'],
            $e['question'] ?: '(n/a)',
            $e['parameters'] ? implode(', ', array_keys($e['parameters'])) : '-',
        ])->all();

        $this->table(['ID', 'Kind', 'Canonical question', 'Parameters'], $rows);
        $this->info(count($entries)." entries for role '{$role}' (".
            collect($entries)->countBy('kind')->map(fn ($c, $k) => "{$k}: {$c}")->implode(', ').').');

        return self::SUCCESS;
    }
}
