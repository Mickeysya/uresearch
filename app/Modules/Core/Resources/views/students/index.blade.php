@extends('core::layouts.app')

@section('title', 'Students')

{{--
    The Students page: a read-only directory of postgraduates. Who appears is
    Services\StudentDirectory; every module's figure arrives through a Core
    contract (see StudentDirectoryController). Styles: students.css.
--}}

@section('content')
@php
    $viewer = auth()->user();
    $scope = match ($viewer->role) {
        \App\Modules\Core\Support\Role::CHAIR => 'Students in '.($viewer->department ?: 'your department').'.',
        \App\Modules\Core\Support\Role::SUPERVISOR => 'Students you supervise, as main supervisor or co-supervisor.',
        default => 'Every postgraduate student, with their programme, supervisor, candidacy, attendance and pending applications.',
    };
    $sortLink = function (string $column) use ($filters) {
        $current = $filters['sort'];
        $next = $current === $column ? '-'.$column : $column;

        return route('students.index', array_filter(array_merge(request()->query(), ['sort' => $next, 'page' => null]), fn ($v) => $v !== null && $v !== ''));
    };
    $sortMark = fn (string $column) => match ($filters['sort']) { $column => '↑', '-'.$column => '↓', default => '' };
    $toneOf = fn (?string $tone) => in_array($tone, ['good', 'warn', 'critical', 'info', 'neutral'], true) ? $tone : 'neutral';
@endphp

<div class="std">
    <x-core::page-header title="Students" :subtitle="$scope" />

    <form method="GET" action="{{ route('students.index') }}" class="card std-filters" role="search">
        <div class="std-field std-field-search">
            <label for="std-q">Find a student</label>
            <input type="search" id="std-q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Name or matric number">
        </div>

        @if ($options['departments']->count() > 1)
            <div class="std-field">
                <label for="std-dept">Department</label>
                <select id="std-dept" name="department">
                    <option value="">All departments</option>
                    @foreach ($options['departments'] as $department)
                        <option value="{{ $department }}" @selected(($filters['department'] ?? '') === $department)>{{ $department }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        <div class="std-field">
            <label for="std-prog">Programme</label>
            <select id="std-prog" name="programme">
                <option value="">All programmes</option>
                @foreach ($options['programmes'] as $programme)
                    <option value="{{ $programme }}" @selected(($filters['programme'] ?? '') === $programme)>{{ $programme }}</option>
                @endforeach
            </select>
        </div>

        @if ($options['supervisors']->count() > 1)
            <div class="std-field">
                <label for="std-sup">Main supervisor</label>
                <select id="std-sup" name="supervisor">
                    <option value="">All supervisors</option>
                    @foreach ($options['supervisors'] as $supervisor)
                        <option value="{{ $supervisor->id }}" @selected((int) ($filters['supervisor'] ?? 0) === $supervisor->id)>{{ $supervisor->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        <div class="std-checks">
            @if ($attendance !== null)
                <label class="std-check"><input type="checkbox" name="at_risk" value="1" @checked($filters['at_risk'] ?? false)> At risk (attendance)</label>
            @endif
            <label class="std-check"><input type="checkbox" name="pending" value="1" @checked($filters['pending'] ?? false)> Has a pending application</label>
        </div>

        <input type="hidden" name="sort" value="{{ $filters['sort'] }}">
        <div class="std-actions">
            <button type="submit">Search</button>
            @if ($filtered)
                <a href="{{ route('students.index') }}" class="btn-secondary">Clear</a>
            @endif
        </div>
    </form>

    @if ($students->total() === 0)
        <div class="card empty-state">
            @if ($filtered)
                <p>No students match these filters.</p>
                <a href="{{ route('students.index') }}" class="btn-secondary">Clear the filters</a>
            @else
                <p>No students to show yet.</p>
                <p class="std-muted">Students appear here once they are on record{{ $viewer->role === \App\Modules\Core\Support\Role::SUPERVISOR ? ' with you as their supervisor' : '' }}.</p>
            @endif
        </div>
    @else
        <div class="card std-results">
            <p class="std-count">
                {{ number_format($students->total()) }} {{ Str::plural('student', $students->total()) }}
                @if ($students->hasPages()) · showing {{ $students->firstItem() }} to {{ $students->lastItem() }} @endif
            </p>

            <div class="table-scroll">
                <table class="data-table std-table">
                    <thead>
                        <tr>
                            <th scope="col"><a href="{{ $sortLink('name') }}" class="std-sort">Name {{ $sortMark('name') }}</a></th>
                            <th scope="col">Matric no.</th>
                            <th scope="col">Programme</th>
                            <th scope="col">Department</th>
                            <th scope="col">Main supervisor</th>
                            @if ($candidacy !== null) <th scope="col">Candidacy</th> @endif
                            @if ($attendance !== null)
                                <th scope="col" class="std-num"><a href="{{ $sortLink('attendance') }}" class="std-sort">Attendance {{ $sortMark('attendance') }}</a></th>
                            @endif
                            <th scope="col" class="std-num">Pending</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($students as $student)
                            @php
                                $att = $attendance[$student->id] ?? null;
                                $cand = $candidacy[$student->id] ?? null;
                            @endphp
                            <tr>
                                <td><a href="{{ route('students.show', $student) }}" class="std-name">{{ $student->name }}</a></td>
                                <td class="std-nowrap">{{ $student->matric_no ?: 'Not recorded' }}</td>
                                <td>{{ $student->programme ?: 'Not recorded' }}</td>
                                <td>{{ $student->department ?: 'Not recorded' }}</td>
                                <td>{{ $student->supervisor?->name ?? 'None assigned' }}</td>
                                @if ($candidacy !== null)
                                    <td>
                                        @if ($cand)
                                            <span class="std-pill std-tone-{{ $toneOf($cand['tone'] ?? null) }}">{{ $cand['statusLabel'] }}</span>
                                        @else
                                            <span class="std-muted">None on record</span>
                                        @endif
                                    </td>
                                @endif
                                @if ($attendance !== null)
                                    <td class="std-num std-nowrap">
                                        @if ($att && $att['percentage'] !== null)
                                            {{ rtrim(rtrim(number_format($att['percentage'], 1), '0'), '.') }}%
                                            @if ($att['at_risk']) <span class="std-pill std-tone-critical">At risk</span> @endif
                                        @else
                                            <span class="std-muted">No data</span>
                                        @endif
                                    </td>
                                @endif
                                <td class="std-num">{{ $student->pending_count }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        @include('core::partials.pagination', ['paginator' => $students, 'label' => 'Student pages'])
    @endif
</div>
@endsection
