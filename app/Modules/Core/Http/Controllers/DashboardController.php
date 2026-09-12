<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Services\StudentDashboard;
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
            // Every figure on the student dashboard comes from here, one
            // named method per panel -- see Services\StudentDashboard.
            $dash = new StudentDashboard($user);

            return view('core::dashboard.student', [
                'attendance' => $dash->attendance(),
                'predicted' => $dash->predictedAttendance(),
                'activeCount' => $dash->activeApplications(),
                'unreadCount' => $dash->unreadNotifications(),
                'taskCount' => $dash->upcomingTaskCount(),
                'applications' => $dash->applications(),
                'notifications' => $dash->notifications(),
                'tasks' => $dash->tasks(),
                // Panels whose data could not be read; the view holds a
                // skeleton for these instead of showing a false empty state.
                'unavailable' => $dash->unavailable(),
            ]);
        }

        if ($user->isCgs()) {
            // CGS gets its own screen rather than the generic approver view.
            // Only the banner so far; the panels land here as they are built.
            return view('core::dashboard.cgs');
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
