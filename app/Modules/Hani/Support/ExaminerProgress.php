<?php

namespace App\Modules\Hani\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Hani\Models\ExaminerNomination;
use App\Modules\Hani\Models\VivaExaminerProgress;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the examiner-progress rows in step with the CURRENT panel.
 *
 * The seat columns on examiner_nominations are the truth about who sits on
 * the panel (PanelSeats updates them after a decline). sync() makes sure
 * there is a live row for whoever holds each main seat now, and marks the
 * row of anyone who no longer does as superseded -- kept, never deleted.
 */
class ExaminerProgress
{
    /**
     * @return Collection<string, VivaExaminerProgress> the current rows, keyed by seat
     */
    public function sync(Application $notice, ExaminerNomination $panel): Collection
    {
        return DB::transaction(function () use ($notice, $panel) {
            $rows = VivaExaminerProgress::with('examiner')
                ->where('viva_application_id', $notice->id)
                ->where('nomination_id', $panel->id)
                ->lockForUpdate()
                ->get();

            $current = collect();
            $dispatch = $this->dispatch();

            foreach (array_keys(VivaExaminerProgress::SEATS) as $seat) {
                $examinerId = $panel->{$seat};

                foreach ($rows->where('seat', $seat)->whereNull('superseded_at') as $row) {
                    if ($row->examiner_id !== $examinerId) {
                        $row->update(['superseded_at' => now()]);
                    }
                }

                if (! $examinerId) {
                    continue;
                }

                $row = $rows->first(fn ($r) => $r->seat === $seat && $r->examiner_id === $examinerId)
                    ?? VivaExaminerProgress::create([
                        'viva_application_id' => $notice->id,
                        'nomination_id' => $panel->id,
                        'seat' => $seat,
                        'examiner_id' => $examinerId,
                    ]);

                if ($row->superseded_at !== null) {
                    // Back in the seat after all (a replacement undone by hand).
                    $row->update(['superseded_at' => null]);
                }

                // The letter date, from Jason's module when it can tell us.
                if ($row->letter_sent_at === null && $dispatch && ($sent = $dispatch->packSentAt($panel->application_id, $examinerId))) {
                    $row->update(['letter_sent_at' => $sent, 'letter_source' => 'dispatch']);
                }

                $current->put($seat, $row->load('examiner'));
            }

            return $current;
        });
    }

    /** Superseded rows (declined examiners), oldest first, for the history list. */
    public function history(Application $notice): Collection
    {
        return VivaExaminerProgress::with('examiner')
            ->where('viva_application_id', $notice->id)
            ->whereNotNull('superseded_at')
            ->orderBy('superseded_at')
            ->get();
    }

    /** Is the appointment-letter dispatch contract available? (SHARED_CHANGES.md, section 1b.) */
    public function dispatchAvailable(): bool
    {
        return $this->dispatch() !== null;
    }

    protected function dispatch(): ?object
    {
        $contract = 'App\\Modules\\Core\\Contracts\\SuppliesAppointmentDispatch';

        return interface_exists($contract) && app()->bound($contract) ? app($contract) : null;
    }
}
