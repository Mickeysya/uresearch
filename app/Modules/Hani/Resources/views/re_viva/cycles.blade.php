@extends('core::layouts.app')

@section('title', 'Re-viva Cycles')

@section('content')
<div class="card-container-inline">
    {{-- Read-only for everyone. The Academic Executive runs the stages and
         the outcome elsewhere; the only link here, for the Senior Executive,
         goes to the logging form. --}}
    <x-core::page-header
        title="Re-viva Cycles"
        subtitle="Every re-viva round across CGS: where it stands, its deadlines and its outcome. This page is read-only.">
        @if ($canLog)
            <a href="{{ route('reviva.create') }}" class="btn">Log Re-viva Submission</a>
        @endif
    </x-core::page-header>

    @foreach ($sections as $key => [$heading, $hint])
        @php $rows = $groups->get($key, collect()); @endphp
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
                                <th>Progress</th>
                                <th>Resubmission and deadlines</th>
                                <th>Outcome</th>
                                @if ($canLog) <th></th> @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $detail)
                                @php $student = $detail->application->student; @endphp
                                <tr>
                                    <td>
                                        {{ $student->name }}
                                        @if ($student->matric_no) <br><span class="fstep-hint">{{ $student->matric_no }}</span> @endif
                                        @if ($student->department) <br><span class="fstep-hint">{{ $student->department }}</span> @endif
                                    </td>
                                    <td>{{ $detail->cycle_number }}</td>
                                    <td>
                                        @include('hani::partials.module_stepper', [
                                            'tracker' => \App\Modules\Hani\Support\ModuleTracker::reviva($detail),
                                            'compact' => true,
                                        ])
                                    </td>
                                    <td>
                                        Resubmitted {{ $detail->resubmission_at->format('j M Y') }}
                                        @if ($detail->correction_deadline)
                                            <br>Corrections due {{ $detail->correction_deadline->format('j M Y') }}
                                        @endif
                                        @if ($detail->hardbound_deadline)
                                            <br>Hardbound due {{ $detail->hardbound_deadline->format('j M Y') }}
                                        @endif
                                    </td>
                                    <td>
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
