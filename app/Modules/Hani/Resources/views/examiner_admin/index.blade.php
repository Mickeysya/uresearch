@extends('core::layouts.app')

@section('title', 'Examiner Pool')

@section('content')

@include('hani::examiner_admin._pool_styles')

{{-- Scoped to this page only -- nothing here touches a Core/shared stylesheet.
     The .sdash-* classes borrowed below (stat card shell, tone colours) are
     already global via partials/stylesheets.blade.php; _pool_styles has what the
     supervisor's read-only list shares; this block adds what only this page has:
     the stat grid, the action column, duplicate warnings and the reason modal. --}}
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
    /* The header's actions as one row of one size. uresearch.css still gives
       .btn margin-top: 16px and margin-right: 8px, which .btn-secondary never
       had, and layout.css gives the two different padding and line-height,
       which is why "Add an examiner" sat lower and stood a different height.
       The row's gap (layout.css, .page-header-actions) is the only spacing. */
    .page-header-actions > .btn,
    .page-header-actions > .btn-secondary {
        margin: 0;
        padding: var(--space-3) var(--space-5);
        line-height: 1.2;
        white-space: nowrap;
    }

    /* Sizes come from tokens.css, not literals: --page-max and the --text-*
       scale are fluid, so this page narrows and its type shrinks with the
       viewport instead of holding laptop-era numbers on every screen. */
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

    /* The two date-ish columns and the action never need to wrap. */
    .exam-table td:nth-child(6), .exam-table th:nth-child(6) { white-space: nowrap; }
    /* The row's actions stack, at one compact size, so the column is one button
       wide. Side by side they pushed the second action off the table's edge,
       behind a sideways scroll, at 1440px. */
    /* By class, not :last-child: the Academic Executive's list has no action
       column, and its last cell is the students. */
    .exam-table th { white-space: normal; }
    .exam-table td.exam-actions { text-align: right; white-space: nowrap; }
    .exam-table td.exam-actions > * { display: flex; width: 100%; margin: 0 0 6px; }
    .exam-table td.exam-actions > :last-child { margin-bottom: 0; }
    .exam-table td.exam-actions button,
    .exam-table td.exam-actions .btn-secondary {
        width: 100%; margin: 0; padding: 6px 12px;
        font-size: var(--text-sm); line-height: 1.2; box-sizing: border-box;
    }

    .examiner-duplicate { display: block; margin-top: 3px; padding: 2px 8px; border-radius: 8px; font-size: var(--text-2xs); background: var(--warning-bg); color: var(--warning-fg); }
    .examiner-duplicate form { display: inline; }
    .examiner-duplicate .btn-link { background: none; border: none; padding: 0; margin: 0 0 0 4px; color: inherit; text-decoration: underline; font-size: inherit; cursor: pointer; }
    .examiner-reason { display: block; font-size: var(--text-2xs); color: var(--text-grey); margin-top: 2px; max-width: 220px; }

    .exam-proposals {
        background: var(--surface); border: 1px solid var(--border-subtle);
        border-radius: 12px; padding: 4px 16px 16px; overflow-x: auto; margin-bottom: 18px;
    }

    .reason-modal { border: none; border-radius: 14px; padding: 0; max-width: 420px; width: 90vw; }
    .reason-modal::backdrop { background: rgba(20, 25, 40, 0.45); }
    .reason-modal .card { border: none; box-shadow: none; margin: 0; max-width: none; }
    .reason-modal-head { display: flex; justify-content: space-between; align-items: center; }
    .reason-modal-close { background: none; border: none; font-size: var(--text-xl); cursor: pointer; color: var(--text-grey); }
    .reason-modal textarea { width: 100%; }

    .exam-tab-note { margin: -6px 0 16px; color: var(--text-grey); font-size: var(--text-sm); }

    /* The Academic Executive's read-only note: who to email to change the pool. */
    .exam-cgs-note {
        margin: 0 0 18px; padding: 12px 16px; border-radius: 12px;
        background: var(--info-bg); color: var(--info-fg); font-size: var(--text-sm);
    }
    .exam-cgs-note a { color: inherit; font-weight: 600; }

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
    .page-header      { view-transition-name: exam-header; }
    .exam-tabs        { view-transition-name: exam-tabs; }
    .examiner-filters { view-transition-name: exam-filters; }

    ::view-transition-group(exam-header),
    ::view-transition-group(exam-tabs),
    ::view-transition-group(exam-filters) {
        animation: none;
    }

</style>

@php
    $isExternal = $type === \App\Modules\Hani\Models\Examiner::TYPE_EXTERNAL;
    $isInternal = $type === \App\Modules\Hani\Models\Examiner::TYPE_INTERNAL;
    // Display only: routes.php is what enforces this. Both CGS desks add
    // examiners, mark them unavailable and keep emails current; the full edit
    // form, import, the review list and "Not the same person" stay with the
    // Senior Executive. The Academic Executive ($canManage false) reads only.
    $isSeniorExec = auth()->user()->role === \App\Modules\Core\Support\Role::SENIOR_EXEC_CGS;
@endphp

<div class="card-container-inline">
    <x-core::page-header
        title="Examiner Pool"
        subtitle="{{ $examiners->total() }} {{ Str::plural('examiner', $examiners->total()) }} on record. State is derived, so anyone on gap becomes available again on their own.">
        @if ($canManage)
            <a href="{{ route('examiner-admin.create') }}" class="btn">Add an examiner</a>
        @endif
        @if ($isSeniorExec)
            <a href="{{ route('examiner-import.create') }}" class="btn-secondary">Import from Excel</a>
            @if ($openReviewItems)
                <a href="{{ route('examiner-import.review') }}" class="btn-secondary">Review list ({{ $openReviewItems }})</a>
            @endif
        @endif
    </x-core::page-header>

    @if ($cgsContacts)
        <p class="exam-cgs-note">
            To add or remove an examiner, email CGS:
            @foreach ($cgsContacts as $contact)
                <a href="mailto:{{ $contact['email'] }}">{{ $contact['name'] }}</a> ({{ $contact['role'] }}, {{ $contact['email'] }}){{ $loop->last ? '.' : ' or' }}
            @endforeach
        </p>
    @endif

    @if ($proposals->isNotEmpty())
        {{-- Not a RegionSwap region (its own class, not .exam-table-wrap, which
             RegionSwap swaps by its first match), so it is left alone by tab and
             filter swaps and its links and forms are ordinary navigations. --}}
        <div class="exam-proposals" id="proposed-emails">
            <h3 style="margin: 12px 0 4px;">Proposed emails to confirm ({{ $proposals->count() }})</h3>
            <p class="exam-tab-note" style="margin: 0 0 8px;">Given by supervisors at nomination for examiners whose email was missing or reported not working. Confirming makes it the examiner's email and logs it; nothing changes until then.</p>
            @error('proposal') <p class="message-error">{{ $message }}</p> @enderror
            <table class="data-table exam-table">
                <thead><tr><th>Examiner</th><th>Email now</th><th>Proposed email</th><th>From</th><th>CV</th><th></th></tr></thead>
                <tbody>
                    @foreach ($proposals as $proposal)
                        <tr>
                            <td><a href="{{ route('examiner-admin.show', $proposal->examiner) }}" data-region-swap-ignore>{{ $proposal->examiner->name }}</a></td>
                            <td>{{ $proposal->examiner->email ?? 'None' }} @include('hani::examiner_admin._email_badge', ['examiner' => $proposal->examiner])</td>
                            <td><b>{{ $proposal->proposed_email }}</b></td>
                            <td>{{ $proposal->proposedBy?->name ?? 'Unknown' }}@if ($proposal->nomination), nomination #{{ $proposal->nomination->application_id }}@endif<span class="examiner-reason">{{ $proposal->created_at->format('j M Y') }}</span></td>
                            <td>@if ($proposal->cv_path)<a href="{{ route('examiner-admin.proposal.cv', $proposal) }}" data-region-swap-ignore>Download CV</a>@else None @endif</td>
                            <td>
                                <form method="POST" action="{{ route('examiner-admin.proposal.confirm', $proposal) }}" style="display: inline;">
                                    @csrf
                                    <button type="submit" @disabled(! $proposal->examiner->emailNeedsUpdating()) title="{{ $proposal->examiner->emailNeedsUpdating() ? '' : 'The examiner already has a working email' }}">Confirm</button>
                                </form>
                                <form method="POST" action="{{ route('examiner-admin.proposal.dismiss', $proposal) }}" style="display: inline;">
                                    @csrf
                                    <button type="submit" class="btn-secondary">Dismiss</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @include('hani::examiner_admin._tabs', ['route' => 'examiner-admin.index'])

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

        @include('hani::examiner_admin._filters', ['route' => 'examiner-admin.index', 'emailFilter' => true])

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
                    @if ($canManage)<th></th>@endif
                </tr>
            </thead>
            <tbody>
                @forelse ($examiners as $i => $examiner)
                    @php($students = $studentsByExaminer[$examiner->id] ?? [])
                    <tr>
                        <td>{{ $examiners->firstItem() + $i }}</td>
                        <td>
                            <div class="examiner-name-cell">
                                @include('hani::examiner_admin._avatar')
                                <span>
                                    @if (isset($openable[$examiner->id]))
                                        <a href="{{ route('examiner-admin.show', $examiner) }}" data-region-swap-ignore>{{ $examiner->name }}</a>
                                    @else
                                        {{ $examiner->name }}
                                    @endif
                                    {{-- CGS's pool file has no email column; nothing that
                                         writes to an examiner will reach this one yet. --}}
                                    @if ($examiner->emailInvalid())
                                        <span class="examiner-state-badge tone-critical">Email needs updating</span>
                                    @elseif ($examiner->needsEmail())
                                        <span class="examiner-state-badge tone-warn">Email needed</span>
                                    @elseif ($examiner->emailStale())
                                        <span class="examiner-state-badge tone-info">Email not confirmed</span>
                                    @endif
                                    {{-- Support\DuplicateDetector: flagged, never blocked. --}}
                                    @foreach ($duplicates[$examiner->id] ?? [] as $dup)
                                        <span class="examiner-duplicate">
                                            Possible duplicate of {{ $dup['other']->name }} ({{ $dup['score'] }}% similar)
                                            @if ($isSeniorExec)
                                                <form method="POST" action="{{ route('examiner-admin.dismiss-duplicate') }}">
                                                    @csrf
                                                    <input type="hidden" name="examiner_id" value="{{ $examiner->id }}">
                                                    <input type="hidden" name="other_id" value="{{ $dup['other']->id }}">
                                                    <button type="submit" class="btn-link">Not the same person</button>
                                                </form>
                                            @endif
                                        </span>
                                    @endforeach
                                </span>
                            </div>
                        </td>
                        @if (! $type)
                            <td>@include('hani::examiner_admin._type_badge')</td>
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
                            @include('hani::examiner_admin._state_badge')
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
                        @if ($canManage)
                        <td class="exam-actions">
                            @if ($isSeniorExec)
                                <a href="{{ route('examiner-admin.edit', $examiner) }}" class="btn-secondary" data-region-swap-ignore>Edit</a>
                            @else
                                <a href="{{ route('examiner-admin.email.edit', $examiner) }}" class="btn-secondary" data-region-swap-ignore>Update email</a>
                            @endif
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
                        @endif
                    </tr>
                @empty
                    {{-- 6 shared, + the action column for CGS, + List on the
                         combined list, + Faculty on every list but external,
                         + external's own eight. --}}
                    <tr><td colspan="{{ 6 + ($canManage ? 1 : 0) + (! $type ? 1 : 0) + (! $isExternal ? 1 : 0) + ($isExternal ? 8 : 0) }}">
                        <div class="empty-state">No examiners found. Try adjusting your filters.</div>
                    </td></tr>
                @endforelse
            </tbody>
        </table>

        @include('hani::examiner_admin._pagination')
    </div>
</div>

@if ($canManage)
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
@endif

@include('core::partials.region-swap')

{{-- Bound here with addEventListener, not with handler attributes. script-src is
     'self' plus this request's nonce with no unsafe-inline, and a nonce cannot
     cover an inline handler attribute -- the browser refuses to run it. As
     written before, the two filters did not auto-submit and the modal never
     opened.

     Tabs, the stat cards' "View list", the filter form and pagination are all
     plain GET links/forms to this same route -- they work with JS off. With it
     on, RegionSwap (public/js/region-swap.js) fetches and swaps in the six
     regions below instead, so switching tabs no longer reloads the sidebar,
     the stylesheet and the rest of the document just to change a table. --}}
<script @cspNonce>
    (function () {
        var modal = document.getElementById('unavailable-modal');
        var unavailableForm = document.getElementById('unavailable-form');

        // Outside every swapped region, so bound once and never touched again.
        document.querySelectorAll('[data-close-modal]').forEach(function (button) {
            button.addEventListener('click', function () { modal.close(); });
        });

        // Links in these regions that leave the pool (an examiner's page,
        // Edit, Update email) carry data-region-swap-ignore, so they are
        // real navigations; without it RegionSwap fetches the page, finds
        // none of these regions in it, and only changes the address bar.
        RegionSwap({
            selectors: [
                '.page-header p',
                '.exam-tabs',
                '.exam-tab-note',
                '.examiner-stats',
                '.examiner-filters',
                '.exam-table-wrap',
            ],
            // "Mark Unavailable" opens the modal above -- a button that only
            // exists inside a region RegionSwap just replaced, so it has to be
            // rebound after every swap, not just once at page load.
            afterSwap: function () {
                document.querySelectorAll('[data-unavailable]').forEach(function (button) {
                    button.addEventListener('click', function () {
                        unavailableForm.action = '/examiners/' + button.dataset.unavailable + '/toggle-active';
                        document.getElementById('unavailable-modal-name').textContent =
                            'Examiner: ' + button.dataset.examinerName;
                        document.getElementById('unavailable_reason').value = '';
                        modal.showModal();
                    });
                });
            },
        });
    })();
</script>
@endsection
