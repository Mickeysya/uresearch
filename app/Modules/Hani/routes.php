<?php

use App\Modules\Core\Support\Role;
use App\Modules\Hani\Http\Controllers\ConflictDetectionController;
use App\Modules\Hani\Http\Controllers\ExaminerAdminController;
use App\Modules\Hani\Http\Controllers\ExaminerNominationController;
use App\Modules\Hani\Http\Controllers\ReVivaController;
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

        // Closing the lifecycle: mark an approved nomination's evaluation done.
        Route::get('/examiner-nomination/pending-evaluation', [ExaminerNominationController::class, 'pendingEvaluation'])
            ->name('examiner-nomination.pending-evaluation');
        Route::post('/examiner-nomination/{nomination}/mark-complete', [ExaminerNominationController::class, 'markComplete'])
            ->name('examiner-nomination.mark-complete');

        // Touchpoint 2: cross-department conflict compilation.
        Route::get('/examiner-nomination/conflicts', [ConflictDetectionController::class, 'index'])
            ->name('conflict-detection.index');
    });

    // ---- Examiner pool admin -------------------------------------------
    Route::middleware('role:'.Role::NON_EXEC_CGS)->group(function () {
        Route::get('/examiners', [ExaminerAdminController::class, 'index'])->name('examiner-admin.index');
        Route::get('/examiners/new', [ExaminerAdminController::class, 'create'])->name('examiner-admin.create');
        Route::post('/examiners', [ExaminerAdminController::class, 'store'])->name('examiner-admin.store');
        Route::post('/examiners/{examiner}/toggle-active', [ExaminerAdminController::class, 'toggleActive'])
            ->name('examiner-admin.toggle-active');
    });

    // ---- Re-viva Monitoring --------------------------------------------
    // Logged by CGS Staff, not the student: the corrected thesis reaches CGS
    // through the same channel the rest of the re-viva process is informally
    // run through today, so CGS is the one who records the formal
    // resubmission timestamp -- not a self-service student upload.
    Route::middleware('role:'.Role::NON_EXEC_CGS)->group(function () {
        Route::get('/re-viva/new', [ReVivaController::class, 'create'])->name('reviva.create');
        Route::post('/re-viva', [ReVivaController::class, 'store'])->name('reviva.store');
    });

    Route::middleware('role:'.Role::ACADEMIC_EXEC)->group(function () {
        Route::get('/re-viva/queue', [ReVivaController::class, 'queue'])->name('reviva.queue');
        Route::post('/re-viva/{application}/decide', [ReVivaController::class, 'decide'])->name('reviva.decide');

        Route::get('/re-viva/outcomes', [ReVivaController::class, 'outcomesIndex'])->name('reviva.outcomes.index');
        Route::post('/re-viva/outcomes/{detail}', [ReVivaController::class, 'recordOutcome'])
            ->name('reviva.outcomes.record');
    });

});
