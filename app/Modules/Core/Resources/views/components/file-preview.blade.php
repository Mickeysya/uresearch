{{--
    The in-app document viewer. components/doc-link pulls it in with the
    first link on a page (@once, pushed to the layout's 'scripts' stack so
    the <dialog> never lands inside a <p>), so a page with no documents
    carries none of it; any link carrying data-preview-url / -kind / -name / -download
    opens it. Its script moves the dialog to <body>.
    ponytail: a link that only arrives later through RegionSwap, on a page
    that had none at load, gets no overlay and opens in a new tab instead. Moved here from Nureen's
    module, where it started. A native <dialog> opened with showModal(),
    so the rest of the page is inert and Esc closes it; Tab is also kept
    inside it, clicking the dimmed area outside closes it, and focus goes
    back to the link that opened it. One limit: after someone clicks INTO a
    PDF, the browser's viewer has the keyboard and Esc can't reach this page
    until they click outside it or use Close.

    PDFs load in an <iframe>, JPG/PNG in an <img>, both from the inline
    preview routes, which use the same authorisation as the downloads.
    Word and other files can't render in a browser: the overlay says so
    and offers the download -- nothing is converted and nothing is framed,
    because Core's CSP forbids framing any HTML page.

    About 90% of the viewport; full screen at phone width.
--}}
@once
@push('scripts')
<dialog class="file-preview" id="file-preview" aria-labelledby="file-preview-title">
    <div class="file-preview-box">
        <div class="file-preview-head">
            <h2 class="file-preview-title" id="file-preview-title">Preview</h2>
            <a href="#" class="btn-secondary file-preview-download" data-preview-download>Download</a>
            <button type="button" class="btn-secondary file-preview-close" data-preview-close aria-label="Close preview">Close</button>
        </div>
        <div class="file-preview-body">
            {{-- tabindex -1: once focus is inside the browser's PDF viewer,
                 its key presses never reach this page, so Esc and the Tab
                 trap would stop working. Mouse and touch still scroll it;
                 Download is there for anyone who needs the file itself. --}}
            <iframe class="file-preview-frame" title="Document preview" tabindex="-1" hidden data-preview-frame></iframe>
            <img class="file-preview-image" alt="" hidden data-preview-image>
            <div class="file-preview-unavailable" hidden data-preview-unavailable>
                <p data-preview-unavailable-text>Preview not available for Word files.</p>
                <a href="#" class="btn" data-preview-unavailable-download>Download</a>
            </div>
        </div>
    </div>
</dialog>

<style>
    html:has(dialog.file-preview[open]) { overflow: hidden; }
    .file-preview {
        width: 90vw; height: 90vh; max-width: 90vw; max-height: 90vh;
        padding: 0; border: 1px solid var(--border-subtle); border-radius: var(--radius-md);
        background: var(--surface); color: var(--text-dark); box-shadow: var(--shadow-lg);
    }
    .file-preview::backdrop { background: rgba(10, 16, 28, 0.62); }
    .file-preview-box { display: flex; flex-direction: column; height: 100%; }
    .file-preview-head {
        display: flex; align-items: center; gap: var(--space-3);
        padding: var(--space-3) var(--space-4); border-bottom: 1px solid var(--border-subtle);
    }
    .file-preview-title { flex: 1; min-width: 0; margin: 0; font-size: var(--text-md); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .file-preview-head .btn-secondary { margin: 0; min-height: 36px; padding: 6px 14px; font-size: var(--text-sm); }
    .file-preview-head :is(a, button):focus-visible { outline: 3px solid var(--navy); outline-offset: 2px; }
    .file-preview-body { flex: 1; min-height: 0; display: flex; align-items: center; justify-content: center; background: var(--surface-raised); }
    .file-preview-frame { width: 100%; height: 100%; border: 0; background: #fff; }
    .file-preview-image { max-width: 100%; max-height: 100%; object-fit: contain; }
    .file-preview-unavailable { text-align: center; padding: var(--space-6); }
    .file-preview-unavailable .btn { display: inline-block; margin: var(--space-3) 0 0; text-decoration: none; }
    @media (max-width: 640px) {
        .file-preview { width: 100vw; height: 100dvh; max-width: 100vw; max-height: 100dvh; border-radius: 0; border: 0; }
    }
</style>

<script @cspNonce>
(function () {
    var dialog = document.getElementById('file-preview');
    if (!dialog || dialog.dataset.ready) return;
    dialog.dataset.ready = '1';

    // Rendered wherever the first Documents list is -- often inside a
    // queue row's closed <details>, where even a modal dialog can't show.
    document.body.appendChild(dialog);

    var title = dialog.querySelector('#file-preview-title');
    var download = dialog.querySelector('[data-preview-download]');
    var frame = dialog.querySelector('[data-preview-frame]');
    var image = dialog.querySelector('[data-preview-image]');
    var unavailable = dialog.querySelector('[data-preview-unavailable]');
    var unavailableText = dialog.querySelector('[data-preview-unavailable-text]');
    var unavailableDownload = dialog.querySelector('[data-preview-unavailable-download]');
    var trigger = null;

    function open(link) {
        var kind = link.getAttribute('data-preview-kind');
        var name = link.getAttribute('data-preview-name');
        var url = link.getAttribute('data-preview-url');
        var file = link.getAttribute('data-preview-download');

        trigger = link;
        title.textContent = name;
        download.href = file;
        frame.hidden = image.hidden = unavailable.hidden = true;

        if (kind === 'pdf') {
            frame.title = 'Preview of ' + name;
            frame.src = url;
            frame.hidden = false;
        } else if (kind === 'image') {
            image.alt = name;
            image.src = url;
            image.hidden = false;
        } else {
            unavailableText.textContent = kind === 'word'
                ? 'Preview not available for Word files.'
                : 'Preview not available for this file type.';
            unavailableDownload.href = file;
            unavailable.hidden = false;
        }

        dialog.showModal();
        dialog.querySelector('[data-preview-close]').focus();
    }

    function close() {
        if (dialog.open) dialog.close();
    }

    document.addEventListener('click', function (event) {
        var link = event.target.closest && event.target.closest('[data-preview-url]');
        if (!link || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey) return;
        event.preventDefault();
        open(link);
    });

    dialog.querySelector('[data-preview-close]').addEventListener('click', close);

    // A click on the dimmed area lands on the <dialog> itself, never on the box.
    dialog.addEventListener('click', function (event) {
        if (event.target === dialog) close();
    });

    // Keep Tab inside the overlay.
    dialog.addEventListener('keydown', function (event) {
        if (event.key !== 'Tab') return;
        var focusable = Array.prototype.filter.call(
            dialog.querySelectorAll('a[href], button'),
            function (el) { return !el.hidden && !el.closest('[hidden]'); }
        );
        if (!focusable.length) return;
        var first = focusable[0], last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });

    // Esc (the dialog's own cancel), Close or the backdrop all end here.
    dialog.addEventListener('close', function () {
        frame.removeAttribute('src');
        image.removeAttribute('src');
        if (trigger) trigger.focus();
        trigger = null;
    });
})();
</script>
@endpush
@endonce
