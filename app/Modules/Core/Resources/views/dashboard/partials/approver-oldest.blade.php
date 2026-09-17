{{--
    The triage list: the rows closest to breaching, oldest first.

    Five rows a Chair can clear before anything else, each linking into the
    queue it sits on. This is the panel the old dashboard's "Recent activity"
    should have been -- that one listed applications already decided and no
    longer anyone's problem.
--}}
<section class="sdash-card approver-panel">
    <header class="sdash-card-head">
        <h3>Waiting longest</h3>
    </header>

    @if (isset($unavailable['oldest']))
        @include('core::dashboard.partials.skeleton', ['type' => 'rows', 'rows' => 4])
    @elseif ($oldest->isEmpty())
        <div class="empty-state">
            <p>Nothing is waiting on you.</p>
        </div>
    @else
        <ul class="approver-feed approver-scroll">
            @foreach ($oldest as $application)
                @php
                    $days = $application->submitted_at ? (int) $application->submitted_at->diffInDays() : null;
                    $tone = \App\Modules\Core\Services\ApproverDashboard::toneFor($days);
                @endphp
                <li class="approver-feed-row">
                    <span class="approver-feed-main">
                        <b>#{{ $application->id }}</b>
                        {{ $application->student?->name ?? 'Unknown student' }}
                        <span class="approver-feed-sub">{{ $application->module()->label() }}</span>
                    </span>

                    <span class="approver-queue-age tone-{{ $tone }}">
                        {{ $days === null ? 'not submitted' : $days.'d' }}
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</section>
