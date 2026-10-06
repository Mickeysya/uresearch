<?php

use App\Modules\Core\Http\Controllers\AdminController;
use App\Modules\Core\Http\Controllers\ApplicationTrackingController;
use App\Modules\Core\Http\Controllers\DashboardController;
use App\Modules\Core\Http\Controllers\CalendarController;
use App\Modules\Core\Http\Controllers\DepartmentAdminController;
use App\Modules\Core\Http\Controllers\DocumentController;
use App\Modules\Core\Http\Controllers\DocumentLibraryController;
use App\Modules\Core\Http\Controllers\LoginController;
use App\Modules\Core\Http\Controllers\NotificationController;
use App\Modules\Core\Http\Controllers\PageController;
use App\Modules\Core\Http\Controllers\ProfileController;
use App\Modules\Core\Http\Controllers\QueueController;
use App\Modules\Core\Http\Controllers\UserAdminController;
use App\Modules\Core\Support\Role;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // Tracking is students-only; approvers see applications through their queues.
    Route::middleware('role:student')->group(function () {
        Route::get('/applications', [ApplicationTrackingController::class, 'index'])->name('applications.index');
        Route::get('/applications/{application}', [ApplicationTrackingController::class, 'show'])->name('applications.show');
    });

    // Deciding a page of any module's queue in one submission. Generic on
    // purpose -- the module is a route parameter, so this is one route rather
    // than one in each of the thirteen module route files, and a new module
    // gets it for free. Authorisation is not here: every row goes through
    // WorkflowEngine::decide(), which re-checks the actor's role against the
    // stage that row is on, so posting ids you do not own decides nothing.
    Route::post('/queue/{module}/decide', [QueueController::class, 'decideBulk'])
        ->name('queue.decide-bulk');

    Route::get('/documents/{document}', [DocumentController::class, 'show'])->name('documents.show');

    // The notification feed. Real, for every role -- see NotificationController.
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');

    // Every file this user may see, in one list. Reads application_documents
    // through the same visibility rule documents.show enforces.
    Route::get('/documents', [DocumentLibraryController::class, 'index'])->name('documents.index');

    // A month grid over dates the modules already own -- no events table.
    Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');

    // Sidebar destinations with no feature behind them yet -- see PageController.
    Route::get('/help', [PageController::class, 'show'])->name('help.index')->defaults('page', 'help');
    // The signed-in user's own record. Contact details and password are
    // separate routes on purpose — see ProfileController.
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::patch('/profile/contact', [ProfileController::class, 'updateContact'])->name('profile.contact');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
    // Gated to the roles the sidebar actually offers it to. Everything the
    // page describes (notification preferences, the attendance threshold,
    // reminder timings) is system-wide configuration, and it was reachable
    // by any signed-in account, including a student, purely because no
    // role middleware was on it.
    Route::middleware('role:'.implode(',', [...Role::cgsTeam(), Role::ADMIN]))
        ->get('/settings', [PageController::class, 'show'])->name('settings.index')->defaults('page', 'settings');

    // Administrator screens. Oversight is read-only by design: the admin owns
    // no workflow stage, so nothing here acts on an application.
    Route::middleware('role:'.Role::ADMIN)->prefix('admin')->name('admin.')->group(function () {
        Route::get('/students', [AdminController::class, 'students'])->name('students.index');
        Route::get('/applications', [AdminController::class, 'applications'])->name('applications.index');
        Route::get('/attendance', [AdminController::class, 'attendance'])->name('attendance.overview');
        Route::get('/attendance/at-risk', [AdminController::class, 'attendanceAtRisk'])->name('attendance.at-risk');
        Route::get('/reports', [AdminController::class, 'reports'])->name('reports.index');
        Route::get('/reports/approval-times', [AdminController::class, 'approvalTimes'])->name('reports.approval-times');
        Route::get('/reports/bottlenecks', [AdminController::class, 'bottlenecks'])->name('reports.bottlenecks');
        Route::get('/audit-logs', [AdminController::class, 'auditLogs'])->name('audit.index');
        Route::get('/documents', [AdminController::class, 'documents'])->name('documents.index');
    });

    // Departments and Users and Roles. Both live under /admin and the admin.*
    // route names rather than being duplicated under /cgs, since it is the
    // same screen either way; the role gate is what actually controls access,
    // and the sidebar link is placed for each role separately.
    //
    // Reading a list and changing what is on it are separate permissions here.
    // Non-Executive CGS reads the department list -- who covers each Academic
    // Executive desk is exactly what they need from it, see
    // Role::isDepartmentScoped() -- and nothing else on either screen.
    // Accounts and roles are the administrator's alone.
    Route::middleware('role:'.implode(',', [Role::ADMIN, Role::NON_EXEC_CGS]))
        ->prefix('admin')->name('admin.')->group(function () {
            Route::get('/departments', [DepartmentAdminController::class, 'index'])->name('departments.index');
        });

    Route::middleware('role:'.Role::ADMIN)->prefix('admin')->name('admin.')->group(function () {
        // Adding, renaming and retiring a department: a rename rewrites
        // users.department on every account filed under the old name.
        Route::get('/departments/create', [DepartmentAdminController::class, 'create'])->name('departments.create');
        Route::post('/departments', [DepartmentAdminController::class, 'store'])->name('departments.store');
        Route::get('/departments/{department}/edit', [DepartmentAdminController::class, 'edit'])->name('departments.edit');
        Route::put('/departments/{department}', [DepartmentAdminController::class, 'update'])->name('departments.update');
        Route::patch('/departments/{department}/toggle', [DepartmentAdminController::class, 'toggleActive'])->name('departments.toggle');

        // Accounts and roles. Nobody but the administrator, not even CGS:
        // this screen mints logins and hands out every role in the portal,
        // including the Academic Executive queues and the administrator role
        // itself. "We need another AE" is a request to the administrator now.
        Route::get('/users', [UserAdminController::class, 'index'])->name('users.index');
        Route::get('/users/create', [UserAdminController::class, 'create'])->name('users.create');
        Route::post('/users', [UserAdminController::class, 'store'])->name('users.store');
        Route::get('/users/{user}/edit', [UserAdminController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UserAdminController::class, 'update'])->name('users.update');
    });

    // CGS-only screens. Gated by role here as well as hidden from the sidebar,
    // because a sidebar that does not render a link is not access control.
    Route::middleware('role:'.implode(',', Role::cgsTeam()))->group(function () {
        Route::get('/cgs/attendance', [PageController::class, 'show'])->name('cgs.attendance.overview')->defaults('page', 'cgs-attendance');
        Route::get('/cgs/attendance/students', [PageController::class, 'show'])->name('cgs.attendance.students')->defaults('page', 'cgs-student-list');
        Route::get('/cgs/students', [PageController::class, 'show'])->name('cgs.students.index')->defaults('page', 'cgs-students');
        Route::get('/cgs/reports', [PageController::class, 'show'])->name('cgs.reports.index')->defaults('page', 'cgs-reports');
    });
});
