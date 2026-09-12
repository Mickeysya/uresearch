{{--
    Placeholder shown IN PLACE OF a panel whose data could not be read.

    This is NOT a load-timing effect. The dashboard is server-rendered: by the
    time the browser paints, every figure is already in the HTML, so there is
    nothing to wait for and holding a placeholder would only add lag. Panels
    therefore include this ONLY when StudentDashboard::unavailable() reports
    their source failed -- a dead query, or a module that is not installed.

    It deliberately does not shimmer. A pulsing placeholder that will never
    resolve reads as "still loading" when it actually means "gave up".

    Params:
      type   'stat' | 'rows' | 'gauge'
      rows   how many row placeholders to draw for type=rows (default 4)
--}}
@php
    $type = $type ?? 'rows';
    $rows = $rows ?? 4;
@endphp

<div class="sdash-skeleton" aria-hidden="true">
    @switch($type)
        @case('stat')
            <div class="skel-stat">
                <div class="skel-stat-top">
                    <span class="skel skel-circle"></span>
                    <span class="skel skel-bar" style="width: 62%;"></span>
                </div>
                <span class="skel skel-bar skel-lg" style="width: 54%;"></span>
                <span class="skel skel-bar" style="width: 70%;"></span>
                <span class="skel skel-pill"></span>
            </div>
            @break

        @case('gauge')
            <div class="skel-gauge">
                <div class="skel-gauge-dial">
                    <span class="skel skel-arc"></span>
                    <span class="skel skel-bar skel-lg" style="width: 48%; margin: 0 auto;"></span>
                </div>
                <div class="skel-gauge-side">
                    <span class="skel skel-bar" style="width: 78%;"></span>
                    <span class="skel skel-bar" style="width: 64%;"></span>
                    <span class="skel skel-bar" style="width: 70%;"></span>
                    <span class="skel skel-bar skel-tall" style="width: 100%;"></span>
                </div>
            </div>
            @break

        @default
            <div class="skel-rows">
                @for ($i = 0; $i < $rows; $i++)
                    <div class="skel-row">
                        <span class="skel skel-square"></span>
                        <span class="skel-row-main">
                            <span class="skel skel-bar" style="width: {{ [58, 72, 64, 50, 68][$i % 5] }}%;"></span>
                            <span class="skel skel-bar skel-sm" style="width: {{ [40, 34, 46, 30, 38][$i % 5] }}%;"></span>
                        </span>
                        <span class="skel skel-pill skel-tag"></span>
                    </div>
                @endfor
            </div>
    @endswitch

    <p class="sdash-skeleton-msg">
        <span aria-hidden="true">@include('core::dashboard.partials.icon', ['name' => 'info'])</span>
        Couldn’t load this right now. Refresh to try again.
    </p>
</div>
