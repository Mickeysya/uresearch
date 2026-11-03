@props([
    // An ApplicationDocument, or url + download (+ name, kind) for a PDF a
    // module generates itself.
    'document' => null,
    'url' => null,
    'download' => null,
    'name' => null,
    'kind' => null,
])

{{--
    A link that opens a file in the in-app viewer (components/file-preview).
    It is a real link to the inline route, so without JavaScript, or on a
    middle/ctrl click, the file opens in a new tab instead.

    <x-core::doc-link :document="$doc" />                      -> "View"
    <x-core::doc-link :document="$doc">{{ $doc->original_name }}</x-core::doc-link>
    <x-core::doc-link :url="route('x.pdf', [$a, 'inline' => 1])" :download="route('x.pdf', $a)" name="Letter.pdf" />
--}}
@php
    if ($document) {
        $url ??= $document->viewUrl();
        $download ??= $document->url();
        $name ??= $document->original_name;
        $kind ??= \App\Modules\Core\Support\FilePreview::kind($document->original_name);
    }
    $kind ??= \App\Modules\Core\Support\FilePreview::PDF;
    $download ??= $url;
@endphp
<a href="{{ $url }}" target="_blank" rel="noopener"
   data-preview-url="{{ $url }}" data-preview-kind="{{ $kind }}"
   data-preview-name="{{ $name ?? 'Document' }}" data-preview-download="{{ $download }}"
   {{ $attributes }}>{{ $slot->isEmpty() ? 'View' : $slot }}</a><x-core::file-preview />
