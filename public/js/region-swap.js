/**
 * Same-document navigation for a page built out of independent regions --
 * tabs, a filter form, a results list -- that all filter the same GET
 * route. Fetches the target URL, swaps only the named regions in from the
 * response, and updates the address bar, instead of letting the browser do
 * a full navigation for what is, structurally, still the same page.
 *
 * Every link and form this binds keeps its real href/action, so nothing
 * here is required -- with JS off, or if a fetch fails, it is a normal
 * navigation. That fallback is deliberate, not a leftover: see the .catch()
 * in navigate() below.
 *
 * Deliberately NOT document.startViewTransition(): layout.css's
 * @view-transition rule fades the whole page root on every real navigation,
 * with only .sidebar and .main-content-header pinned out of it. Reusing
 * that same API for an in-page swap pulls whatever regions a caller names
 * into that global root fade too, and since a caller's regions are usually
 * a different size tab to tab, the root snapshot's height changes
 * mid-animation -- stretching the page while the pinned sidebar stays
 * still. A plain swap has none of that.
 *
 * Usage (see hani::examiner_admin.index or core::documents.index):
 *
 *   RegionSwap({
 *     // Every one of these must exist once in both the current document
 *     // and the fetched one -- each is swapped whole, via replaceWith().
 *     selectors: ['.page-header p', '.exam-tabs', '.exam-table-wrap'],
 *
 *     // Optional: forms that should navigate but live outside every
 *     // selector above, so they are never themselves replaced (a search
 *     // box in the page header, say, next to a tab strip below it).
 *     extraForms: ['.doc-search'],
 *
 *     // Optional: re-run whatever page-specific JS wires up the swapped
 *     // regions' own widgets (a modal trigger, a second autosubmit field)
 *     // -- called once up front and again after every swap.
 *     afterSwap: function () { ... },
 *   });
 */
(function () {
    function RegionSwap(config) {
        var selectors = config.selectors || [];
        var extraForms = config.extraForms || [];
        var afterSwap = config.afterSwap || function () {};

        // A swapped region can mix same-page filter/sort/page links (bind
        // these) with links that go somewhere else entirely -- a file
        // download, a different page's detail view (leave these as real
        // navigations). data-region-swap-ignore marks the second kind; there
        // is no way to infer it from the href alone.
        function bindLinks(scope) {
            scope.querySelectorAll('a[href]').forEach(function (link) {
                // The in-app document viewer (components/file-preview) owns these.
                if (link.dataset.regionSwapIgnore !== undefined || link.dataset.previewUrl !== undefined) return;

                link.addEventListener('click', function (e) {
                    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;
                    e.preventDefault();
                    navigate(link.href);
                });
            });
        }

        // Guarded: a selector-region form is a fresh element every time
        // (swapped in from the fetch), but an extraForms element is the
        // same persistent node across every swap, so binding it again on
        // every swap would stack up duplicate submit listeners.
        //
        // GET only: a swapped region can hold POST forms that have nothing
        // to do with filtering -- "mark as read", "reactivate" -- and those
        // must stay real submissions. method reflects the resolved HTTP
        // method ('get' is the default for a form with none set), so this
        // also correctly leaves an unmarked form alone.
        function bindForm(form) {
            if (! form || form.dataset.regionSwapBound || form.method !== 'get') return;
            form.dataset.regionSwapBound = '1';

            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var params = new URLSearchParams(new FormData(form));
                var qs = params.toString();
                navigate(form.getAttribute('action') + (qs ? '?' + qs : ''));
            });

            // requestSubmit(), not submit(): the latter bypasses the
            // 'submit' listener above entirely, which would put a full
            // navigation right back the moment a select's value changed.
            form.querySelectorAll('[data-autosubmit]').forEach(function (field) {
                field.addEventListener('change', function () { form.requestSubmit(); });
            });
        }

        function bindRegions() {
            selectors.forEach(function (selector) {
                document.querySelectorAll(selector).forEach(function (region) {
                    bindLinks(region);
                    if (region.tagName === 'FORM') bindForm(region);
                    region.querySelectorAll('form').forEach(bindForm);
                });
            });
            extraForms.forEach(function (selector) {
                document.querySelectorAll(selector).forEach(bindForm);
            });
            afterSwap();
        }

        function applySwap(html) {
            var incoming = new DOMParser().parseFromString(html, 'text/html');
            selectors.forEach(function (selector) {
                var next = incoming.querySelector(selector);
                var current = document.querySelector(selector);
                if (next && current) current.replaceWith(next);
            });
            bindRegions();
        }

        function navigate(url, recordHistory) {
            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (response) {
                    if (! response.ok) throw new Error('region-swap: request failed');
                    return response.text();
                })
                .then(function (html) {
                    if (recordHistory !== false) history.pushState({ regionSwap: true }, '', url);
                    applySwap(html);
                })
                // A network hiccup shouldn't strand the page on stale data --
                // fall back to the real navigation the link/form already
                // pointed at.
                .catch(function () { window.location.assign(url); });
        }

        window.addEventListener('popstate', function () { navigate(location.href, false); });

        bindRegions();
    }

    window.RegionSwap = RegionSwap;
})();
