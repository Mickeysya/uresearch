{{--
    Travel, Claims and Publication's Reports and Analytics, as links on Core's
    CGS "Reports and Analytics" page (/cgs/reports), so CGS finds them where
    it looks for reports. Core's placeholder view includes this one partial
    on that route only; everything else about it lives here.

    Shown only to someone who can open the reports, and only the modules
    they may open (ApplicationReportController::reportModulesFor(), the same
    rule the report itself enforces).
--}}
@php($reportModules = \App\Modules\Norhanis\Http\Controllers\ApplicationReportController::reportModulesFor(auth()->user()))

@if ($reportModules)
    <div class="card card-wide norhanis-report-links" style="margin-top: 16px;">
        <h3 style="margin-top: 0;">Travel, Claims and Publication reports</h3>
        <p class="queue-meta">Where applications are waiting, how long each stage takes, and how many come in each month. Each report exports as CSV.</p>

        <ul style="list-style: none; padding: 0; margin: 12px 0 0; display: grid; gap: 10px;">
            @if (count($reportModules) > 1)
                <li>
                    <a href="{{ route('norhanis.report') }}"><b>{{ implode(', ', $reportModules) }}: all together</b></a>
                    <br><span class="queue-meta">Every module side by side: outcomes, where they wait and the median days per stage.</span>
                </li>
            @endif
            @foreach ($reportModules as $slug => $label)
                <li>
                    <a href="{{ route('norhanis.report', ['module' => $slug]) }}"><b>{{ $label }} Reports and Analytics</b></a>
                    <br><span class="queue-meta">Where {{ $label }} applications are waiting and how long each stage takes.</span>
                </li>
            @endforeach
        </ul>
    </div>
@endif
