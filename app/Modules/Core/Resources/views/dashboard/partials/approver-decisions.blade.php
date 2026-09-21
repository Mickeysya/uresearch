{{--
    "Your recent decisions" — the one panel on these screens that looks
    backwards.

    Everything else asks what is waiting. This answers the question an
    approver actually gets asked by a student or a colleague: what did you
    decide about mine, and when. The Dean signs the last stage of several
    chains and is asked most.

    Scoped to this approver's own rows. It is not an audit screen; the
    administrator has one of those at /admin/audit-logs.
--}}
<section class="sdash-card approver-panel">
    <header class="sdash-card-head">
        <h3>Your recent decisions</h3>
        <span class="sdash-action">{{ $decided['total'] }} in 30 days</span>
    </header>

    @if (isset($unavailable['decisions']))
        @include('core::dashboard.partials.skeleton', ['type' => 'rows', 'rows' => 4])
    @elseif ($decisions->isEmpty())
        <div class="empty-state">
            <p>You have not decided anything yet.</p>
            <p class="queue-meta">Approvals and rejections you make appear here.</p>
        </div>
    @else
        <ul class="approver-feed approver-scroll">
            @foreach ($decisions as $decision)
                @php
                    $rejected = $decision->decision === 'rejected';
                    $application = $decision->application;
                @endphp
                <li class="approver-feed-row">
                    <span class="approver-feed-main">
                        <span>
                            <b>#{{ $application->id }}</b>
                            {{ $application->student?->name ?? 'Unknown student' }}
                        </span>
                        <span class="approver-feed-sub">
                            {{ $application->module()->label() }}
                            &middot; {{ $decision->stage_label }}
                            &middot; {{ $decision->created_at->diffForHumans() }}
                        </span>
                    </span>

                    <span class="status-badge {{ $rejected ? 'rejected' : 'approved' }}">
                        {{ $rejected ? 'Rejected' : $decision->decision }}
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</section>
