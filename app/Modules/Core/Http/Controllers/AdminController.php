<?php

namespace App\Modules\Core\Http\Controllers;

use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

/**
 * The system administrator's screens.
 *
 * Scope comes from `docs/scope/technical.md` ("Manage user roles, system
 * health, and global configurations") and `docs/scope/jason.md` §1.4, §1.5 and
 * §5.5 (audit log viewer, secure file vault, system analytics).
 *
 * TWO DELIBERATE DIFFERENCES FROM Sample/ADMIN_DASHBOARD.png:
 *
 *   - **No Courses screen.** "Course" does not appear in any of the six scope
 *     documents, there is no `courses` table, and `users.programme` is a free
 *     text string. The mockup's course figures have nothing behind them.
 *   - **No separate Faculty screen.** Everywhere the scope documents say
 *     "Faculty" they mean an approver role (Jason's Faculty Academic /
 *     Faculty Department, Norhanis' dismissal chain) or the FOE/FSMC attribute
 *     on a user — never a staff directory. Staff accounts belong under
 *     Users & Roles.
 *
 * Oversight screens here are READ-ONLY on purpose. The administrator owns no
 * workflow stage (`queuesForRole('admin')` is empty by design), so anything
 * that acts on an application belongs to CGS, not here.
 *
 * Every method renders the shared placeholder until its screen is built, and
 * each description names exactly what is missing.
 */
class AdminController extends Controller
{
    protected function page(string $title, string $description)
    {
        return view('core::pages.placeholder', compact('title', 'description'));
    }

    public function students()
    {
        return $this->page('Students',
            'A read-only roster of every postgraduate — programme, department, supervisor, '
            .'candidacy status and application history. Read-only on purpose: the administrator '
            .'oversees, CGS acts. Account creation and role changes live under Users and Roles.');
    }

    public function applications(Request $request)
    {
        $status = $request->query('status');

        $label = match ($status) {
            'pending' => 'Pending Applications',
            'approved' => 'Approved Applications',
            'rejected' => 'Rejected Applications',
            default => 'All Applications',
        };

        return $this->page($label,
            'Every application across every module, filterable by status and module, for oversight only. '
            .'The data is already there — `applications` plus each module\'s detail table — so this is a '
            .'listing screen, not new machinery. Deciding on an application stays with the role that owns '
            .'its current stage.');
    }

    public function attendance()
    {
        return $this->page('Attendance Monitoring',
            'A read-only, institution-wide view of attendance: the distribution against the 80% '
            .'threshold and period-on-period movement. The per-student figures exist in '
            .'`attendance_records` today. Uploading data and acting on appeals remain with CGS.');
    }

    public function attendanceAtRisk()
    {
        return $this->page('At-Risk Students',
            'Every student currently flagged at-risk, across all departments, read-only. '
            .'CGS has the actionable version of this list under Attendance Alerts.');
    }

    public function reports()
    {
        return $this->page('Reports and Analytics',
            'System-level analytics, as scoped in jason.md §5.5: total submissions, average approval '
            .'times and where applications are backing up. Distinct from the CGS dashboard, which shows '
            .'one desk\'s operational workload rather than the institution\'s.');
    }

    public function approvalTimes()
    {
        return $this->page('Approval Times',
            'How long each stage takes, per module and per approver. Computable today from '
            .'`approval_history` — every decision already carries who made it and when — so this '
            .'needs a query and a chart, not a schema change.');
    }

    public function bottlenecks()
    {
        return $this->page('Bottlenecks',
            'Where applications are piling up: which stage, which module, and how long the oldest '
            .'has been waiting. Norhanis\' scope describes this same view ("stuck at Chair vs Dean"), '
            .'so agree ownership before building it.');
    }

    public function users()
    {
        return $this->page('Users and Roles',
            'Create and edit accounts and assign roles — the administrator\'s core job per '
            .'technical.md and jason.md §5.5, and the one piece of this dashboard nothing else covers. '
            .'Roles can currently only be set in the seeder or phpMyAdmin. The 13 roles are listed in '
            .'App\\Modules\\Core\\Support\\Role; staff accounts are filtered from here rather than '
            .'getting a separate Faculty screen.');
    }

    /**
     * The audit log, backed by spatie/laravel-activitylog.
     *
     * Closes what docs/scope/jason.md §1.4 asks for and `approval_history`
     * could not: that table records decisions only, so a document *view* or
     * *upload* left no trace. Those are now logged from DocumentController,
     * DocumentStore, WorkflowEngine and LoginController.
     */
    public function auditLogs(Request $request)
    {
        $log = $request->query('log');

        $activities = Activity::with('causer')
            ->when($log, fn ($q) => $q->where('log_name', $log))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('core::admin.audit', [
            'activities' => $activities,
            'log' => $log,
            'logNames' => Activity::select('log_name')
                ->distinct()->orderBy('log_name')->pluck('log_name')->filter()->values(),
            'total' => Activity::count(),
        ]);
    }

    public function documents()
    {
        return $this->page('Document Repository',
            'The central file vault from jason.md §1.5 — every uploaded and generated document with '
            .'version history. Files already go through `DocumentStore` onto the private disk and are '
            .'streamed back through an authorised controller, so the storage half exists; what is '
            .'missing is the browse-and-search screen and version tracking.');
    }
}
