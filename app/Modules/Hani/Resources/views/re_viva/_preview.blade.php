{{-- Inline PDFs. Same-origin frames of non-HTML responses pass the CSP. Expects: $src, $title. --}}
<details class="reviva-preview">
    <summary>{{ $title }}</summary>
    <iframe src="{{ $src }}" title="{{ $title }}" style="width: 100%; height: 480px; border: 1px solid var(--border-strong);"></iframe>
</details>
