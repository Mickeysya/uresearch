<?php

namespace App\Modules\Chloe\Http\Controllers\Cgs;

use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;

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
        $pendingAppeals = Application::where('module_type', 'candidacy_appeal')
            ->where('status', Application::STATUS_PENDING)
            ->with('student')
            ->latest('submitted_at')
            ->get();

        $appealStatusSummary = [
            'Pending' => Application::where('module_type', 'candidacy_appeal')->where('status', Application::STATUS_PENDING)->count(),
            'Returned' => Application::where('module_type', 'candidacy_appeal')->where('status', Application::STATUS_RETURNED)->count(),
            'Approved' => Application::where('module_type', 'candidacy_appeal')->where('status', Application::STATUS_APPROVED)->count(),
            'Rejected' => Application::where('module_type', 'candidacy_appeal')->where('status', Application::STATUS_REJECTED)->count(),
        ];

        $nearExpiry = StudyCandidacy::where('status', StudyCandidacy::STATUS_ACTIVE)
            ->whereDate('candidacy_expiry_date', '<=', now()->addMonths(3))
            ->with('student')
            ->orderBy('candidacy_expiry_date')
            ->get();

        return view('chloe::candidacy.cgs.operations', compact('pendingAppeals', 'appealStatusSummary', 'nearExpiry'));
    }
}
