@props([
    // An ApplicationDocument, or url + download (+ name, kind) for a PDF a
    // module generates itself.
    'document' => null,
    'url' => null,
    'download' => null,
    'name' => null,
    'kind' => null,
    // 'view' opens the in-app viewer; 'download' is a plain download link.
    'action' => 'view',
])

{{--
    A link that opens a file in the in-app viewer (components/file-preview),
    or with action="download" the matching Download link. View is a real link
    to the inline route, so without JavaScript, or on a middle/ctrl click,
    the file opens in a new tab instead.

    With no document (missing, or no url), or a document whose stored file
    is gone, it renders greyed out and cannot be clicked.

    <x-core::doc-link :document="$doc" />                      -> "View"
    <x-core::doc-link :document="$doc" action="download" />    -> "Download"
    <x-core::doc-link :document="$doc">{{ $doc->original_name }}</x-core::doc-link>
    <x-core::doc-link :document="$form" class="btn-secondary">View the form</x-core::doc-link>   (greyed out while $form is null)
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

    $label = $slot->isEmpty() ? ($action === 'download' ? 'Download' : 'View') : $slot;
    $unavailable = (! $document && ! $url) || ($document && ! $document->exists());
@endphp
@if ($unavailable)
    <span aria-disabled="true" title="{{ $document ? 'The stored file is missing' : 'No document yet' }}"
          {{ $attributes->class('doc-link-disabled') }}>{{ $label }}</span>
@elseif ($action === 'download')
    <a href="{{ $download }}" {{ $attributes }}>{{ $label }}</a>
@else
    <a href="{{ $url }}" target="_blank" rel="noopener"
       data-preview-url="{{ $url }}" data-preview-kind="{{ $kind }}"
       data-preview-name="{{ $name ?? 'Document' }}" data-preview-download="{{ $download }}"
       {{ $attributes }}>{{ $label }}</a><x-core::file-preview />
@endif
