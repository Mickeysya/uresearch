{{--
    One tooltip for the whole portal, in the same dark bubble the charts use
    (.chart-tip in charts.css).

    WHAT GETS ONE, with nothing to opt into:
      - any element with a title="..." -- the browser's own tooltip is slow,
        unstyled, and cannot be read on a phone;
      - any element with data-tip="..." -- use this in new markup;
      - an icon-only button or link (no visible text) with an aria-label, so
        "what does this button do" is answered on hover and on keyboard focus.

    The title attribute is moved to data-tip on first hover so the native one
    never shows as well. While the bubble is open the element points at it with
    aria-describedby, so a screen reader still hears the text.

    Included once from both layouts. Change the look in charts.css, not here.
--}}
<script @cspNonce>
(function () {
    var tip = null, current = null, pending = null, timer = null;
    var SELECTOR = '[title], [data-tip], button[aria-label], a[aria-label]';

    function bubble() {
        if (! tip) {
            tip = document.createElement('div');
            tip.id = 'uresearch-tip';
            tip.className = 'chart-tip ui-tip';
            tip.setAttribute('role', 'tooltip');
            document.body.appendChild(tip);
        }
        return tip;
    }

    function textFor(el) {
        if (el.hasAttribute('title')) {
            var t = el.getAttribute('title');
            el.removeAttribute('title');
            if (t) el.setAttribute('data-tip', t);
        }
        if (el.hasAttribute('data-tip')) return el.getAttribute('data-tip');
        // An aria-label only becomes a tooltip when nothing on screen says it.
        if (el.hasAttribute('aria-label') && ! el.textContent.trim()) return el.getAttribute('aria-label');
        return '';
    }

    function place(el) {
        var t = bubble(), r = el.getBoundingClientRect();
        var w = t.offsetWidth, h = t.offsetHeight, gap = 8;
        var vw = document.documentElement.clientWidth;
        var left = r.left + r.width / 2 - w / 2;
        var top = r.top - h - gap;
        if (top < 8) top = r.bottom + gap;               // no room above: go below
        left = Math.max(8, Math.min(left, vw - w - 8));  // stay on screen
        t.style.left = Math.round(left + window.scrollX) + 'px';
        t.style.top = Math.round(top + window.scrollY) + 'px';
    }

    function show(el) {
        var text = textFor(el);
        if (! text) return;
        current = el;
        var t = bubble();
        t.textContent = text;
        t.classList.add('is-visible');
        place(el);
        el.setAttribute('aria-describedby', 'uresearch-tip');
    }

    function hide() {
        clearTimeout(timer);
        pending = null;
        if (current) current.removeAttribute('aria-describedby');
        current = null;
        if (tip) tip.classList.remove('is-visible');
    }

    document.addEventListener('mouseover', function (e) {
        var el = e.target.closest && e.target.closest(SELECTOR);
        if (el && (el === current || el === pending)) return;   // still inside it
        hide();
        if (! el) return;
        // Strip the native title right away, or the browser shows its own
        // tooltip during the delay below.
        textFor(el);
        pending = el;
        timer = setTimeout(function () { pending = null; show(el); }, 300);
    });

    document.addEventListener('focusin', function (e) {
        var el = e.target.closest && e.target.closest(SELECTOR);
        hide();
        if (el && el.matches(':focus-visible')) show(el);   // keyboard only, not every click
    });

    document.addEventListener('focusout', hide);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') hide(); });
    window.addEventListener('scroll', hide, true);
})();
</script>
