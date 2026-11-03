{{--
    The documents on one application, read in the browser, laid out like a
    course page on ULearn: a list of files, each opening on its own page.

      no ?document  the list: a file icon and name per row, with Download.
      ?document=id  that one file: the PDF or image, with Download and a way
                    back to the list.

    Opened from the queue (pack/_buttons). Plain links throughout, so the page
    needs no script. The files listed are the ones this reader may open
    (ApplicationPackController::openableDocuments); the frame loads them
    through the preview route, which checks again.
--}}
@extends('core::layouts.app')

@section('title', 'Documents · '.$application->reference())

@section('content')
    @include('norhanis::pack._styles')

    @php($queue = route($application->module()->queueRoute(), ['q' => '#'.$application->id]))

    @if ($selected)
        {{-- One document --}}
        <p style="margin: 0 0 var(--space-3);">
            <a href="{{ route('norhanis.pack.documents', $application) }}">&larr; All documents</a>
        </p>

        <div class="pv-box">
            <div class="pv-doc-head">
                @include('norhanis::pack._file_icon', ['label' => $pack::fileLabel($selected)])
                <div class="pv-title">
                    <b>{{ $selected->doc_type }}</b>
                    <span>{{ $selected->original_name }} &middot; {{ $application->module()->label() }} Application #{{ $application->id }}</span>
                </div>
                <x-core::doc-link :document="$selected" action="download" class="btn-secondary">Download</x-core::doc-link>
            </div>

            @php($type = $pack::previewType($selected))
            @if ($type === 'application/pdf')
                <iframe class="pv-frame" src="{{ route('norhanis.pack.preview', $selected) }}"
                        title="{{ $selected->doc_type }}: {{ $selected->original_name }}"></iframe>
            @elseif ($type)
                <img class="pv-image" src="{{ route('norhanis.pack.preview', $selected) }}"
                     alt="{{ $selected->doc_type }}: {{ $selected->original_name }}">
            @else
                <div class="pv-none">
                    <span>{{ $selected->original_name }} cannot be shown in the browser.</span>
                    <x-core::doc-link :document="$selected" action="download" class="btn-secondary">Download it</x-core::doc-link>
                </div>
            @endif
        </div>
    @else
        {{-- The list --}}
        <p style="margin: 0 0 var(--space-3);">
            <a href="{{ $queue }}">&larr; Back to the {{ $application->module()->label() }} queue</a>
        </p>

        <x-core::page-header :title="'Documents · '.$application->module()->label().' Application #'.$application->id"
                             :subtitle="$application->student?->name.' · '.$application->module()->summary($application)">
            <x-core::doc-link class="btn-secondary" :url="route('norhanis.pack.pdf', [$application, 'inline' => 1])"
                              :download="route('norhanis.pack.pdf', $application)" name="Application summary">View summary</x-core::doc-link>
            <a href="{{ route('norhanis.pack.pdf', $application) }}" class="btn-secondary">Download summary (PDF)</a>
            <a href="{{ route('norhanis.pack.zip', $application) }}" class="btn-secondary">Download all files (ZIP)</a>
        </x-core::page-header>

        @if ($documents->isEmpty())
            <div class="empty-state">There are no documents on this application for you to open yet.</div>
        @else
            <div class="pv-box">
                <h3>{{ $documents->count() }} {{ $documents->count() === 1 ? 'document' : 'documents' }}</h3>
                <ul class="pv-rows">
                    @foreach ($documents as $document)
                        <li class="pv-row">
                            @include('norhanis::pack._file_icon', ['label' => $pack::fileLabel($document)])
                            <div class="pv-title">
                                <a href="{{ route('norhanis.pack.documents', [$application, 'document' => $document->id]) }}">{{ $document->doc_type }}</a>
                                <span>{{ $document->original_name }}</span>
                            </div>
                            <x-core::doc-link :document="$document" action="download" class="btn-secondary">Download</x-core::doc-link>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endif
@endsection
