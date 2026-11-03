<?php

use App\Modules\Core\Support\Role;
use App\Modules\Hani\Http\Controllers\ConflictDetectionController;
use App\Modules\Hani\Http\Controllers\ExaminerAdminController;
use App\Modules\Hani\Http\Controllers\ExaminerNominationController;
use App\Modules\Hani\Http\Controllers\ReVivaController;
use App\Modules\Hani\Http\Controllers\VivaController;
use App\Modules\Hani\Http\Controllers\VivaScheduleController;
use App\Modules\Hani\Http\Controllers\VivaSoftboundController;
use Illuminate\Support\Facades\Route;

/*
| Nur Hani Sofia (22001418)
| Viva Voce · Examiner Nomination · Conflict Detection · Re-viva Monitoring
*/

Route::middleware('auth')->group(function () {

    // ---- Viva Voce Assessment -------------------------------------------
    // The checklist gates the notice. VivaController::noticeGuard() enforces
    // it on both notice routes, whatever the buttons on the page say.
    Route::middleware('role:'.Role::STUDENT)->group(function () {
        Route::get('/viva/eligibility', [VivaController::class, 'eligibility'])->name('viva.eligibility');
        Route::post('/viva/eligibility', [VivaController::class, 'saveEligibility'])->name('viva.eligibility.save');
        Route::get('/viva/notice', [VivaController::class, 'notice'])->name('viva.notice');
        Route::post('/viva/notice', [VivaController::class, 'storeNotice'])->name('viva.notice.store');

        // Softbound thesis (Form #019). Only once the notice is acknowledged;
        // VivaProgress::softboundBlockReason() says why not otherwise.
        Route::get('/viva/softbound', [VivaSoftboundController::class, 'create'])->name('viva.softbound.create');
        Route::post('/viva/softbound', [VivaSoftboundController::class, 'store'])->name('viva.softbound.store');
    });

    Route::middleware('role:'.Role::ACADEMIC_EXEC)->group(function () {
        Route::get('/viva/queue', [VivaController::class, 'queue'])->name('viva.queue');
        Route::post('/viva/{application}/decide', [VivaController::class, 'decide'])->name('viva.decide');

        Route::get('/viva/softbound/queue', [VivaSoftboundController::class, 'queue'])->name('viva.softbound.queue');
        Route::post('/viva/softbound/{application}/decide', [VivaSoftboundController::class, 'decide'])
            ->name('viva.softbound.decide');

        // Setting the date (step 8). Off-chain: it changes no status.
        Route::get('/viva/scheduling', [VivaScheduleController::class, 'index'])->name('viva.schedule.index');
        Route::get('/viva/{application}/schedule', [VivaScheduleController::class, 'edit'])->name('viva.schedule.edit');
        Route::post('/viva/{application}/schedule', [VivaScheduleController::class, 'update'])->name('viva.schedule.update');
    });

    // Supervisor: their own candidates' vivas, read only.
    Route::middleware('role:'.Role::SUPERVISOR)->group(function () {
        Route::get('/viva/my-students', [VivaController::class, 'supervisees'])->name('viva.supervisees');
    });

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
    // Non-Executive CGS owns no stage here since 2026-11-03 (the Senior
    // Executive releases the list), so is not on the queue routes; they keep
    // the compiled list below, read-only.
    Route::middleware('role:'.implode(',', [
        Role::ACADEMIC_EXEC, Role::SENIOR_EXEC_CGS, Role::SENIOR_DIRECTOR_CGS,
        Role::DEAN_PGR,
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
    });

    Route::middleware('role:'.implode(',', [
        Role::ACADEMIC_EXEC, Role::SENIOR_EXEC_CGS, Role::SENIOR_DIRECTOR_CGS,
        Role::DEAN_PGR, Role::NON_EXEC_CGS,
    ]))->group(function () {
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
    });

    // Touchpoint 2: cross-department conflict compilation. The Academic
    // Executive for their department; the Senior Executive across all of
    // them, since cgs_compile is where the department lists are merged.
    Route::middleware('role:'.Role::ACADEMIC_EXEC.','.Role::SENIOR_EXEC_CGS)->group(function () {
        Route::get('/examiner-nomination/conflicts', [ConflictDetectionController::class, 'index'])
            ->name('conflict-detection.index');
    });

    // ---- Examiner pool admin -------------------------------------------
    // Senior Executive CGS (Puan Waheeda) since 2026-11-03. Non-Executive CGS
    // has no write access to the pool.
    Route::middleware('role:'.Role::SENIOR_EXEC_CGS)->group(function () {
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
