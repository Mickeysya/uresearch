<?php

namespace App\Modules\Norhanis\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Norhanis\Models\Candidacy;
use App\Modules\Norhanis\Models\RpdReminderLog;
use App\Modules\Norhanis\Services\RpdReminderSender;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Read-only visibility into the 3/2/1-month RPD reminders, plus a manual
 * "Send Now" fallback for a failed automatic send or an early nudge.
 *
 * Sending goes through RpdReminderSender::sendIfDue(), the same function
 * the scheduled rpd:remind scan calls, so the due-check, the "already sent"
 * check and the log write live in exactly one place. Never creates an
 * Application row and never touches candidacies.status.
 */
class UpcomingRpdRemindersController extends Controller
{
    /**
     * One row per (candidacy, month-mark) currently inside its reminder
     * window -- a candidacy no automatic scan has caught up with yet can
     * appear more than once, same as RemindRpdCandidates would send more
     * than one reminder for it in a single run.
     */
    public function index()
    {
        $rows = $this->upcomingRows();

        return view('norhanis::rpd_reminders.index', ['rows' => $rows]);
    }

    public function send(Request $request, Candidacy $candidacy, RpdReminderSender $sender): RedirectResponse
    {
        $data = $request->validate([
            'month_mark' => ['required', 'integer', 'in:3,2,1'],
        ]);

        abort_unless($candidacy->status === Candidacy::STATUS_ACTIVE, 404);

        return match ($sender->sendIfDue($candidacy, (int) $data['month_mark'])) {
            RpdReminderSender::SENT => back()->with('status', "Sent the {$data['month_mark']}-month reminder to {$candidacy->student->name}."),
            RpdReminderSender::ALREADY_SENT => back()->with('status', 'That reminder was already sent -- nothing to do.'),
            RpdReminderSender::NOT_DUE => abort(404, 'That reminder is not due yet.'),
            RpdReminderSender::NO_STUDENT => back()->with('error', 'This candidacy has no student on record.'),
        };
    }

    /** @return Collection<int, array{candidacy: Candidacy, month_mark: int, sent_at: ?\Illuminate\Support\Carbon}> */
    protected function upcomingRows(): Collection
    {
        $candidacies = Candidacy::where('status', Candidacy::STATUS_ACTIVE)
            ->with('student')
            ->orderBy('deadline')
            ->get();

        $sentLogs = RpdReminderLog::whereIn('candidacy_id', $candidacies->pluck('id'))
            ->get()
            ->groupBy('candidacy_id');

        $rows = collect();

        foreach ($candidacies as $candidacy) {
            foreach (Candidacy::REMINDER_MONTH_MARKS as $mark) {
                if (now()->lt($candidacy->reminderWindowOpensAt($mark))) {
                    continue; // Not due yet, same test rpd:remind uses.
                }

                $sentLog = ($sentLogs->get($candidacy->id) ?? collect())
                    ->firstWhere('month_mark', $mark);

                $rows->push([
                    'candidacy' => $candidacy,
                    'month_mark' => $mark,
                    'sent_at' => $sentLog?->sent_at,
                ]);
            }
        }

        return $rows->sortBy('month_mark')->values();
    }
}
