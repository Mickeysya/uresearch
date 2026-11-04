{{--
    Pins the guest layout's logo bar to the top while the page scrolls, the
    way .main-content-header is pinned once signed in (sidebar.css). The
    sign-off pages are long forms reached from an email link, so without it
    the UTP and UResearch logos scroll away. Kept here rather than in the
    shared stylesheet because core::layouts.guest is Core's.
--}}
<style>
    .top-header {
        position: sticky;
        top: 0;
        z-index: 5;
    }
</style>
