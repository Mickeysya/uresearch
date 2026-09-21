<?php

use App\Modules\Core\Support\Role;
use App\Modules\Jason\Http\Controllers\AppointmentLetterController;
use App\Modules\Jason\Http\Controllers\ExaminerPoolController;
use App\Modules\Jason\Http\Controllers\HardboundAppealController;
use App\Modules\Jason\Http\Controllers\HardboundSubmissionController;
use Illuminate\Support\Facades\Route;

/*
| Jason (22003299)
| Hardbound Submission · Appeal Hardbound Submission · Appointment Letter
*/

Route::middleware('auth')->group(function () {

    // ---- Appointment Letter --------------------------------------------

    // The examiner list. CGS keeps it -- the Chair no longer nominates, so
    // they no longer need it either. The list and the add form are two
    // pages, not one: adding is an action, not a second view of the list,
    // and the wizard wants a card of its own (core::partials.form-stepper
    // re-casts whatever .card it finds the form in). Same shape as Hani's
    // examiner pool, /examiners and /examiners/new.
    Route::middleware('role:'.Role::NON_EXEC_CGS)->group(function () {
        Route::get('/appointment-letter/examiners', [ExaminerPoolController::class, 'index'])
            ->name('appointment-letter.examiners');
        Route::get('/appointment-letter/examiners/new', [ExaminerPoolController::class, 'create'])
            ->name('appointment-letter.examiners.create');
        Route::post('/appointment-letter/examiners', [ExaminerPoolController::class, 'store'])
            ->name('appointment-letter.examiners.store');
        Route::post('/appointment-letter/examiners/{examiner}/toggle', [ExaminerPoolController::class, 'toggle'])
            ->name('appointment-letter.examiners.toggle');
    });

    // All three stages of the chain share one queue screen; ?stage= selects
    // which. The engine re-checks the role against the application's actual
    // stage before allowing any decision, same as every other module.
    Route::middleware('role:'.implode(',', [Role::ACADEMIC_EXEC, Role::NON_EXEC_CGS, Role::DEAN_PGR]))->group(function () {
        Route::get('/appointment-letter/queue', [AppointmentLetterController::class, 'queue'])
            ->name('appointment-letter.queue');
        Route::post('/appointment-letter/{application}/decide', [AppointmentLetterController::class, 'decide'])
            ->name('appointment-letter.decide');
    });

    // Pack preparation, the Non-Executive CGS's stage. Approving out of that
    // stage happens here rather than through the generic decide() button,
    // because this is what writes and generates the documents the Dean then
    // approves. The Dean reads them through the queue's document list --
    // they are ordinary ApplicationDocuments, served by Core's download route.
    Route::middleware('role:'.Role::NON_EXEC_CGS)->group(function () {
        Route::get('/appointment-letter/{application}/prepare', [AppointmentLetterController::class, 'prepare'])
            ->name('appointment-letter.prepare');
        Route::post('/appointment-letter/{application}/prepare', [AppointmentLetterController::class, 'savePreparation'])
            ->name('appointment-letter.prepare.store');

        // What happened to the packs after the Dean approved. Dispatch runs
        // outside the workflow engine, so this is the only place a pack that
        // never reached its examiner shows up -- and where CGS resends it.
        Route::get('/appointment-letter/issued', [AppointmentLetterController::class, 'issued'])
            ->name('appointment-letter.issued');
        Route::post('/appointment-letter/{application}/resend/{examiner}', [AppointmentLetterController::class, 'resend'])
            ->name('appointment-letter.resend');
    });

    // ---- Hardbound Submission ------------------------------------------
    Route::middleware('role:'.Role::STUDENT)->group(function () {
        // The blank CGS forms the student downloads, completes and uploads back.
        Route::get('/hardbound/templates/{form}', [HardboundSubmissionController::class, 'template'])
            ->where('form', 'submission')
            ->name('hardbound.template');

        Route::get('/hardbound/new', [HardboundSubmissionController::class, 'create'])
            ->name('hardbound.create');
        Route::post('/hardbound', [HardboundSubmissionController::class, 'store'])
            ->name('hardbound.store');

        // Replacing a returned submission. The controller re-checks that the
        // application is this student's and is actually awaiting correction.
        Route::get('/hardbound/{application}/resubmit', [HardboundSubmissionController::class, 'resubmitForm'])
            ->name('hardbound.resubmit.form');
        Route::post('/hardbound/{application}/resubmit', [HardboundSubmissionController::class, 'resubmit'])
            ->name('hardbound.resubmit');
    });

    // Every role that signs the Confirmation of Correction: the Supervisor
    // confirms, the Chair endorses, CGS accepts. Same queue screen, ?stage=
    // selects which; the engine re-checks the role against the stage.
    Route::middleware('role:'.implode(',', [Role::SUPERVISOR, Role::CHAIR, Role::NON_EXEC_CGS]))->group(function () {
        Route::get('/hardbound/queue', [HardboundSubmissionController::class, 'queue'])
            ->name('hardbound.queue');
        Route::post('/hardbound/{application}/decide', [HardboundSubmissionController::class, 'decide'])
            ->name('hardbound.decide');

        // The signature each of them stamps onto the Confirmation on approval.
        Route::get('/hardbound/signature', [HardboundSubmissionController::class, 'signature'])
            ->name('hardbound.signature');
        Route::post('/hardbound/signature', [HardboundSubmissionController::class, 'storeSignature'])
            ->name('hardbound.signature.store');
        Route::get('/hardbound/signature/image', [HardboundSubmissionController::class, 'signatureImage'])
            ->name('hardbound.signature.image');
    });

    // ---- Appeal Hardbound Submission ------------------------------------
    Route::middleware('role:'.Role::STUDENT)->group(function () {
        Route::get('/hardbound-appeal/new', [HardboundAppealController::class, 'create'])
            ->name('hardbound-appeal.create');
        Route::post('/hardbound-appeal', [HardboundAppealController::class, 'store'])
            ->name('hardbound-appeal.store');
    });

    Route::middleware('role:'.implode(',', [Role::NON_EXEC_CGS, Role::SENIOR_EXEC_CGS]))->group(function () {
        Route::get('/hardbound-appeal/queue', [HardboundAppealController::class, 'queue'])
            ->name('hardbound-appeal.queue');
        Route::post('/hardbound-appeal/{application}/decide', [HardboundAppealController::class, 'decide'])
            ->name('hardbound-appeal.decide');
    });

});
