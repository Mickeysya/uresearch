{{--
    The shell every approval queue shares.

    A module supplies only the detail lines for its own records, by passing
    the name of a partial as $detailView. That partial receives $application.

    Expects: $stage, $module, $applications, $filters, $moduleLabel,
             $decideRoute, $detailView
    Optional: $intro -- a line of guidance about what deciding here means.
              Pass it in rather than writing it above the include, or it
              renders ABOVE the page title and the screen reads headless.

    WHAT CHANGED, AND WHY (2026-09-17)

    This used to render every pending application fully expanded, each with
    its own textarea and two buttons. That reads well with two rows and
    collapses completely with two thousand: the controller loaded all of
    them, the browser laid out a form for each, and there was no way to find
    one row, no way to tell which had been waiting longest, and no way to
    clear a backlog except one page reload per decision.

    So a queue is now a work list:

      - one page at a time, oldest first (ApprovesApplications::queueFor)
      - a row is one line; the decision form is behind a <details>
      - search by application number, student name or matric number
      - how long each row has waited, called out once it is overdue
      - tick several rows and decide them in one submission

    NESTED FORMS ARE ILLEGAL, so the bulk form is a sibling of the rows and
    the per-row checkboxes join it with form="queue-bulk". That is the HTML5
    form-owner attribute, not a trick: an input may sit anywhere in the
    document and belong to a form elsewhere. Each row keeps its own ordinary
    form for deciding that row alone.
--}}
@php
    $total = $applications->total();
    $overdueAfter = 14;   // days waiting before a row is called out
    $criticalAfter = 30;
@endphp

@php
    $subtitle = $total === 0
        ? 'Nothing is waiting on you right now.'
        : number_format($total).' '.Str::plural('application', $total).' awaiting your decision.'
            .($applications->hasPages()
                ? ' Showing '.number_format($applications->firstItem()).' to '.number_format($applications->lastItem()).'.'
                : '');
@endphp

<x-core::page-header :title="$moduleLabel.': '.$stage->queueTitle()" :subtitle="$subtitle" />

@isset ($intro)
    <p class="queue-intro">{!! $intro !!}</p>
@endisset

@if ($total > 0 || $filters['q'] !== '')
    {{-- Sort submits on change; search needs the button, because submitting
         on every keystroke would be a query per character. --}}
    <form method="GET" class="queue-tools">
        <input type="hidden" name="stage" value="{{ $stage->key }}">

        <div class="queue-tool-field">
            <label for="queue-q">Find an application</label>
            <input type="search" name="q" id="queue-q" value="{{ $filters['q'] }}"
                   placeholder="Application number, student name or matric number">
        </div>

        <div class="queue-tool-field">
            <label for="queue-sort">Order</label>
            <select name="sort" id="queue-sort" data-queue-autosubmit>
                <option value="oldest" @selected($filters['sort'] === 'oldest')>Longest waiting first</option>
                <option value="newest" @selected($filters['sort'] === 'newest')>Most recent first</option>
            </select>
        </div>

        <button type="submit" class="btn-secondary">Search</button>

        @if ($filters['q'] !== '')
            <a href="{{ route(Route::currentRouteName(), ['stage' => $stage->key]) }}" class="queue-clear">Clear</a>
        @endif
    </form>
@endif

@if ($applications->isEmpty())
    <div class="empty-state">
        @if ($filters['q'] !== '')
            <p>No application matches "{{ $filters['q'] }}".</p>
            <a href="{{ route(Route::currentRouteName(), ['stage' => $stage->key]) }}" class="btn-secondary">Clear the search</a>
        @else
            <p>Nothing is waiting on you right now.</p>
            <p class="queue-meta">Applications appear here the moment they reach your stage.</p>
        @endif
    </div>
@else
    {{-- The bulk form owns nothing visually until something is ticked. It is
         a sibling of the rows, never a parent: see the note at the top. --}}
    <form method="POST" action="{{ route('queue.decide-bulk', $module->key()) }}" id="queue-bulk">
        @csrf
    </form>

    <div class="queue-bulk" data-queue-bulkbar hidden>
        <label class="queue-bulk-count">
            <span data-queue-count>0</span> selected
        </label>

        <input type="text" name="remarks" form="queue-bulk" maxlength="2000"
               class="queue-bulk-remarks"
               placeholder="Remarks applied to all of them (optional)">

        <button type="submit" name="decision" value="approve" form="queue-bulk">Approve selected</button>
        <button type="submit" name="decision" value="reject" form="queue-bulk" class="btn-reject">Reject selected</button>
    </div>

    <div class="queue-list">
        <div class="queue-list-head">
            <label class="queue-check">
                <input type="checkbox" data-queue-all aria-label="Select every application on this page">
                <span>Select all on this page</span>
            </label>
        </div>

        @foreach ($applications as $application)
            @php
                // (int), because Carbon 3 returns a FLOAT here -- 41.000000002049
                // for a row submitted 41 days ago, which renders verbatim.
                $waiting = (int) ($application->submitted_at?->diffInDays() ?? 0);
                $tone = match (true) {
                    $waiting >= $criticalAfter => 'critical',
                    $waiting >= $overdueAfter => 'warn',
                    default => 'quiet',
                };
            @endphp

            <details class="queue-row">
                <summary class="queue-row-summary">
                    {{-- Outside the <summary>'s toggle behaviour would be
                         nicer, but a checkbox inside one works as long as the
                         click does not also toggle the row; the script below
                         stops that. --}}
                    <label class="queue-check" data-queue-check-wrap>
                        <input type="checkbox" name="ids[]" value="{{ $application->id }}"
                               form="queue-bulk" data-queue-check
                               aria-label="Select application #{{ $application->id }}">
                    </label>

                    <span class="queue-row-id">#{{ $application->id }}</span>

                    <span class="queue-row-who">
                        {{ $application->student->name }}
                        @if ($application->student->matric_no)
                            <span class="queue-row-matric">{{ $application->student->matric_no }}</span>
                        @endif
                    </span>

                    {{-- ponytail: one query per row for the summary line, so 20
                         per page. Bounded by the page size and fine at this
                         scale; give WorkflowModule a bulk summary if a queue
                         ever pages 100 at a time. --}}
                    <span class="queue-row-summary-text">{{ $module->summary($application) }}</span>

                    @if ($application->documents->isNotEmpty())
                        <span class="queue-row-docs" title="{{ $application->documents->count() }} attached">
                            {{ $application->documents->count() }} file{{ $application->documents->count() === 1 ? '' : 's' }}
                        </span>
                    @endif

                    <span class="queue-row-age tone-{{ $tone }}">
                        {{ $waiting === 0 ? 'today' : $waiting.'d' }}
                    </span>

                    <span class="queue-row-chevron" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                             stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                    </span>
                </summary>

                <div class="queue-row-body">
                    <p class="queue-row-submitted">
                        Submitted {{ $application->submitted_at?->diffForHumans() }}
                        @if ($application->submitted_at)
                            on {{ $application->submitted_at->format('j M Y') }}
                        @endif
                    </p>

                    @include($detailView, ['application' => $application])

                    @if ($application->documents->isNotEmpty())
                        <ul class="doc-list">
                            @foreach ($application->documents as $doc)
                                <li><a href="{{ $doc->url() }}">{{ $doc->doc_type }}: {{ $doc->original_name }}</a></li>
                            @endforeach
                        </ul>
                    @endif

                    <x-core::decision-form :application="$application" :route="route($decideRoute, $application)" />
                </div>
            </details>
        @endforeach
    </div>

    @include('core::partials.pagination', ['paginator' => $applications, 'label' => 'Queue pages'])
@endif

<script @cspNonce>
    (function () {
        var boxes = Array.prototype.slice.call(document.querySelectorAll('[data-queue-check]'));
        if (! boxes.length) return;

        var all = document.querySelector('[data-queue-all]');
        var bar = document.querySelector('[data-queue-bulkbar]');
        var count = document.querySelector('[data-queue-count]');

        function refresh() {
            var picked = boxes.filter(function (b) { return b.checked; }).length;

            count.textContent = picked;
            bar.hidden = picked === 0;

            if (all) {
                all.checked = picked === boxes.length;
                all.indeterminate = picked > 0 && picked < boxes.length;
            }
        }

        boxes.forEach(function (box) {
            box.addEventListener('change', refresh);
        });

        // A <summary> toggles the row on any click inside it, including on the
        // checkbox. Ticking a row should not also open it.
        document.querySelectorAll('[data-queue-check-wrap]').forEach(function (wrap) {
            wrap.addEventListener('click', function (event) { event.stopPropagation(); });
        });

        if (all) {
            all.addEventListener('change', function () {
                boxes.forEach(function (box) { box.checked = all.checked; });
                refresh();
            });
        }

        document.querySelectorAll('[data-queue-autosubmit]').forEach(function (field) {
            field.addEventListener('change', function () { field.form.submit(); });
        });

        refresh();
    })();
</script>
