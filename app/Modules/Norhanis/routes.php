<?php

use App\Modules\Core\Support\Role;
use App\Modules\Norhanis\Http\Controllers\ClaimsController;
use App\Modules\Norhanis\Http\Controllers\PublicationController;
use App\Modules\Norhanis\Http\Controllers\TravelController;
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

});
