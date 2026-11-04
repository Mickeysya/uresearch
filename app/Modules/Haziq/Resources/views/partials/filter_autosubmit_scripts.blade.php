{{--
    Auto-submit a filter form on change — add data-autosubmit to the
    select/input, not onchange="this.form.submit()". The CSP's script-src
    has no 'unsafe-inline' (see ContentSecurityPolicy's own docblock for
    why), which blocks inline event handler attributes exactly like it
    blocks inline <script> without a nonce — onchange="..." just silently
    never fires, with nothing in the UI to say why. Same delegated-listener
    shape as confirm_scripts.blade.php's data-confirm. Safe to @include more
    than once per page — @once means the listener is only ever attached one
    time.
--}}
@once
    @push('scripts')
        <script @cspNonce>
            (function () {
                document.addEventListener('change', function (event) {
                    var el = event.target.closest('[data-autosubmit]');
                    if (! el || ! el.form) return;
                    el.form.submit();
                });
            })();
        </script>
    @endpush
@endonce
