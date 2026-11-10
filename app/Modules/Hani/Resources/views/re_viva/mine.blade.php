@extends('core::layouts.app')

@section('title', 'Re-viva')

@section('content')
{{-- The student's view. Outcome levels and deadlines only: no examiner
     names, no individual recommendations, no staff-only files. --}}
<div class="card-container-inline">
    <x-core::page-header
        title="Re-viva"
        subtitle="Download the forms, submit your documents and follow each round." />

    <div class="card card-wide">
        <h3>Forms</h3>
        <div class="table-scroll"><table class="data-table"><tbody>
            @foreach ($forms as $key => $label)
                <tr><td>{{ $label }}</td>
                    <td><a href="{{ route('reviva.forms', [$key, 'blank']) }}">Blank (pre-filled for me)</a></td>
                    <td><a href="{{ route('reviva.forms', [$key, 'example']) }}">Example (filled)</a></td></tr>
            @endforeach
        </tbody></table></div>
    </div>

    @if ($expecting)
        <div class="card card-wide">
            <h3>Submit your documents</h3>
            <p class="fstep-hint">Due by {{ $expecting->closes?->format('j M Y') }}. PDF only, up to {{ config('hani.reviva.upload_max_mb') }} MB each. Status of Thesis must be the first page of the thesis.</p>
            <form method="POST" action="{{ route('reviva.submit') }}" enctype="multipart/form-data">
                @csrf
                @foreach ($fields as $field => $type)
                    <label for="{{ $field }}">{{ $type }}</label>
                    <input type="file" name="{{ $field }}" id="{{ $field }}" accept="application/pdf,.pdf" required>
                    @error($field) <p class="field-error">{{ $message }}</p> @enderror
                @endforeach
                <button type="submit">Submit</button>
            </form>
        </div>
    @endif

    @forelse ($rounds as $round)
        <div class="card card-wide">
            <h3>Re-viva round {{ $round->cycle_number }}</h3>
            @include('hani::partials.module_stepper', ['tracker' => \App\Modules\Hani\Support\ModuleTracker::reviva($round), 'hideTitle' => true])

            @if ($round->application->status === \App\Modules\Core\Models\Application::STATUS_RETURNED && ($return = $round->documentReturns->first()))
                <p class="message-error">Returned on {{ $return->created_at->format('j M Y') }}: {{ implode(', ', $return->documents) }}. {{ $return->reason }}</p>
                <form method="POST" action="{{ route('reviva.resubmit', $round) }}" enctype="multipart/form-data">
                    @csrf
                    @foreach (array_intersect($fields, $return->documents) as $field => $type)
                        <label for="re-{{ $field }}">{{ $type }}</label>
                        <input type="file" name="{{ $field }}" id="re-{{ $field }}" accept="application/pdf,.pdf" required>
                        @error($field) <p class="field-error">{{ $message }}</p> @enderror
                    @endforeach
                    <button type="submit">Resubmit</button>
                </form>
            @endif

            <p>Submitted: {{ $round->resubmission_at->format('j M Y') }}
                @if ($round->windowLabel()) <span class="fstep-hint">(resubmission window {{ $round->windowLabel() }})</span> @endif
            </p>

            @if ($round->hasOutcome())
                <p><b>Outcome: {{ $round->outcomeLabel() }}</b></p>

                @if ($round->needsFinalThesis())
                    @if ($round->corrections_accepted_at)
                        <p class="message-success">Your final thesis was accepted on {{ $round->corrections_accepted_at->format('j M Y') }}. Your re-viva is complete.</p>
                    @else
                        <p>Final thesis due: <b>{{ $round->correction_deadline?->format('j M Y') }}</b>
                            @if ($round->finalThesisOverdue()) <span class="status-badge rejected">Overdue</span> @endif
                        </p>
                        @if ($round->final_thesis_submitted_at)
                            <p class="fstep-hint">Submitted {{ $round->final_thesis_submitted_at->format('j M Y') }}. Waiting for CGS to accept it. You can upload a newer version below.</p>
                        @endif
                        <form method="POST" action="{{ route('reviva.final-thesis.store', $round) }}" enctype="multipart/form-data">
                            @csrf
                            <label for="final_thesis-{{ $round->id }}">Final thesis (PDF)</label>
                            <input type="file" name="final_thesis" id="final_thesis-{{ $round->id }}" accept="application/pdf,.pdf" required>
                            @error('final_thesis') <p class="field-error">{{ $message }}</p> @enderror
                            <button type="submit">Upload Final Thesis</button>
                        </form>
                    @endif
                @elseif ($round->requiresLoopBack())
                    <p>An oral examination is required. Submit your documents again above by {{ \App\Modules\Hani\Support\ReVivaOutcome::nextRoundCloses($round)->format('j M Y') }}.</p>
                @else
                    <p>This is the final outcome. There is no further action.</p>
                @endif
            @else
                <p class="fstep-hint">You will be told the result here and by email.</p>
            @endif

            @if ($round->application->documents->isNotEmpty())
                <ul class="doc-list">
                    @foreach ($round->application->documents as $doc)
                        <li><a href="{{ route('documents.show', $doc) }}">{{ $doc->doc_type }}: {{ $doc->original_name }}</a></li>
                    @endforeach
                </ul>
            @endif
        </div>
    @empty
        @unless ($expecting)
            <div class="card card-wide"><div class="empty-state">You have no re-viva rounds.</div></div>
        @endunless
    @endforelse
</div>
@endsection
