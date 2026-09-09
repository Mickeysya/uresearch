<?php

use App\Modules\Core\Support\Role;
use App\Modules\Hani\Http\Controllers\ExaminerNominationController;
use Illuminate\Support\Facades\Route;

/*
| Nur Hani Sofia (22001418)
| Examiner Nomination · Conflict Detection · Re-viva Monitoring
*/

Route::middleware('auth')->group(function () {

    // ---- Examiner Nomination ------------------------------------------
    Route::middleware('role:'.Role::SUPERVISOR)->group(function () {
        Route::get('/examiner-nomination/new', [ExaminerNominationController::class, 'create'])
            ->name('examiner-nomination.create');
        Route::post('/examiner-nomination', [ExaminerNominationController::class, 'store'])
            ->name('examiner-nomination.store');
    });

    Route::middleware('role:'.Role::ACADEMIC_EXEC)->group(function () {
        Route::get('/examiner-nomination/queue', [ExaminerNominationController::class, 'queue'])
            ->name('examiner-nomination.queue');
        Route::post('/examiner-nomination/{application}/decide', [ExaminerNominationController::class, 'decide'])
            ->name('examiner-nomination.decide');
    });

});
