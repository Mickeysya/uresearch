{{--
    One button, two glyphs. CSS decides which is visible (see layout.css
    §11): the icon shown is the theme you would switch TO, because that is
    what pressing it does.

    aria-pressed is not used here -- this is not an on/off control, it is a
    "switch to X" action, and the label says which. The label is rewritten
    on click so a screen reader announces the new destination, not the old.
--}}
<button type="button"
        id="theme-toggle"
        class="theme-toggle"
        title="Switch theme"
        aria-label="Switch to dark theme">
    <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
    </svg>
    <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <circle cx="12" cy="12" r="4.2"/>
        <path d="M12 1.6v2.4M12 20v2.4M4.1 4.1l1.7 1.7M18.2 18.2l1.7 1.7M1.6 12h2.4M20 12h2.4M4.1 19.9l1.7-1.7M18.2 5.8l1.7-1.7"/>
    </svg>
</button>

<script @cspNonce>
    (function () {
        var toggle = document.getElementById('theme-toggle');
        if (! toggle) return;

        var root = document.documentElement;

        // What the page is showing right now: an explicit choice if one has
        // been made, otherwise whatever the OS asked for.
        function current() {
            return root.getAttribute('data-theme')
                || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        }

        function describe() {
            toggle.setAttribute('aria-label',
                current() === 'dark' ? 'Switch to light theme' : 'Switch to dark theme');
        }

        toggle.addEventListener('click', function () {
            var next = current() === 'dark' ? 'light' : 'dark';

            root.setAttribute('data-theme', next);
            describe();

            try {
                localStorage.setItem('theme', next);
            } catch (e) {}
        });

        // Follow the OS while the reader has expressed no preference of
        // their own. Once they have, their choice wins and this stops.
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
            if (! root.hasAttribute('data-theme')) describe();
        });

        describe();
    })();
</script>
