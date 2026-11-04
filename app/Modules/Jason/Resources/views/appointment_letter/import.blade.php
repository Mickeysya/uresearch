@extends('core::layouts.app')

@section('title', 'Import Examiner List')

@section('content')
@push('head')
    <style>
        /* A column heading is one thing: "Matric No" broken across two lines
           reads as two headings that do not exist. */
        .sheet-note code { white-space: nowrap; }

        .sheet-columns { display: flex; flex-wrap: wrap; gap: 6px; margin: 0; padding: 0; list-style: none; }

        /* Rows, not runs of text: a reference, a name and one action in
           the same place on every line. */
        .sub-list { list-style: none; margin: 0; padding: 0; }
        .sub-list > li { border-top: 1px solid var(--border-subtle); }
        .sub-list > li:first-child { border-top: 0; }
        .sub-row { display: flex; flex-wrap: wrap; align-items: center;
                   gap: var(--space-2) var(--space-4); padding: var(--space-3) 0; }
        .sub-ref { font-variant-numeric: tabular-nums; font-weight: var(--weight-semi); flex: 0 0 auto; }
        .sub-title { flex: 1 1 14rem; min-width: 0; }
        .sub-action { flex: 0 0 auto; margin-left: auto; }

        .sheet-columns li {
            white-space: nowrap;
            font-family: var(--font-mono, monospace);
            font-size: var(--text-sm);
            background: var(--surface-sunken, #f1f3f6);
            border-radius: var(--radius-sm, 4px);
            padding: 2px 7px;
        }
    </style>
@endpush

<div class="card-container-inline">
    {{-- Everything explanatory lives OUT here, between the page header and
         the card. `core::partials.form-stepper` re-casts the card it finds
         the form in -- `.card.is-wizard` is `padding: 0; overflow: hidden`
         -- so a paragraph left inside it as a sibling of the form loses its
         padding, runs the full width into the header and is clipped. --}}
    <x-core::page-header
        title="Import Examiner List"
        subtitle="The finalised list, once the Dean has settled it. Each candidate on the sheet becomes an appointment waiting for its pack." />

    {{-- The direct hand-off. Until the nomination chain's Core contract
         exists this whole section is the one notice, and the spreadsheet
         below is the only way in. --}}
    @if ($handoffLive)
        <div class="card card-wide" style="margin-bottom: 18px;">
            <p style="margin-top: 0;"><b>Ready for appointment letters</b>
                <span class="field-hint" style="font-weight: normal;">({{ count($released) }} released)</span>
            </p>

            @forelse ($released as $panel)
                <div class="app-item">
                    <p style="margin-top: 0;">
                        <b>{{ $panel['student']['name'] ?? 'Candidate' }}</b>
                        <span class="field-hint">{{ $panel['student']['matric_id'] ?? '' }}</span>
                    </p>
                    <p class="field-hint">{{ $panel['thesis_title'] ?: 'No thesis title recorded' }}</p>
                    <ul class="sheet-columns">
                        @foreach ($panel['examiners'] as $seat)
                            <li>{{ $seat['examiner_name'] }} ({{ $seat['examiner_type'] }})</li>
                        @endforeach
                    </ul>
                </div>
            @empty
                <p class="field-hint">No panels have been released yet.</p>
            @endforelse
        </div>
    @else
        <div class="message-warning">
            The direct hand-off from examiner nomination is not switched on yet, so the
            finalised list still arrives as a spreadsheet. Upload it below.
        </div>
    @endif

    {{-- A main examiner who has no letter: the panel moved on after the
         pack went out, which is what a decline looks like from here. --}}
    @if (! empty($lettersNeeded))
        <div class="card card-wide" style="margin-bottom: 18px;">
            <p style="margin-top: 0;"><b>Letter needed</b>
                <span class="field-hint" style="font-weight: normal;">({{ count($lettersNeeded) }})</span>
            </p>

            <p class="field-hint">
                These examiners are in a main seat on a panel whose pack has already gone out,
                and have no letter of their own. It usually means a main declined and the
                backup was promoted. Preparing one opens its own appointment, which the Dean
                signs as usual. Examiners who already have a letter are never listed here, so
                nothing can be sent twice.
            </p>

            <ul class="sub-list">
                @foreach ($lettersNeeded as $seat)
                    <li>
                        <div class="sub-row">
                            <span class="sub-ref">#{{ $seat['nomination_application_id'] }}</span>
                            <span class="sub-title">
                                <b>{{ $seat['examiner_name'] }}</b>
                                <span class="field-hint">{{ $seat['examiner_type'] }} &middot;
                                    {{ $seat['student']['name'] ?? 'candidate unknown' }}</span>
                            </span>
                            <span class="sub-action">
                                <form method="POST" action="{{ route('appointment-letter.promoted') }}">
                                    @csrf
                                    <input type="hidden" name="nomination_application_id"
                                           value="{{ $seat['nomination_application_id'] }}">
                                    <input type="hidden" name="hani_examiner_id"
                                           value="{{ $seat['hani_examiner_id'] }}">
                                    <button type="submit" style="padding: 4px 10px; font-size: 12px;">
                                        Prepare letter
                                    </button>
                                </form>
                            </span>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="sheet-note">
        <p class="queue-meta">
            Examiner selection happens before this screen: the supervisor chooses the panel,
            the Academic Executive compiles it, and the list is agreed between CGS, the Senior
            Director, the Chair and the Dean. What you upload here is that finished list.
        </p>

        <p class="queue-meta">
            <b>One row per candidate</b>, with the panel in four named slots: internal main and
            backup, external main and backup. A row is tied to a candidate by its
            <code>Matric No</code>, and every panel needs at least one internal and one external
            examiner. All four named examiners are appointed, and all four receive a pack.
        </p>

        <p class="queue-meta">
            Columns are read <b>by name, in any order</b>, and anything unrecognised is ignored,
            so an export with extra or reordered columns still imports. Each examiner's email and
            institution are read from the examiner records the nomination chain keeps, so the
            sheet only has to name them.
        </p>
    </div>

    @unless ($poolAvailable)
        <div class="message-warning">
            The examiner records are not on this database, so an import will only succeed for
            examiners the sheet fully describes.
        </div>
    @endunless

    <div class="card card-wide">
        <form method="POST" action="{{ route('appointment-letter.import.store') }}"
              enctype="multipart/form-data" class="app-form" data-stepper>
            @csrf

            <fieldset class="fstep" data-label="Get the template">
            <p class="fstep-hint">Only needed if you do not already have the list as a
               file. It comes filled in with two worked examples.</p>

            {{-- In a block of its own: .btn-secondary is inline-flex, so a
                 bare anchor sits on a text line and its padding overflows
                 into whatever follows. Same shape as the hardbound form's
                 download step. --}}
            <p>
                <a href="{{ route('appointment-letter.template') }}" class="btn-secondary">
                    Download the template (.xlsx)
                </a>
            </p>

            <p class="queue-meta">
                Or <a href="{{ route('appointment-letter.template', ['format' => 'csv']) }}">download it as .csv</a>.
                The headings it is written with, which a real export may extend or reorder:
            </p>

            <ul class="sheet-columns">
                @foreach ($columns as $column)
                    <li>{{ $column }}</li>
                @endforeach
            </ul>
            </fieldset>

            <fieldset class="fstep" data-label="Upload the list">
            <p class="fstep-hint">Nothing is imported unless every row is good, so a
               file with one bad row leaves you with no half-finished candidates to
               chase.</p>

            <label for="sheet">Finalised Examiner List</label>
            <input type="file" name="sheet" id="sheet" required
                   accept=".csv,.txt,.xlsx,.xls"
                   class="@error('sheet') is-invalid @enderror">
            @error('sheet') <p class="field-error">{{ $message }}</p> @enderror
            <p class="field-hint">
                .xlsx or .csv, up to 5 MB. A candidate already imported gets a second
                appointment, so import each list once. A candidate listed twice on the same
                sheet is reported rather than imported twice.
            </p>
            </fieldset>

            <button type="submit">Import the list</button>
        </form>

        @include('core::partials.form-stepper')
    </div>
</div>
@endsection
