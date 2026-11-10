<?php

use App\Modules\Core\Support\Role;
use App\Modules\Norhanis\Http\Controllers\ApplicationOverviewController;
use App\Modules\Norhanis\Http\Controllers\ApplicationPackController;
use App\Modules\Norhanis\Http\Controllers\ApplicationReportController;
use App\Modules\Norhanis\Http\Controllers\ClaimsController;
use App\Modules\Norhanis\Http\Controllers\PublicationCertificationController;
use App\Modules\Norhanis\Http\Controllers\PublicationController;
use App\Modules\Norhanis\Http\Controllers\PublicationEvaluationController;
use App\Modules\Norhanis\Http\Controllers\TaHourApprovalController;
use App\Modules\Norhanis\Http\Controllers\TaHourController;
use App\Modules\Norhanis\Http\Controllers\TravelController;
use App\Modules\Norhanis\Support\Roles;
use Illuminate\Support\Facades\Route;

/*
| Norhanis Erna Natasha (22006318)
| Travel · Publication · Claims
*/

Route::middleware('auth')->group(function () {

    // ---- Travel, Claims and Publication: who has applied -----------------
    Route::middleware('role:'.implode(',', ApplicationOverviewController::ROLES))->group(function () {
        // Read-only: has this student applied, and where has it reached? One
        // list per module (/travel, /claims, /publication), or all together
        // without one. CGS only, since it shows every approver's stage: the
        // others act from their own queue, which shows theirs alone.
        Route::get('/applications-overview/{module?}', [ApplicationOverviewController::class, 'index'])
            ->middleware('role:'.implode(',', ApplicationOverviewController::LIST_ROLES))
            ->whereIn('module', array_keys(ApplicationOverviewController::MODULES))
            ->name('norhanis.overview');

        // One application to keep: a summary PDF of what the student filled
        // in, or that summary with every attached file as a ZIP. Which
        // applications, and which files, is decided in the controller.
        Route::get('/applications-pack/{application}/summary.pdf', [ApplicationPackController::class, 'pdf'])
            ->name('norhanis.pack.pdf');
        Route::get('/applications-pack/{application}/documents.zip', [ApplicationPackController::class, 'zip'])
            ->middleware('throttle:20,1')
            ->name('norhanis.pack.zip');
        // Every document on one application, to read in the browser, and the
        // single PDF or image that page shows.
        Route::get('/applications-pack/{application}/documents', [ApplicationPackController::class, 'documents'])
            ->name('norhanis.pack.documents');
        Route::get('/applications-pack/document/{document}/preview', [ApplicationPackController::class, 'preview'])
            ->name('norhanis.pack.preview');

        // The same applications, counted: totals, where they are waiting and
        // how long each stage takes, with a CSV of the rows behind it. CGS
        // only: analysing the whole process is CGS's job, and every other
        // approver's dashboard is their own decisions, on the list above.
        Route::middleware('role:'.implode(',', ApplicationReportController::REPORT_ROLES))->group(function () {
            Route::get('/applications-report/{module?}', [ApplicationReportController::class, 'index'])
                ->whereIn('module', array_keys(ApplicationOverviewController::MODULES))
                ->name('norhanis.report');
            Route::get('/applications-report-export/{module?}', [ApplicationReportController::class, 'export'])
                ->whereIn('module', array_keys(ApplicationOverviewController::MODULES))
                ->name('norhanis.report.export');
        });
    });

    // ---- Travel -------------------------------------------------------
    Route::middleware('role:'.Role::STUDENT)->group(function () {
        Route::get('/travel/new', [TravelController::class, 'create'])->name('travel.create');
        Route::post('/travel', [TravelController::class, 'store'])->name('travel.store');

        // Saved drafts -- the owning student only (Support\Drafts::ownedBy()).
        // Named <module>.draft.* so Nureen's My Drafts page can link to them.
        Route::post('/travel/draft', [TravelController::class, 'saveDraft'])->name('travel.draft.save');
        Route::get('/travel/draft/{application}', [TravelController::class, 'editDraft'])->name('travel.draft.edit');
        Route::post('/travel/draft/{application}', [TravelController::class, 'saveDraft'])->name('travel.draft.update');
        Route::delete('/travel/draft/{application}', [TravelController::class, 'destroyDraft'])->name('travel.draft.destroy');
    });

    // Every role that owns a stage in the travel chain shares one queue
    // screen; ?stage= selects which. The engine re-checks the role against
    // the application's actual stage before allowing any decision.
    Route::middleware('role:'.implode(',', [
        Role::SUPERVISOR, Role::CHAIR, Role::NON_EXEC_CGS, Role::DEAN_PGR, Role::SENIOR_DIRECTOR_CGS,
    ]))->group(function () {
        Route::get('/travel/queue', [TravelController::class, 'queue'])->name('travel.queue');
        Route::post('/travel/{application}/decide', [TravelController::class, 'decide'])->name('travel.decide');
    });

    // The applicant's own details page, or anyone holding a stage this
    // application has reached -- see TravelController::visibleTo(). Not
    // role-gated here because both students and approvers use it.
    Route::get('/travel/{application}', [TravelController::class, 'show'])->name('travel.show');

    // ---- Claims (student) ----------------------------------------------
    Route::middleware('role:'.Role::STUDENT)->group(function () {
        Route::get('/claims/new', [ClaimsController::class, 'create'])->name('claims.create');
        Route::post('/claims', [ClaimsController::class, 'store'])->name('claims.store');

        // module_type is claims_student, hence the route names.
        Route::post('/claims/draft', [ClaimsController::class, 'saveDraft'])->name('claims-student.draft.save');
        Route::get('/claims/draft/{application}', [ClaimsController::class, 'editDraft'])->name('claims-student.draft.edit');
        Route::post('/claims/draft/{application}', [ClaimsController::class, 'saveDraft'])->name('claims-student.draft.update');
        Route::delete('/claims/draft/{application}', [ClaimsController::class, 'destroyDraft'])->name('claims-student.draft.destroy');
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

        Route::post('/publication/draft', [PublicationController::class, 'saveDraft'])->name('publication.draft.save');
        Route::get('/publication/draft/{application}', [PublicationController::class, 'editDraft'])->name('publication.draft.edit');
        Route::post('/publication/draft/{application}', [PublicationController::class, 'saveDraft'])->name('publication.draft.update');
        Route::delete('/publication/draft/{application}', [PublicationController::class, 'destroyDraft'])->name('publication.draft.destroy');

        // Who has signed the online Authorship Certification, and a nudge
        // for whoever has not. Throttled so it cannot be used to flood an inbox.
        Route::get('/publication/{application}/sign-offs', [PublicationCertificationController::class, 'signOffs'])
            ->name('publication.sign-offs');
        Route::post('/publication/{application}/authors/{author}/remind', [PublicationCertificationController::class, 'remind'])
            ->middleware('throttle:6,1')
            ->name('publication.authors.remind');

        // The same for the internal reviewers' Paper Evaluation Sheets, plus
        // asking a different reviewer and answering "with corrections".
        Route::post('/publication/{application}/evaluations/{evaluation}/remind', [PublicationEvaluationController::class, 'remind'])
            ->middleware('throttle:6,1')
            ->name('publication.evaluations.remind');
        Route::post('/publication/{application}/evaluations/{evaluation}/replace', [PublicationEvaluationController::class, 'replace'])
            ->middleware('throttle:6,1')
            ->name('publication.evaluations.replace');
        Route::post('/publication/{application}/evaluations/{evaluation}/respond', [PublicationEvaluationController::class, 'respond'])
            ->name('publication.evaluations.respond');
    });

    Route::middleware('role:'.implode(',', [
        Role::SUPERVISOR, Role::CHAIR, Role::NON_EXEC_CGS, Role::SENIOR_DIRECTOR_CGS,
    ]))->group(function () {
        Route::get('/publication/queue', [PublicationController::class, 'queue'])->name('publication.queue');
        Route::post('/publication/{application}/decide', [PublicationController::class, 'decide'])->name('publication.decide');
    });

    // ---- Daily Log TA Hours ---------------------------------------------
    // Every role reaches the index: a GA/GRA student logs their own hours,
    // a course lecturer is sent to their approvals, supervisors and CGS see
    // hours in their scope. Scoped inside TaHourController, because "my own"
    // vs "my supervisees" needs the signed-in user's row.
    Route::get('/ta-hours', [TaHourController::class, 'index'])->name('ta-hours.index');

    Route::middleware('role:'.Role::STUDENT)->group(function () {
        Route::get('/ta-hours/log/{year}/{month}', [TaHourController::class, 'sheet'])
            ->whereNumber(['year', 'month'])->name('ta-hours.sheet');
        Route::post('/ta-hours/log/{year}/{month}', [TaHourController::class, 'store'])
            ->whereNumber(['year', 'month'])->name('ta-hours.store');
        Route::post('/ta-hours/log/{year}/{month}/submit', [TaHourController::class, 'submit'])
            ->whereNumber(['year', 'month'])->name('ta-hours.submit');
        Route::delete('/ta-hours/rows/{log}', [TaHourController::class, 'destroy'])->name('ta-hours.destroy');
    });

    // The course lecturer, for rows naming them only (TaHourApprovalController).
    Route::middleware('role:'.Roles::LECTURER)->group(function () {
        Route::get('/ta-hours/approvals', [TaHourApprovalController::class, 'index'])->name('ta-hours.approvals');
        Route::post('/ta-hours/approvals', [TaHourApprovalController::class, 'decide'])->name('ta-hours.decide');
    });
});

// ---- Publication: sign-offs from people without an account ---------------
// Deliberately outside `auth`: a co-author is often outside UTP, and a
// reviewer need not have a portal account. The signed link emailed to them is
// the credential, and it names exactly one author or evaluation row -- see
// PublicationSignOffs::signedUrl().
Route::middleware(['signed:relative', 'throttle:30,1'])->group(function () {
    Route::get('/publication/certify/{author}', [PublicationCertificationController::class, 'show'])
        ->name('publication.certify');
    Route::post('/publication/certify/{author}', [PublicationCertificationController::class, 'sign'])
        ->name('publication.certify.sign');

    Route::get('/publication/evaluate/{evaluation}', [PublicationEvaluationController::class, 'show'])
        ->name('publication.evaluate');
    Route::get('/publication/evaluate/{evaluation}/paper', [PublicationEvaluationController::class, 'paper'])
        ->name('publication.evaluate.paper');
    Route::post('/publication/evaluate/{evaluation}', [PublicationEvaluationController::class, 'submit'])
        ->name('publication.evaluate.submit');
    Route::post('/publication/evaluate/{evaluation}/decline', [PublicationEvaluationController::class, 'decline'])
        ->name('publication.evaluate.decline');
});
