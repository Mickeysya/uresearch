<?php

namespace App\Modules\Chloe\Console\Commands;

use App\Modules\Chloe\Models\AssistantLog;
use Illuminate\Console\Command;

/**
 * PART 7/8. Read-only -- no screen or route exists for this table at all
 * (per the brief), so the terminal is the only place anyone ever sees
 * these rows. Every column printed here is metadata only; there is no
 * question or answer text in this table to accidentally print.
 */
class AssistantLogList extends Command
{
    protected $signature = 'assistant:log {--limit=20} {--domain= : only this domain}';

    protected $description = 'Print the latest assistant_logs rows as a table';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $rows = AssistantLog::with('user')
            ->when($this->option('domain'), fn ($q, $d) => $q->where('domain', $d))
            ->latest('occurred_at')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No assistant_logs rows yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['Occurred At', 'User', 'Role', 'Domain', 'Source', 'Lookup', 'Outcome', 'Refusal Reason', 'Result Count', 'Answered By', 'Model Used', 'Error Code'],
            $rows->map(fn (AssistantLog $row) => [
                $row->occurred_at->format('Y-m-d H:i:s'),
                $row->user?->email ?? "#{$row->user_id}",
                $row->role,
                $row->domain,
                $row->source,
                $row->lookup,
                $row->outcome,
                $row->refusal_reason ?? '-',
                $row->result_count ?? '-',
                $row->answered_by,
                $row->model_used ?? '-',
                $row->error_code ?? '-',
            ])
        );

        return self::SUCCESS;
    }
}
