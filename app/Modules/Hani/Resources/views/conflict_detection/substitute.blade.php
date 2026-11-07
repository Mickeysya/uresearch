@extends('core::layouts.app')

@section('title', 'Examiner Substitution')

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        :title="'Replace an examiner: '.$student->name"
        :subtitle="'Application #'.$nomination->application_id.' · '.$nomination->thesis_title" />

    <div class="card card-wide" style="max-width: none;">
        <p>
            <b>{{ $substitution->seatLabel() }}:</b> {{ $substitution->examiner?->name ?? 'Empty' }}<br>
            <b>Asked by:</b> {{ $substitution->requestedBy?->name ?? 'CGS' }}, {{ $substitution->created_at?->format('j M Y') }}<br>
            <b>Reason:</b> {{ $substitution->reason }}
        </p>

        @if ($substitution->status === \App\Modules\Hani\Models\ExaminerSubstitutionRequest::DONE)
            <p class="message-success">
                Replaced with {{ $substitution->replacement?->name }} by {{ $substitution->resolvedBy?->name ?? 'the department' }}
                on {{ $substitution->resolved_at?->format('j M Y') }}.
            </p>
        @elseif ($substitution->isReplaceable())
            <h3>Choose a replacement</h3>

            @if ($suggested)
                <p style="color: var(--text-grey); font-size: var(--text-sm);">Suggested from the eligible pool, best fit first:</p>
                <ol>
                    @foreach ($suggested as $s)
                        <li>
                            <b>{{ $s['name'] }}</b> ({{ $s['where'] }}), {{ $s['score'] }}% match
                            @if ($s['matched']) · matched on: {{ implode(', ', $s['matched']) }} @endif
                            <span style="display: block; color: var(--text-grey); font-size: var(--text-xs);">{{ implode(' · ', $s['reasons']) }}</span>
                            @include('hani::examiner_nomination._coi', ['flags' => $s['flags']])
                        </li>
                    @endforeach
                </ol>
            @endif

            <form method="POST" action="{{ route('examiner-substitution.replace', $substitution) }}" class="app-form">
                @csrf
                <label for="examiner-id">Appoint</label>
                <select name="examiner_id" id="examiner-id" required class="@error('examiner_id') is-invalid @enderror">
                    <option value="">Select an eligible examiner</option>
                    @foreach ($eligible as $e)
                        <option value="{{ $e->id }}" @selected(old('examiner_id') == $e->id)>{{ $e->name }}, {{ $e->isExternal() ? $e->institution : $e->department }}</option>
                    @endforeach
                </select>
                @error('examiner_id') <p class="field-error">{{ $message }}</p> @enderror
                <button type="submit">Replace the examiner</button>
            </form>
        @else
            <p class="message-warning">
                This seat cannot be changed here any more: the request has been closed, or the list has moved on
                ({{ $nomination->application->currentStage()?->label ?? ucfirst($nomination->application->status) }}).
                Please contact CGS.
            </p>
        @endif
    </div>
</div>
@endsection
