{{--
    "Postgraduate Progress" — allowance, Stage Gate risk and TA Hours, for
    a student whose appointment is tracked by whichever module supplies
    Core\Contracts\SuppliesPostgraduateProgress (Haziq's Stage Gate /
    Allowance / TA Hours module, bound in its own ModuleProvider).

    $postgraduate is null for two different reasons the panel treats the
    same way — nothing supplies the contract at all, or the student has
    no qualifying appointment — both read as "this panel has nothing to
    say," not an error. $unavailable['postgraduate'] is the one case that
    IS an error (the source threw), which gets the skeleton instead.
--}}
<section class="sdash-card">
    @if (isset($unavailable['postgraduate']))
        @include('core::dashboard.partials.skeleton', ['type' => 'rows', 'rows' => 3])
    @endif

    <header class="sdash-card-head">
        <h3>Postgraduate Progress</h3>
    </header>

    <div class="sdash-card-body">
        @if (! $postgraduate)
            <p class="sdash-empty">No tracked appointment yet.</p>
        @else
            @php($allowance = $postgraduate['allowance'])
            <a href="{{ route('allowance.index') }}" class="sdash-row">
                <span class="sdash-row-icon tone-info" aria-hidden="true">
                    @include('core::dashboard.partials.icon', ['name' => 'doc'])
                </span>
                <span class="sdash-row-main">
                    <span class="sdash-row-title">Allowance</span>
                    <span class="sdash-row-sub">
                        {{ $allowance ? 'Payable for '.$allowance['forMonth'] : 'Nothing tracked yet' }}
                    </span>
                </span>
                <span class="sdash-row-side">
                    <span class="sdash-row-meta tone-info">
                        {{ $allowance && $allowance['amount'] !== null ? 'RM '.number_format($allowance['amount'], 2) : '—' }}
                    </span>
                </span>
                <span class="sdash-row-chevron" aria-hidden="true">
                    @include('core::dashboard.partials.icon', ['name' => 'chevron'])
                </span>
            </a>

            @php($risk = $postgraduate['risk'])
            <a href="{{ route('stage-gate.index') }}" class="sdash-row">
                <span class="sdash-row-icon tone-{{ $risk === null ? 'good' : ($risk >= 100 ? 'critical' : ($risk >= 60 ? 'warn' : 'good')) }}" aria-hidden="true">
                    @include('core::dashboard.partials.icon', ['name' => 'trend'])
                </span>
                <span class="sdash-row-main">
                    <span class="sdash-row-title">Stage Gate Risk</span>
                    <span class="sdash-row-sub">{{ $risk === null ? 'Nothing open right now' : 'Closest to its grace period' }}</span>
                </span>
                <span class="sdash-row-side">
                    <span class="sdash-row-meta tone-{{ $risk === null ? 'good' : ($risk >= 100 ? 'critical' : ($risk >= 60 ? 'warn' : 'good')) }}">
                        {{ $risk === null ? '—' : $risk.'%' }}
                    </span>
                </span>
                <span class="sdash-row-chevron" aria-hidden="true">
                    @include('core::dashboard.partials.icon', ['name' => 'chevron'])
                </span>
            </a>

            @php($ta = $postgraduate['taHours'])
            <a href="{{ route('ta-hours.index') }}" class="sdash-row">
                <span class="sdash-row-icon tone-{{ $ta['hours'] > $ta['cap'] ? 'critical' : ($ta['hours'] >= $ta['cap'] * 0.85 ? 'warn' : 'good') }}" aria-hidden="true">
                    @include('core::dashboard.partials.icon', ['name' => 'calendar'])
                </span>
                <span class="sdash-row-main">
                    <span class="sdash-row-title">TA Hours</span>
                    <span class="sdash-row-sub">This month, against the cap</span>
                </span>
                <span class="sdash-row-side">
                    <span class="sdash-row-meta tone-{{ $ta['hours'] > $ta['cap'] ? 'critical' : ($ta['hours'] >= $ta['cap'] * 0.85 ? 'warn' : 'good') }}">
                        {{ number_format($ta['hours'], 1) }} / {{ number_format($ta['cap'], 0) }} hrs
                    </span>
                </span>
                <span class="sdash-row-chevron" aria-hidden="true">
                    @include('core::dashboard.partials.icon', ['name' => 'chevron'])
                </span>
            </a>
        @endif
    </div>
</section>
