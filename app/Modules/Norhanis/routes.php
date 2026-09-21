<?php

use App\Modules\Core\Support\Role;
use App\Modules\Norhanis\Http\Controllers\CandidacyController;
use App\Modules\Norhanis\Http\Controllers\ClaimsController;
use App\Modules\Norhanis\Http\Controllers\PublicationController;
use App\Modules\Norhanis\Http\Controllers\RpdAppealController;
use App\Modules\Norhanis\Http\Controllers\RpdDismissalController;
use App\Modules\Norhanis\Http\Controllers\TravelController;
use App\Modules\Norhanis\Http\Controllers\UpcomingRpdRemindersController;
use Illuminate\Support\Facades\Route;

/*
| Norhanis Erna Natasha (22006318)
| Travel · Publication · Claims · RPD
*/

Route::middleware('auth')->group(function () {

    // ---- Travel -------------------------------------------------------
    Route::middleware('role:'.Role::STUDENT)->group(function () {
        Route::get('/travel/new', [TravelController::class, 'create'])->name('travel.create');
        Route::post('/travel', [TravelController::class, 'store'])->name('travel.store');
    });

    // Every role that owns a stage in the travel chain shares one queue
    // screen; ?stage= selects which. The engine re-checks the role against
    // the application's actual stage before allowing any decision.
    Route::middleware('role:'.implode(',', [
        Role::SUPERVISOR, Role::CHAIR, Role::NON_EXEC_CGS, Role::DEAN_PGR,
    ]))->group(function () {
        Route::get('/travel/queue', [TravelController::class, 'queue'])->name('travel.queue');
        Route::post('/travel/{application}/decide', [TravelController::class, 'decide'])->name('travel.decide');
    });

    // ---- Claims (student) ----------------------------------------------
    Route::middleware('role:'.Role::STUDENT)->group(function () {
        Route::get('/claims/new', [ClaimsController::class, 'create'])->name('claims.create');
        Route::post('/claims', [ClaimsController::class, 'store'])->name('claims.store');
    });

    Route::middleware('role:'.implode(',', [
        Role::SUPERVISOR, Role::CHAIR, Role::NON_EXEC_CGS, Role::MANAGER_CGS,
    ]))->group(function () {
        Route::get('/claims/queue', [ClaimsController::class, 'queue'])->name('claims.queue');
        Route::post('/claims/{application}/decide', [ClaimsController::class, 'decide'])->name('claims.decide');
    });

    // ---- Publication -----------------------------------------------------
    Route::middleware('role:'.Role::STUDENT)->group(function () {
        Route::get('/publication/new', [PublicationController::class, 'create'])->name('publication.create');
        Route::post('/publication', [PublicationController::class, 'store'])->name('publication.store');
    });

    Route::middleware('role:'.implode(',', [
        Role::SUPERVISOR, Role::CHAIR, Role::NON_EXEC_CGS, Role::SENIOR_DIRECTOR_CGS,
    ]))->group(function () {
        Route::get('/publication/queue', [PublicationController::class, 'queue'])->name('publication.queue');
        Route::post('/publication/{application}/decide', [PublicationController::class, 'decide'])->name('publication.decide');
    });

    // ---- RPD Appeal / Extension (student) --------------------------------
    Route::middleware('role:'.Role::STUDENT)->group(function () {
        Route::get('/rpd-appeal/new', [RpdAppealController::class, 'create'])->name('rpd-appeal.create');
        Route::post('/rpd-appeal', [RpdAppealController::class, 'store'])->name('rpd-appeal.store');
    });

    Route::middleware('role:'.implode(',', [
        Role::SUPERVISOR, Role::CHAIR, Role::NON_EXEC_CGS, Role::DEAN_PGR,
    ]))->group(function () {
        Route::get('/rpd-appeal/queue', [RpdAppealController::class, 'queue'])->name('rpd-appeal.queue');
        Route::post('/rpd-appeal/{application}/decide', [RpdAppealController::class, 'decide'])->name('rpd-appeal.decide');
    });

    // ---- RPD Dismissal (CGS-initiated, not student-submitted) ------------
    // Registry is not a stage in this chain -- see RpdDismissalWorkflow --
    // so it has no queue/decide access here.
    Route::middleware('role:'.Role::NON_EXEC_CGS)->group(function () {
        Route::get('/rpd-dismissal/new', [RpdDismissalController::class, 'create'])->name('rpd-dismissal.create');
        Route::post('/rpd-dismissal', [RpdDismissalController::class, 'store'])->name('rpd-dismissal.store');
    });

    Route::middleware('role:'.implode(',', [
        Role::DEAN_PGR, Role::FACULTY,
    ]))->group(function () {
        Route::get('/rpd-dismissal/queue', [RpdDismissalController::class, 'queue'])->name('rpd-dismissal.queue');
        Route::post('/rpd-dismissal/{application}/decide', [RpdDismissalController::class, 'decide'])->name('rpd-dismissal.decide');
    });

    // ---- Upcoming RPD Reminders (Non-Exec CGS, read-only + manual fallback) --
    // Visibility into the same 3/2/1-month windows rpd:remind scans
    // automatically at 07:00 -- does not touch that command or its schedule.
    Route::middleware('role:'.Role::NON_EXEC_CGS)->group(function () {
        Route::get('/rpd-reminders', [UpcomingRpdRemindersController::class, 'index'])->name('rpd-reminders.index');
        Route::post('/rpd-reminders/{candidacy}/send', [UpcomingRpdRemindersController::class, 'send'])->name('rpd-reminders.send');
    });

    // ---- RPD failed-attempt recording (Non-Exec CGS, no approval chain) --
    // Not a WorkflowModule -- there is no approver to route this through,
    // it is CGS recording an outcome that reached them manually from the AE
    // (department-level RPD assessment scheduling is explicitly outside this
    // module's scope). See CandidacyController.
    Route::middleware('role:'.Role::NON_EXEC_CGS)->group(function () {
        Route::get('/candidacies/failed-attempt', [CandidacyController::class, 'create'])->name('candidacy.failed-attempt.create');
        Route::post('/candidacies/{candidacy}/failed-attempt', [CandidacyController::class, 'recordFailedAttempt'])->name('candidacy.failed-attempt.store');
    });

});
