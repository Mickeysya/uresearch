<?php

namespace App\Modules\Chloe\Console\Commands;

use App\Modules\Chloe\Models\SentMail;
use App\Modules\Chloe\Support\MailArchive;
use Illuminate\Console\Command;

/** Read-only: prints archived e-mails in time order. Writes nothing. */
class MailArchiveList extends Command
{
    protected $signature = 'mail:archive-list {--date= : One day, YYYY-MM-DD (Asia/Kuala_Lumpur)} {--module= : workstation, candidacy, ...} {--to= : Part of the recipient address}';

    protected $description = 'List e-mails kept in the permanent sent-mail archive, oldest first (read-only).';

    public function handle(): int
    {
        $query = SentMail::query()->orderBy('sent_at')->orderBy('id');

        if ($date = $this->option('date')) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $this->error('--date must look like 2026-11-07.');

                return self::FAILURE;
            }
            $query->whereBetween('sent_at', [$date.' 00:00:00', $date.' 23:59:59']);
        }
        if ($module = $this->option('module')) {
            $query->where('module', $module);
        }
        if ($to = $this->option('to')) {
            $query->where('to_addresses', 'like', '%'.addcslashes($to, '%_\\').'%');
        }

        $rows = $query->get();
        if ($rows->isEmpty()) {
            $this->info('No archived e-mail matches.');

            return self::SUCCESS;
        }

        $this->table(
            ['#', 'Sent (KL)', 'Module', 'To', 'Subject', 'Class', 'Reference', 'File'],
            $rows->map(fn (SentMail $m) => [
                $m->id, $m->sent_at, $m->module, $m->to_addresses, $m->subject,
                $m->mail_class ? class_basename($m->mail_class) : '-', $m->reference ?? '-', $m->eml_path,
            ])->all()
        );
        $this->line($rows->count().' e-mail(s). Files are under '.MailArchive::root().'/');

        return self::SUCCESS;
    }
}
