{{--
    Every student this supervisor is responsible for -- the full list behind
    the dashboard's "My candidates" panel. See SupervisorCandidatesController.
--}}
@extends('core::layouts.app')

@section('title', 'My candidates')

@section('content')
<div class="rpd-page">
    <x-core::page-header title="My candidates" :back="route('dashboard')">
        <x-slot:subtitle>
            {{ $total }} {{ Str::plural('student', $total) }} under your supervision
            @if ($atRisk > 0) · {{ $atRisk }} flagged at risk @endif
        </x-slot:subtitle>

        @if ($total > 0)
            <form method="GET" action="{{ route('supervisor.candidates') }}" class="doc-search">
                <input type="search" name="q" id="candidate-search" value="{{ $search }}" maxlength="100"
                       placeholder="Search by name, matric no. or programme" aria-label="Search your candidates">
            </form>
        @endif
    </x-core::page-header>

    {{-- A list screen: the table fills the page width, like the other lists. --}}
    <div class="card card-wide" style="max-width: none; width: 100%;">
        @if (isset($unavailable['candidates']))
            <p class="message-warning">Your candidates could not be loaded just now. Please refresh the page.</p>
        @elseif ($total === 0)
            <div class="empty-state">
                <p>No students are assigned to you yet.</p>
                <p class="queue-meta">CGS sets the supervisor on a student's record.</p>
            </div>
        @elseif ($rows->isEmpty())
            <div class="empty-state">
                <p>No candidate matches "{{ $search }}".</p>
                <a href="{{ route('supervisor.candidates') }}" class="btn-secondary">Show all candidates</a>
            </div>
        @else
            @if ($search !== '')
                <p class="queue-meta" style="margin-top: 0;">
                    {{ $rows->count() }} of {{ $total }} match "{{ $search }}".
                    <a href="{{ route('supervisor.candidates') }}">Show all</a>
                </p>
            @endif

            <div style="overflow-x: auto;">
                <table class="recent-activity-table cand-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Matric no.</th>
                            <th>Programme</th>
                            <th>Email</th>
                            <th>Attendance</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php
                                $student = $row['student'];
                                $reading = $row['attendance'];
                            @endphp
                            <tr>
                                <td>{{ $loop->iteration }}</td>
                                <td>
                                    <span class="cand-name">
                                        @if ($reading?->at_risk)
                                            <span class="cand-flag" title="Flagged at risk">●</span>
                                        @endif
                                        {{ $student->name }}
                                    </span>
                                </td>
                                <td>{{ $student->matric_no ?: 'Not recorded' }}</td>
                                <td>{{ $student->programme ?: 'Not recorded' }}</td>
                                <td><a href="mailto:{{ $student->email }}">{{ $student->email }}</a></td>
                                <td>
                                    @if ($reading)
                                        <span class="cand-attendance tone-{{ $row['tone'] }}"
                                              title="{{ $reading->sessions_attended }} of {{ $reading->sessions_total }} sessions to {{ $reading->period_end->format('j M Y') }}">
                                            {{ rtrim(rtrim(number_format($reading->percentage, 1), '0'), '.') }}%
                                        </span>
                                    @else
                                        <span class="cand-attendance tone-none">Not recorded</span>
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
