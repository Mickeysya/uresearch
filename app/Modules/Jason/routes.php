<?php

use App\Modules\Core\Support\Role;
use App\Modules\Jason\Http\Controllers\AppointmentLetterController;
use App\Modules\Jason\Http\Controllers\HardboundAppealController;
use App\Modules\Jason\Http\Controllers\HardboundSubmissionController;
use Illuminate\Support\Facades\Route;

/*
| Jason (22003299)
| Hardbound Submission · Appeal Hardbound Submission · Appointment Letter
*/

Route::middleware('auth')->group(function () {

    // ---- Appointment Letter --------------------------------------------

    // Examiner selection is settled before this module: the finalised list
    // arrives as a spreadsheet and CGS imports it. There is no nomination
    // form here any more, and no examiner list of our own -- both were
    // retired on 2026-09-30 when the boundary with Hani's chain was agreed.
    // Core's Chair dashboard links here -- "Panels you filed" and its stat
    // card both call route('appointment-letter.create') unconditionally, so
    // retiring the name outright 500s the whole dashboard for every Chair.
    // Those two partials belong to the team, so rather than edit them this
    // keeps the name answering and tells the Chair where nomination went.
    // Delete this, and the view, once Core's partials are updated.
    Route::middleware('role:'.Role::CHAIR)->group(function () {
        Route::get('/appointment-letter/new', [AppointmentLetterController::class, 'nominationMoved'])
            ->name('appointment-letter.create');
    });

    Route::middleware('role:'.Role::NON_EXEC_CGS)->group(function () {
        Route::get('/appointment-letter/import', [AppointmentLetterController::class, 'importForm'])
            ->name('appointment-letter.import');
        Route::post('/appointment-letter/import', [AppointmentLetterController::class, 'import'])
            ->name('appointment-letter.import.store');
        Route::get('/appointment-letter/template', [AppointmentLetterController::class, 'template'])
            ->name('appointment-letter.template');

        // Pack preparation, the Non-Executive CGS's stage. Approving out of
        // that stage happens here rather than through the generic decide()
        // button, because this is what writes and generates the documents
        // the Dean then approves. The Dean reads them through the queue's
        // document list -- they are ordinary ApplicationDocuments, served by
        // Core's download route.
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

    // Both stages share one queue screen; ?stage= selects which. The engine
    // re-checks the role against the application's actual stage before
    // allowing any decision, same as every other module.
    Route::middleware('role:'.implode(',', [Role::NON_EXEC_CGS, Role::DEAN_PGR]))->group(function () {
        Route::get('/appointment-letter/queue', [AppointmentLetterController::class, 'queue'])
            ->name('appointment-letter.queue');
        Route::post('/appointment-letter/{application}/decide', [AppointmentLetterController::class, 'decide'])
            ->name('appointment-letter.decide');
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

        // The blank memo to fill in and sign, and the candidate's own copy
        // of the one they uploaded.
        Route::get('/hardbound-appeal/template', [HardboundAppealController::class, 'template'])
            ->name('hardbound-appeal.template');
        Route::get('/hardbound-appeal/{application}/memo', [HardboundAppealController::class, 'memo'])
            ->name('hardbound-appeal.memo');
    });

    // The memo is routed "Through" the Supervisor and the HOD/Chair, exactly
    // as the paper template is, before it reaches CGS.
    Route::middleware('role:'.implode(',', [Role::SUPERVISOR, Role::CHAIR, Role::NON_EXEC_CGS]))->group(function () {
        Route::get('/hardbound-appeal/queue', [HardboundAppealController::class, 'queue'])
            ->name('hardbound-appeal.queue');
        Route::post('/hardbound-appeal/{application}/decide', [HardboundAppealController::class, 'decide'])
            ->name('hardbound-appeal.decide');
    });

});
