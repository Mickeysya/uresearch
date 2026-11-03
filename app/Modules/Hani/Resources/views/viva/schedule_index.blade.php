@extends('core::layouts.app')

@section('title', 'Viva Scheduling')

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Viva Scheduling"
        subtitle="Set the viva date once the examiner nomination is approved and the softbound thesis is verified. The student, supervisor, examiners and chairman are emailed a calendar invitation." />

    <div class="card card-wide">
        <h3>Ready to Schedule ({{ $ready->count() }})</h3>
        @if ($ready->isEmpty())
            <p class="fstep-hint">Nobody is ready yet. Students appear here once both conditions are met.</p>
        @else
            <div class="table-scroll">
                <table class="data-table">
                    <thead><tr><th>Student</th><th>Thesis</th><th>Notice filed</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($ready as $p)
                            <tr>
                                <td>{{ $p->notice->student->name }}<br><span class="fstep-hint">{{ $p->notice->student->matric_no }}</span></td>
                                <td>{{ $p->detail?->thesis_title }}</td>
                                <td>{{ $p->notice->submitted_at->format('j M Y') }}</td>
                                <td><a href="{{ route('viva.schedule.edit', $p->notice) }}" class="btn">Set Date</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="card card-wide">
        <h3>Scheduled ({{ $scheduled->count() }})</h3>
        @if ($scheduled->isEmpty())
            <p class="fstep-hint">No vivas scheduled yet.</p>
        @else
            <div class="table-scroll">
                <table class="data-table">
                    <thead><tr><th>Student</th><th>Date and time</th><th>Venue</th><th>Chairman</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($scheduled as $p)
                            <tr>
                                <td>{{ $p->notice->student->name }}</td>
                                <td>{{ $p->detail->viva_at->format('j M Y, g:i a') }}</td>
                                <td>{{ $p->detail->venue }}</td>
                                <td>{{ $p->detail->chair_name }}</td>
                                <td><a href="{{ route('viva.schedule.edit', $p->notice) }}" class="btn-secondary">Reschedule</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="card card-wide">
        <h3>Waiting ({{ $waiting->count() }})</h3>
        @if ($waiting->isEmpty())
            <p class="fstep-hint">Nothing outstanding.</p>
        @else
            <div class="table-scroll">
                <table class="data-table">
                    <thead><tr><th>Student</th><th>Still waiting for</th></tr></thead>
                    <tbody>
                        @foreach ($waiting as $p)
                            <tr>
                                <td>{{ $p->notice->student->name }}</td>
                                <td>{{ implode('; ', $p->waitingFor()) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection
