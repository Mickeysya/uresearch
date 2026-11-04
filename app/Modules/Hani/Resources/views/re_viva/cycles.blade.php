@extends('core::layouts.app')

@section('title', 'Re-viva Cycles')

@section('content')
<div class="card-container-inline">
    {{-- Read-only for everyone. The Academic Executive runs the stages and
         the outcome elsewhere; the only link here, for the Senior Executive,
         goes to the logging form. --}}
    <x-core::page-header
        title="Re-viva Cycles"
        subtitle="Every re-viva round across CGS: where it stands, its deadlines and its outcome, open rounds sorted by deadline risk. This page is read-only.">
        @if ($canLog)
            <a href="{{ route('reviva.create') }}" class="btn">Log Re-viva Submission</a>
        @endif
    </x-core::page-header>

    @foreach ($sections as $key => [$heading, $hint])
        @php($rows = $groups->get($key, collect()))
        @continue($rows->isEmpty() && $key === 'closed')
        <div class="card card-wide">
            <h3>{{ $heading }} ({{ $rows->count() }})</h3>
            <p class="fstep-hint">{{ $hint }}</p>

            @if ($rows->isEmpty())
                <p class="fstep-hint">None.</p>
            @else
                <div class="table-scroll">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Round</th>
                                <th>Risk</th>
                                <th>Progress</th>
                                <th>Resubmission and deadlines</th>
                                <th>Outcome</th>
                                @if ($canLog) <th></th> @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $detail)
                                @php($student = $detail->application->student)
                                <tr>
                                    <td>
                                        {{ $student->name }}
                                        @if ($student->matric_no) <br><span class="fstep-hint">{{ $student->matric_no }}</span> @endif
                                        @if ($student->department) <br><span class="fstep-hint">{{ $student->department }}</span> @endif
                                    </td>
                                    <td>{{ $detail->cycle_number }}</td>
                                    <td>@include('hani::re_viva._risk', ['risk' => $risks[$detail->id] ?? null])</td>
                                    <td>
                                        @include('hani::partials.module_stepper', [
                                            'tracker' => \App\Modules\Hani\Support\ModuleTracker::reviva($detail),
                                            'compact' => true,
                                        ])
                                        @if ($detail->sent_to_panel_at)
                                            <p class="fstep-hint">Sent to panel {{ $detail->sent_to_panel_at->format('j M Y') }}; reports due {{ \App\Modules\Hani\Support\ReVivaLetters::reviewDue($detail->sent_to_panel_at)->format('j M Y') }}.</p>
                                        @endif
                                        @include('hani::re_viva._staff_documents', ['detail' => $detail])
                                    </td>
                                    <td>
                                        Resubmitted {{ $detail->resubmission_at->format('j M Y') }}
                                        @if ($detail->windowLabel())
                                            <br>Window {{ $detail->windowLabel() }}
                                            @if ($detail->logged_outside_window) <span class="status-badge returned">Logged outside window</span> @endif
                                        @endif
                                        @if ($detail->correction_deadline)
                                            <br>{{ $detail->outcome_level ? 'Final thesis' : 'Corrections' }} due {{ $detail->correction_deadline->format('j M Y') }}
                                            @if ($detail->finalThesisOverdue()) <span class="status-badge rejected">Overdue</span> @endif
                                            @if ($detail->final_thesis_submitted_at && ! $detail->corrections_accepted_at) <br><span class="fstep-hint">Final thesis submitted {{ $detail->final_thesis_submitted_at->format('j M Y') }}</span> @endif
                                        @endif
                                        @if ($key === 'next_round')
                                            @php($closes = \App\Modules\Hani\Support\ReVivaOutcome::nextRoundCloses($detail))
                                            <br>Next round due by {{ $closes->format('j M Y') }}
                                            @if ($closes->lt(today())) <span class="status-badge rejected">Window closed: examiners freed</span> @endif
                                        @endif
                                        @if ($detail->hardbound_deadline)
                                            <br>Hardbound due {{ $detail->hardbound_deadline->format('j M Y') }}
                                        @endif
                                    </td>
                                    <td>
                                        @if ($detail->sent_to_panel_at)
                                            @include('hani::re_viva._evaluations', ['detail' => $detail])
                                        @endif
                                        @if ($detail->hasOutcome())
                                            <b>{{ $detail->outcomeLabel() }}</b>
                                            @if ($detail->nextCycle) <br><span class="fstep-hint">Round {{ $detail->nextCycle->cycle_number }} logged.</span> @endif
                                        @else
                                            Not recorded yet
                                        @endif
                                    </td>
                                    @if ($canLog)
                                        <td>
                                            @if ($key === 'next_round')
                                                <a href="{{ route('reviva.create', ['student' => $student->id]) }}" class="btn">Log round {{ $detail->cycle_number + 1 }}</a>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endforeach
</div>
@endsection
