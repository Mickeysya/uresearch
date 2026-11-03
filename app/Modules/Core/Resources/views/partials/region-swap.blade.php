{{--
    Loads region-swap.js once per page, however many times a page includes
    this partial. See public/js/region-swap.js for what it does and why.

    Same convention as dashboard/partials/chartjs.blade.php: served from
    public/js rather than a CDN (the CSP's script-src is nonce-only, no host
    allow-list), cache-busted the same way the stylesheets are.

    Including this only loads the library. A page still calls
    RegionSwap({...}) itself, from its own inline script, because the
    regions and any page-specific rebinding (a modal trigger, say) are
    always page-specific.
--}}
@once
    @push('head')
        <script src="{{ asset('js/region-swap.js') }}?v={{ @filemtime(public_path('js/region-swap.js')) ?: 1 }}"></script>
    @endpush
@endonce
