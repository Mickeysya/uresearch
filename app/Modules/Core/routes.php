<?php

use App\Modules\Core\Http\Controllers\ApplicationTrackingController;
use App\Modules\Core\Http\Controllers\DashboardController;
use App\Modules\Core\Http\Controllers\DocumentController;
use App\Modules\Core\Http\Controllers\LoginController;
use App\Modules\Core\Http\Controllers\PageController;
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

    // Sidebar destinations with no feature behind them yet -- see PageController.
    Route::get('/notifications', [PageController::class, 'notifications'])->name('notifications.index');
    Route::get('/documents', [PageController::class, 'documents'])->name('documents.index');
    Route::get('/calendar', [PageController::class, 'calendar'])->name('calendar.index');
    Route::get('/help', [PageController::class, 'help'])->name('help.index');
    Route::get('/profile', [PageController::class, 'profile'])->name('profile.show');
});
