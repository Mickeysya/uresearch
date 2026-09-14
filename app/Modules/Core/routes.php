<?php

use App\Modules\Core\Http\Controllers\AdminController;
use App\Modules\Core\Http\Controllers\ApplicationTrackingController;
use App\Modules\Core\Http\Controllers\DashboardController;
use App\Modules\Core\Http\Controllers\DocumentController;
use App\Modules\Core\Http\Controllers\LoginController;
use App\Modules\Core\Http\Controllers\NotificationController;
use App\Modules\Core\Http\Controllers\PageController;
use App\Modules\Core\Http\Controllers\ProfileController;
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

    Route::get('/documents/{document}', [DocumentController::class, 'show'])->name('documents.show');

    // The notification feed. Real, for every role -- see NotificationController.
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');

    // Sidebar destinations with no feature behind them yet -- see PageController.
    Route::get('/documents', [PageController::class, 'documents'])->name('documents.index');
    Route::get('/calendar', [PageController::class, 'calendar'])->name('calendar.index');
    Route::get('/help', [PageController::class, 'help'])->name('help.index');
    // The signed-in user's own record. Contact details and password are
    // separate routes on purpose — see ProfileController.
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::patch('/profile/contact', [ProfileController::class, 'updateContact'])->name('profile.contact');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
    Route::get('/settings', [PageController::class, 'settings'])->name('settings.index');

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
        Route::get('/users', [AdminController::class, 'users'])->name('users.index');
        Route::get('/audit-logs', [AdminController::class, 'auditLogs'])->name('audit.index');
        Route::get('/documents', [AdminController::class, 'documents'])->name('documents.index');
    });

    // CGS-only screens. Gated by role here as well as hidden from the sidebar,
    // because a sidebar that does not render a link is not access control.
    Route::middleware('role:'.implode(',', Role::cgsTeam()))->group(function () {
        Route::get('/cgs/attendance', [PageController::class, 'cgsAttendanceOverview'])->name('cgs.attendance.overview');
        Route::get('/cgs/attendance/students', [PageController::class, 'cgsStudentList'])->name('cgs.attendance.students');
        Route::get('/cgs/students', [PageController::class, 'cgsStudents'])->name('cgs.students.index');
        Route::get('/cgs/reports', [PageController::class, 'cgsReports'])->name('cgs.reports.index');
    });
});
