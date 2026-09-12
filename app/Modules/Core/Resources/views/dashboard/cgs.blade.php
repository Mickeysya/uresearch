@extends('core::layouts.app')

@section('title', 'Dashboard')

@section('content')
    {{--
        CGS staff dashboard. Same single-screen grid as the student one:
        the page itself never scrolls, panels scroll internally.

        Only the banner exists so far — the stat cards, Workload Overview,
        Pending Actions, Attendance Alerts, Recent Activities and Quick
        Shortcuts are still to come, and each will be its own partial under
        dashboard/partials/ exactly as the student dashboard's are.
    --}}
    <div class="sdash sdash-cgs">
        <x-core::welcome-banner
            art="desk"
            subtitle="Here's what's happening with postgraduate administration today." />
    </div>
@endsection
