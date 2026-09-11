@extends('core::layouts.app')

@section('title', 'At-Risk Students')

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>At-Risk Students</h2>
        <div class="card-divider"></div>
        <p class="queue-meta">
            {{ $records->count() }} {{ Str::plural('student', $records->count()) }} currently below,
            or trending toward, the mandatory 80% attendance threshold.
        </p>

        @forelse ($records as $record)
            <div class="app-item">
                <div class="app-item-header">
                    <p><b>{{ $record->student->name }}</b>
                        @if ($record->student->matric_no) ({{ $record->student->matric_no }}) @endif
                    </p>
                    <span style="color: var(--red, #c0392b); font-weight: 600;">{{ $record->percentage }}%</span>
                </div>
                <p>Programme: {{ $record->student->programme ?? '—' }}</p>
                <p style="color: var(--text-grey); font-size: 12.5px;">
                    As of period ending {{ $record->period_end->format('j M Y') }}
                </p>
            </div>
        @empty
            <div class="empty-state">No students currently at risk.</div>
        @endforelse
    </div>
</div>
@endsection
