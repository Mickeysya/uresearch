@extends('core::layouts.app')

@section('title', 'Examiner List')

@section('content')

{{-- Scoped to this page. The shared pieces -- .data-table, .status-badge,
     .empty-state, .sdash-action -- are already global via
     partials/stylesheets.blade.php; this only adds the page header, the
     bordered table well and the two-line name cell.

     Full-width rather than a .card, for the same reason Hani's examiner
     pool is: a six-column list inside a 680px card scrolls sideways on a
     1900px monitor. Sizes come from tokens.css, not literals. --}}
<style>
    .pool-table-wrap {
        background: var(--surface);
        border: 1px solid var(--border-subtle);
        border-radius: var(--radius-lg);
        padding: 0 var(--space-6) var(--space-6);
        overflow-x: auto;
    }

    .pool-table { width: 100%; }
    .pool-table th, .pool-table td { vertical-align: middle; }
    .pool-table td:last-child { text-align: right; white-space: nowrap; }
    .pool-table td:last-child button { margin: 0; padding: var(--space-1) var(--space-3); font-size: var(--text-sm); }

    /* A removed examiner stays on the list -- past nominations reference the
       row -- so it is greyed rather than gone, and its name carries the badge
       that says why it cannot be picked. */
    .pool-table tr.is-removed td { color: var(--text-grey); }

    .pool-name { display: flex; align-items: center; gap: var(--space-3); min-width: 200px; }
    .pool-avatar {
        display: inline-flex; align-items: center; justify-content: center;
        width: calc(var(--text-md) * 2.3); height: calc(var(--text-md) * 2.3);
        border-radius: var(--radius-full); flex-shrink: 0;
        background: var(--light-blue-bg); color: var(--navy);
        font-size: var(--text-sm); font-weight: var(--weight-bold);
    }
    .pool-table tr.is-removed .pool-avatar { background: var(--surface-sunken); color: var(--text-grey); }

    .pool-expertise { display: block; max-width: 24ch; }
    .pool-email { font-size: var(--text-sm); color: var(--text-grey); }

    /* The counts, as a quiet line under the heading rather than four stat
       cards: this list is a handful of rows, not a dashboard. */
    .pool-counts { display: flex; gap: var(--space-4); flex-wrap: wrap; margin: 0 0 var(--space-4); }
    .pool-count { font-size: var(--text-sm); color: var(--text-grey); }
    .pool-count b { color: var(--text-dark); font-variant-numeric: tabular-nums; }
</style>

@php
    $active = $examiners->where('is_active', true);
    // Active but on an appointment: still listed, not pickable until the
    // cooldown lapses.
    $busy = $active->reject->isAvailable();
@endphp

<div class="card-container-inline">
    <x-core::page-header
        title="Examiner List"
        subtitle="The examiners a Chair can put on a panel. Removing one keeps past nominations intact, because each nomination holds its own copy of the details.">
        <a href="{{ route('appointment-letter.examiners.create', array_filter(['return' => $returnToNomination ? 'nominate' : null])) }}"
           class="btn">Add an examiner</a>
    </x-core::page-header>

    @if ($examiners->isNotEmpty())
        <div class="pool-counts">
            <span class="pool-count"><b>{{ $active->count() - $busy->count() }}</b> available to nominate</span>
            <span class="pool-count"><b>{{ $active->where('examiner_type', 'internal')->count() }}</b> internal</span>
            <span class="pool-count"><b>{{ $active->where('examiner_type', 'external')->count() }}</b> external</span>
            @if ($busy->isNotEmpty())
                <span class="pool-count"><b>{{ $busy->count() }}</b> on an appointment</span>
            @endif
            @if ($examiners->count() > $active->count())
                <span class="pool-count"><b>{{ $examiners->count() - $active->count() }}</b> removed</span>
            @endif
        </div>

        {{-- The cooldown rule, stated once where the "on an appointment"
             badges are, so nobody wonders why a listed examiner is missing
             from the nomination form. --}}
        <p class="queue-meta" style="margin: 0 0 var(--space-4);">
            An examiner the Dean has appointed is unavailable for the next
            {{ \App\Modules\Jason\Models\PoolExaminer::COOLDOWN_MONTHS }} months, so no one
            carries two panels at once. They return to the nomination form on their own.
        </p>
    @endif

    @if ($examiners->isEmpty())
        <div class="empty-state">
            <p>No examiners yet.</p>
            <p class="queue-meta">Add the first one and they appear on the nomination form.</p>
            <a href="{{ route('appointment-letter.examiners.create', array_filter(['return' => $returnToNomination ? 'nominate' : null])) }}"
               class="btn">Add an examiner</a>
        </div>
    @else
        <div class="pool-table-wrap">
            <table class="data-table pool-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Institution / Faculty</th>
                        <th>Area of Expertise</th>
                        <th>Availability</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($examiners as $examiner)
                        @php
                            $initials = collect(preg_split('/\s+/', trim($examiner->name)))
                                ->reject(fn ($w) => in_array(rtrim($w, '.'), ['Dr', 'Prof', 'Ir', 'Professor', 'Assoc', 'AP', 'Puan', 'En'], true))
                                ->filter()->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode('');
                        @endphp
                        <tr @unless ($examiner->is_active) class="is-removed" @endunless>
                            <td>
                                <div class="pool-name">
                                    <span class="pool-avatar">{{ mb_strtoupper($initials) }}</span>
                                    <span>
                                        {{ $examiner->name }}<br>
                                        <span class="pool-email">{{ $examiner->email }}</span>
                                    </span>
                                </div>
                            </td>
                            <td>
                                @if ($examiner->is_active)
                                    <span class="status-badge {{ $examiner->isInternal() ? 'approved' : 'pending' }}">
                                        {{ $examiner->isInternal() ? 'Internal' : 'External' }}
                                    </span>
                                @else
                                    <span class="status-badge draft">Removed</span>
                                @endif
                            </td>
                            <td>{{ $examiner->institution }}</td>
                            <td><span class="pool-expertise">{{ $examiner->expertise }}</span></td>
                            <td>
                                @if (! $examiner->is_active)
                                    <span class="pool-email">removed</span>
                                @elseif ($examiner->isAvailable())
                                    <span class="status-badge approved">Available</span>
                                @else
                                    <span class="status-badge rejected">On an appointment</span><br>
                                    <span class="pool-email">until {{ $examiner->availableFrom()->format('j M Y') }}</span>
                                @endif
                            </td>
                            <td>
                                <form method="POST" action="{{ route('appointment-letter.examiners.toggle', $examiner) }}">
                                    @csrf
                                    <button type="submit" @class(['btn-reject' => $examiner->is_active])>
                                        {{ $examiner->is_active ? 'Remove' : 'Reinstate' }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Set when the Chair arrived from the nomination form. They came to add
         someone; this is the way out if they only came to look. The panel they
         had already picked is still waiting to be restored. --}}
    @if ($returnToNomination)
        <p class="field-hint" style="margin-top: var(--space-5);">
            <a href="{{ route('appointment-letter.create') }}">&larr; Back to the nomination without adding anyone</a>
        </p>
    @endif
</div>
@endsection
