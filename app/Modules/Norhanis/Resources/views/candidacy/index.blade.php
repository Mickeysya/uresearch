@extends('core::layouts.app')

@section('title', 'RPD Masterlist')

@section('content')
@php use App\Modules\Norhanis\Models\Candidacy; @endphp

<div class="rpd-page">
    <header class="rpd-header">
        <div>
            <h2>RPD Masterlist</h2>
            <p class="queue-meta">Every candidature clock CGS tracks. Reminders fire automatically at 3, 2 and 1 months.</p>
        </div>
        <a href="{{ route('candidacies.create') }}" class="sdash-action">+ Register candidacy</a>
    </header>

    <nav class="notif-filters" aria-label="Filter candidacies">
        @foreach ([
            'active' => 'Active',
            'due_soon' => 'Due soon',
            'overdue' => 'Overdue',
            'closed' => 'Closed',
        ] as $key => $label)
            <a href="{{ route('candidacies.index', ['filter' => $key]) }}"
               class="notif-filter @if($filter === $key) active @endif">
                {{ $label }} <span>{{ $counts[$key] }}</span>
            </a>
        @endforeach
    </nav>

    @if ($counts['overdue'] > 0 && $filter !== 'overdue')
        <p class="message-warning">
            {{ $counts['overdue'] }} {{ Str::plural('candidacy', $counts['overdue']) }} past the deadline with no approved extension.
            <a href="{{ route('candidacies.index', ['filter' => 'overdue']) }}">Review them</a>
        </p>
    @endif

    @if ($candidacies->isEmpty())
        <div class="empty-state">Nothing in this list.</div>
    @else
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Programme</th>
                        <th>Started</th>
                        <th>RPD deadline</th>
                        <th>Remaining</th>
                        <th>Extension</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($candidacies as $candidacy)
                        <tr>
                            <td>
                                {{ $candidacy->student->name }}
                                @if ($candidacy->student->matric_no)
                                    <span class="queue-meta">{{ $candidacy->student->matric_no }}</span>
                                @endif
                            </td>
                            <td>{{ Candidacy::programmeTypes()[$candidacy->programme_type] ?? $candidacy->programme_type }}</td>
                            <td>{{ $candidacy->candidature_start_date->format('j M Y') }}</td>
                            <td class="rpd-num">{{ $candidacy->rpd_deadline->format('j M Y') }}</td>
                            <td class="rpd-num tone-{{ $candidacy->tone() }}">
                                {{ $candidacy->isOverdue()
                                    ? abs($candidacy->daysRemaining()).'d overdue'
                                    : $candidacy->daysRemaining().'d' }}
                            </td>
                            <td class="rpd-num">{{ $candidacy->extension_months_used }}/{{ Candidacy::MAX_EXTENSION_MONTHS }}m</td>
                            <td><span class="status-badge status-{{ $candidacy->status }}">{{ ucfirst($candidacy->status) }}</span></td>
                            <td class="rpd-actions">
                                @if ($candidacy->isDismissible())
                                    <a href="{{ route('rpd-dismissal.create') }}" class="sdash-action">Dismiss</a>
                                @endif
                                @if (in_array($candidacy->status, [Candidacy::STATUS_ACTIVE, Candidacy::STATUS_EXTENDED], true))
                                    <form method="POST" action="{{ route('candidacies.defended', $candidacy) }}" class="rpd-inline-form">
                                        @csrf
                                        <input type="hidden" name="defended_on" value="{{ now()->toDateString() }}">
                                        <button type="submit" class="btn-secondary">Mark defended</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{ $candidacies->links() }}
    @endif
</div>
@endsection
