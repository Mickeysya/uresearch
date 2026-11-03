@extends('core::layouts.app')

@section('title', $returned ? 'Resubmit Hardbound Thesis' : 'Hardbound Submission')

@section('content')
@push('head')
    <style>
        /* Two lists of submissions. Both are rows, not runs of text: a
           reference, a title, a state and one action, in the same place on
           every row so the eye can run down a column. */
        .sub-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; }

        /* A candidate who has filed a dozen theses should not push the form
           itself off the page. Past about half a dozen rows the list scrolls
           in its own box; a shorter list is untouched, since a max-height
           only bites once the content passes it. The reserved gutter keeps
           the action column in the same place whether it scrolls or not. */
        .sub-list {
            max-height: min(21rem, 45vh);
            overflow-y: auto;
            overscroll-behavior: contain;
            scrollbar-gutter: stable;
        }

        .sub-list > li { border-top: 1px solid var(--border-subtle); }
        .sub-list > li:first-child { border-top: 0; }

        .sub-row {
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            gap: var(--space-2) var(--space-4);
            padding: var(--space-3) 0;
        }

        .sub-ref {
            font-variant-numeric: tabular-nums;
            font-weight: var(--weight-semi);
            flex: 0 0 auto;
        }

        /* Takes the slack, and wraps rather than pushing the row wider. */
        .sub-title { flex: 1 1 14rem; min-width: 0; }

        .sub-state { flex: 0 0 auto; }
        .sub-action { flex: 0 0 auto; margin-left: auto; white-space: nowrap; }

        /* The reviewer's own words, set apart from the candidate's title. */
        .sub-remark {
            flex: 1 1 100%;
            min-width: 0;
            margin: 0;
            padding-left: var(--space-3);
            border-left: 2px solid var(--border-subtle);
            color: var(--text-grey);
            font-size: var(--text-sm);
        }

        .sub-remark .who { color: var(--text-dark); font-weight: var(--weight-semi); }
    </style>
@endpush

<div class="card-container-inline">
    <x-core::page-header
        title="{{ $returned ? 'Resubmit Hardbound Thesis' : 'Hardbound Thesis Submission' }}" />

    <div class="card card-wide">
        @if ($awaiting->isNotEmpty())
            <div class="app-item" style="margin-bottom: 18px; border-left: 4px solid #A86A12;">
                <p style="margin-top: 0;"><b>{{ $awaiting->count() === 1 ? 'A submission was' : $awaiting->count().' submissions were' }} returned to you for correction</b></p>

                <ul class="sub-list">
                    @foreach ($awaiting as $app)
                        @php($last = $app->history->last())
                        <li>
                            <div class="sub-row">
                                <span class="sub-ref">#{{ $app->id }}</span>
                                <span class="sub-title">{{ $awaitingDetails[$app->id]->thesis_title ?? 'Hardbound submission' }}</span>
                                <span class="sub-action">
                                    <a href="{{ route('hardbound.resubmit.form', $app) }}"><b>Resubmit &rarr;</b></a>
                                </span>

                                @if ($last?->remarks)
                                    <p class="sub-remark">
                                        <span class="who">{{ $last->stage_label }}:</span>
                                        &ldquo;{{ $last->remarks }}&rdquo;
                                    </p>
                                @elseif ($last)
                                    <p class="sub-remark">
                                        <span class="who">{{ $last->stage_label }}</span> gave no comments.
                                    </p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>

                <p class="field-hint">
                    Correct what was asked and resubmit. The form below starts a brand-new
                    submission instead.
                </p>
            </div>
        @endif

        @if ($returned)
            <p class="queue-meta">
                Replacing submission #{{ $returned->id }}. Fix what was asked and say what
                you changed. The reviewer sees your response next to their own comments.
            </p>

            @php($lastRemark = $returned->history->last())
            @if ($lastRemark?->remarks)
                <div class="app-item" style="margin-bottom: 18px;">
                    <p><b>CGS comments</b>: {{ $lastRemark->stage_label }},
                        {{ $lastRemark->created_at?->format('j M Y') }}</p>
                    <p>{{ $lastRemark->remarks }}</p>
                </div>
            @endif
        @endif

        @if (($filed ?? collect())->isNotEmpty())
            <div class="app-item" style="margin-bottom: 18px;">
                <p style="margin-top: 0;"><b>Submissions you have filed</b>
                    <span class="field-hint" style="font-weight: normal;">({{ $filed->count() }} in all)</span>
                </p>

                <ul class="sub-list">
                    @foreach ($filed as $app)
                        {{-- Every rejection in this chain is a return, so that is what
                             the badge says: "rejected" reads as a dead end the candidate
                             does not actually have.

                             Assigned inline, one per line, rather than in a raw PHP
                             block. Blade lifts raw blocks out of the template before it
                             compiles anything else, pairing each opener with the next
                             closer non-greedily -- and this file already opens one
                             inline further up. A block here would pair with that opener
                             and swallow everything between, leaving the loops and
                             conditionals inside it on the page as literal text. --}}
                        @php($returnedHere = $app->status === \App\Modules\Core\Models\Application::STATUS_REJECTED)
                        @php($tone = $returnedHere ? 'returned' : $app->status)
                        @php($label = $returnedHere ? 'Returned' : ucfirst($app->status))
                        <li>
                            <div class="sub-row">
                                <span class="sub-ref">#{{ $app->id }}</span>
                                <span class="sub-title">{{ $filedDetails[$app->id]->thesis_title ?? 'Hardbound submission' }}</span>
                                <span class="sub-state">
                                    <span class="status-badge {{ $tone }}">{{ $label }}</span>
                                </span>
                                <span class="sub-action">
                                    <a href="{{ route('hardbound.forms', $app) }}">Forms &rarr;</a>
                                </span>
                            </div>
                        </li>
                    @endforeach
                </ul>

                <p class="field-hint">
                    Both CGS forms are written from what you entered and signed with the signature
                    on your account, so there is nothing to download to check them.
                </p>
            </div>
        @endif

        <form method="POST"
              action="{{ $returned ? route('hardbound.resubmit', $returned) : route('hardbound.store') }}"
              class="app-form" data-stepper>
            @csrf

            <fieldset class="fstep" data-label="About you">
            <p class="fstep-hint">
                These fill the Hardbound Thesis Submission form (UTP/CGS/021), which the
                portal writes for you. Nothing to download, print or scan: your name,
                matric number and programme come from your student record, and these are
                the boxes the form asks you for.
            </p>

            <label for="country">Country</label>
            <input type="text" name="country" id="country" maxlength="6"
                   placeholder="e.g. MYS"
                   value="{{ old('country', $detail?->country) }}"
                   class="@error('country') is-invalid @enderror">
            @error('country') <p class="field-error">{{ $message }}</p> @enderror
            <p class="field-hint">
                Six boxes on UTP/CGS/021, so a short code rather than the full name.
            </p>

            <label for="intake">Intake</label>
            <input type="text" name="intake" id="intake" maxlength="6"
                   placeholder="e.g. SEP23"
                   value="{{ old('intake', $detail?->intake) }}"
                   class="@error('intake') is-invalid @enderror">
            @error('intake') <p class="field-error">{{ $message }}</p> @enderror
            <p class="field-hint">Six boxes on the form, like the country.</p>

            <label for="passport_no">Passport / IC Number</label>
            <input type="text" name="passport_no" id="passport_no" maxlength="40"
                   value="{{ old('passport_no', $detail?->passport_no) }}"
                   class="@error('passport_no') is-invalid @enderror">
            @error('passport_no') <p class="field-error">{{ $message }}</p> @enderror
            <p class="field-hint">Printed on your own copy of UTP/CGS/021 and nowhere else.</p>

            <label for="date_of_birth">Date of Birth</label>
            <input type="date" name="date_of_birth" id="date_of_birth" max="{{ now()->toDateString() }}"
                   value="{{ old('date_of_birth', $detail?->date_of_birth?->toDateString()) }}"
                   class="@error('date_of_birth') is-invalid @enderror">
            @error('date_of_birth') <p class="field-error">{{ $message }}</p> @enderror

            <p class="field-hint">
                The second CGS form, the Confirmation of Correction to Thesis
                (UTP/CGS/017A), is written for you too. Your supervisor and the viva
                chairman sign that one electronically as they approve.
            </p>
            </fieldset>

            <fieldset class="fstep" data-label="Thesis &amp; candidate">
            <p class="fstep-hint">What goes on both CGS forms. Your matric number
               comes from your student record and cannot be edited here.</p>

            <label for="thesis_title">Title of Thesis <span style="color: var(--text-grey);">(not more than 15 words)</span></label>
            <textarea name="thesis_title" id="thesis_title" rows="3" required
                      class="@error('thesis_title') is-invalid @enderror">{{ old('thesis_title', $detail?->thesis_title) }}</textarea>
            @error('thesis_title') <p class="field-error">{{ $message }}</p> @enderror

            <label for="matric_no_display">Matric Number</label>
            <input type="text" id="matric_no_display" value="{{ $student->matric_no }}" disabled>
            <p class="field-hint">Taken from your student record.</p>

            <label for="programme">Programme</label>
            <input type="text" name="programme" id="programme" required
                   value="{{ old('programme', $detail?->programme ?? $student->programme) }}"
                   class="@error('programme') is-invalid @enderror">
            @error('programme') <p class="field-error">{{ $message }}</p> @enderror

            <label for="supervisor_name">Supervisor</label>
            <input type="text" name="supervisor_name" id="supervisor_name" required
                   value="{{ old('supervisor_name', $detail?->supervisor_name ?? $student->supervisor?->name) }}"
                   class="@error('supervisor_name') is-invalid @enderror">
            @error('supervisor_name') <p class="field-error">{{ $message }}</p> @enderror

            @if ($returned)
                <label for="response_to_comments">Your Response to the CGS Comments</label>
                <textarea name="response_to_comments" id="response_to_comments" rows="4" required
                          class="@error('response_to_comments') is-invalid @enderror">{{ old('response_to_comments') }}</textarea>
                @error('response_to_comments') <p class="field-error">{{ $message }}</p> @enderror
            @endif
            </fieldset>

            <fieldset class="fstep" data-label="Your examiners">
            <p class="fstep-hint">Who examined you at your viva voce. They are asked to
               countersign the Confirmation of Correction once your supervisor has
               confirmed the corrections, and before it reaches the viva chairman.</p>

            @foreach (['internal' => 'Internal Examiner', 'external' => 'External Examiner'] as $slot => $label)
                @php($list = $examiners[$slot] ?? collect())

                <label for="{{ $slot }}_examiner_id">{{ $label }}</label>

                @if ($list->isEmpty())
                    <p class="field-hint">
                        No {{ $slot }} examiners are on record yet, so this one cannot be named.
                    </p>
                @else
                    <input type="search" class="examiner-filter" data-for="{{ $slot }}_examiner_id"
                           autocomplete="off" placeholder="Search {{ $slot }} examiners by name or institution"
                           aria-label="Search {{ $label }}">

                    <select name="{{ $slot }}_examiner_id" id="{{ $slot }}_examiner_id"
                            class="examiner-select @error($slot.'_examiner_id') is-invalid @enderror">
                        <option value="">Not naming an {{ $slot }} examiner</option>
                        @foreach ($list as $examiner)
                            <option value="{{ $examiner->id }}"
                                    @selected(old($slot.'_examiner_id', $detail?->{$slot.'_examiner_id'}) == $examiner->id)>
                                {{ $examiner->name }}@if ($examiner->institution || $examiner->department), {{ $examiner->institution ?: $examiner->department }}@endif
                            </option>
                        @endforeach
                    </select>
                    <p class="examiner-count field-hint" data-for="{{ $slot }}_examiner_id"></p>
                @endif

                @error($slot.'_examiner_id') <p class="field-error">{{ $message }}</p> @enderror
            @endforeach

            <p class="field-hint">
                Name both where you can. The Confirmation has one examiner signature block, so
                whichever of them signs first releases the submission to the chairman.
            </p>
            </fieldset>

            <fieldset class="fstep" data-label="Confirmation of Correction">
            <p class="fstep-hint">Fills UTP/CGS/017A. Your supervisor and the viva
               chairman sign this one electronically as they approve, so you do not
               upload it.</p>

            <label for="viva_date">Viva Date</label>
            <input type="date" name="viva_date" id="viva_date" required max="{{ now()->toDateString() }}"
                   value="{{ old('viva_date', $detail?->viva_date?->toDateString()) }}"
                   class="@error('viva_date') is-invalid @enderror">
            @error('viva_date') <p class="field-error">{{ $message }}</p> @enderror

            <label for="co_supervisor_name">Co-Supervisor <span style="color: var(--text-grey);">(optional)</span></label>
            <input type="text" name="co_supervisor_name" id="co_supervisor_name"
                   value="{{ old('co_supervisor_name', $detail?->co_supervisor_name) }}"
                   class="@error('co_supervisor_name') is-invalid @enderror">
            @error('co_supervisor_name') <p class="field-error">{{ $message }}</p> @enderror

            </fieldset>

            <button type="submit">{{ $returned ? 'Resubmit for Confirmation' : 'Submit for Confirmation' }}</button>
        </form>
    </div>
</div>

@include('core::partials.form-stepper')
@endsection

@push('scripts')
{{-- @cspNonce is not optional: script-src is 'self' plus this request's
     nonce, with no unsafe-inline, so without it the browser refuses to run
     this and the search boxes silently do nothing. --}}
<script @cspNonce>
    (function () {
        // The whole list is in the markup; this only hides what does not
        // match, so nothing depends on a round trip and the posted value is
        // still a plain examiner id.
        document.querySelectorAll('.examiner-filter').forEach(function (box) {
            var select = document.getElementById(box.dataset.for);
            var count = document.querySelector('.examiner-count[data-for="' + box.dataset.for + '"]');
            if (! select) return;

            function apply() {
                var needle = box.value.trim().toLowerCase();
                var shown = 0;

                select.querySelectorAll('option').forEach(function (option) {
                    if (option.value === '') return;
                    var match = needle === '' || option.textContent.toLowerCase().includes(needle);
                    option.hidden = ! match;
                    option.disabled = ! match;
                    if (match) shown++;
                });

                // A name filtered out from under a choice must not stay chosen.
                var chosen = select.selectedOptions[0];
                if (chosen && chosen.value !== '' && chosen.hidden) select.value = '';

                if (! count) return;
                count.textContent = needle === ''
                    ? shown + ' on record.'
                    : (shown === 0
                        ? 'No examiner matches \u201c' + needle + '\u201d.'
                        : shown + ' match \u201c' + needle + '\u201d.');
            }

            box.addEventListener('input', apply);
            apply();
        });
    })();
</script>
@endpush
