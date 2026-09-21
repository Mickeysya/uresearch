{{--
    Applied before the body paints, straight from localStorage, so a dark
    theme never flashes white first -- this is a full page reload on every
    navigation, not an SPA. Same reason and same shape as the sidebar's
    collapsed-state script in the app layout.

    No stored choice means no attribute at all, which is deliberate: the
    tokens then fall through to the @media (prefers-color-scheme) block and
    follow the operating system. The attribute is only ever written when
    someone has actually pressed the toggle.
--}}
<script @cspNonce>
    (function () {
        try {
            var choice = localStorage.getItem('theme');
            if (choice === 'dark' || choice === 'light') {
                document.documentElement.setAttribute('data-theme', choice);
            }
        } catch (e) {}
    })();
</script>
