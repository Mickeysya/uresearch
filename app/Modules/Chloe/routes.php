<?php

use App\Modules\Chloe\Http\Controllers\CandidacyAppealController;
use App\Modules\Chloe\Http\Controllers\CandidacyController;
use App\Modules\Chloe\Http\Controllers\Cgs\CandidacyDismissalController;
use App\Modules\Chloe\Http\Controllers\Cgs\CandidacyOperationsController;
use App\Modules\Chloe\Http\Controllers\Cgs\WorkstationCatalogController;
use App\Modules\Chloe\Http\Controllers\Cgs\WorkstationInsightsController;
use App\Modules\Chloe\Http\Controllers\Cgs\WorkstationManagementController;
use App\Modules\Chloe\Http\Controllers\Cgs\WorkstationLiveController;
use App\Modules\Chloe\Http\Controllers\Cgs\WorkstationOperationsController;
use App\Modules\Chloe\Http\Controllers\LockerKeyController;
use App\Modules\Chloe\Http\Controllers\WorkstationAssistantController;
use App\Modules\Chloe\Http\Controllers\WorkstationController;
use App\Modules\Chloe\Http\Controllers\WorkstationTermsController;
use App\Modules\Chloe\Http\Middleware\NoStoreResponse;
use App\Modules\Core\Support\Role;
use Illuminate\Support\Facades\Route;

/*
| Workstation Management. Not an approval chain — no Application rows, no
| WorkflowEngine — so unlike every other module's routes.php, nothing here
| is gated by a Stage. See app/Modules/Chloe/README.md.
*/
Route::middleware(['auth', NoStoreResponse::class])->group(function () {
    // The assistant backend. Not gated by role: middleware on purpose --
    // any authenticated role may reach it, and a role the assistant does
    // not support yet gets the in-app message "The assistant is not
    // available for your role yet.", not a 403. See Assistant\Pipeline.
    Route::middleware('throttle:assistant-user')->prefix('workstation/assistant')->group(function () {
        Route::get('/suggestions', [WorkstationAssistantController::class, 'suggestions'])->name('workstation.assistant.suggestions');
        Route::post('/suggestion', [WorkstationAssistantController::class, 'suggestion'])->name('workstation.assistant.suggestion');
        Route::post('/ask', [WorkstationAssistantController::class, 'ask'])->name('workstation.assistant.ask');
    });

    Route::middleware('role:'.Role::STUDENT)->group(function () {
        // Home (block picker) -> Room picker -> Seat map, matching CGS's own
        // seat map reference hierarchy. See WorkstationController.
        Route::get('/workstation', [WorkstationController::class, 'index'])->name('workstation.select');
        Route::get('/workstation/live/version', [WorkstationLiveController::class, 'studentVersion'])->middleware('throttle:workstation-live')->name('workstation.live.student-version');
        Route::get('/workstation/blocks/{block}', [WorkstationController::class, 'rooms'])->name('workstation.rooms');
        Route::get('/workstation/rooms/{workstationLocation}', [WorkstationController::class, 'seats'])->name('workstation.seats');
        // Static single-segment routes MUST be registered before
        // POST /workstation/{workstation} below — otherwise that dynamic
        // route matches first and tries (and fails) to bind a Workstation
        // with id "locker-key". Gender has no student-facing route at all —
        // see WorkstationController's class doc for why.
        Route::post('/workstation/locker-key', [LockerKeyController::class, 'store'])->name('workstation.locker-key.request');
        Route::post('/workstation/locker-key/cancel', [LockerKeyController::class, 'cancel'])->name('workstation.locker-key.cancel');
        Route::post('/workstation/return', [WorkstationController::class, 'returnWorkstation'])->name('workstation.return');
        Route::post('/workstation/{workstation}', [WorkstationController::class, 'store'])->name('workstation.register');
    });

    // CGS staff run the seat catalogue and the day-to-day allocation
    // overrides — see docs/scope/chloe.md ("CGS keeps manual assignment for
    // exceptional cases"). Not Role::ADMIN: that role is the system
    // administrator's read-only oversight account, not CGS's operational one.
    Route::middleware('role:'.implode(',', Role::cgsTeam()))->group(function () {
        // The new landing page, Block/Room/Seat drill-down and every
        // per-seat action -- see WorkstationManagementController's class
        // doc. Static segments ("students/search", "export/...") are
        // registered before the dynamic {workstation} seat routes so they
        // can never be swallowed by it.
        Route::get('/cgs/workstation', [WorkstationManagementController::class, 'index'])->name('workstation.cgs.operations');
        Route::get('/cgs/workstation/insights', [WorkstationInsightsController::class, 'index'])->name('workstation.cgs.insights');
        Route::middleware('throttle:workstation-live')->prefix('/cgs/workstation/live')->group(function () {
            Route::get('/version', [WorkstationLiveController::class, 'version'])->name('workstation.cgs.live.version');
            Route::get('/stats', [WorkstationLiveController::class, 'stats'])->name('workstation.cgs.live.stats');
            Route::get('/insights', [WorkstationLiveController::class, 'insights'])->name('workstation.cgs.live.insights');
        });
        Route::get('/cgs/workstation/blocks/{block}', [WorkstationManagementController::class, 'block'])->name('workstation.cgs.block');
        Route::get('/cgs/workstation/rooms/{workstationLocation}', [WorkstationManagementController::class, 'room'])->name('workstation.cgs.room');
        Route::get('/cgs/workstation/export/seats', [WorkstationManagementController::class, 'exportSeats'])->name('workstation.cgs.export.seats');
        Route::get('/cgs/workstation/export/history', [WorkstationManagementController::class, 'exportHistory'])->name('workstation.cgs.export.history');
        Route::get('/cgs/workstation/suggestions', [WorkstationManagementController::class, 'suggestions'])
            ->middleware('throttle:30,1')
            ->name('workstation.cgs.suggestions');
        Route::get('/cgs/workstation/seats/{workstation}/students/search', [WorkstationManagementController::class, 'searchStudents'])->name('workstation.cgs.seat.students.search');
        Route::get('/cgs/workstation/seats/{workstation}', [WorkstationManagementController::class, 'show'])->name('workstation.cgs.seat');
        Route::post('/cgs/workstation/seats/{workstation}/reassign', [WorkstationManagementController::class, 'reassign'])->name('workstation.cgs.seat.reassign');
        Route::post('/cgs/workstation/seats/{workstation}/release', [WorkstationManagementController::class, 'release'])->name('workstation.cgs.seat.release');
        Route::post('/cgs/workstation/seats/{workstation}/release-now', [WorkstationManagementController::class, 'releaseNow'])->name('workstation.cgs.seat.release-now');
        Route::post('/cgs/workstation/seats/{workstation}/withdraw-termination', [WorkstationManagementController::class, 'withdrawTermination'])->name('workstation.cgs.seat.withdraw-termination');
        Route::post('/cgs/workstation/seats/{workstation}/maintenance', [WorkstationManagementController::class, 'setMaintenance'])->name('workstation.cgs.seat.maintenance');
        Route::post('/cgs/workstation/seats/{workstation}/release-hold', [WorkstationManagementController::class, 'releaseHold'])->name('workstation.cgs.seat.release-hold');
        Route::post('/cgs/workstation/seats/{workstation}/restore', [WorkstationManagementController::class, 'restore'])->name('workstation.cgs.seat.restore');
        Route::post('/cgs/workstation/seats/{workstation}/assign', [WorkstationManagementController::class, 'assign'])->name('workstation.cgs.seat.assign');
        Route::post('/cgs/workstation/seats/{workstation}/key/collected', [WorkstationManagementController::class, 'markKeyCollected'])->name('workstation.cgs.seat.key-collected');
        Route::post('/cgs/workstation/seats/{workstation}/key/returned', [WorkstationManagementController::class, 'markKeyReturned'])->name('workstation.cgs.seat.key-returned');

        // Superseded by the screens above but kept working -- see
        // WorkstationOperationsController's class doc for what each one
        // now delegates to.
        Route::post('/cgs/workstation/{workstation}/force-assign', [WorkstationOperationsController::class, 'forceAssign'])->name('workstation.cgs.force-assign');
        Route::post('/cgs/workstation/{workstation}/force-release', [WorkstationOperationsController::class, 'forceRelease'])->name('workstation.cgs.force-release');
        Route::post('/cgs/workstation/locker-keys/{lockerKey}/collected', [WorkstationOperationsController::class, 'markLockerCollected'])->name('workstation.cgs.locker-key.collected');
        Route::post('/cgs/workstation/locker-keys/{lockerKey}/returned', [WorkstationOperationsController::class, 'markLockerReturned'])->name('workstation.cgs.locker-key.returned');

        Route::get('/cgs/workstation/catalog', [WorkstationCatalogController::class, 'index'])->name('workstation.cgs.catalog');
        Route::post('/cgs/workstation/catalog/locations', [WorkstationCatalogController::class, 'storeLocation'])->name('workstation.cgs.catalog.locations.store');
        Route::post('/cgs/workstation/catalog/seats', [WorkstationCatalogController::class, 'storeSeat'])->name('workstation.cgs.catalog.seats.store');
        Route::put('/cgs/workstation/catalog/seats/{workstation}', [WorkstationCatalogController::class, 'updateSeat'])->name('workstation.cgs.catalog.seats.update');
        Route::delete('/cgs/workstation/catalog/seats/{workstation}', [WorkstationCatalogController::class, 'destroySeat'])->name('workstation.cgs.catalog.seats.destroy');
    });

    /*
    | Study Candidacy Reminder / Appeal / Dismiss. Appeal IS an approval
    | chain and is built on the shared WorkflowEngine, routed
    | Student -> Supervisor -> Programme Chair -> CGS Verification -> Dean.
    | See app/Modules/Chloe/README.md.
    */
    Route::middleware('role:'.Role::STUDENT)->group(function () {
        Route::get('/candidacy', [CandidacyController::class, 'status'])->name('candidacy.status');

        Route::get('/candidacy-appeal/new', [CandidacyAppealController::class, 'create'])->name('candidacy-appeal.create');
        Route::post('/candidacy-appeal', [CandidacyAppealController::class, 'store'])->name('candidacy-appeal.store');
        Route::get('/candidacy-appeal/{application}/edit', [CandidacyAppealController::class, 'edit'])->name('candidacy-appeal.edit');
        Route::post('/candidacy-appeal/{application}/resubmit', [CandidacyAppealController::class, 'resubmit'])->name('candidacy-appeal.resubmit');
    });

    // The chain's four approver roles share one queue/decide pair — queueFor()
    // already scopes each to only the stage(s) that role owns.
    Route::middleware('role:'.implode(',', [Role::SUPERVISOR, Role::CHAIR, Role::NON_EXEC_CGS, Role::DEAN_PGR]))->group(function () {
        Route::get('/candidacy-appeal/queue', [CandidacyAppealController::class, 'queue'])->name('candidacy-appeal.queue');
        Route::post('/candidacy-appeal/{application}/decide', [CandidacyAppealController::class, 'decide'])->name('candidacy-appeal.decide');
    });

    Route::middleware('role:'.implode(',', Role::cgsTeam()))->group(function () {
        Route::get('/cgs/candidacy', [CandidacyOperationsController::class, 'index'])->name('candidacy.cgs.operations');

        Route::get('/cgs/candidacy/dismissals', [CandidacyDismissalController::class, 'index'])->name('candidacy.cgs.dismissals');
        Route::post('/cgs/candidacy/dismissals/refresh', [CandidacyDismissalController::class, 'refresh'])->name('candidacy.cgs.dismissals.refresh');
        Route::post('/cgs/candidacy/dismissals/{dismissal}/confirm', [CandidacyDismissalController::class, 'confirm'])->name('candidacy.cgs.dismissals.confirm');
    });
});

// The link in Email 1. Deliberately outside the `auth` group above —
// see WorkstationController::confirm()'s own doc for why: the token is the
// authorisation, not a login session, the same shape as a password-reset
// link.
Route::get('/workstation/confirm/{token}', [WorkstationController::class, 'confirm'])->name('workstation.confirm');

// PART 4 #1. Also outside `auth` -- every email this module sends links
// here (Email 1, Termination Notice, Workstation Assigned/Changed), so it
// has to open for whoever clicks it, logged in or not.
Route::get('/workstation/terms', [WorkstationTermsController::class, 'show'])->name('workstation.terms');
Route::get('/workstation/terms/pdf', [WorkstationTermsController::class, 'downloadPdf'])->name('workstation.terms.pdf');
