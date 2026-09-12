{{--
    Counts any number on the page up to its final value once, on load.

    Opt in by putting data-count-to on the element:

        <span data-count-to="88" data-count-decimals="1" data-count-suffix="%">88%</span>
        <span data-count-to="1432">1,432</span>

    Attributes:
      data-count-to        the final value (required)
      data-count-decimals  decimal places, default 0
      data-count-suffix    appended to every frame, e.g. "%"
      data-count-duration  ms, default 900
      data-count-delay     seconds to wait before starting, default 0

    The final value is always rendered server-side as the element's own text,
    so if this script never runs the correct number is already on screen.
    @once means it ships once however many panels include it.
--}}
@once
    @push('scripts')
        <script>
            (function () {
                if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

                document.querySelectorAll('[data-count-to]').forEach(function (el) {
                    var target = parseFloat(el.getAttribute('data-count-to'));
                    if (isNaN(target)) return;

                    var decimals = parseInt(el.getAttribute('data-count-decimals') || '0', 10);
                    var suffix   = el.getAttribute('data-count-suffix') || '';
                    var duration = parseInt(el.getAttribute('data-count-duration') || '900', 10);
                    var delay    = (parseFloat(el.getAttribute('data-count-delay')) || 0) * 1000;

                    function format(v) {
                        if (decimals > 0) {
                            var p = Math.pow(10, decimals);
                            return (Math.round(v * p) / p).toFixed(decimals).replace(/\.0+$/, '') + suffix;
                        }
                        return Math.round(v).toLocaleString() + suffix;
                    }

                    var started = null;

                    function frame(now) {
                        if (started === null) started = now;
                        var p = Math.min((now - started) / duration, 1);
                        // Matches the easing on the gauge sweep and the donut
                        // draw, so a number and its chart land together.
                        el.textContent = format(target * (1 - Math.pow(1 - p, 3)));
                        if (p < 1) requestAnimationFrame(frame);
                    }

                    el.textContent = format(0);
                    if (delay > 0) {
                        setTimeout(function () { requestAnimationFrame(frame); }, delay);
                    } else {
                        requestAnimationFrame(frame);
                    }
                });
            })();
        </script>
    @endpush
@endonce
