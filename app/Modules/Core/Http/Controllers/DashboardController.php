<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\AdminDashboard;
use App\Modules\Core\Services\CgsDashboard;
use App\Modules\Core\Services\ChairDashboard;
use App\Modules\Core\Services\GeneralApproverDashboard;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Services\StudentDashboard;
use App\Modules\Core\Services\SupervisorDashboard;
use App\Modules\Core\Support\Role;
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
            $dash = new StudentDashboard($user, $registry);

            return view('core::dashboard.student', [
                'bannerSubtitle' => $dash->bannerSubtitle(),
                'today' => $dash->todayCard(),
                'dueTodayCount' => $dash->dueTodayCount(),
                'candidacy' => $dash->candidacyCard(),
                'viva' => $dash->vivaCard(),
                'allowance' => $dash->allowanceCard(),
                'quickLinks' => $dash->quickLinks(),
                'drafts' => $dash->drafts(),
                'attendance' => $dash->attendance(),
                'attendanceAction' => $dash->attendanceAction(),
                'activeCount' => $dash->activeApplications(),
                'nextDecision' => $dash->nextDecisionDate(),
                'unreadCount' => $dash->unreadNotifications(),
                'taskCount' => $dash->upcomingTaskCount(),
                // My Application Status and Recent Notifications are
                // capped panels, not full lists -- "View All" is the way
                // out, not a scrollbar.
                'applications' => $dash->applications(3),
                'notifications' => $dash->notifications(5),
                'tasks' => $dash->tasks(),
                'postgraduate' => $dash->postgraduateProgress(),
                // Panels whose data could not be read; the view holds a
                // skeleton for these instead of showing a false empty state.
                'unavailable' => $dash->unavailable(),
            ]);
        }

        if ($user->isAdmin()) {
            $dash = new AdminDashboard;

            return view('core::dashboard.admin', [
                'totalStudents' => $dash->totalStudents(),
                'programmes' => $dash->programmes(),
                'staffMembers' => $dash->staffMembers(),
                'averageAttendance' => $dash->averageAttendance(),
                'activeApplications' => $dash->activeApplications(),
                'activeCandidatures' => $dash->activeCandidatures(),
                'terminatedCandidatures' => $dash->terminatedCandidatures(),
                'activities' => $dash->recentActivities(),
                'health' => $dash->systemHealth(),
                'mix' => $dash->applicationMix(),
                'byStatus' => $dash->applicationsByStatus(),
                'unavailable' => $dash->unavailable(),
            ]);
        }

        if ($user->isCgs()) {
            // CGS gets its own screen rather than the generic approver view.
            $dash = new CgsDashboard($user);

            return view('core::dashboard.cgs', [
                'totalApplications' => $dash->totalApplications(),
                'pendingMyAction' => $dash->pendingMyAction(),
                'assignedToMe' => $dash->assignedToMe(),
                'approvedCount' => $dash->approved(),
                'rejectedCount' => $dash->rejected(),
                'activeStudents' => $dash->activeStudents(),
                'stageGateBottleneck' => $dash->stageGateBottleneck(),
                'allowanceAnomalies' => $dash->allowanceAnomalies(),
                'workload' => $dash->workload(),
                'pendingActions' => $dash->pendingActions(),
                'attendance' => $dash->attendanceAlerts(),
                'activities' => $dash->recentActivities(),
                'unavailable' => $dash->unavailable(),
            ]);
        }

        if ($user->role === Role::CHAIR) {
            // A Chair owns five stages, and the generic approver view below
            // renders one stat card per stage -- six cards, five of them
            // normally zero, over a chart of five categories with one bar in
            // it. See Services\ChairDashboard for what replaced it.
            $dash = new ChairDashboard($user, $registry);

            return view('core::dashboard.chair', [
                'queues' => $dash->queues(),
                'ageing' => $dash->ageingProfile(),
                'overdue' => $dash->overdueCount(),
                'awaitingMe' => $dash->awaitingMe(),
                'longestWait' => $dash->longestWait(),
                'decided' => $dash->decidedRecently(),
                'busiestRoute' => $dash->busiestQueueRoute(),
                'longestWaitRoute' => $dash->longestWaitRoute(),
                'oldest' => $dash->oldestWaiting(),
                'decisions' => $dash->myRecentDecisions(),
                'alerts' => $dash->alerts(),
                // The same module-declared links the sidebar builds from, so
                // a teammate's new Chair-facing screen appears here too.
                'shortcuts' => $registry->linksFor($user),
                'unavailable' => $dash->unavailable(),
            ]);
        }

        if ($user->role === Role::SUPERVISOR) {
            // Seven stages, so the generic view below would render seven stat
            // cards mostly reading zero -- and none of them would answer the
            // question a supervisor actually opens the portal with, which is
            // whether one of their candidates is in trouble.
            $dash = new SupervisorDashboard($user, $registry);

            return view('core::dashboard.supervisor', [
                'queues' => $dash->queues(),
                'ageing' => $dash->ageingProfile(),
                'overdue' => $dash->overdueCount(),
                'awaitingMe' => $dash->awaitingMe(),
                'longestWait' => $dash->longestWait(),
                'busiestRoute' => $dash->busiestQueueRoute(),
                'longestWaitRoute' => $dash->longestWaitRoute(),
                'candidates' => $dash->candidates(),
                'candidateCount' => $dash->candidateCount(),
                'atRisk' => $dash->atRiskCount(),
                'attendanceSpread' => $dash->attendanceSpread(),
                'oldest' => $dash->oldestWaiting(),
                'alerts' => $dash->alerts(),
                'shortcuts' => $registry->linksFor($user),
                'unavailable' => $dash->unavailable(),
            ]);
        }

        // Every other approving role: the Dean of PGR, the Academic
        // Executive, the Registry, the Faculty office, the Senior Executive.
        // One screen, because what each of them owns is a set of stages and
        // ApproverDashboard derives the whole dashboard from that.
        $dash = new GeneralApproverDashboard($user, $registry);

        return view('core::dashboard.approver', [
            'queues' => $dash->queues(),
            'ageing' => $dash->ageingProfile(),
            'overdue' => $dash->overdueCount(),
            'awaitingMe' => $dash->awaitingMe(),
            'longestWait' => $dash->longestWait(),
            'decided' => $dash->decidedRecently(),
            'finalised' => $dash->finalisedByMe(),
            'busiestRoute' => $dash->busiestQueueRoute(),
            'longestWaitRoute' => $dash->longestWaitRoute(),
            'oldest' => $dash->oldestWaiting(),
            'decisions' => $dash->myRecentDecisions(),
            'alerts' => $dash->alerts(),
            'shortcuts' => $registry->linksFor($user),
            'unavailable' => $dash->unavailable(),
        ]);
    }
}
