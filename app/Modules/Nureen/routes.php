<?php

use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Http\Controllers\GaExtensionController;
use Illuminate\Support\Facades\Route;

/*
| Nureen Nellysha (22006973)
| Attendance · GA Extension · Supervision · GA/GRA Certification Letter
*/

Route::middleware('auth')->group(function () {

    // ---- GA Extension -------------------------------------------------
    Route::middleware('role:'.Role::STUDENT)->group(function () {
        Route::get('/ga-extension/new', [GaExtensionController::class, 'create'])->name('ga-extension.create');
        Route::post('/ga-extension', [GaExtensionController::class, 'store'])->name('ga-extension.store');
    });

    Route::middleware('role:'.implode(',', [
        Role::SUPERVISOR, Role::NON_EXEC_CGS, Role::SENIOR_DIRECTOR_CGS,
    ]))->group(function () {
        Route::get('/ga-extension/queue', [GaExtensionController::class, 'queue'])->name('ga-extension.queue');
        Route::post('/ga-extension/{application}/decide', [GaExtensionController::class, 'decide'])->name('ga-extension.decide');
    });

});
