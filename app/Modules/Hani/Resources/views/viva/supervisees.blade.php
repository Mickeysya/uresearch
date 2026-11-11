@extends('core::layouts.app')

@section('title', "Students' Viva Progress")

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Students' Viva Progress"
        subtitle="Where each of your candidates is in their viva. View only: you propose examiners through Nominate Examiners." />

    <div class="card card-wide">
        @if ($progress->isEmpty())
            <p class="empty-state">None of your candidates has filed a Notice of Thesis Submission yet.</p>
        @else
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                        <tr><th>Student</th><th>Notice</th><th>Examiner Nomination</th><th>Softbound</th><th>Viva</th><th>Result</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($progress as $p)
                            <tr>
                                <td>{{ $p->notice->student->name }}<br><span class="fstep-hint">{{ $p->detail?->thesis_title }}</span></td>
                                <td>{{ $p->notice->status === \App\Modules\Core\Models\Application::STATUS_APPROVED ? 'Verified' : 'With the Academic Executive' }}</td>
                                <td>{{ $p->nominationLabel() }}</td>
                                <td>{{ $p->softboundLabel() }}</td>
                                <td>
                                    @if ($p->detail?->isScheduled())
                                        {{ $p->detail->viva_at->format('j M Y, g:i a') }}<br>
                                        <span class="fstep-hint">{{ $p->detail->venue }}</span>
                                    @else
                                        Not scheduled
                                    @endif
                                </td>
                                <td>
                                    @if ($p->detail?->hasResult())
                                        {{ $p->detail->resultLabel() }}
                                        @if ($p->detail->corrections_accepted_at)
                                            <br><span class="fstep-hint">Corrections accepted</span>
                                        @elseif ($p->detail->corrections_due_at)
                                            <br><span class="fstep-hint">Corrections due {{ $p->detail->corrections_due_at->format('j M Y') }}</span>
                                        @elseif ($p->detail->resubmission_opens_at)
                                            <br><span class="fstep-hint">Resubmit {{ $p->detail->resubmission_opens_at->format('j M Y') }} to {{ $p->detail->resubmission_closes_at->format('j M Y') }}</span>
                                        @endif
                                    @else
                                        Not recorded
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection
