@extends('core::layouts.app')

@section('title', 'Examiner Pool')

@section('content')

{{-- Scoped to this page only -- nothing here touches a Core/shared stylesheet.
     The .sdash-* classes borrowed below (stat card shell, tone colours) are
     already global via partials/stylesheets.blade.php; this block only adds
     what doesn't already exist anywhere: a 4-column stat grid, the status
     badge, the avatar circle, the filter bar and the reason modal. --}}
<style>
    /* Scoped to this page -- nothing here touches a Core/shared stylesheet.
       The .sdash-* classes borrowed below (stat card shell, tone colours) and
       .data-table are already global via partials/stylesheets.blade.php; this
       block only adds what does not exist anywhere else.

       The page used to sit in a .card.card-wide, which caps at 680px. A pool
       screen is a dashboard -- four figures, a filter bar and a seven-column
       table -- and 680px is why the fourth stat card wrapped onto its own row
       and the table scrolled sideways on a 1900px monitor. It is a full-width
       page now, capped only where a table stops being readable. */
    /* Sizes come from tokens.css, not literals: --page-max and the --text-*
       scale are fluid, so this page narrows and its type shrinks with the
       viewport instead of holding laptop-era numbers on every screen. */
    .exam-page { max-width: var(--page-max); margin: 0 auto; }

    .exam-header {
        display: flex; align-items: flex-start; justify-content: space-between;
        gap: 16px; flex-wrap: wrap; margin-bottom: 20px;
    }
    .exam-header h2 { margin: 0 0 4px; }

    /* auto-fit, not a fixed 4: the cards share out whatever the row has, so
       they stay even at every width instead of leaving a 3+1 orphan. */
    .examiner-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
        gap: 14px;
        margin-bottom: 20px;
    }
    .examiner-stats .sdash-stat { max-width: none; }
    .examiner-stats .sdash-pill.is-active-filter { box-shadow: inset 0 0 0 1.5px currentColor; }

    /* The filter bar is its own surface, so it reads as a control strip over
       the table rather than as more page content. */
    .examiner-filters {
        display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;
        margin-bottom: 16px; padding: 14px 16px;
        background: var(--surface); border: 1px solid var(--border-subtle);
        border-radius: 12px;
    }
    .examiner-filters .field { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
    .examiner-filters label { font-size: var(--text-xs); color: var(--text-grey); font-weight: 600; margin: 0; }
    .examiner-filters select,
    .examiner-filters input[type="text"] {
        margin: 0; padding: 8px 10px; font-size: var(--text-sm); min-width: 170px;
    }
    .examiner-filters .search-field { flex: 1 1 260px; }
    .examiner-filters .search-field input { min-width: 0; width: 100%; }
    .examiner-filters button { margin: 0; padding: 9px 18px; }
    .examiner-filters .clear-link { align-self: center; font-size: var(--text-sm); color: var(--text-grey); }

    .exam-table-wrap {
        background: var(--surface);
        border: 1px solid var(--border-subtle);
        border-radius: 12px;
        padding: 4px 16px 16px;
        overflow-x: auto;
    }

    .exam-table { width: 100%; }
    .exam-table th, .exam-table td { vertical-align: middle; }
    /* The two date-ish columns and the action never need to wrap. */
    .exam-table td:nth-child(6), .exam-table th:nth-child(6) { white-space: nowrap; }
    .exam-table td:last-child { text-align: right; white-space: nowrap; }
    .exam-table td:last-child button { margin: 0; padding: 6px 12px; font-size: var(--text-sm); }

    .examiner-avatar {
        display: inline-flex; align-items: center; justify-content: center;
        width: calc(var(--text-md) * 2.3); height: calc(var(--text-md) * 2.3);
        border-radius: 50%; flex-shrink: 0;
        background: var(--light-blue-bg); color: var(--navy);
        font-size: var(--text-sm); font-weight: 700;
    }
    .examiner-name-cell { display: flex; align-items: center; gap: 10px; min-width: 190px; }

    .examiner-state-badge {
        display: inline-block; padding: 3px 10px; border-radius: 999px;
        font-size: var(--text-xs); font-weight: 600; white-space: nowrap;
    }
    /* Tokens, not the literals this block used to carry -- the old hexes were
       light-mode values and stayed light on the dark theme. */
    .examiner-state-badge.tone-good     { background: var(--success-bg); color: var(--success-fg); }
    .examiner-state-badge.tone-warn     { background: var(--warning-bg); color: var(--warning-fg); }
    .examiner-state-badge.tone-info     { background: var(--info-bg); color: var(--info-fg); }
    .examiner-state-badge.tone-critical { background: var(--danger-bg); color: var(--danger-fg); }

    .examiner-reason { display: block; font-size: var(--text-2xs); color: var(--text-grey); margin-top: 2px; max-width: 220px; }

    .examiner-pagination { display: flex; gap: 6px; margin-top: 14px; flex-wrap: wrap; }
    .examiner-pagination a, .examiner-pagination span {
        padding: 6px 11px; border-radius: 8px; font-size: var(--text-sm); border: 1px solid var(--border-grey);
    }
    .examiner-pagination a { color: var(--navy); text-decoration: none; }
    .examiner-pagination a:hover { background: var(--surface-hover); border-color: var(--navy); }
    .examiner-pagination .is-current { background: var(--navy); color: var(--text-on-accent); border-color: var(--navy); }
    .examiner-pagination .is-disabled { color: var(--text-grey); }

    .reason-modal { border: none; border-radius: 14px; padding: 0; max-width: 420px; width: 90vw; }
    .reason-modal::backdrop { background: rgba(20, 25, 40, 0.45); }
    .reason-modal .card { border: none; box-shadow: none; margin: 0; max-width: none; }
    .reason-modal-head { display: flex; justify-content: space-between; align-items: center; }
    .reason-modal-close { background: none; border: none; font-size: var(--text-xl); cursor: pointer; color: var(--text-grey); }
    .reason-modal textarea { width: 100%; }

    /* The internal/external split reads as two lists, so it gets a tab strip
       rather than a third dropdown sitting among the filters -- a filter you
       have to open to see the state of is the wrong control for the thing
       that decides which columns the table even has. */
    .exam-tabs {
        display: flex; gap: 4px; margin-bottom: 18px;
        border-bottom: 1px solid var(--border-grey);
    }
    .exam-tab {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 10px 16px; margin-bottom: -1px;
        border: 1px solid transparent; border-bottom: none;
        border-radius: 10px 10px 0 0;
        font-size: var(--text-sm); font-weight: 600;
        color: var(--text-grey); text-decoration: none;
    }
    .exam-tab:hover { color: var(--navy); background: var(--surface-hover); }
    .exam-tab.is-current {
        background: var(--surface); color: var(--navy);
        border-color: var(--border-grey);
        box-shadow: inset 0 2px 0 var(--navy);
    }
    .exam-tab-count {
        padding: 1px 8px; border-radius: 999px;
        background: var(--border-subtle); color: var(--text-grey);
        font-size: var(--text-2xs); font-weight: 700;
    }
    .exam-tab.is-current .exam-tab-count { background: var(--accent-subtle); color: var(--navy); }

    .exam-tab-note { margin: -6px 0 16px; color: var(--text-grey); font-size: var(--text-sm); }

    /* Experience / MSc / PhD arrive as one string with newlines, exactly as
       the sheet stacks them in a single cell. */
    .exam-details { white-space: pre-line; font-size: var(--text-xs); min-width: 130px; }
    .exam-expertise { display: block; max-width: 220px; font-size: var(--text-xs); }
    .exam-students { display: flex; flex-direction: column; gap: 2px; min-width: 170px; font-size: var(--text-xs); }

    /* Switching tab is a real navigation, so the whole document cross-fades
       (layout.css, "View Transitions"). The header, the tabs and the filter
       bar are identical on every tab -- fading them out and back in is what
       makes a tab click look like the browser reloaded. Naming each one takes
       it out of the root snapshot and pins it across the navigation, exactly
       as the sidebar and the top bar already are, so only the stat counts and
       the table animate. Names must be unique in a document; each of these is
       a single element. */
    .exam-header      { view-transition-name: exam-header; }
    .exam-tabs        { view-transition-name: exam-tabs; }
    .examiner-filters { view-transition-name: exam-filters; }

    ::view-transition-group(exam-header),
    ::view-transition-group(exam-tabs),
    ::view-transition-group(exam-filters) {
        animation: none;
    }

    @media (max-width: 720px) {
        .examiner-filters select, .examiner-filters input[type="text"] { min-width: 0; width: 100%; }
        .examiner-filters .field { flex: 1 1 100%; }
    }
</style>

@php
    $isExternal = $type === \App\Modules\Hani\Models\Examiner::TYPE_EXTERNAL;
    $isInternal = $type === \App\Modules\Hani\Models\Examiner::TYPE_INTERNAL;
@endphp

<div class="exam-page">
    <header class="exam-header">
        <div>
            <h2>Examiner Pool</h2>
            <p class="queue-meta">
                {{ $examiners->total() }} {{ Str::plural('examiner', $examiners->total()) }} on record.
                State is derived, so anyone on gap becomes available again on their own.
            </p>
        </div>
        <a href="{{ route('examiner-admin.create') }}" class="sdash-action">+ Add an examiner</a>
    </header>

    <nav class="exam-tabs" aria-label="Examiner lists">
        @foreach ([
            '' => 'All Examiners',
            \App\Modules\Hani\Models\Examiner::TYPE_INTERNAL => 'Internal',
            \App\Modules\Hani\Models\Examiner::TYPE_EXTERNAL => 'External',
        ] as $tabValue => $tabLabel)
            {{-- Switching list drops the filters on purpose: a department or a
                 status picked on one list rarely means anything on the other. --}}
            <a href="{{ route('examiner-admin.index', array_filter(['type' => $tabValue])) }}"
               class="exam-tab @if($type === $tabValue) is-current @endif"
               @if($type === $tabValue) aria-current="page" @endif>
                {{ $tabLabel }}<span class="exam-tab-count">{{ $typeCounts[$tabValue] }}</span>
            </a>
        @endforeach
    </nav>

    <p class="exam-tab-note">
        @if ($isExternal)
            External examiners carry the faculty approval, institution, expertise and
            supervision record CGS keeps for anyone appointed from outside UTP.
        @elseif ($isInternal)
            Internal examiners are UTP staff. The list shows name, department,
            availability and the students they are holding. No external paperwork
            applies.
        @else
            Both lists, showing only the columns they have in common. Open a list to
            see everything kept for it.
        @endif
    </p>

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
                    <a href="{{ route('examiner-admin.index', array_filter(['status' => $card['state'], 'type' => $type])) }}"
                       class="sdash-pill pill-{{ $card['pillTone'] }} @if(($filters['status'] ?? null) === $card['state']) is-active-filter @endif">
                        View list
                    </a>
                </div>
            @endforeach
        </div>

        <form method="GET" action="{{ route('examiner-admin.index') }}" class="examiner-filters">
            {{-- Filtering stays on the list you are looking at. --}}
            <input type="hidden" name="type" value="{{ $type }}">
            <div class="field">
                <label for="f-department">Department</label>
                <select name="department" id="f-department" data-autosubmit>
                    <option value="">All Departments</option>
                    @foreach ($departments as $dept)
                        <option value="{{ $dept }}" @selected(($filters['department'] ?? null) === $dept)>{{ $dept }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="f-status">Status</label>
                <select name="status" id="f-status" data-autosubmit>
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
            <div class="field search-field">
                <label for="f-search">Search</label>
                <input type="text" name="search" id="f-search" placeholder="Search by name, department..."
                       value="{{ $filters['search'] ?? '' }}">
            </div>
            <button type="submit">Filter</button>
            @if (array_filter(\Illuminate\Support\Arr::except($filters, 'type')))
                <a href="{{ route('examiner-admin.index', array_filter(['type' => $type])) }}" class="clear-link">Clear filters</a>
            @endif
        </form>

    <div class="exam-table-wrap">
        <table class="data-table exam-table">
            {{-- Three column sets off one row, because the sheets are three
                 different records: the external list adds nine columns the
                 internal one has no equivalent of, and the combined list shows
                 only what both actually have. Column order matches the CGS
                 spreadsheets so a reader can follow either one across. --}}
            <thead>
                <tr>
                    <th>No.</th>
                    <th>{{ $isInternal ? 'Approved Internal Examiner' : 'Name Examiner' }}</th>
                    @if (! $type)<th>List</th>@endif
                    <th>{{ $isExternal ? 'Department (UTP)' : 'Department' }}</th>
                    @if (! $isExternal)<th>Faculty</th>@endif
                    @if ($isExternal)
                        <th>Faculty Approval</th>
                        <th>University / Industry</th>
                        <th>Technical / Research</th>
                        <th>UTP Acad Cluster</th>
                        <th>Area of Expertise</th>
                        <th>Details</th>
                    @endif
                    <th>Status of Availability</th>
                    @if ($isExternal)<th>Date 1st</th><th>Date 2nd</th>@endif
                    <th>Remark / Next availability</th>
                    <th>{{ $isInternal ? 'Remarks (students held)' : 'Student Name' }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($examiners as $i => $examiner)
                    @php
                        $initials = collect(explode(' ', preg_replace('/^(Dr\.|Prof\.|Prof\. Madya|Puan|En)\s+/', '', $examiner->name)))
                            ->filter()->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode('');
                        $students = $studentsByExaminer[$examiner->id] ?? [];
                    @endphp
                    <tr>
                        <td>{{ $examiners->firstItem() + $i }}</td>
                        <td>
                            <div class="examiner-name-cell">
                                <span class="examiner-avatar">{{ strtoupper($initials) }}</span>
                                <span>{{ $examiner->name }}</span>
                            </div>
                        </td>
                        @if (! $type)
                            <td><span class="examiner-state-badge tone-{{ $examiner->isExternal() ? 'info' : 'good' }}">{{ $examiner->typeLabel() }}</span></td>
                        @endif
                        <td>{{ $examiner->department }}</td>
                        @if (! $isExternal)<td>{{ $examiner->faculty ?? '—' }}</td>@endif
                        @if ($isExternal)
                            <td>{{ $examiner->faculty_approval ?? '—' }}</td>
                            <td>{{ $examiner->institution ?? '—' }}</td>
                            <td>{{ $examiner->sectorLabel() ?? '—' }}</td>
                            <td>{{ $examiner->utp_cluster ?? '—' }}</td>
                            <td><span class="exam-expertise">{{ $examiner->expertise ?? '—' }}</span></td>
                            <td class="exam-details">{{ $examiner->experienceSummary() ?? '—' }}</td>
                        @endif
                        <td>
                            <span class="examiner-state-badge tone-{{ $examiner->stateTone() }}">{{ $examiner->stateLabel() }}</span>
                            @if ($examiner->unavailable_reason)
                                <span class="examiner-reason">{{ $examiner->unavailable_reason }}</span>
                            @endif
                        </td>
                        @if ($isExternal)
                            <td>{{ $examiner->first_examination_date?->format('j M Y') ?? '—' }}</td>
                            <td>{{ $examiner->last_examination_date?->format('j M Y') ?? '—' }}</td>
                        @endif
                        <td>
                            @if ($examiner->state() === \App\Modules\Hani\Models\Examiner::STATE_ON_GAP)
                                Available {{ $examiner->gapEndsOn()->format('j M Y') }}
                            @elseif ($examiner->state() === \App\Modules\Hani\Models\Examiner::STATE_ASSIGNED)
                                Held until {{ $examiner->assigned_until->format('j M Y') }}
                            @elseif ($examiner->state() === \App\Modules\Hani\Models\Examiner::STATE_AVAILABLE)
                                Available
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            @if ($students)
                                <span class="exam-students">
                                    @foreach ($students as $student)<span>{{ $student }}</span>@endforeach
                                </span>
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            @if ($examiner->is_active)
                                <button type="button" class="btn-reject"
                                        data-unavailable="{{ $examiner->id }}"
                                        data-examiner-name="{{ $examiner->name }}">
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
                    {{-- 7 shared, + List on the combined list, + Faculty on every
                         list but external, + external's own eight. --}}
                    <tr><td colspan="{{ 7 + (! $type ? 1 : 0) + (! $isExternal ? 1 : 0) + ($isExternal ? 8 : 0) }}">
                        <div class="empty-state">No examiners found. Try adjusting your filters.</div>
                    </td></tr>
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
            <button type="button" class="reason-modal-close" data-close-modal>&times;</button>
        </div>
        <div class="card-divider"></div>
        <form method="POST" id="unavailable-form">
            @csrf
            <p id="unavailable-modal-name" style="color: var(--text-grey); font-size: var(--text-sm);"></p>

            <label for="unavailable_reason">Reason</label>
            <textarea name="unavailable_reason" id="unavailable_reason" rows="4" required maxlength="2000"
                      placeholder="Please provide a reason for marking this examiner as unavailable..."></textarea>

            <div class="decision-row">
                <button type="button" data-close-modal>Cancel</button>
                <button type="submit" class="btn-reject">Confirm</button>
            </div>
        </form>
    </div>
</dialog>

{{-- Bound here with addEventListener, not with handler attributes. script-src is
     'self' plus this request's nonce with no unsafe-inline, and a nonce cannot
     cover an inline handler attribute -- the browser refuses to run it. As
     written before, the two filters did not auto-submit and the modal never
     opened. --}}
<script @cspNonce>
    (function () {
        var modal = document.getElementById('unavailable-modal');
        var form = document.getElementById('unavailable-form');

        document.querySelectorAll('[data-autosubmit]').forEach(function (field) {
            field.addEventListener('change', function () { field.form.submit(); });
        });

        document.querySelectorAll('[data-unavailable]').forEach(function (button) {
            button.addEventListener('click', function () {
                form.action = '/examiners/' + button.dataset.unavailable + '/toggle-active';
                document.getElementById('unavailable-modal-name').textContent =
                    'Examiner: ' + button.dataset.examinerName;
                document.getElementById('unavailable_reason').value = '';
                modal.showModal();
            });
        });

        document.querySelectorAll('[data-close-modal]').forEach(function (button) {
            button.addEventListener('click', function () { modal.close(); });
        });
    })();
</script>
@endsection
