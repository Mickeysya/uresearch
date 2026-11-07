<?php

use App\Modules\Core\Support\Role;
use App\Modules\Haziq\Http\Controllers\AllowanceController;
use App\Modules\Haziq\Http\Controllers\CandidatureAppealController;
use App\Modules\Haziq\Http\Controllers\GaApplicationController;
use App\Modules\Haziq\Http\Controllers\GraApplicationController;
use App\Modules\Haziq\Http\Controllers\StageGateController;
use App\Modules\Haziq\Support\Roles;
use Illuminate\Support\Facades\Route;

/*
| Abdul Haziq bin Abdul Farouk (22007428)
| GRA Application + Stage Gate Monitoring · GA Application · Allowance Eligibility
*/

Route::middleware('auth')->group(function () {

    // ---- GRA Application (+ Extension) --------------------------------
    Route::middleware('role:'.Role::STUDENT)->group(function () {
        Route::get('/gra-application/terms', [GraApplicationController::class, 'showTerms'])->name('gra-application.terms');
        Route::post('/gra-application/terms', [GraApplicationController::class, 'acceptTerms'])->name('gra-application.terms.accept');
        Route::get('/gra-application/new', [GraApplicationController::class, 'create'])->name('gra-application.create');
        Route::post('/gra-application', [GraApplicationController::class, 'store'])->name('gra-application.store');
        Route::get('/gra-application/extension/new', [GraApplicationController::class, 'createExtension'])->name('gra-application.extension.create');
        Route::post('/gra-application/extension', [GraApplicationController::class, 'storeExtension'])->name('gra-application.extension.store');
    });

    // Every approver role — Admin, CGS, Supervisor, Senior Director. All
    // four can browse the applicant list, open full detail, act on their
    // own queue, and flag a correction on any application at any time (not
    // only Admin, not only on their own current stage — confirmed
    // 2026-10-11). Routes with a literal path segment (queue, correction)
    // must come before the bare `{application}` wildcard below, or Laravel
    // matches the wildcard first and tries to look up an Application with
    // id="queue".
    Route::middleware('role:'.implode(',', array_merge([Role::ADMIN, Role::SUPERVISOR], Role::cgsTeam())))->group(function () {
        Route::get('/gra-application/queue', [GraApplicationController::class, 'queue'])->name('gra-application.queue');
        Route::post('/gra-application/{application}/decide', [GraApplicationController::class, 'decide'])->name('gra-application.decide');
        Route::post('/gra-application/{application}/request-correction', [GraApplicationController::class, 'requestCorrection'])->name('gra-application.request-correction');
        Route::get('/gra-application', [GraApplicationController::class, 'index'])->name('gra-application.index');
        Route::get('/gra-application/{application}', [GraApplicationController::class, 'show'])->name('gra-application.show');
    });

    // Supervisor/Senior Director only — internal feedback on CGS's
    // recommended allowance, never surfaced to the student. See
    // GraApplicationController::flagAllowance().
    Route::middleware('role:'.implode(',', [Role::SUPERVISOR, Role::SENIOR_DIRECTOR_CGS]))->group(function () {
        Route::post('/gra-application/{application}/flag-allowance', [GraApplicationController::class, 'flagAllowance'])->name('gra-application.flag-allowance');
    });

    // CGS only — revising the allowance they set, any time the
    // application is still pending (not gated to the cgs_review stage
    // itself). See GraApplicationController::editAllowance().
    Route::middleware('role:'.Role::NON_EXEC_CGS)->group(function () {
        Route::post('/gra-application/{application}/edit-allowance', [GraApplicationController::class, 'editAllowance'])->name('gra-application.edit-allowance');
    });

    Route::middleware('role:'.Role::STUDENT)->group(function () {
        // Read-only view of what the student submitted, with an Edit
        // button that only appears once something has actually been
        // flagged — see GraApplicationController::showMine().
        Route::get('/gra-application/{application}/mine', [GraApplicationController::class, 'showMine'])->name('gra-application.mine');
        Route::get('/gra-application/{application}/correction', [GraApplicationController::class, 'showCorrection'])->name('gra-application.correction.edit');
        Route::post('/gra-application/{application}/correction', [GraApplicationController::class, 'storeCorrection'])->name('gra-application.correction.store');
    });

    // ---- GA Application -------------------------------------------------
    Route::middleware('role:'.Role::STUDENT)->group(function () {
        Route::get('/ga-application/terms', [GaApplicationController::class, 'showTerms'])->name('ga-application.terms');
        Route::post('/ga-application/terms', [GaApplicationController::class, 'acceptTerms'])->name('ga-application.terms.accept');
        Route::get('/ga-application/new', [GaApplicationController::class, 'create'])->name('ga-application.create');
        Route::post('/ga-application', [GaApplicationController::class, 'store'])->name('ga-application.store');
        Route::get('/ga-application/{application}/mine', [GaApplicationController::class, 'showMine'])->name('ga-application.mine');
        Route::get('/ga-application/{application}/correction', [GaApplicationController::class, 'showCorrection'])->name('ga-application.correction.edit');
        Route::post('/ga-application/{application}/correction', [GaApplicationController::class, 'storeCorrection'])->name('ga-application.correction.store');
    });

    // Every approver role — Admin, Research Centre, CGS, Supervisor, Senior
    // Director — same "everyone can browse, open detail, act on their own
    // queue, flag a correction on any application at any time" shape as
    // GRA's own approver group. Routes with a literal path segment (queue,
    // correction, schedule-interview) come before the bare {application}
    // wildcard, same reason as GRA's.
    Route::middleware('role:'.implode(',', array_merge(
        [Role::ADMIN, Roles::RESEARCH_CENTRE, Role::SUPERVISOR], Role::cgsTeam()
    )))->group(function () {
        Route::get('/ga-application/queue', [GaApplicationController::class, 'queue'])->name('ga-application.queue');
        Route::post('/ga-application/{application}/decide', [GaApplicationController::class, 'decide'])->name('ga-application.decide');
        Route::post('/ga-application/{application}/request-correction', [GaApplicationController::class, 'requestCorrection'])->name('ga-application.request-correction');
        Route::get('/ga-application', [GaApplicationController::class, 'index'])->name('ga-application.index');
        Route::get('/ga-application/{application}', [GaApplicationController::class, 'show'])->name('ga-application.show');
    });

    // Research Centre only — scheduling the interview is a separate action
    // from deciding its outcome; see GaApplicationController::scheduleInterview().
    Route::middleware('role:'.Roles::RESEARCH_CENTRE)->group(function () {
        Route::post('/ga-application/{application}/schedule-interview', [GaApplicationController::class, 'scheduleInterview'])->name('ga-application.schedule-interview');
    });

    // ---- Stage Gate monitoring + Allowance Eligibility -------------------
    // CGS records completion; CGS/Senior Director/Supervisor/Admin monitor
    // everyone; a Student sees the same screen scoped to their own gates
    // only — same "open route, controller scopes by role" split as TA Hours
    // below, since a student's own data isn't a "monitoring" permission.
    // Allowance Eligibility now follows the identical split — confirmed
    // explicitly 2026-10-23: "student akan nampak die nye je yang lain
    // semua student ah" — so it moved out of the approver-only group below
    // and into this one.
    Route::middleware('role:'.implode(',', array_merge(
        [Role::ADMIN, Role::SUPERVISOR, Role::STUDENT],
        Role::cgsTeam(),
    )))->group(function () {
        Route::get('/stage-gate', [StageGateController::class, 'index'])->name('stage-gate.index');
        Route::get('/allowance', [AllowanceController::class, 'index'])->name('allowance.index');
    });

    Route::middleware('role:'.implode(',', array_merge(
        [Role::ADMIN, Role::SUPERVISOR],
        Role::cgsTeam(),
    )))->group(function () {
        Route::post('/stage-gate/{gate}/complete', [StageGateController::class, 'markComplete'])->name('stage-gate.complete');
        Route::post('/stage-gate/{gate}/not-complete', [StageGateController::class, 'markNotComplete'])->name('stage-gate.not-complete');

        // Not open to students — confirmed explicitly 2026-10-23: "admin
        // sampai director leh dowload excel", the same approver audience
        // as the monitoring screens, never the student's own narrow view.
        Route::get('/allowance/export', [AllowanceController::class, 'export'])->name('allowance.export');
    });

    // Admin or CGS (non_exec) only — narrower than the monitoring group
    // above, confirmed explicitly 2026-10-23: "cgs admin dua dua boleh
    // buat" (not Supervisor, not Senior Director — same authorization
    // shape as Stage Gate's own markComplete()).
    Route::middleware('role:'.implode(',', [Role::ADMIN, Role::NON_EXEC_CGS]))->group(function () {
        Route::post('/allowance/{application}/confirm-payment', [AllowanceController::class, 'confirmPayment'])->name('allowance.confirm-payment');
        Route::post('/allowance/{application}/set-review-amount', [AllowanceController::class, 'setReviewAmount'])->name('allowance.set-review-amount');
    });

    // ---- Candidature Appeal -----------------------------------------------
    // A terminated student appealing the same application, not reapplying
    // fresh — see CandidatureAppealController. Student routes keyed by
    // application id, queue/decide routes keyed by appeal id (plural
    // "-appeals" path), so there's no literal-vs-wildcard collision to
    // order around.
    Route::middleware('role:'.Role::STUDENT)->group(function () {
        Route::get('/candidature-appeal/{application}', [CandidatureAppealController::class, 'create'])->name('candidature-appeal.create');
        Route::post('/candidature-appeal/{application}', [CandidatureAppealController::class, 'store'])->name('candidature-appeal.store');
    });

    Route::middleware('role:'.implode(',', array_merge([Role::ADMIN, Role::SUPERVISOR], Role::cgsTeam())))->group(function () {
        Route::get('/candidature-appeals', [CandidatureAppealController::class, 'queue'])->name('candidature-appeal.queue');
    });

    Route::middleware('role:'.Role::SENIOR_DIRECTOR_CGS)->group(function () {
        Route::post('/candidature-appeals/{appeal}/decide', [CandidatureAppealController::class, 'decide'])->name('candidature-appeal.decide');
    });

    // TA Hours moved to Norhanis' module (app/Modules/Norhanis/routes.php),
    // same URL and route names.

});
