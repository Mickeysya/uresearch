@extends('core::layouts.app')

@section('title', 'Examiner Pool')

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Examiner Pool</h2>
        <div class="card-divider"></div>
        <p class="queue-meta">
            <a href="{{ route('examiner-admin.create') }}">+ Add an examiner</a>
        </p>

        <table class="recent-activity-table">
            <thead>
                <tr>
                    <th>Name</th><th>Department</th><th>Faculty</th><th>Type</th>
                    <th>State</th><th>Until / Since</th><th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($examiners as $examiner)
                    <tr>
                        <td>{{ $examiner->name }}</td>
                        <td>{{ $examiner->department }}</td>
                        <td>{{ $examiner->faculty ?? '—' }}</td>
                        <td>{{ ucfirst($examiner->type) }}</td>
                        <td>{{ ucwords(str_replace('_', ' ', $examiner->state())) }}</td>
                        <td>
                            @if ($examiner->state() === \App\Modules\Hani\Models\Examiner::STATE_ON_GAP)
                                {{ $examiner->gapEndsOn()->format('j M Y') }}
                            @elseif ($examiner->state() === \App\Modules\Hani\Models\Examiner::STATE_ASSIGNED)
                                {{ $examiner->assigned_until->format('j M Y') }}
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            <form method="POST" action="{{ route('examiner-admin.toggle-active', $examiner) }}">
                                @csrf
                                <button type="submit" class="@unless($examiner->is_active) btn-reject @endunless">
                                    {{ $examiner->is_active ? 'Mark Unavailable' : 'Reactivate' }}
                                </button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
