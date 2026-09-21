<?php

namespace App\Modules\Chloe\Http\Controllers\Cgs;

use App\Modules\Chloe\Models\CandidacyDismissal;
use App\Modules\Chloe\Notifications\CandidacyDismissed;
use App\Modules\Chloe\Services\DismissalGenerator;
use App\Modules\Core\Http\Controllers\Controller;

/**
 * CGS reviews the generated list, then confirms only after Registry (outside
 * this system) has actually processed a dismissal — see
 * app/Modules/Chloe/README.md and Services\DismissalGenerator.
 */
class CandidacyDismissalController extends Controller
{
    public function index()
    {
        $pending = CandidacyDismissal::with('student', 'candidacy')
            ->where('status', CandidacyDismissal::STATUS_PENDING_REVIEW)
            ->oldest('generated_at')
            ->get();

        $confirmed = CandidacyDismissal::with('student', 'candidacy', 'confirmedBy')
            ->where('status', CandidacyDismissal::STATUS_CONFIRMED)
            ->latest('confirmed_at')
            ->limit(20)
            ->get();

        return view('chloe::candidacy.cgs.dismissals', compact('pending', 'confirmed'));
    }

    public function refresh(DismissalGenerator $generator)
    {
        $created = $generator->generate();

        return redirect()->route('candidacy.cgs.dismissals')
            ->with('status', $created === 0
                ? 'List is already up to date — no new candidates.'
                : "{$created} new candidate(s) added to the list.");
    }

    public function confirm(CandidacyDismissal $dismissal)
    {
        if ($dismissal->status === CandidacyDismissal::STATUS_CONFIRMED) {
            return redirect()->route('candidacy.cgs.dismissals')->with('error', 'That dismissal is already confirmed.');
        }

        $dismissal->update([
            'status' => CandidacyDismissal::STATUS_CONFIRMED,
            'confirmed_by_id' => auth()->id(),
            'confirmed_at' => now(),
        ]);

        $dismissal->candidacy->update([
            'status' => \App\Modules\Chloe\Models\StudyCandidacy::STATUS_DISMISSED,
            'dismissed_at' => now(),
            'dismissed_by_id' => auth()->id(),
        ]);

        $dismissal->student?->notify(new CandidacyDismissed($dismissal));

        return redirect()->route('candidacy.cgs.dismissals')
            ->with('status', "Dismissal confirmed for {$dismissal->student->name}.");
    }
}
