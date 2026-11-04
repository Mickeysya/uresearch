{{--
    "← Back" links marked data-browser-back (x-core::page-header) go back in
    real browser history when the previous page was on this site, so a
    filtered or paged queue comes back exactly as it was left. Otherwise the
    link just follows its href. Included once from both layouts.

    A page that already wires its own handler (several of Chloe's do) wins:
    that handler runs on the link itself and calls preventDefault(), and this
    one, listening on the document, sees that and stands back.
--}}
<script @cspNonce>
document.addEventListener('click', function (event) {
    var link = event.target.closest && event.target.closest('[data-browser-back]');
    if (! link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey) return;
    if (! document.referrer || window.history.length < 2) return;

    var previous;
    try { previous = new URL(document.referrer); } catch (e) { return; }

    if (previous.origin === window.location.origin && previous.pathname !== window.location.pathname) {
        event.preventDefault();
        window.history.back();
    }
});
</script>
