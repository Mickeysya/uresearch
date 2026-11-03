@extends('core::layouts.app')

@section('title', 'Preview not available')

{{-- A direct visit to a preview URL for a file the browser can't show
     (DocumentPreviewController::view()). The overlay never loads this page:
     it shows the same message itself. --}}
@section('content')
<div class="card-container-inline">
    <x-core::page-header title="Preview not available" />
    <div class="card card-wide">
        <p style="margin-top: 0;">
            {{ $isWord ? 'Preview not available for Word files.' : 'Preview not available for this file type.' }}
            Download it to open it on your computer.
        </p>
        <p class="queue-meta">{{ $document->doc_type }}: {{ $document->original_name }}</p>
        <a href="{{ $document->url() }}" class="btn-secondary">Download</a>
    </div>
</div>
@endsection
