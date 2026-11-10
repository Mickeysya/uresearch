@extends('core::layouts.app')

@section('title', 'GA Allowance Eligibility')

@push('head')
    @include('haziq::partials.styles')
@endpush

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="GA Allowance Eligibility: {{ $month->format('F Y') }}"
        subtitle="GA's own allowance cycle, separate from GRA's screen and export. No Stage Gates and no termination here, but a month nobody confirms still adds onto the next month's running total, same as GRA's own. A service period only starts showing an amount the month after it begins, and keeps showing allowance every month after that, including one final month right after service ends, the same payroll lag as any wage, paid out the month after the work." />

    <div class="card card-wide">
        <form method="GET" action="{{ route('allowance.index') }}" class="haziq-filter-bar">
            {{-- Shares the Allowance Eligibility screen and nav entry
                 with GRA, confirmed explicitly 2026-11-10 -- "apply for
                 admin dah ke atas," never a student (confirmed the same
                 day): a student only ever reaches this view through their
                 own "My GA Allowance" link, which already only appears
                 once their GA is approved, so they get no toggle to poke
                 at GRA instead -- the hidden field just keeps module=ga
                 when the month changes. --}}
            @if ($canExport ?? false)
                <select name="module" data-autosubmit>
                    <option value="gra" @selected(($module ?? 'ga') === 'gra')>GRA</option>
                    <option value="ga" @selected(($module ?? 'ga') === 'ga')>GA</option>
                </select>
            @else
                <input type="hidden" name="module" value="{{ $module ?? 'ga' }}">
            @endif
            <input type="month" name="month" value="{{ $month->format('Y-m') }}" data-autosubmit>
        </form>
        @include('haziq::partials.filter_autosubmit_scripts')

        @if ($canExport ?? false)
            <p style="margin-top: 10px;">
                <a href="{{ route('ga-allowance.export', ['month' => $month->format('Y-m')]) }}" class="btn-secondary">
                    Download Excel ({{ $month->format('F Y') }})
                </a>
            </p>
        @endif

        <div style="overflow-x: auto;">
            <table class="haziq-table">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Allowance</th>
                        <th>Status</th>
                        @if ($canConfirmPayment ?? false)<th>Action</th>@endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td>{{ $row['student']->name }}
                                <br><span style="color: var(--text-grey); font-size: 12px;">
                                    {{ $row['student']->matric_no }} &middot; {{ $row['application']->reference() }}
                                </span>
                            </td>
                            <td>
                                RM {{ number_format($row['amount'], 2) }}
                                <br><span style="color: var(--text-grey); font-size: 11px;">for {{ $serviceMonth->format('M Y') }}</span>
                            </td>
                            <td><x-core::status-badge :status="$row['confirmed'] ? 'paid' : 'pending'" /></td>
                            @if ($canConfirmPayment ?? false)
                                <td>
                                    @if ($row['confirmable'])
                                        <form method="POST" action="{{ route('ga-allowance.confirm-payment', $row['log']) }}" enctype="multipart/form-data">
                                            @csrf
                                            <input type="file" name="proof" required style="font-size: 12px; max-width: 160px;">
                                            <button type="submit" data-confirm="Confirm RM {{ number_format($row['amount'], 2) }} paid to {{ $row['student']->name }}? The student and stakeholders will be notified.">Mark Payment Complete</button>
                                        </form>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ ($canConfirmPayment ?? false) ? 4 : 3 }}"><div class="empty-state">No allowance records for this month.</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

@include('haziq::partials.confirm_scripts')
@include('haziq::partials.assistant_widget')
@endsection
