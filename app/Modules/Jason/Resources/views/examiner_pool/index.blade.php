@extends('core::layouts.app')

@section('title', 'Examiner List')

@section('content')
<style>
    /* Scoped to this page. The list and the add form are two cards now
       rather than one: `core::partials.form-stepper` re-casts the whole
       .card it finds the form in -- heading into a band at the top, rail
       into a left column -- so with the table still in that card you got
       "Examiner List / Step 1 of 3" over the full table with the wizard
       appended underneath it. The stepper only ever sees the second card.

       880px on both, because that is what .card.is-wizard settles at; the
       list would otherwise sit 200px narrower than the form below it. */
    .pool-card { max-width: 880px; }
    .pool-list-wrap {
        max-height: 340px; overflow-y: auto;
        border: 1px solid var(--border-subtle); border-radius: 8px;
    }
    .pool-list-wrap thead th {
        position: sticky; top: 0; z-index: 1;
        background: var(--surface);
    }
    .pool-list-wrap table { margin: 0; }
    .pool-list-wrap tr.is-removed { color: var(--text-grey); }
    .pool-list-wrap td button { padding: 4px 10px; font-size: var(--text-xs); margin: 0; }
    .pool-return { display: block; margin-top: 16px; font-size: var(--text-sm); }
</style>

<div class="card-container-inline">
    <div class="card card-wide pool-card">
        <h2>Examiner List</h2>
        <div class="card-divider"></div>

        <p class="queue-meta">
            The examiners a Chair can put on a panel. Add one below and they appear on the
            nomination form. Removing one keeps past nominations intact, because each
            nomination holds its own copy of the details.
        </p>

        @if ($examiners->isEmpty())
            <div class="empty-state">No examiners yet. Add the first one below.</div>
        @else
            <p class="queue-meta">
                {{ $examiners->where('is_active', true)->count() }} on the list
                ({{ $examiners->where('examiner_type', 'internal')->where('is_active', true)->count() }} internal,
                {{ $examiners->where('examiner_type', 'external')->where('is_active', true)->count() }} external).
            </p>

            {{-- The list only grows, so it scrolls inside a fixed box rather
                 than pushing everything below it off the page. --}}
            <div class="pool-list-wrap">
                <table class="recent-activity-table">
                    <thead>
                        <tr><th>Name</th><th>Type</th><th>Institution</th><th>Expertise</th><th>Email</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($examiners as $examiner)
                            <tr @unless ($examiner->is_active) class="is-removed" @endunless>
                                <td>{{ $examiner->name }}@unless ($examiner->is_active) <small>(removed)</small>@endunless</td>
                                <td>{{ $examiner->isInternal() ? 'Internal' : 'External' }}</td>
                                <td>{{ $examiner->institution }}</td>
                                <td>{{ $examiner->expertise }}</td>
                                <td>{{ $examiner->email }}</td>
                                <td>
                                    <form method="POST" action="{{ route('appointment-letter.examiners.toggle', $examiner) }}">
                                        @csrf
                                        <button type="submit">{{ $examiner->is_active ? 'Remove' : 'Reinstate' }}</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Set when the Chair arrived from the nomination form. Adding an
             examiner sends them straight back to it; this is for the case
             where they came to look rather than to add, and the panel they
             had already picked is still waiting to be restored. --}}
        @if ($returnToNomination)
            <a href="{{ route('appointment-letter.create') }}" class="pool-return">
                &larr; Back to the nomination without adding anyone
            </a>
        @endif
    </div>
</div>

<div class="card-container-inline">
    <div class="card card-wide pool-card">
        <h2>Add an Examiner</h2>
        <div class="card-divider"></div>

        {{-- data-stepper-review because the generated review's default line
             says the form goes to the first approver. This one files nothing
             -- it adds a row to a list the Chair and CGS keep themselves. --}}
        <form method="POST" action="{{ route('appointment-letter.examiners.store') }}" data-stepper
              data-stepper-review="Check the details, then add them to the list. Nothing here is submitted for approval — the examiner simply becomes available to pick on a panel.">
            @csrf
            @if ($returnToNomination)
                <input type="hidden" name="return" value="nominate">
            @endif

            <fieldset class="fstep" data-label="Who they are">
            <p class="fstep-hint">How the examiner is named on the appointment letter.</p>

            <label for="examiner_type">Type</label>
            <select name="examiner_type" id="examiner_type" required
                    class="@error('examiner_type') is-invalid @enderror">
                <option value="internal" @selected(old('examiner_type') === 'internal')>Internal Examiner (UTP)</option>
                <option value="external" @selected(old('examiner_type', 'external') === 'external')>External Examiner</option>
            </select>
            @error('examiner_type') <p class="field-error">{{ $message }}</p> @enderror

            <label for="name">Name</label>
            <input type="text" name="name" id="name" required value="{{ old('name') }}"
                   placeholder="e.g. Professor Ir Dr Mohamed Alias Yusof"
                   class="@error('name') is-invalid @enderror">
            @error('name') <p class="field-error">{{ $message }}</p> @enderror

            <label for="institution">Institution / Faculty</label>
            <input type="text" name="institution" id="institution" required value="{{ old('institution') }}"
                   class="@error('institution') is-invalid @enderror">
            @error('institution') <p class="field-error">{{ $message }}</p> @enderror

            <label for="expertise">Area of Expertise</label>
            <input type="text" name="expertise" id="expertise" required value="{{ old('expertise') }}"
                   class="@error('expertise') is-invalid @enderror">
            @error('expertise') <p class="field-error">{{ $message }}</p> @enderror
            </fieldset>

            <fieldset class="fstep" data-label="Where the pack goes">
            <p class="fstep-hint">
                On approval the Dean's office posts the appointment letter and emails
                the pack. Both come from here.
            </p>

            <label for="email">Email</label>
            <input type="email" name="email" id="email" required value="{{ old('email') }}"
                   class="@error('email') is-invalid @enderror">
            @error('email') <p class="field-error">{{ $message }}</p> @enderror

            <label for="address">Postal Address <span style="color: var(--text-grey);">(optional)</span></label>
            <textarea name="address" id="address" rows="4"
                      class="@error('address') is-invalid @enderror">{{ old('address') }}</textarea>
            <p class="field-hint">
                Printed under the examiner's name on the appointment letter, one line each.
                CGS can still adjust it when preparing the letter.
            </p>
            @error('address') <p class="field-error">{{ $message }}</p> @enderror
            </fieldset>

            <button type="submit">Add Examiner</button>
        </form>

        @include('core::partials.form-stepper')
    </div>
</div>
@endsection
