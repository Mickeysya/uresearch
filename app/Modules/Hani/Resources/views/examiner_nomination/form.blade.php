@extends('core::layouts.app')

@section('title', 'Nominate Examiners')

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Examiner Nomination</h2>
        <div class="card-divider"></div>

        @if ($candidates->isEmpty())
            <div class="empty-state">
                You have no candidates assigned to you yet.<br>
                CGS assigns supervisees before nominations can be filed.
            </div>
        @else
            <form method="POST" action="{{ route('examiner-nomination.store') }}">
                @csrf

                <label for="student_id">Candidate</label>
                <select name="student_id" id="student_id" required
                        class="@error('student_id') is-invalid @enderror">
                    <option value="">— Select your candidate —</option>
                    @foreach ($candidates as $candidate)
                        <option value="{{ $candidate->id }}" @selected(old('student_id') == $candidate->id)>
                            {{ $candidate->name }} @if ($candidate->matric_no)({{ $candidate->matric_no }})@endif
                        </option>
                    @endforeach
                </select>
                @error('student_id') <p class="field-error">{{ $message }}</p> @enderror

                <label for="thesis_title">Thesis Title</label>
                <input type="text" name="thesis_title" id="thesis_title" required
                       value="{{ old('thesis_title') }}"
                       class="@error('thesis_title') is-invalid @enderror">
                @error('thesis_title') <p class="field-error">{{ $message }}</p> @enderror

                <label for="main_examiner_id">Main Examiner</label>
                <select name="main_examiner_id" id="main_examiner_id" required
                        class="@error('main_examiner_id') is-invalid @enderror">
                    <option value="">— Select —</option>
                    @foreach ($examiners as $examiner)
                        <option value="{{ $examiner->id }}"
                                @selected(old('main_examiner_id') == $examiner->id)
                                @disabled(! $examiner->isEligible())>
                            {{ $examiner->name }} — {{ $examiner->department }}
                            ({{ ucfirst($examiner->type) }})
                            @unless ($examiner->isEligible())
                                — {{ str_replace('_', ' ', $examiner->state()) }}
                            @endunless
                        </option>
                    @endforeach
                </select>
                @error('main_examiner_id') <p class="field-error">{{ $message }}</p> @enderror

                <label for="backup_examiner_id">Backup Examiner <span style="color: var(--text-grey)">(optional)</span></label>
                <select name="backup_examiner_id" id="backup_examiner_id"
                        class="@error('backup_examiner_id') is-invalid @enderror">
                    <option value="">— None —</option>
                    @foreach ($examiners as $examiner)
                        <option value="{{ $examiner->id }}"
                                @selected(old('backup_examiner_id') == $examiner->id)
                                @disabled(! $examiner->isEligible())>
                            {{ $examiner->name }} — {{ $examiner->department }}
                            ({{ ucfirst($examiner->type) }})
                            @unless ($examiner->isEligible())
                                — {{ str_replace('_', ' ', $examiner->state()) }}
                            @endunless
                        </option>
                    @endforeach
                </select>
                @error('backup_examiner_id') <p class="field-error">{{ $message }}</p> @enderror

                <p class="queue-meta">
                    Examiners who are assigned, unavailable, or still inside the
                    {{ \App\Modules\Hani\Models\Examiner::GAP_DAYS }}-day cooling-off period cannot be selected.
                    Eligibility is re-checked when you submit.
                </p>

                <label for="notes">Notes <span style="color: var(--text-grey)">(optional)</span></label>
                <textarea name="notes" id="notes" rows="3">{{ old('notes') }}</textarea>

                <button type="submit">Submit Nomination</button>
            </form>
        @endif
    </div>
</div>

<h3 style="color: var(--navy); font-size: 15px;">Examiner pool</h3>
<table class="recent-activity-table">
    <thead>
        <tr><th>Name</th><th>Department</th><th>Type</th><th>State</th><th>Available from</th></tr>
    </thead>
    <tbody>
        @foreach ($examiners as $examiner)
            <tr>
                <td>{{ $examiner->name }}</td>
                <td>{{ $examiner->department }}</td>
                <td>{{ ucfirst($examiner->type) }}</td>
                <td>{{ ucwords(str_replace('_', ' ', $examiner->state())) }}</td>
                <td>
                    @if ($examiner->state() === \App\Modules\Hani\Models\Examiner::STATE_ON_GAP)
                        {{ $examiner->gapEndsOn()->format('j M Y') }}
                    @elseif ($examiner->state() === \App\Modules\Hani\Models\Examiner::STATE_ASSIGNED)
                        {{ $examiner->assigned_until->format('j M Y') }}
                    @else
                        —
                    @endif
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
@endsection
