<?php

namespace App\Modules\Hani\Console;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\ExaminerImportBatch;
use App\Modules\Hani\Models\ExaminerNomination;
use App\Modules\Hani\Models\ExaminerReminderLog;
use App\Modules\Hani\Models\VivaDetail;
use App\Modules\Hani\Notifications\EvaluationOverdue;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

/**
 * Daily housekeeping for the examiner pool -- the chasing the Academic
 * Executive and CGS do by hand today.
 *
 *   - A released panel whose viva date has passed but whose evaluation is not
 *     marked complete: remind the candidate's Academic Executive, then again
 *     every config('hani.sweep.remind_every_days') days while it stays open.
 *     Until it is marked, the examiners stay tied up and their gap never
 *     starts.
 *   - Unconfirmed pool-import previews past their lifetime are purged.
 *
 * (A tie-up that has run out without completion needs no job: Pending
 * Evaluation flags it from the stored dates.)
 *
 * IDEMPOTENT the way `rpd:remind` is: each reminder's log row is inserted
 * BEFORE it is sent, and the unique key on (nomination, kind, sequence) makes
 * the database -- not the timing -- decide that a second run skips it.
 * Scheduled from Hani\ModuleProvider; see SHARED_CHANGES.md for the
 * routes/console.php alternative.
 */
class ExaminersSweep extends Command
{
    protected $signature = 'examiners:sweep
                            {--dry-run : List what would be sent and purged without doing either}';

    protected $description = 'Remind Academic Executives about evaluations not marked complete after the viva, and purge stale import previews';

    public const KIND_OVERDUE = 'evaluation_overdue';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $every = max(1, (int) config('hani.sweep.remind_every_days', 7));
        $sent = 0;
        $skipped = 0;

        foreach ($this->openPanels() as [$nomination, $vivaAt]) {
            $days = (int) $vivaAt->copy()->startOfDay()->diffInDays(now()->startOfDay());
            $sequence = intdiv($days, $every);
            $student = $nomination->application->student;

            $recipients = User::where('role', Role::ACADEMIC_EXEC)
                ->where('department', $student?->department)
                ->get();

            if ($dryRun) {
                $this->line(sprintf('  would remind %d Academic Executive(s) about #%d (%s), viva %s, reminder %d',
                    $recipients->count(), $nomination->application_id, $student?->name, $vivaAt->format('Y-m-d'), $sequence + 1));
                $sent++;

                continue;
            }

            try {
                ExaminerReminderLog::create([
                    'nomination_id' => $nomination->id,
                    'kind' => self::KIND_OVERDUE,
                    'sequence' => $sequence,
                    'sent_at' => now(),
                ]);
            } catch (QueryException) {
                $skipped++;

                continue;
            }

            $recipients->each(function (User $ae) use ($nomination, $vivaAt, $days) {
                try {
                    $ae->notify(new EvaluationOverdue($nomination, $vivaAt, $days));
                } catch (\Throwable $e) {
                    report($e);
                }
            });

            $sent++;
        }

        $stale = ExaminerImportBatch::stale()->count();

        if (! $dryRun) {
            ExaminerImportBatch::purgeStale();
        }

        $this->info($dryRun
            ? "Dry run: {$sent} reminder(s) would be sent; {$stale} stale import preview(s) would be purged."
            : "Sent {$sent} evaluation reminder(s); {$skipped} already sent; purged {$stale} stale import preview(s).");

        return self::SUCCESS;
    }

    /**
     * Released panels still holding someone, whose candidate's viva date has
     * passed.
     *
     * @return array<int, array{0: ExaminerNomination, 1: \Carbon\CarbonInterface}>
     */
    protected function openPanels(): array
    {
        $panels = ExaminerNomination::with(array_merge(ExaminerNomination::slotRelations(), ['application.student']))
            ->whereHas('application', fn ($q) => $q->where('module_type', 'examiner_nomination')
                ->where('status', Application::STATUS_APPROVED))
            ->get()
            ->filter(fn (ExaminerNomination $n) => $n->examiners()->contains(fn ($e) => $e->assigned_until !== null));

        $out = [];

        foreach ($panels as $nomination) {
            $vivaAt = VivaDetail::whereHas('application', fn ($q) => $q->where('student_id', $nomination->application->student_id)
                ->where('module_type', 'viva_voce'))
                ->whereNotNull('viva_at')
                ->latest('viva_at')
                ->value('viva_at');

            if ($vivaAt && \Carbon\Carbon::parse($vivaAt)->isPast()) {
                $out[] = [$nomination, \Carbon\Carbon::parse($vivaAt)];
            }
        }

        return $out;
    }
}
