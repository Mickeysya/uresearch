@extends('core::layouts.app')

@section('title', 'Examiner List')

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Examiner List</h2>
        <div class="card-divider"></div>

        <p class="queue-meta">
            The examiners a Chair can put on a panel. Add a new examiner here first; they
            then appear on the nomination form. Removing one keeps past nominations intact —
            each nomination holds its own copy of the details.
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
                 than pushing the add-examiner form off the bottom of the page. --}}
            <div style="max-height: 340px; overflow-y: auto; border: 1px solid var(--border, #ddd); margin-bottom: 24px;">
            <table class="recent-activity-table" style="margin: 0;">
                <thead style="position: sticky; top: 0; background: #fff; z-index: 1;">
                    <tr><th>Name</th><th>Type</th><th>Institution</th><th>Expertise</th><th>Email</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach ($examiners as $examiner)
                        <tr @unless ($examiner->is_active) style="color: var(--text-grey);" @endunless>
                            <td>{{ $examiner->name }}@unless ($examiner->is_active) <small>(removed)</small>@endunless</td>
                            <td>{{ $examiner->isInternal() ? 'Internal' : 'External' }}</td>
                            <td>{{ $examiner->institution }}</td>
                            <td>{{ $examiner->expertise }}</td>
                            <td>{{ $examiner->email }}</td>
                            <td>
                                <form method="POST" action="{{ route('appointment-letter.examiners.toggle', $examiner) }}">
                                    @csrf
                                    <button type="submit" style="padding: 4px 10px; font-size: 12px;">
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

        <h3>Add an Examiner</h3>

        <form method="POST" action="{{ route('appointment-letter.examiners.store') }}">
            @csrf

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

            <label for="address">Postal Address <span style="color: var(--text-grey);">(optional)</span></label>
            <textarea name="address" id="address" rows="4"
                      class="@error('address') is-invalid @enderror">{{ old('address') }}</textarea>
            <p class="queue-meta" style="margin-top: -8px;">
                Printed under the examiner's name on the appointment letter, one line each.
                CGS can still adjust it when preparing the letter.
            </p>
            @error('address') <p class="field-error">{{ $message }}</p> @enderror

            <label for="email">Email</label>
            <input type="email" name="email" id="email" required value="{{ old('email') }}"
                   class="@error('email') is-invalid @enderror">
            @error('email') <p class="field-error">{{ $message }}</p> @enderror

            <label for="expertise">Area of Expertise</label>
            <input type="text" name="expertise" id="expertise" required value="{{ old('expertise') }}"
                   class="@error('expertise') is-invalid @enderror">
            @error('expertise') <p class="field-error">{{ $message }}</p> @enderror

            <button type="submit">Add Examiner</button>
        </form>
    </div>
</div>
@endsection
