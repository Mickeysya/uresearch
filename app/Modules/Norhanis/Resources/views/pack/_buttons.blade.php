{{--
    The downloads for one application, for every approver in its chain who
    may see it (ApplicationPackController::canDownload): its summary as a
    PDF, and the summary with every attached file as a ZIP. A queue can hold
    rows outside the reader's scope -- queues are scoped by role alone -- and
    those get none. The files themselves, to read or download one at a
    time, are pack/_inline_documents at the end of the card.

    Expects: $application
--}}
@php($pack = app(\App\Modules\Norhanis\Http\Controllers\ApplicationPackController::class))
@if ($pack->canDownload(request(), $application))
    <p class="pack-buttons" style="display: flex; flex-wrap: wrap; gap: 8px; margin: 12px 0;">
        <x-core::doc-link class="btn-secondary" :url="route('norhanis.pack.pdf', [$application, 'inline' => 1])"
                          :download="route('norhanis.pack.pdf', $application)" name="Application summary">View summary</x-core::doc-link>
        <a href="{{ route('norhanis.pack.pdf', $application) }}" class="btn-secondary">Download summary (PDF)</a>
        <a href="{{ route('norhanis.pack.zip', $application) }}" class="btn-secondary">Download all files (ZIP)</a>
    </p>
@endif
