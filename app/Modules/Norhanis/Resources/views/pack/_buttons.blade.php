{{--
    The downloads for one application. CGS gets its summary as a PDF, or the
    summary with every attached file it may open, as a ZIP. The supervisor
    gets only the files their student uploaded, as a ZIP. Chair and Dean get
    none (ApplicationPackController). A queue can hold rows the applications
    list hides -- queues are scoped by role alone -- and those get no buttons.

    Expects: $application
--}}
@php($pack = app(\App\Modules\Norhanis\Http\Controllers\ApplicationPackController::class))
@if ($pack->canDownload(request(), $application))
    <p class="pack-buttons" style="display: flex; flex-wrap: wrap; gap: 8px; margin: 12px 0;">
        @if ($pack->canDownloadSummary(request(), $application))
            <a href="{{ route('norhanis.pack.pdf', $application) }}" class="btn-secondary">Download summary (PDF)</a>
            <a href="{{ route('norhanis.pack.zip', $application) }}" class="btn-secondary">Download all files (ZIP)</a>
        @else
            <a href="{{ route('norhanis.pack.zip', $application) }}" class="btn-secondary">Download student's files (ZIP)</a>
        @endif
    </p>
@endif
