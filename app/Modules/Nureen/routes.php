<?php

use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Http\Controllers\AnalyticsController;
use App\Modules\Nureen\Http\Controllers\AttendanceAppealController;
use App\Modules\Nureen\Http\Controllers\AttendanceController;
use App\Modules\Nureen\Http\Controllers\AttendanceIngestController;
use App\Modules\Nureen\Http\Controllers\CertificateVerificationController;
use App\Modules\Nureen\Http\Controllers\CertificationController;
use App\Modules\Nureen\Http\Controllers\GaExtensionController;
use App\Modules\Nureen\Http\Controllers\SupervisionController;
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
        // Open to the whole chain; WorkflowEngine::returnTo() refuses it
        // anywhere but CGS Staff's cgs_verify stage -- see
        // GaExtensionController::returnToSupervisor().
        Route::post('/ga-extension/{application}/return', [GaExtensionController::class, 'returnToSupervisor'])->name('ga-extension.return');
    });

    // ---- Supervision ----------------------------------------------------
    Route::middleware('role:'.Role::STUDENT)->group(function () {
        Route::get('/supervision/new', [SupervisionController::class, 'create'])->name('supervision.create');
        Route::post('/supervision', [SupervisionController::class, 'store'])->name('supervision.store');
    });

    Route::middleware('role:'.implode(',', [Role::SUPERVISOR, Role::CHAIR, Role::NON_EXEC_CGS]))->group(function () {
        Route::get('/supervision/queue', [SupervisionController::class, 'queue'])->name('supervision.queue');
        Route::post('/supervision/{application}/decide', [SupervisionController::class, 'decide'])->name('supervision.decide');
    });

    Route::middleware('role:'.Role::NON_EXEC_CGS)->group(function () {
        // Every appointment letter ever issued -- an approved application
        // drops out of the pending queue above, so this is where Revoke is
        // actually reachable once a letter exists. See
        // SupervisionController::issued()/revokeLetter()/downloadLetter().
        Route::get('/supervision/issued', [SupervisionController::class, 'issued'])->name('supervision.issued');
        Route::get('/supervision/{application}/letter', [SupervisionController::class, 'downloadLetter'])->name('supervision.letter.download');
        Route::post('/supervision/{application}/revoke', [SupervisionController::class, 'revokeLetter'])->name('supervision.revoke');
    });

    // ---- GA/GRA Certification Letter ------------------------------------
    Route::middleware('role:'.Role::STUDENT)->group(function () {
        Route::get('/ga-certification/new', [CertificationController::class, 'create'])->name('ga-certification.create');
        Route::post('/ga-certification', [CertificationController::class, 'store'])->name('ga-certification.store');
    });

    Route::middleware('role:'.implode(',', [Role::SUPERVISOR, Role::NON_EXEC_CGS, Role::SENIOR_DIRECTOR_CGS]))->group(function () {
        Route::get('/ga-certification/queue', [CertificationController::class, 'queue'])->name('ga-certification.queue');
        Route::post('/ga-certification/{application}/decide', [CertificationController::class, 'decide'])->name('ga-certification.decide');
        // Marks an already-issued certificate invalid; does not touch the
        // application's own status -- see CertificationController::revoke().
        Route::post('/ga-certification/{application}/revoke', [CertificationController::class, 'revoke'])->name('ga-certification.revoke');
        // Every certificate ever issued -- an approved application drops
        // out of the pending queue above, so this is where Revoke is
        // actually reachable once a certificate exists.
        Route::get('/ga-certification/issued', [CertificationController::class, 'issued'])->name('ga-certification.issued');
    });

    // ---- Attendance -------------------------------------------------------
    Route::middleware('role:'.Role::STUDENT)->group(function () {
        Route::get('/attendance/overview', [AttendanceController::class, 'overview'])->name('attendance.overview');
        Route::get('/attendance/history', [AttendanceController::class, 'history'])->name('attendance.history');

        Route::get('/attendance-appeal/new', [AttendanceAppealController::class, 'create'])->name('attendance-appeal.create');
        Route::post('/attendance-appeal', [AttendanceAppealController::class, 'store'])->name('attendance-appeal.store');
    });

    Route::middleware('role:'.Role::NON_EXEC_CGS)->group(function () {
        Route::get('/attendance/upload', [AttendanceController::class, 'uploadForm'])->name('attendance.upload.form');
        Route::post('/attendance/upload', [AttendanceController::class, 'upload'])->name('attendance.upload');
        // The blank sheet to fill in. ?format=csv for CSV; .xlsx by default.
        Route::get('/attendance/template', [AttendanceController::class, 'template'])->name('attendance.template');
        Route::get('/attendance/at-risk', [AttendanceController::class, 'atRisk'])->name('attendance.at-risk');
        // Runs attendance:sync on demand -- see AttendanceController::syncNow().
        Route::post('/attendance/sync-now', [AttendanceController::class, 'syncNow'])->name('attendance.sync-now');

        Route::get('/attendance-appeal/queue', [AttendanceAppealController::class, 'queue'])->name('attendance-appeal.queue');
        Route::post('/attendance-appeal/{application}/decide', [AttendanceAppealController::class, 'decide'])->name('attendance-appeal.decide');

        // ---- Processing time analytics ---------------------------------
        Route::get('/reports/processing-time', [AnalyticsController::class, 'index'])->name('nureen.analytics');
    });

});

// ---- Automated attendance ingestion ------------------------------------
// Outside `auth`: this is machine-to-machine, authenticated by the
// X-Api-Key header instead of a session. Still gets the `web` middleware
// group (and therefore CSRF) because ModuleServiceProvider wraps every
// module's routes.php in it -- see SHARED_CHANGES.md for the exemption a
// real external caller needs. Throttled regardless of that, since it is
// reachable without a session at all.
Route::post('/attendance/ingest', [AttendanceIngestController::class, 'store'])
    ->middleware('throttle:60,1')
    ->name('attendance.ingest');

// ---- Public certificate verification -----------------------------------
// No login: the whole point is that anyone holding a printed letter (or
// scanning its QR code) can confirm it is genuine. Throttled for the same
// reason the ingest endpoint is -- reachable with no session at all.
Route::get('/verify/certificate/{reference}', [CertificateVerificationController::class, 'show'])
    ->middleware('throttle:30,1')
    ->where('reference', '.*')
    ->name('certificate.verify');
