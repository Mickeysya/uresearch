@extends('core::layouts.app')

@section('title', $student->name.' · Students')

{{--
    One student's record on the Students page: profile, supervision,
    candidacy, GA/GRA, attendance, applications and issued letters. Every
    module's part comes from a Core contract (StudentDirectoryController);
    the projected attendance figure is only ever passed in for the CGS team.
    IC and bank numbers are never shown here. Styles: students.css.
--}}

@section('content')
@php
    $pct = fn (?float $v) => $v === null ? null : rtrim(rtrim(number_format($v, 1), '0'), '.').'%';
    $toneOf = fn (?string $tone) => in_array($tone, ['good', 'warn', 'critical', 'info', 'neutral'], true) ? $tone : 'neutral';
    $subtitle = collect([$student->matric_no, $student->programme, $student->department])->filter()->implode(' · ');
@endphp

<div class="std">
    <x-core::page-header :title="$student->name" :subtitle="$subtitle ?: 'Student record'">
        <a href="{{ url()->previous() !== url()->current() && str_contains(url()->previous(), '/students') ? url()->previous() : route('students.index') }}" class="btn-secondary">Back to Students</a>
    </x-core::page-header>

    <div class="std-grid">
        <section class="card std-card" aria-labelledby="std-profile">
            <h2 id="std-profile">Profile</h2>
            <dl class="std-dl">
                <dt>Matric no.</dt><dd>{{ $student->matric_no ?: 'Not recorded' }}</dd>
                <dt>Programme</dt><dd>{{ $student->programme ?: 'Not recorded' }}</dd>
                <dt>Department</dt><dd>{{ $student->department ?: 'Not recorded' }}</dd>
                <dt>Faculty</dt><dd>{{ $student->faculty ?: 'Not recorded' }}</dd>
                <dt>Level and mode</dt><dd class="std-muted">Not recorded</dd>
                <dt>Email</dt><dd class="std-break">{{ $student->email }}</dd>
            </dl>
        </section>

        <section class="card std-card" aria-labelledby="std-supervision">
            <h2 id="std-supervision">Supervision</h2>
            <dl class="std-dl">
                <dt>Main supervisor</dt><dd>{{ $student->supervisor?->name ?? 'None assigned' }}</dd>
                @if ($history !== null)
                    <dt>Co-supervisors</dt>
                    <dd>
                        @forelse ($history['co'] as $row)
                            <span class="std-line">{{ $row['user']->name }}@if ($row['since']) <span class="std-muted">since {{ $row['since']->format('j M Y') }}</span>@endif</span>
                        @empty
                            <span class="std-muted">None</span>
                        @endforelse
                    </dd>
                    <dt>Previous supervisors</dt>
                    <dd>
                        @forelse ($history['previous'] as $row)
                            <span class="std-line">{{ $row['user']->name }} <span class="std-muted">{{ $row['note'] }}@if ($row['until']), {{ $row['until']->format('j M Y') }}@endif</span></span>
                        @empty
                            <span class="std-muted">None on record</span>
                        @endforelse
                    </dd>
                @endif
            </dl>
        </section>

        <section class="card std-card" aria-labelledby="std-candidacy">
            <h2 id="std-candidacy">Candidacy</h2>
            @if ($candidacy === false)
                <p class="std-muted">Candidacy is not available in this installation.</p>
            @elseif ($candidacy === null)
                <p class="std-muted">No candidacy on record.</p>
            @else
                <p><span class="std-pill std-tone-{{ $toneOf($candidacy['tone'] ?? null) }}">{{ $candidacy['statusLabel'] }}</span></p>
                <dl class="std-dl">
                    <dt>Expires</dt><dd>{{ $candidacy['expiresAt']->format('j M Y') }}</dd>
                    <dt>Days remaining</dt><dd>{{ $candidacy['daysRemaining'] < 0 ? abs($candidacy['daysRemaining']).' days overdue' : $candidacy['daysRemaining'] }}</dd>
                    <dt>Extension used</dt><dd>{{ $candidacy['extensionUsedMonths'] }} of {{ $candidacy['extensionMaxMonths'] }} months</dd>
                </dl>
            @endif
        </section>

        <section class="card std-card" aria-labelledby="std-ga">
            <h2 id="std-ga">GA / GRA</h2>
            @if (! $gaInstalled)
                <p class="std-muted">GA and GRA appointments are not available in this installation.</p>
            @elseif ($appointment)
                <dl class="std-dl">
                    <dt>Appointment</dt><dd>{{ $appointment['type'] }}</dd>
                    <dt>Since</dt><dd>{{ $appointment['since']->format('j M Y') }}</dd>
                    @if ($gaEnds && $appointment['type'] === 'GA')
                        <dt>Ends</dt><dd>{{ $gaEnds->format('j M Y') }}</dd>
                    @endif
                </dl>
            @else
                <p class="std-muted">No current GA or GRA appointment.</p>
            @endif
        </section>

        <section class="card std-card" aria-labelledby="std-attendance">
            <h2 id="std-attendance">Attendance</h2>
            @if ($attendance === false)
                <p class="std-muted">Attendance is not available in this installation.</p>
            @elseif ($attendance === null)
                <p class="std-muted">No attendance on record this semester.</p>
            @else
                @if ($attendance['at_risk'])
                    <p><span class="std-pill std-tone-critical">At risk</span></p>
                @endif
                <dl class="std-dl">
                    <dt>This semester</dt><dd>{{ $pct($attendance['percentage']) ?? 'No data' }}</dd>
                    <dt>Latest period</dt><dd>{{ $attendance['latest_period_end'] ? 'Week ending '.$attendance['latest_period_end']->format('j M Y') : 'Not recorded' }}</dd>
                    @if ($seesProjection && array_key_exists('projected_percentage', $attendance))
                        <dt>Projected <span class="std-muted">(CGS only)</span></dt>
                        <dd>{{ $pct($attendance['projected_percentage']) ?? 'Not enough periods yet' }}</dd>
                    @endif
                </dl>
            @endif
        </section>
    </div>

    <section class="card std-card" aria-labelledby="std-applications">
        <h2 id="std-applications">Applications</h2>
        @if ($applications === [])
            <p class="std-muted">No applications yet.</p>
        @else
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                        <tr><th scope="col">Module</th><th scope="col">Reference</th><th scope="col">Status</th><th scope="col">Current stage</th><th scope="col">Submitted</th><th scope="col"><span class="sr-only">Open</span></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($applications as $a)
                            <tr>
                                <td>{{ $a['module'] }}</td>
                                <td class="std-nowrap">{{ $a['reference'] }}</td>
                                <td><x-core::status-badge :status="$a['status']" /></td>
                                <td>{{ $a['status'] === 'pending' ? ($a['stage'] ?? 'Not recorded') : 'Finished' }}</td>
                                <td class="std-nowrap">{{ $a['date']?->format('j M Y') ?? 'Not recorded' }}</td>
                                <td class="std-nowrap">
                                    @if ($a['url'])
                                        <a href="{{ $a['url'] }}">View</a>
                                    @else
                                        <span class="std-muted">No staff view</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="card std-card" aria-labelledby="std-letters">
        <h2 id="std-letters">Issued letters</h2>
        @if ($letters === [])
            <p class="std-muted">No letters issued yet.</p>
        @else
            <div class="table-scroll">
                <table class="data-table">
                    <thead><tr><th scope="col">Letter</th><th scope="col">Reference</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Verify</span></th></tr></thead>
                    <tbody>
                        @foreach ($letters as $l)
                            <tr>
                                <td>{{ $l['label'] }}</td>
                                <td class="std-nowrap">{{ $l['reference'] ?? 'Not recorded' }}</td>
                                <td>
                                    @if ($l['revoked_at'])
                                        <span class="std-pill std-tone-critical">Revoked</span> <span class="std-muted">{{ $l['revoked_at']->format('j M Y') }}</span>
                                    @else
                                        <span class="std-pill std-tone-good">Valid</span>
                                    @endif
                                </td>
                                <td class="std-nowrap">@if ($l['verify_url'])<a href="{{ $l['verify_url'] }}" target="_blank" rel="noopener">Verification page</a>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
@endsection
