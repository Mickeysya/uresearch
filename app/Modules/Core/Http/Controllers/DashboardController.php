<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\ModuleRegistry;
use Illuminate\Http\Request;

/**
 * Norhanis' Chart.js dashboard, generalised.
 *
 * Her original home.php hardcoded a role -> stage lookup table, so it could
 * only ever count her own three modules. This asks the registry which queues
 * the signed-in role owns, so a teammate's new module appears on the chart
 * the moment they register it.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, ModuleRegistry $registry)
    {
        $user = $request->user();

        if ($user->isStudent()) {
            return view('core::dashboard.student', [
                'open' => $user->applications()->where('status', Application::STATUS_PENDING)->count(),
                'approved' => $user->applications()->where('status', Application::STATUS_APPROVED)->count(),
                'rejected' => $user->applications()->where('status', Application::STATUS_REJECTED)->count(),
                'recent' => $user->applications()->latest('submitted_at')->limit(5)->get(),
            ]);
        }

        $queues = $registry->queuesForRole($user->role);

        $chart = [];
        $total = 0;

        foreach ($queues as $q) {
            $count = Application::query()
                ->where('module_type', $q['module']->key())
                ->where('current_stage', $q['stage']->key)
                ->where('status', Application::STATUS_PENDING)
                ->count();

            $chart[] = [
                'label' => $q['module']->label(),
                'count' => $count,
                'url' => route($q['module']->queueRoute(), ['stage' => $q['stage']->key]),
            ];

            $total += $count;
        }

        return view('core::dashboard.approver', [
            'chart' => $chart,
            'total' => $total,
            'recent' => Application::query()
                ->whereIn('module_type', array_map(fn ($q) => $q['module']->key(), $queues))
                ->with('student')
                ->latest('updated_at')
                ->limit(8)
                ->get(),
        ]);
    }
}
