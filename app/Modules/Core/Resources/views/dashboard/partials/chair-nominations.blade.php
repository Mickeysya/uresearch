{{--
    Examiner panels this Chair filed.

    A Chair files a nomination and it leaves their hands completely: it sits
    on the Academic Executive's queue, not theirs, and the tracking page is
    students only. Until this panel it was simply invisible to the person who
    filed it, which `TODO.md` carries as the one thing a Chair should be able
    to reach and could not.
--}}
<section class="sdash-card chair-panel">
    <header class="sdash-card-head">
        <h3>Panels you filed</h3>
    </header>

    @if (isset($unavailable['nominations']))
        @include('core::dashboard.partials.skeleton', ['type' => 'rows', 'rows' => 4])
    @elseif ($nominations->isEmpty())
        <div class="empty-state">
            <p>You have not filed an examiner panel yet.</p>
            <a href="{{ route('appointment-letter.create') }}" class="btn-secondary">Nominate a panel</a>
        </div>
    @else
        <ul class="chair-feed">
            @foreach ($nominations as $application)
                <li class="chair-feed-row">
                    <span class="chair-feed-main">
                        <b>#{{ $application->id }}</b>
                        {{ $application->student?->name ?? 'Unknown student' }}
                        <span class="chair-feed-sub">
                            {{ $application->module()->label() }}
                            @if ($application->status === \App\Modules\Core\Models\Application::STATUS_PENDING)
                                &middot; with {{ $stageLabels[$application->id] ?? 'the next approver' }}
                            @endif
                        </span>
                    </span>

                    <x-core::status-badge :status="$application->status" />
                </li>
            @endforeach
        </ul>
    @endif
</section>
