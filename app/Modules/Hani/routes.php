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

    // The chain above the department. Every stage from the Academic Executive
    // up reads the same queue screen and posts to the same decide route --
    // WorkflowEngine decides what each of them is allowed to do with it, so
    // one route per action is enough for all six stages. See
    // ExaminerNominationWorkflow for the chain itself.
    Route::middleware('role:'.implode(',', [
        Role::ACADEMIC_EXEC, Role::SENIOR_EXEC_CGS, Role::SENIOR_DIRECTOR_CGS,
        Role::DEAN_PGR, Role::NON_EXEC_CGS,
    ]))->group(function () {
        Route::get('/examiner-nomination/queue', [ExaminerNominationController::class, 'queue'])
            ->name('examiner-nomination.queue');
        Route::post('/examiner-nomination/{application}/decide', [ExaminerNominationController::class, 'decide'])
            ->name('examiner-nomination.decide');

        // Sends a list back to the department instead of ending it. The route
        // is open to the whole chain and the engine refuses it anywhere but
        // the Senior Director's and the Dean's stage -- authorisation belongs
        // to WorkflowEngine::returnTo(), not to a middleware list.
        Route::post('/examiner-nomination/{application}/return', [ExaminerNominationController::class, 'returnToDepartment'])
            ->name('examiner-nomination.return');

        // The compiled list, and the same rows as a file.
        Route::get('/examiner-nomination/report', [ExaminerNominationController::class, 'report'])
            ->name('examiner-nomination.report');
        Route::get('/examiner-nomination/report/export', [ExaminerNominationController::class, 'export'])
            ->name('examiner-nomination.export');
    });

    Route::middleware('role:'.Role::ACADEMIC_EXEC)->group(function () {

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
