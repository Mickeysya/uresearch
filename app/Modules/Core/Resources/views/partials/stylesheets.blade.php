{{--
    The portal's stylesheets, in cascade order. Included by both layouts so
    the list lives in exactly one place — adding a sheet is a one-line edit
    here, not the same edit made twice and kept in sync by hand.

    ORDER MATTERS. Later sheets override earlier ones, and several rules
    depend on it: dashboard-student.css ends with the responsive block that
    overrides its own base rules, and dashboard-gauge.css sizes a column
    dashboard-student.css lays out. Do not reorder these.

      uresearch.css       Norhanis' original. Never edited in place.
      layout.css          page frame
      sidebar.css         sidebar nav
      dashboard-*.css     one dashboard screen each
      notifications.css   the /notifications feed
      charts.css          every Chart.js surface, shared by all dashboards
      sidebar-identity.css the sidebar's identity card

    ?v=<file mtime> so a change always reaches the browser instead of
    silently serving a stale cached copy.
--}}
@foreach ([
    'uresearch',
    'layout',
    'sidebar',
    'dashboard-banner',
    'dashboard-student',
    'dashboard-gauge',
    'dashboard-states',
    'dashboard-cgs',
    'notifications',
    'dashboard-admin',
    'charts',
    'sidebar-identity',
] as $sheet)
    <link rel="stylesheet" href="{{ asset("css/{$sheet}.css") }}?v={{ @filemtime(public_path("css/{$sheet}.css")) ?: 1 }}">
@endforeach
