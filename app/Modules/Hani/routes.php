<?php

use App\Modules\Core\Support\Role;
use App\Modules\Hani\Http\Controllers\ConflictDetectionController;
use App\Modules\Hani\Http\Controllers\ExaminerDashboardController;
use App\Modules\Hani\Http\Controllers\ExaminerAdminController;
use App\Modules\Hani\Http\Controllers\ExaminerContactController;
use App\Modules\Hani\Http\Controllers\ExaminerImportController;
use App\Modules\Hani\Http\Controllers\ExaminerNominationController;
use App\Modules\Hani\Http\Controllers\ExaminerSuggestionController;
use App\Modules\Hani\Http\Controllers\PanelController;
use App\Modules\Hani\Http\Controllers\ReVivaController;
use App\Modules\Hani\Http\Controllers\ReVivaCyclesController;
use App\Modules\Hani\Http\Controllers\VivaController;
use App\Modules\Hani\Http\Controllers\VivaExaminerController;
use App\Modules\Hani\Http\Controllers\VivaResultController;
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
        // Reads the chosen Turnitin report to pre-fill the similarity index. Stores nothing.
        // Step 5: the corrected final thesis (result categories 1-3).
        Route::get('/viva/corrections', [VivaResultController::class, 'corrections'])->name('viva.corrections.create');
        Route::post('/viva/corrections', [VivaResultController::class, 'submitCorrections'])->name('viva.corrections.store');

        Route::post('/viva/softbound/similarity-preview', [VivaSoftboundController::class, 'similarityPreview'])
            ->middleware('throttle:20,1')
            ->name('viva.softbound.similarity-preview');
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

        // Step 5: result and corrections. Module-side state, not a stage.
        Route::get('/viva/results', [VivaResultController::class, 'index'])->name('viva.results.index');
        Route::post('/viva/{application}/result', [VivaResultController::class, 'record'])->name('viva.results.record');
        Route::post('/viva/{application}/corrections/accept', [VivaResultController::class, 'accept'])->name('viva.results.accept');
    });

    // Examiner progress per viva: letter, acceptance, thesis, report. The
    // candidate's own AE or the Senior Executive (the controller checks).
    Route::middleware('role:'.Role::ACADEMIC_EXEC.','.Role::SENIOR_EXEC_CGS)->group(function () {
        Route::get('/viva/{application}/examiners', [VivaExaminerController::class, 'show'])->name('viva.examiners.show');
        Route::post('/viva/{application}/examiners/{row}', [VivaExaminerController::class, 'update'])->name('viva.examiners.update');
        Route::get('/viva/{application}/examiners/{row}/report', [VivaExaminerController::class, 'report'])->name('viva.examiners.report');
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

    // Suggested examiners (JSON). Staff only -- the controller narrows a
    // supervisor to their own candidates and an AE to their department.
    Route::middleware('role:'.implode(',', [Role::SUPERVISOR, Role::ACADEMIC_EXEC, Role::SENIOR_EXEC_CGS]))
        ->get('/examiner-nomination/suggestions', ExaminerSuggestionController::class)
        ->name('examiner-nomination.suggestions');

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

    // A released panel: declines, backup promotion, replacements. The
    // controller narrows an AE to their own department's panels.
    Route::middleware('role:'.Role::ACADEMIC_EXEC.','.Role::SENIOR_EXEC_CGS)->group(function () {
        Route::get('/examiner-nomination/panels/{nomination}', [PanelController::class, 'show'])->name('examiner-panel.show');
        Route::post('/examiner-nomination/panels/{nomination}/decline', [PanelController::class, 'decline'])->name('examiner-panel.decline');
        Route::post('/examiner-nomination/panels/{nomination}/replace', [PanelController::class, 'replace'])->name('examiner-panel.replace');
    });

    // Touchpoint 2: cross-department conflict compilation. The Academic
    // Executive for their department; the Senior Executive across all of
    // them, since cgs_compile is where the department lists are merged.
    Route::middleware('role:'.Role::ACADEMIC_EXEC.','.Role::SENIOR_EXEC_CGS)->group(function () {
        Route::get('/examiner-nomination/conflicts', [ConflictDetectionController::class, 'index'])
            ->name('conflict-detection.index');
    });

    // ---- Examiner availability dashboard --------------------------------
    // The AE sees their own department (the dashboard scopes itself).
    Route::middleware('role:'.implode(',', [
        Role::ACADEMIC_EXEC, Role::SENIOR_EXEC_CGS, Role::SENIOR_DIRECTOR_CGS, Role::DEAN_PGR, Role::NON_EXEC_CGS,
    ]))->get('/examiner-dashboard', ExaminerDashboardController::class)->name('examiner-dashboard');

    // ---- Examiner pool admin -------------------------------------------
    // Senior Executive CGS (Puan Waheeda) since 2026-11-03. Non-Executive CGS
    // (Encik Syahmi, who sends the letters) reads the pool and may keep an
    // examiner's EMAIL current, nothing else (2026-11-03).
    Route::middleware('role:'.Role::SENIOR_EXEC_CGS.','.Role::NON_EXEC_CGS)->group(function () {
        Route::get('/examiners', [ExaminerAdminController::class, 'index'])->name('examiner-admin.index');
        Route::get('/examiners/{examiner}/email', [ExaminerContactController::class, 'editEmail'])->whereNumber('examiner')->name('examiner-admin.email.edit');
        Route::put('/examiners/{examiner}/email', [ExaminerContactController::class, 'updateEmail'])->whereNumber('examiner')->name('examiner-admin.email.update');
        Route::post('/examiners/{examiner}/confirm-email', [ExaminerContactController::class, 'confirmEmail'])->whereNumber('examiner')->name('examiner-admin.email.confirm');
        Route::post('/examiners/proposed-emails/{proposal}/confirm', [ExaminerContactController::class, 'confirmProposal'])->name('examiner-admin.proposal.confirm');
        Route::post('/examiners/proposed-emails/{proposal}/dismiss', [ExaminerContactController::class, 'dismissProposal'])->name('examiner-admin.proposal.dismiss');
        Route::get('/examiners/proposed-emails/{proposal}/cv', [ExaminerContactController::class, 'proposalCv'])->name('examiner-admin.proposal.cv');
    });

    // The examiner page and "Email not working". The controller narrows an
    // AE to examiners in their own department or on its students' panels.
    Route::middleware('role:'.Role::SENIOR_EXEC_CGS.','.Role::NON_EXEC_CGS.','.Role::ACADEMIC_EXEC)->group(function () {
        Route::get('/examiners/{examiner}', [ExaminerContactController::class, 'show'])->whereNumber('examiner')->name('examiner-admin.show');
        Route::post('/examiners/{examiner}/report-email', [ExaminerContactController::class, 'reportEmail'])->whereNumber('examiner')->name('examiner-admin.report-email');
    });

    Route::middleware('role:'.Role::SENIOR_EXEC_CGS)->group(function () {
        Route::get('/examiners/new', [ExaminerAdminController::class, 'create'])->name('examiner-admin.create');
        Route::post('/examiners', [ExaminerAdminController::class, 'store'])->name('examiner-admin.store');
        Route::post('/examiners/{examiner}/toggle-active', [ExaminerAdminController::class, 'toggleActive'])
            ->name('examiner-admin.toggle-active');
        Route::post('/examiners/duplicates/dismiss', [ExaminerAdminController::class, 'dismissDuplicate'])
            ->name('examiner-admin.dismiss-duplicate');
        Route::get('/examiners/{examiner}/edit', [ExaminerAdminController::class, 'edit'])->name('examiner-admin.edit');
        Route::put('/examiners/{examiner}', [ExaminerAdminController::class, 'update'])->name('examiner-admin.update');

        // Pool import: upload -> preview -> confirm. See ExaminerImportController.
        Route::get('/examiners/import', [ExaminerImportController::class, 'create'])->name('examiner-import.create');
        Route::post('/examiners/import', [ExaminerImportController::class, 'store'])->name('examiner-import.store');
        Route::get('/examiners/import/template', [ExaminerImportController::class, 'template'])->name('examiner-import.template');
        Route::get('/examiners/import/{batch}', [ExaminerImportController::class, 'show'])->name('examiner-import.show');
        Route::post('/examiners/import/{batch}/confirm', [ExaminerImportController::class, 'confirm'])->name('examiner-import.confirm');
        Route::get('/examiners/review', [ExaminerImportController::class, 'review'])->name('examiner-import.review');
        Route::post('/examiners/review/{item}/resolve', [ExaminerImportController::class, 'resolve'])->name('examiner-import.resolve');
    });

    // ---- Re-viva Monitoring --------------------------------------------
    // Logged by CGS Staff, not the student: the corrected thesis reaches CGS
    // through the same channel the rest of the re-viva process is informally
    // run through today, so CGS is the one who records the formal
    // resubmission timestamp -- not a self-service student upload.
    // Senior Executive CGS logs re-vivas (CGS practice). Non-Executive CGS is
    // read-only in re-viva since Prompt 3: Re-viva Cycles, no actions.
    Route::middleware('role:'.Role::SENIOR_EXEC_CGS)->group(function () {
        Route::get('/re-viva/new', [ReVivaController::class, 'create'])->name('reviva.create');
        Route::post('/re-viva', [ReVivaController::class, 'store'])->name('reviva.store');
    });

    Route::middleware('role:'.Role::SENIOR_EXEC_CGS.','.Role::NON_EXEC_CGS)->group(function () {
        Route::get('/re-viva/cycles', [ReVivaCyclesController::class, 'index'])->name('reviva.cycles.index');
    });

    Route::middleware('role:'.Role::ACADEMIC_EXEC)->group(function () {
        Route::get('/re-viva/queue', [ReVivaController::class, 'queue'])->name('reviva.queue');
        Route::post('/re-viva/{application}/decide', [ReVivaController::class, 'decide'])->name('reviva.decide');

        Route::get('/re-viva/outcomes', [ReVivaController::class, 'outcomesIndex'])->name('reviva.outcomes.index');
        Route::post('/re-viva/outcomes/{detail}', [ReVivaController::class, 'recordOutcome'])
            ->name('reviva.outcomes.record');
    });

});
