<?php

use App\Modules\Core\Support\Role;
use App\Modules\Jason\Http\Controllers\AppointmentLetterController;
use Illuminate\Support\Facades\Route;

/*
| Jason (22003299)
| Appointment Letter & Report Management
|
| Hardbound Submission and Appeal Hardbound Submission are not routed yet --
| both are blocked on the open WorkflowEngine "return to student" question
| and the unseeded senior_exec_cgs account. See TODO.md.
*/

Route::middleware('auth')->group(function () {

    // ---- Appointment Letter --------------------------------------------
    Route::middleware('role:'.Role::CHAIR)->group(function () {
        Route::get('/appointment-letter/new', [AppointmentLetterController::class, 'create'])
            ->name('appointment-letter.create');
        Route::post('/appointment-letter', [AppointmentLetterController::class, 'store'])
            ->name('appointment-letter.store');
    });

    // Both stages of the chain share one queue screen; ?stage= selects
    // which. The engine re-checks the role against the application's actual
    // stage before allowing any decision, same as every other module.
    Route::middleware('role:'.implode(',', [Role::ACADEMIC_EXEC, Role::DEAN_PGR]))->group(function () {
        Route::get('/appointment-letter/queue', [AppointmentLetterController::class, 'queue'])
            ->name('appointment-letter.queue');
        Route::post('/appointment-letter/{application}/decide', [AppointmentLetterController::class, 'decide'])
            ->name('appointment-letter.decide');
    });

});
