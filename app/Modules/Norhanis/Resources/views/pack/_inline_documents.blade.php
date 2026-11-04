{{--
    The application's documents inside its queue card, like a course page on
    ULearn: one row per file. Its name opens the PDF or image in Core's
    in-app viewer, so the queue stays where it was; Download saves it. Drawn at the end of
    each module's _detail partial, and replaces Core's plain list of the
    same files on that card (see pack/_styles).

    For readers ApplicationPackController::canDownload() allows; anyone else
    keeps Core's list. Expects: $application
--}}
@php($pack = app(\App\Modules\Norhanis\Http\Controllers\ApplicationPackController::class))
@if ($pack->canDownload(request(), $application))
    @php($documents = $pack->openableDocuments(request(), $application))

    @if ($documents->isNotEmpty())
        @include('norhanis::pack._styles')

        <div class="pv-inline">
            <h4>Documents ({{ $documents->count() }})</h4>
            <div class="pv-box">
                <ul class="pv-rows">
                    @foreach ($documents as $document)
                        <li class="pv-row">
                            @include('norhanis::pack._file_icon', ['label' => $pack::fileLabel($document)])
                            <span class="pv-title">
                                {{-- Core's in-app viewer; a file the browser cannot show offers its download. --}}
                                <x-core::doc-link :document="$document">{{ $document->doc_type }}</x-core::doc-link>
                                <span>{{ $document->original_name }}</span>
                            </span>
                            <x-core::doc-link :document="$document" action="download" class="btn-secondary">Download</x-core::doc-link>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif
@endif
