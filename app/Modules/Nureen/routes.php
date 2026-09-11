<?php

use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Http\Controllers\AttendanceAppealController;
use App\Modules\Nureen\Http\Controllers\AttendanceController;
use App\Modules\Nureen\Http\Controllers\CertificationController;
use App\Modules\Nureen\Http\Controllers\GaExtensionController;
use App\Modules\Nureen\Http\Controllers\SupervisionController;
use Illuminate\Support\Facades\Route;

/*
| Nureen Nellysha (22006973)
| Attendance · GA Extension · Supervision · GA/GRA Certification Letter
*/

Route::middleware('auth')->group(function () {

    // ---- GA Extension -------------------------------------------------
    Route::middleware('role:'.Role::STUDENT)->group(function () {
        Route::get('/ga-extension/new', [GaExtensionController::class, 'create'])->name('ga-extension.create');
        Route::post('/ga-extension', [GaExtensionController::class, 'store'])->name('ga-extension.store');
    });

    Route::middleware('role:'.implode(',', [
        Role::SUPERVISOR, Role::NON_EXEC_CGS, Role::SENIOR_DIRECTOR_CGS,
    ]))->group(function () {
        Route::get('/ga-extension/queue', [GaExtensionController::class, 'queue'])->name('ga-extension.queue');
        Route::post('/ga-extension/{application}/decide', [GaExtensionController::class, 'decide'])->name('ga-extension.decide');
    });

    // ---- Supervision ----------------------------------------------------
    Route::middleware('role:'.Role::STUDENT)->group(function () {
        Route::get('/supervision/new', [SupervisionController::class, 'create'])->name('supervision.create');
        Route::post('/supervision', [SupervisionController::class, 'store'])->name('supervision.store');
    });

    Route::middleware('role:'.implode(',', [Role::SUPERVISOR, Role::NON_EXEC_CGS]))->group(function () {
        Route::get('/supervision/queue', [SupervisionController::class, 'queue'])->name('supervision.queue');
        Route::post('/supervision/{application}/decide', [SupervisionController::class, 'decide'])->name('supervision.decide');
    });

    // ---- GA/GRA Certification Letter ------------------------------------
    Route::middleware('role:'.Role::STUDENT)->group(function () {
        Route::get('/ga-certification/new', [CertificationController::class, 'create'])->name('ga-certification.create');
        Route::post('/ga-certification', [CertificationController::class, 'store'])->name('ga-certification.store');
    });

    Route::middleware('role:'.implode(',', [Role::NON_EXEC_CGS, Role::SENIOR_DIRECTOR_CGS]))->group(function () {
        Route::get('/ga-certification/queue', [CertificationController::class, 'queue'])->name('ga-certification.queue');
        Route::post('/ga-certification/{application}/decide', [CertificationController::class, 'decide'])->name('ga-certification.decide');
    });

    // ---- Attendance -------------------------------------------------------
    Route::middleware('role:'.Role::STUDENT)->group(function () {
        Route::get('/attendance/overview', [AttendanceController::class, 'overview'])->name('attendance.overview');
        Route::get('/attendance/history', [AttendanceController::class, 'history'])->name('attendance.history');

        Route::get('/attendance-appeal/new', [AttendanceAppealController::class, 'create'])->name('attendance-appeal.create');
        Route::post('/attendance-appeal', [AttendanceAppealController::class, 'store'])->name('attendance-appeal.store');
    });

    Route::middleware('role:'.Role::NON_EXEC_CGS)->group(function () {
        Route::get('/attendance/upload', [AttendanceController::class, 'uploadForm'])->name('attendance.upload.form');
        Route::post('/attendance/upload', [AttendanceController::class, 'upload'])->name('attendance.upload');
        Route::get('/attendance/at-risk', [AttendanceController::class, 'atRisk'])->name('attendance.at-risk');

        Route::get('/attendance-appeal/queue', [AttendanceAppealController::class, 'queue'])->name('attendance-appeal.queue');
        Route::post('/attendance-appeal/{application}/decide', [AttendanceAppealController::class, 'decide'])->name('attendance-appeal.decide');
    });

});
