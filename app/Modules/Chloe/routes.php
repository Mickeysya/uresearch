<?php

use App\Modules\Chloe\Http\Controllers\CandidacyAppealController;
use App\Modules\Chloe\Http\Controllers\CandidacyController;
use App\Modules\Chloe\Http\Controllers\Cgs\CandidacyDismissalController;
use App\Modules\Chloe\Http\Controllers\Cgs\CandidacyOperationsController;
use App\Modules\Chloe\Http\Controllers\Cgs\WorkstationCatalogController;
use App\Modules\Chloe\Http\Controllers\Cgs\WorkstationOperationsController;
use App\Modules\Chloe\Http\Controllers\LockerKeyController;
use App\Modules\Chloe\Http\Controllers\WorkstationController;
use App\Modules\Core\Support\Role;
use Illuminate\Support\Facades\Route;

/*
| Workstation Management. Not an approval chain — no Application rows, no
| WorkflowEngine — so unlike every other module's routes.php, nothing here
| is gated by a Stage. See app/Modules/Chloe/README.md.
*/
Route::middleware('auth')->group(function () {
    Route::middleware('role:'.Role::STUDENT)->group(function () {
        // Home (block picker) -> Room picker -> Seat map, matching CGS's own
        // seat map reference hierarchy. See WorkstationController.
        Route::get('/workstation', [WorkstationController::class, 'index'])->name('workstation.select');
        Route::get('/workstation/blocks/{block}', [WorkstationController::class, 'rooms'])->name('workstation.rooms');
        Route::get('/workstation/rooms/{workstationLocation}', [WorkstationController::class, 'seats'])->name('workstation.seats');
        // Static single-segment routes MUST be registered before
        // POST /workstation/{workstation} below — otherwise that dynamic
        // route matches first and tries (and fails) to bind a Workstation
        // with id "gender" or "locker-key".
        Route::post('/workstation/gender', [WorkstationController::class, 'setGender'])->name('workstation.gender.set');
        Route::post('/workstation/locker-key', [LockerKeyController::class, 'store'])->name('workstation.locker-key.request');
        Route::post('/workstation/requests/{workstationRequest}/release', [WorkstationController::class, 'release'])->name('workstation.release');
        Route::post('/workstation/{workstation}', [WorkstationController::class, 'store'])->name('workstation.register');
    });

    // CGS staff run the seat catalogue and the day-to-day allocation
    // overrides — see docs/scope/chloe.md ("CGS keeps manual assignment for
    // exceptional cases"). Not Role::ADMIN: that role is the system
    // administrator's read-only oversight account, not CGS's operational one.
    Route::middleware('role:'.implode(',', Role::cgsTeam()))->group(function () {
        Route::get('/cgs/workstation', [WorkstationOperationsController::class, 'index'])->name('workstation.cgs.operations');
        Route::post('/cgs/workstation/{workstation}/force-assign', [WorkstationOperationsController::class, 'forceAssign'])->name('workstation.cgs.force-assign');
        Route::post('/cgs/workstation/{workstation}/force-release', [WorkstationOperationsController::class, 'forceRelease'])->name('workstation.cgs.force-release');
        Route::post('/cgs/workstation/locker-keys/{lockerKey}/collected', [WorkstationOperationsController::class, 'markLockerCollected'])->name('workstation.cgs.locker-key.collected');
        Route::post('/cgs/workstation/locker-keys/{lockerKey}/returned', [WorkstationOperationsController::class, 'markLockerReturned'])->name('workstation.cgs.locker-key.returned');
        Route::post('/cgs/workstation/students/{user}/gender', [WorkstationOperationsController::class, 'setStudentGender'])->name('workstation.cgs.gender.set');

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

    // Registered after /candidacy-appeal/queue (static) above on purpose —
    // a dynamic {application} segment at the same depth must come after
    // every static route at that depth, or it swallows them first (see
    // app/Modules/Chloe/README.md's route-ordering note).
    Route::middleware('role:'.Role::STUDENT)->group(function () {
        Route::get('/candidacy-appeal/{application}', [CandidacyAppealController::class, 'show'])->name('candidacy-appeal.show');
    });

    Route::middleware('role:'.implode(',', Role::cgsTeam()))->group(function () {
        Route::get('/cgs/candidacy', [CandidacyOperationsController::class, 'index'])->name('candidacy.cgs.operations');

        Route::get('/cgs/candidacy/dismissals', [CandidacyDismissalController::class, 'index'])->name('candidacy.cgs.dismissals');
        Route::post('/cgs/candidacy/dismissals/refresh', [CandidacyDismissalController::class, 'refresh'])->name('candidacy.cgs.dismissals.refresh');
        Route::post('/cgs/candidacy/dismissals/{dismissal}/confirm', [CandidacyDismissalController::class, 'confirm'])->name('candidacy.cgs.dismissals.confirm');
    });
});
