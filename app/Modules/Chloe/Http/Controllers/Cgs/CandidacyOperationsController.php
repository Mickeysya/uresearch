<?php

namespace App\Modules\Chloe\Http\Controllers\Cgs;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Core\Http\Controllers\Controller;

/**
 * This module's three operational widgets (Pending Appeals / Appeal Status
 * Summary / Students Near Candidacy Expiry). Kept as its own screen rather
 * than injected into Core's CgsDashboard, which has no per-module widget
 * hook today — same call made for Workstation Management; see
 * app/Modules/Chloe/README.md.
 */
class CandidacyOperationsController extends Controller
{
    public function index()
    {
        // The appeal tables of this module are the single source of truth.
        $pendingAppeals = CandidacyAppeal::whereIn('status', array_values(CandidacyAppeal::STAGE_STATUS))
            ->with(['student', 'demoProfile'])
            ->orderByRaw('COALESCE(stage_entered_at, submitted_at) asc')
            ->get();

        $appealStatusSummary = [
            'Pending' => $pendingAppeals->count(),
            'Returned' => CandidacyAppeal::where('status', CandidacyAppeal::STATUS_RETURNED)->count(),
            'Approved' => CandidacyAppeal::where('status', CandidacyAppeal::STATUS_APPROVED)->count(),
            'Rejected' => CandidacyAppeal::where('status', CandidacyAppeal::STATUS_REJECTED)->count(),
        ];

        $nearExpiry = StudyCandidacy::where('status', StudyCandidacy::STATUS_ACTIVE)
            ->whereDate('candidacy_expiry_date', '<=', now()->addMonths(3))
            ->with('student')
            ->orderBy('candidacy_expiry_date')
            ->get();

        return view('chloe::candidacy.cgs.operations', compact('pendingAppeals', 'appealStatusSummary', 'nearExpiry'));
    }
}
