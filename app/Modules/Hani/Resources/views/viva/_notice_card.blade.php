{{--
    One Notice of Thesis Submission, as UCampus shows it. Expects
    $application, $detail (VivaDetail), $record (VivaEligibility or null),
    $verifying (true: the AE marks each item and decides).
--}}
@php
    $student = $application->student;
    $items = $student ? \App\Modules\Hani\Support\VivaChecklist::itemsFor($student) : [];
    $results = $record?->systemResults() ?? [];
    $marks = old('items', $detail?->ae_item_checks ?? []);
    $programme = (string) $student?->programme;
    $mode = str_contains(strtolower($programme), 'part') ? 'Part Time' : (str_contains(strtolower($programme), 'full') ? 'Full Time' : '—');
    $waiting = (int) ($application->submitted_at?->diffInDays() ?? 0);
    $verifying = $verifying ?? false;
@endphp

<details class="queue-row" @if ($loop->first ?? false) open @endif>
    <summary class="queue-row-summary">
        <span class="queue-row-id">#{{ $application->id }}</span>
        <span class="queue-row-who">
            {{ $student?->name }}
            @if ($student?->matric_no) <span class="queue-row-matric">{{ $student->matric_no }}</span> @endif
        </span>
        <span class="queue-row-summary-text">{{ $detail?->thesis_title }}</span>
        <span class="queue-row-age tone-{{ $waiting >= 30 ? 'critical' : ($waiting >= 14 ? 'warn' : 'quiet') }}">{{ $waiting === 0 ? 'today' : $waiting.'d' }}</span>
    </summary>

    <div class="queue-row-body notice-card">
        <h3>Student Details</h3>
        <dl class="notice-dl">
            <dt>Name</dt><dd>{{ $student?->name }}</dd>
            <dt>Student number</dt><dd>{{ $student?->matric_no ?: '—' }}</dd>
            {{-- UResearch holds no registration date yet. --}}
            <dt>Date of registration</dt><dd>—</dd>
            <dt>Email</dt><dd>{{ $student?->email }}</dd>
            <dt>Department</dt><dd>{{ $student?->department ?: '—' }}</dd>
            <dt>Supervisor</dt><dd>{{ $student?->supervisor?->name ?: '—' }}</dd>
            <dt>Co-supervisor</dt><dd>{{ $detail?->co_supervisor_name ?: '—' }}</dd>
            <dt>Programme</dt><dd>{{ $detail?->programme_level === \App\Modules\Hani\Support\VivaChecklist::PHD ? 'PhD' : 'MSc by Research' }}{{ $programme ? ' ('.$programme.')' : '' }}</dd>
            <dt>Programme mode</dt><dd>{{ $mode }}</dd>
            <dt>Thesis title</dt><dd>{{ $detail?->thesis_title ?: '—' }}</dd>
        </dl>

        <h3>Request Summary</h3>
        <dl class="notice-dl">
            <dt>Notice thesis submission date</dt><dd>{{ $detail?->expected_softbound_date?->format('j M Y') ?? '—' }} <span class="fstep-hint">(expected softbound date)</span></dd>
            <dt>Submitted</dt><dd>{{ $application->submitted_at?->format('j M Y, g:i a') ?? '—' }}</dd>
        </dl>
        <p><b>Submission abstract</b></p>
        <p class="notice-abstract">{{ $detail?->abstract ?: '—' }}</p>

        <h3>Documents</h3>
        @if ($application->documents->isEmpty())
            <p class="fstep-hint">No documents attached.</p>
        @else
            <table class="table notice-docs">
                <thead><tr><th>Type</th><th>File</th><th>Size</th><th>Uploaded</th></tr></thead>
                <tbody>
                    @foreach ($application->documents as $doc)
                        <tr>
                            <td>{{ $doc->doc_type }}</td>
                            <td><x-core::doc-link :document="$doc">{{ $doc->original_name }}</x-core::doc-link></td>
                            <td>{{ $doc->size_bytes ? \Illuminate\Support\Number::fileSize($doc->size_bytes, 1) : '—' }}</td>
                            <td>{{ $doc->created_at?->format('j M Y') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @includeIf('hani::viva._notice_checks', ['application' => $application])

        @if ($verifying)
            <form method="POST" action="{{ route('viva.decide', $application) }}" class="notice-verify">
                @csrf
        @endif

        <h3>Eligibility</h3>
        @if ($items === [])
            <p class="fstep-hint">This programme has no viva checklist.</p>
        @else
            <table class="table notice-eligibility">
                <thead><tr><th>Item</th><th>Student / system</th>@if ($verifying)<th>Academic Executive</th>@endif</tr></thead>
                <tbody>
                    @foreach ($items as $column => $item)
                        @php($check = $results[$column] ?? \App\Modules\Hani\Support\Eligibility\EligibilityResult::selfDeclared())
                        <tr>
                            <td>{{ $item['label'] }}<br><span class="fstep-hint">{{ $item['hint'] }}</span></td>
                            <td>
                                @include('hani::viva._eligibility_badge', ['check' => $check])
                                @if ($check->message && $check->status !== 'self_declared') <br><span class="fstep-hint">{{ $check->message }}</span> @endif
                            </td>
                            @if ($verifying)
                                <td>
                                    <label><input type="radio" name="items[{{ $column }}]" value="verified" @checked(($marks[$column] ?? null) === 'verified')> Verified</label>
                                    <label><input type="radio" name="items[{{ $column }}]" value="not_met" @checked(($marks[$column] ?? null) === 'not_met')> Not met</label>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @if ($record?->verified_at)
                <p class="fstep-hint">Verified by {{ $record->verifiedBy?->name }} on {{ $record->verified_at->format('j M Y') }}.</p>
            @endif
        @endif

        @if ($verifying)
                @error('items') <p class="field-error">{{ $message }}</p> @enderror
                <h3>Academic Executive</h3>
                <fieldset>
                    <legend>Verification</legend>
                    <label><input type="radio" name="verification" value="verified" required @checked(old('verification') === 'verified')> Verified</label>
                    <label><input type="radio" name="verification" value="rejected" @checked(old('verification') === 'rejected')> Rejected</label>
                </fieldset>
                @error('verification') <p class="field-error">{{ $message }}</p> @enderror
                <label for="comment-{{ $application->id }}">Comment <span class="fstep-hint">(required to reject; emailed to the student)</span></label>
                <textarea id="comment-{{ $application->id }}" name="comment" rows="3" maxlength="2000">{{ old('comment') }}</textarea>
                @error('comment') <p class="field-error">{{ $message }}</p> @enderror
                <p class="fstep-hint">Verified needs every eligibility item marked Verified. The supervisor is then asked to nominate examiners.</p>
                <button type="submit">Submit verification</button>
            </form>
        @endif
    </div>
</details>

@once
    @push('head')
        <style @cspNonce>
            .notice-card h3 { margin: var(--space-5) 0 var(--space-2); font-size: var(--text-base); }
            .notice-dl { display: grid; grid-template-columns: minmax(10rem, 14rem) 1fr; gap: var(--space-1) var(--space-4); margin: 0; }
            .notice-dl dt { color: var(--text-grey); }
            .notice-dl dd { margin: 0; overflow-wrap: anywhere; }
            .notice-abstract { white-space: pre-line; }
            .notice-eligibility label, .notice-verify fieldset label { display: inline-flex; gap: var(--space-1); margin-right: var(--space-3); }
            @media (max-width: 640px) { .notice-dl { grid-template-columns: 1fr; } }
        </style>
    @endpush
@endonce
