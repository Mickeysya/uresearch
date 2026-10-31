@extends('core::layouts.app')

@section('title', 'Workstation Management')

@php
    use App\Modules\Chloe\Models\Workstation;
    use App\Modules\Chloe\Models\WorkstationLocation;

    $blockCodes = WorkstationLocation::query()->select('block')->distinct()->orderBy('block')->pluck('block');
    $allLocations = $locations;

    // Every filter link below reuses this: toggle the one dimension it
    // controls, keep every other filter (and the search text) exactly as
    // it was, and always drop back to page 1 -- a filter change invalidates
    // whatever page number the old result set was on.
    $toggle = function (string $key, string $value) use ($filters) {
        $query = $filters;
        $query[$key] = ($filters[$key] === $value) ? '' : $value;

        return route('workstation.cgs.operations', array_filter($query, fn ($v) => $v !== ''));
    };
@endphp

@section('content')
    @include('chloe::workstation._assistant-widget')

{{-- Scoped to this page -- nothing here touches a Core/shared stylesheet.
     The block grid below is the same fixed 4-column layout the student
     Home page uses (see workstation/select.blade.php's own note on why
     auto-fit/flex-grow are wrong here); .cgs-seat-card is the same
     178px-tall row card the student Room page uses, widened in practice
     to min-height rather than a hard height because this card also carries
     the occupant's name/ID and key badges, which the student's never did. --}}
<style>
    .cgs-top-card { display: flex; flex-direction: column; gap: 16px; }

    .cgs-search-row { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; }
    .cgs-search-wrap { position: relative; flex: 1 1 260px; min-width: 220px; }
    .cgs-search-wrap input[type="search"] { width: 100%; margin: 0; }

    .cgs-suggest-list {
        display: none;
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        z-index: 20;
        margin-top: 4px;
        background: white;
        border: 1px solid var(--border-grey);
        border-radius: 8px;
        max-height: 320px;
        overflow-y: auto;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
    }
    .cgs-suggest-item { padding: 8px 12px; cursor: pointer; }
    .cgs-suggest-item:hover, .cgs-suggest-item.is-active { background: var(--light-blue-bg, #eef3fc); }
    .cgs-suggest-text { font-size: 14px; }
    .cgs-suggest-text b { font-weight: 700; }
    .cgs-suggest-label { font-size: 12px; color: var(--text-grey); margin-top: 2px; }

    .cgs-filters-row { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
    .cgs-filters-row .field { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
    .cgs-filters-row label { margin: 0; }
    .cgs-filters-row select { margin: 0; min-width: 160px; }
    .cgs-filters-row button, .cgs-filters-row a.clear-link { margin: 0; }
    .cgs-filters-row a.clear-link { align-self: center; font-size: 13px; color: var(--text-grey); }

    .cgs-quick-row { display: flex; gap: 8px; flex-wrap: wrap; }
    .cgs-quick-row a button { margin: 0; }
    .cgs-quick-row a.is-active button { background-color: var(--navy-dark); box-shadow: inset 0 0 0 2px var(--gold); }

    .cgs-stats-row { display: flex; gap: 14px; flex-wrap: wrap; }
    .cgs-stats-row a { text-decoration: none; color: inherit; flex: 1; min-width: 130px; }
    .cgs-stats-row .stat-card { cursor: pointer; width: 100%; }
    .cgs-stats-row a.is-active .stat-card { box-shadow: inset 0 0 0 2px var(--navy); }

    .cgs-export-row { display: flex; gap: 10px; justify-content: flex-end; }
    .cgs-export-row button { margin: 0; }
    .cgs-export-row .btn-secondary { background: white; color: var(--navy); border: 1px solid var(--navy); }

    .ws-block-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        justify-items: stretch;
        gap: 24px;
        width: 100%;
    }
    @media (max-width: 1099px) { .ws-block-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
    @media (max-width: 799px) { .ws-block-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 519px) { .ws-block-grid { grid-template-columns: repeat(1, minmax(0, 1fr)); } }

    .ws-block-card { width: 100%; max-width: none; margin: 0; padding: 0; overflow: hidden; text-decoration: none; color: inherit; display: flex; flex-direction: column; }
    .ws-block-image { width: 100%; aspect-ratio: 4 / 3; object-fit: cover; display: block; background: var(--surface-sunken, #eef1f6); }
    .ws-block-body { height: 120px; padding: 18px; text-align: center; display: flex; align-items: center; justify-content: center; overflow: hidden; }

    .cgs-seat-list { display: flex; flex-direction: column; gap: 16px; margin-top: 12px; }

    .cgs-seat-card {
        display: flex;
        align-items: center;
        justify-content: space-between;
        min-height: 178px;
        border-radius: 20px;
        background: var(--surface-sunken, #F8F9FB);
        padding: 14px 20px;
        gap: 16px;
    }
    .cgs-seat-image { width: 204px; height: 153px; object-fit: cover; border-radius: 8px; flex-shrink: 0; background: #fff; }
    .cgs-seat-text { flex: 1; min-width: 0; }
    .cgs-seat-title { font-weight: 700; font-size: 17px; }
    .cgs-seat-sub { color: var(--text-grey); font-size: 13px; margin-top: 2px; }
    .cgs-seat-badges { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }
    .cgs-seat-sub-label { margin-top: 8px; font-size: 12px; color: var(--text-grey); text-transform: uppercase; letter-spacing: .03em; font-weight: 600; }
    .cgs-seat-lines { margin-top: 4px; min-width: 0; max-width: 360px; }
    .cgs-seat-line { font-size: 14px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .cgs-seat-line:nth-child(2), .cgs-seat-line:nth-child(3) { font-size: 13px; font-weight: 400; color: var(--text-grey); margin-top: 2px; }
    .cgs-seat-button { flex-shrink: 0; }
    .cgs-seat-button button { width: 132px; height: 50px; margin: 0; padding: 0; }

    .cgs-pagination { display: flex; gap: 6px; margin-top: 16px; flex-wrap: wrap; }
    .cgs-pagination a, .cgs-pagination span { padding: 6px 11px; border-radius: 8px; font-size: 13px; border: 1px solid var(--border-grey); text-decoration: none; color: var(--navy); }
    .cgs-pagination .is-current { background: var(--navy); color: white; border-color: var(--navy); }
    .cgs-pagination .is-disabled { color: var(--text-grey); }
</style>

<div class="card-container-inline">
    {{-- This is the module's CGS landing page, so browse mode gets no Back
         link -- there is nothing above it. Results mode (a search/filter
         active) is one step down from that, so it gets one: back to the
         unfiltered landing page, clearing the search and every filter. --}}
    <x-core::page-header title="Workstation Management" :back="$mode === 'results' ? route('workstation.cgs.operations') : null">
        @if ($mode === 'results')
            <a href="{{ route('workstation.cgs.operations') }}"><button type="button">Home</button></a>
        @endif
        <a href="{{ route('workstation.cgs.insights') }}"><button type="button" class="btn-secondary">Insights</button></a>
        <a href="{{ route('workstation.cgs.catalog') }}"><button type="button">Manage the seat catalogue</button></a>
    </x-core::page-header>

    <div class="card card-wide cgs-top-card">
        <form method="GET" action="{{ route('workstation.cgs.operations') }}">
            <div class="cgs-search-row">
                <div class="cgs-search-wrap">
                    <input type="search" name="q" id="cgs-search-input" value="{{ $filters['q'] }}" placeholder="Search" autocomplete="off">
                    <div class="cgs-suggest-list" id="cgs-suggest-list" role="listbox"></div>
                </div>
                <button type="submit">Search</button>
            </div>

            <div class="cgs-filters-row" style="margin-top: 12px;">
                <div class="field">
                    <label for="filter-block">Block</label>
                    <select name="block" id="filter-block">
                        <option value="">All Blocks</option>
                        @foreach ($blockCodes as $code)
                            <option value="{{ $code }}" @selected($filters['block'] === $code)>Block {{ $code }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label for="filter-room">Room</label>
                    <select name="location_id" id="filter-room">
                        <option value="">All Rooms</option>
                        @foreach ($allLocations as $location)
                            <option value="{{ $location->id }}" @selected($filters['location_id'] == $location->id)>Block {{ $location->block }} &middot; {{ $location->room_code }} ({{ $location->name }})</option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label for="filter-seat-status">Seat Status</label>
                    <select name="seat_status" id="filter-seat-status">
                        <option value="">Any Status</option>
                        <option value="available" @selected($filters['seat_status'] === 'available')>Available</option>
                        <option value="occupied" @selected($filters['seat_status'] === 'occupied')>Occupied</option>
                        <option value="pending" @selected($filters['seat_status'] === 'pending')>Pending</option>
                        <option value="disabled" @selected($filters['seat_status'] === 'disabled')>Maintenance</option>
                        <option value="awaiting_key_return" @selected($filters['seat_status'] === 'awaiting_key_return')>Awaiting Key Return</option>
                        {{-- PART 3 #4: occupied seats with an active termination notice -- not a Workstation::STATUS_* value, the seat stays Occupied. No new stats card. --}}
                        <option value="termination_notice" @selected($filters['seat_status'] === 'termination_notice')>Termination Notice</option>
                    </select>
                </div>

                <div class="field">
                    <label for="filter-gender">Gender</label>
                    <select name="gender" id="filter-gender">
                        <option value="">Any Gender</option>
                        <option value="male" @selected($filters['gender'] === 'male')>Male</option>
                        <option value="female" @selected($filters['gender'] === 'female')>Female</option>
                    </select>
                </div>

                <div class="field">
                    <label for="filter-key-status">Locker Key Status</label>
                    <select name="key_status" id="filter-key-status">
                        <option value="">Any Key Status</option>
                        <option value="not_requested" @selected($filters['key_status'] === 'not_requested')>Not Requested</option>
                        <option value="requested" @selected($filters['key_status'] === 'requested')>Pending Collection</option>
                        <option value="collected" @selected($filters['key_status'] === 'collected')>Collected</option>
                        <option value="return_pending" @selected($filters['key_status'] === 'return_pending')>Return Pending</option>
                        <option value="overdue" @selected($filters['key_status'] === 'overdue')>Overdue</option>
                    </select>
                </div>

                <button type="submit">Apply Filters</button>

                @if ($mode === 'results')
                    <a href="{{ route('workstation.cgs.operations') }}" class="clear-link">Clear</a>
                @endif
            </div>
        </form>

        <div class="cgs-quick-row">
            <a href="{{ $toggle('key_status', 'requested') }}" class="@if($filters['key_status'] === 'requested') is-active @endif">
                <button type="button">Key Pending Collection</button>
            </a>
            <a href="{{ $toggle('key_status', 'return_pending') }}" class="@if($filters['key_status'] === 'return_pending') is-active @endif">
                <button type="button">Key Pending Return</button>
            </a>
        </div>

        <div class="cgs-stats-row">
            <a href="{{ route('workstation.cgs.operations') }}">
                <div class="stat-card accent-blue"><div class="stat-number" data-live-stat="total">{{ $stats['total'] }}</div><div class="stat-label">Total</div></div>
            </a>
            <a href="{{ $toggle('seat_status', 'available') }}" class="@if($filters['seat_status'] === 'available') is-active @endif">
                <div class="stat-card accent-green"><div class="stat-number" data-live-stat="available">{{ $stats['available'] }}</div><div class="stat-label">Available</div></div>
            </a>
            <a href="{{ $toggle('seat_status', 'occupied') }}" class="@if($filters['seat_status'] === 'occupied') is-active @endif">
                <div class="stat-card accent-blue"><div class="stat-number" data-live-stat="occupied">{{ $stats['occupied'] }}</div><div class="stat-label">Occupied</div></div>
            </a>
            <a href="{{ $toggle('seat_status', 'disabled') }}" class="@if($filters['seat_status'] === 'disabled') is-active @endif">
                <div class="stat-card accent-red"><div class="stat-number" data-live-stat="maintenance">{{ $stats['maintenance'] }}</div><div class="stat-label">Maintenance</div></div>
            </a>
            {{-- PART 1 #9: same seat set as the "Key Pending Return" quick
                 filter below -- every Return Pending key's seat is Awaiting
                 Key Return by construction, so filtering either way shows
                 the same seats. --}}
            <a href="{{ $toggle('seat_status', 'awaiting_key_return') }}" class="@if($filters['seat_status'] === 'awaiting_key_return') is-active @endif">
                <div class="stat-card accent-gold"><div class="stat-number" data-live-stat="awaiting_key_return">{{ $stats['awaiting_key_return'] }}</div><div class="stat-label">Awaiting Key Return</div></div>
            </a>
            <a href="{{ $toggle('key_status', 'requested') }}" class="@if($filters['key_status'] === 'requested') is-active @endif">
                <div class="stat-card accent-gold"><div class="stat-number" data-live-stat="key_pending_collection">{{ $stats['key_pending_collection'] }}</div><div class="stat-label">Key Pending Collection</div></div>
            </a>
            <a href="{{ $toggle('key_status', 'overdue') }}" class="@if($filters['key_status'] === 'overdue') is-active @endif">
                <div class="stat-card accent-red"><div class="stat-number" data-live-stat="key_return_overdue">{{ $stats['key_return_overdue'] }}</div><div class="stat-label">Key Return Overdue</div></div>
            </a>
        </div>

        <div class="cgs-export-row">
            <a href="{{ route('workstation.cgs.export.seats', array_filter($filters, fn ($v) => $v !== '')) }}"><button type="button" class="btn-secondary">Export Results</button></a>
            <a href="{{ route('workstation.cgs.export.history') }}"><button type="button" class="btn-secondary">Export History</button></a>
        </div>
    </div>
</div>

@include('chloe::workstation._live', ['liveKind' => 'cgs', 'liveStats' => true])

<div class="card-container-inline">
    <div class="card card-wide" @if ($mode === 'results') data-live-region @endif>
        @if ($mode === 'browse')
            <h3>Blocks</h3>
            <p style="color: var(--text-grey); font-size: 13px;">Click a block to see its rooms.</p>
            <div class="card-divider"></div>

            <div class="ws-block-grid">
                @foreach ($blocks as $block)
                    <a href="{{ route('workstation.cgs.block', $block->code) }}" class="card ws-block-card">
                        <img src="{{ $block->photoUrl() }}" alt="{{ $block->name }}" loading="lazy" class="ws-block-image">
                        <div class="ws-block-body"><b style="font-size:15px;">{{ $block->name }}</b></div>
                    </a>
                @endforeach
            </div>
        @else
            <h3>Results</h3>
            <p style="color: var(--text-grey); font-size: 13px;">{{ $seats->total() }} matching {{ \Illuminate\Support\Str::plural('seat', $seats->total()) }}.</p>
            <div class="card-divider"></div>

            @if ($seats->isEmpty())
                <div class="empty-state">No matching seats found.</div>
            @else
                <div class="cgs-seat-list">
                    @foreach ($seats as $row)
                        @include('chloe::workstation.cgs._seat-card', ['row' => $row])
                    @endforeach
                </div>

                @if ($seats->hasPages())
                    <div class="cgs-pagination">
                        @if ($seats->onFirstPage())
                            <span class="is-disabled">&laquo; Prev</span>
                        @else
                            <a href="{{ $seats->previousPageUrl() }}">&laquo; Prev</a>
                        @endif

                        @for ($i = 1; $i <= $seats->lastPage(); $i++)
                            @if ($i === $seats->currentPage())
                                <span class="is-current">{{ $i }}</span>
                            @else
                                <a href="{{ $seats->url($i) }}">{{ $i }}</a>
                            @endif
                        @endfor

                        @if ($seats->hasMorePages())
                            <a href="{{ $seats->nextPageUrl() }}">Next &raquo;</a>
                        @else
                            <span class="is-disabled">Next &raquo;</span>
                        @endif
                    </div>
                @endif
            @endif
        @endif
    </div>
</div>

<script @cspNonce>
    document.querySelectorAll('[data-browser-back]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            if (window.history.length > 1 && document.referrer) {
                event.preventDefault();
                window.history.back();
            }
        });
    });
</script>

{{-- PART 1: smart search suggestions. Clicking or Enter-on-highlighted
     only FILLS the box -- the real search still only runs on Search or a
     plain Enter with nothing highlighted, so this never surprises anyone
     by searching before they meant it to. Every piece of text from the
     endpoint is escaped before it is ever put into innerHTML -- none of
     it is trusted just because it came from our own endpoint. --}}
<script @cspNonce>
    (function () {
        var input = document.getElementById('cgs-search-input');
        var list = document.getElementById('cgs-suggest-list');
        if (! input || ! list) return;

        var suggestUrl = @json(route('workstation.cgs.suggestions'));
        var items = [];
        var activeIndex = -1;
        var debounceTimer = null;

        function escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function renderHighlighted(text, highlight) {
            if (! highlight) return escapeHtml(text);

            var start = highlight.start;
            var end = start + highlight.length;

            return escapeHtml(text.slice(0, start))
                + '<b>' + escapeHtml(text.slice(start, end)) + '</b>'
                + escapeHtml(text.slice(end));
        }

        function closeList() {
            list.style.display = 'none';
            list.innerHTML = '';
            items = [];
            activeIndex = -1;
        }

        function setActive(index) {
            var rows = list.querySelectorAll('.cgs-suggest-item');
            rows.forEach(function (row, i) { row.classList.toggle('is-active', i === index); });
            activeIndex = index;
        }

        function selectItem(index) {
            var item = items[index];
            if (! item) return;
            input.value = item.fill;
            closeList();
            input.focus();
        }

        function renderList(results) {
            items = results;
            activeIndex = -1;

            if (! results.length) {
                closeList();
                return;
            }

            list.innerHTML = '';

            results.forEach(function (item, index) {
                var row = document.createElement('div');
                row.className = 'cgs-suggest-item';
                row.setAttribute('role', 'option');

                var text = document.createElement('div');
                text.className = 'cgs-suggest-text';
                text.innerHTML = renderHighlighted(item.text, item.highlight);

                var label = document.createElement('div');
                label.className = 'cgs-suggest-label';
                label.textContent = item.label;

                row.appendChild(text);
                row.appendChild(label);
                row.addEventListener('click', function () { selectItem(index); });

                list.appendChild(row);
            });

            list.style.display = 'block';
        }

        input.addEventListener('input', function () {
            var term = input.value.trim();

            if (debounceTimer) clearTimeout(debounceTimer);

            if (term.length < 2) {
                closeList();
                return;
            }

            debounceTimer = setTimeout(function () {
                fetch(suggestUrl + '?q=' + encodeURIComponent(term), { headers: { Accept: 'application/json' } })
                    .then(function (response) { return response.ok ? response.json() : []; })
                    .then(renderList)
                    .catch(function () { closeList(); });
            }, 200);
        });

        input.addEventListener('keydown', function (event) {
            if (list.style.display !== 'block') return;

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                setActive(Math.min(activeIndex + 1, items.length - 1));
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                setActive(Math.max(activeIndex - 1, 0));
            } else if (event.key === 'Escape') {
                closeList();
            } else if (event.key === 'Enter' && activeIndex >= 0) {
                // Enter on a highlighted suggestion only fills the box;
                // the form's own submit is prevented so the real search
                // does not also fire on the same keystroke.
                event.preventDefault();
                selectItem(activeIndex);
            }
        });

        document.addEventListener('click', function (event) {
            if (event.target !== input && ! list.contains(event.target)) {
                closeList();
            }
        });
    })();
</script>
@endsection
