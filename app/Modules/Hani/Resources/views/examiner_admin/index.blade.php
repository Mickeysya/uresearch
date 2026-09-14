@extends('core::layouts.app')

@section('title', 'Examiner Pool')

@section('content')

{{-- Scoped to this page only -- nothing here touches a Core/shared stylesheet.
     The .sdash-* classes borrowed below (stat card shell, tone colours) are
     already global via partials/stylesheets.blade.php; this block only adds
     what doesn't already exist anywhere: a 4-column stat grid, the status
     badge, the avatar circle, the filter bar and the reason modal. --}}
<style>
    .examiner-stats { grid-template-columns: repeat(4, minmax(0, 1fr)); margin-bottom: 20px; }
    .examiner-stats .sdash-pill.is-active-filter { box-shadow: inset 0 0 0 1.5px currentColor; }

    .examiner-filters {
        display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end;
        margin-bottom: 16px;
    }
    .examiner-filters .field { display: flex; flex-direction: column; gap: 4px; }
    .examiner-filters label { font-size: 11.5px; color: var(--text-grey); font-weight: 600; }
    .examiner-filters select, .examiner-filters input[type="text"] {
        padding: 7px 10px; border: 1px solid var(--border-grey); border-radius: 8px; font-size: 13px;
    }
    .examiner-filters .search-field { flex: 1 1 220px; }
    .examiner-filters .clear-link { align-self: center; font-size: 12.5px; color: var(--text-grey); }

    .examiner-avatar {
        display: inline-flex; align-items: center; justify-content: center;
        width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0;
        background: var(--light-blue-bg); color: var(--navy);
        font-size: 12px; font-weight: 700;
    }
    .examiner-name-cell { display: flex; align-items: center; gap: 10px; }

    .examiner-state-badge {
        display: inline-block; padding: 3px 10px; border-radius: 999px;
        font-size: 11.5px; font-weight: 600; white-space: nowrap;
    }
    .examiner-state-badge.tone-good     { background: #E4F6EC; color: #067A42; }
    .examiner-state-badge.tone-warn     { background: #FDF1DC; color: #8A5D12; }
    .examiner-state-badge.tone-info     { background: #E8F0FD; color: #2456A6; }
    .examiner-state-badge.tone-critical { background: #FDECEA; color: #A8271C; }

    .examiner-reason { display: block; font-size: 11px; color: var(--text-grey); margin-top: 2px; max-width: 220px; }

    .examiner-pagination { display: flex; gap: 6px; margin-top: 14px; flex-wrap: wrap; }
    .examiner-pagination a, .examiner-pagination span {
        padding: 6px 11px; border-radius: 8px; font-size: 12.5px; border: 1px solid var(--border-grey);
    }
    .examiner-pagination a { color: var(--navy); }
    .examiner-pagination .is-current { background: var(--navy); color: white; border-color: var(--navy); }
    .examiner-pagination .is-disabled { color: var(--text-grey); }

    .reason-modal { border: none; border-radius: 14px; padding: 0; max-width: 420px; width: 90vw; }
    .reason-modal::backdrop { background: rgba(20, 25, 40, 0.45); }
    .reason-modal .card { border: none; box-shadow: none; margin: 0; }
    .reason-modal-head { display: flex; justify-content: space-between; align-items: center; }
    .reason-modal-close { background: none; border: none; font-size: 18px; cursor: pointer; color: var(--text-grey); }
    .reason-modal textarea { width: 100%; }
</style>

<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Examiner Pool</h2>
        <div class="card-divider"></div>

        <div class="sdash-stats examiner-stats">
            @php
                $cards = [
                    ['tone' => 'green',  'pillTone' => 'good',     'icon' => 'check',  'label' => 'Available',   'state' => \App\Modules\Hani\Models\Examiner::STATE_AVAILABLE],
                    ['tone' => 'orange', 'pillTone' => 'warn',     'icon' => 'clock',  'label' => 'On Gap',      'state' => \App\Modules\Hani\Models\Examiner::STATE_ON_GAP],
                    ['tone' => 'blue',   'pillTone' => 'info',     'icon' => 'people', 'label' => 'Assigned',    'state' => \App\Modules\Hani\Models\Examiner::STATE_ASSIGNED],
                    ['tone' => 'red',    'pillTone' => 'critical', 'icon' => 'cross',  'label' => 'Unavailable', 'state' => \App\Modules\Hani\Models\Examiner::STATE_UNAVAILABLE],
                ];
            @endphp
            @foreach ($cards as $card)
                <div class="sdash-stat tone-{{ $card['tone'] }}">
                    <div class="sdash-stat-top">
                        <span class="sdash-stat-icon" aria-hidden="true">
                            @include('core::dashboard.partials.icon', ['name' => $card['icon']])
                        </span>
                        <span class="sdash-stat-label">{{ $card['label'] }}</span>
                    </div>
                    <div class="sdash-stat-value">{{ $stateCounts[$card['state']] }}</div>
                    <div class="sdash-stat-note">in the pool right now</div>
                    <a href="{{ route('examiner-admin.index', ['status' => $card['state']]) }}"
                       class="sdash-pill pill-{{ $card['pillTone'] }} @if(($filters['status'] ?? null) === $card['state']) is-active-filter @endif">
                        View list
                    </a>
                </div>
            @endforeach
        </div>

        <form method="GET" action="{{ route('examiner-admin.index') }}" class="examiner-filters">
            <div class="field">
                <label for="f-department">Department</label>
                <select name="department" id="f-department" onchange="this.form.submit()">
                    <option value="">All Departments</option>
                    @foreach ($departments as $dept)
                        <option value="{{ $dept }}" @selected(($filters['department'] ?? null) === $dept)>{{ $dept }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="f-status">Status</label>
                <select name="status" id="f-status" onchange="this.form.submit()">
                    <option value="">All Status</option>
                    @foreach ([
                        \App\Modules\Hani\Models\Examiner::STATE_AVAILABLE => 'Available',
                        \App\Modules\Hani\Models\Examiner::STATE_ON_GAP => 'On Gap',
                        \App\Modules\Hani\Models\Examiner::STATE_ASSIGNED => 'Assigned',
                        \App\Modules\Hani\Models\Examiner::STATE_UNAVAILABLE => 'Unavailable',
                    ] as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['status'] ?? null) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="f-type">Type</label>
                <select name="type" id="f-type" onchange="this.form.submit()">
                    <option value="">All (Internal / External)</option>
                    <option value="internal" @selected(($filters['type'] ?? null) === 'internal')>Internal</option>
                    <option value="external" @selected(($filters['type'] ?? null) === 'external')>External</option>
                </select>
            </div>
            <div class="field search-field">
                <label for="f-search">Search</label>
                <input type="text" name="search" id="f-search" placeholder="Search by name, department..."
                       value="{{ $filters['search'] ?? '' }}">
            </div>
            <button type="submit">Filter</button>
            @if (array_filter($filters))
                <a href="{{ route('examiner-admin.index') }}" class="clear-link">Clear filters</a>
            @endif
        </form>

        <p class="queue-meta">
            <a href="{{ route('examiner-admin.create') }}">+ Add an examiner</a>
        </p>

        <table class="recent-activity-table">
            <thead>
                <tr>
                    <th>Name</th><th>Department</th><th>Faculty</th><th>Type</th>
                    <th>Status</th><th>Until / Since</th><th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($examiners as $examiner)
                    @php
                        $initials = collect(explode(' ', preg_replace('/^(Dr\.|Prof\.|Prof\. Madya|Puan|En)\s+/', '', $examiner->name)))
                            ->filter()->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode('');
                    @endphp
                    <tr>
                        <td>
                            <div class="examiner-name-cell">
                                <span class="examiner-avatar">{{ strtoupper($initials) }}</span>
                                <span>{{ $examiner->name }}</span>
                            </div>
                        </td>
                        <td>{{ $examiner->department }}</td>
                        <td>{{ $examiner->faculty ?? '—' }}</td>
                        <td>{{ ucfirst($examiner->type) }}</td>
                        <td>
                            <span class="examiner-state-badge tone-{{ $examiner->stateTone() }}">{{ $examiner->stateLabel() }}</span>
                            @if ($examiner->unavailable_reason)
                                <span class="examiner-reason">{{ $examiner->unavailable_reason }}</span>
                            @endif
                        </td>
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
                            @if ($examiner->is_active)
                                <button type="button" class="btn-reject"
                                        onclick="openUnavailableModal({{ $examiner->id }}, '{{ addslashes($examiner->name) }}')">
                                    Mark Unavailable
                                </button>
                            @else
                                <form method="POST" action="{{ route('examiner-admin.toggle-active', $examiner) }}">
                                    @csrf
                                    <button type="submit">Reactivate</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="empty-state">No examiners found. Try adjusting your filters.</div></td></tr>
                @endforelse
            </tbody>
        </table>

        @if ($examiners->hasPages())
            <div class="examiner-pagination">
                @if ($examiners->onFirstPage())
                    <span class="is-disabled">&laquo; Prev</span>
                @else
                    <a href="{{ $examiners->previousPageUrl() }}">&laquo; Prev</a>
                @endif

                @for ($i = 1; $i <= $examiners->lastPage(); $i++)
                    @if ($i === $examiners->currentPage())
                        <span class="is-current">{{ $i }}</span>
                    @else
                        <a href="{{ $examiners->url($i) }}">{{ $i }}</a>
                    @endif
                @endfor

                @if ($examiners->hasMorePages())
                    <a href="{{ $examiners->nextPageUrl() }}">Next &raquo;</a>
                @else
                    <span class="is-disabled">Next &raquo;</span>
                @endif
            </div>
        @endif
    </div>
</div>

<dialog id="unavailable-modal" class="reason-modal">
    <div class="card">
        <div class="reason-modal-head">
            <h3 style="margin: 0;">Mark as Unavailable</h3>
            <button type="button" class="reason-modal-close" onclick="document.getElementById('unavailable-modal').close()">&times;</button>
        </div>
        <div class="card-divider"></div>
        <form method="POST" id="unavailable-form">
            @csrf
            <p id="unavailable-modal-name" style="color: var(--text-grey); font-size: 13px;"></p>

            <label for="unavailable_reason">Reason</label>
            <textarea name="unavailable_reason" id="unavailable_reason" rows="4" required maxlength="2000"
                      placeholder="Please provide a reason for marking this examiner as unavailable..."></textarea>

            <div class="decision-row">
                <button type="button" onclick="document.getElementById('unavailable-modal').close()">Cancel</button>
                <button type="submit" class="btn-reject">Confirm</button>
            </div>
        </form>
    </div>
</dialog>

<script @cspNonce>
    function openUnavailableModal(examinerId, name) {
        var modal = document.getElementById('unavailable-modal');
        var form = document.getElementById('unavailable-form');
        form.action = '/examiners/' + examinerId + '/toggle-active';
        document.getElementById('unavailable-modal-name').textContent = 'Examiner: ' + name;
        document.getElementById('unavailable_reason').value = '';
        modal.showModal();
    }
</script>
@endsection
