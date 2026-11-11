@extends('core::layouts.app')

@section('title', 'Re-viva Outcomes')

@section('content')
<div class="card-container-inline">
    <x-core::page-header title="Re-viva Outcomes" subtitle="Final theses to accept, and recorded results." />

    <div class="card card-wide">
        <h3>Recorded results ({{ $results->count() }})</h3>
        @forelse ($results as $r)
            <p><b>{{ $r->application->student->name }}</b>, round {{ $r->cycle_number }}: {{ $r->outcomeLabel() }} <span class="fstep-hint">{{ $r->outcome_recorded_at?->format('j M Y') }}</span></p>
        @empty
            <p class="fstep-hint">None.</p>
        @endforelse
    </div>

    <div class="card card-wide">
        <h3>Final thesis (levels 1 to 3) ({{ $finalTheses->count() }})</h3>
        <p class="fstep-hint">Accepting the corrections generates the Confirmation of Correction, pre-filled for examiners 1 and 2 and the chairman to sign.</p>
        @error('corrections') <p class="field-error">{{ $message }}</p> @enderror
        @if ($finalTheses->isEmpty())
            <p class="fstep-hint">None.</p>
        @else
            <div class="table-scroll">
                <table class="data-table">
                    <thead><tr><th>Student</th><th>Outcome</th><th>Final thesis</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($finalTheses as $d)
                            @php($final = $d->application->documents->where('doc_type', \App\Modules\Hani\Support\ReVivaCorrections::DOC_FINAL)->last())
                            <tr>
                                <td>{{ $d->application->student->name }}<br><span class="fstep-hint">Round {{ $d->cycle_number }}</span></td>
                                <td>{{ $d->outcomeLabel() }}<br>Due {{ $d->correction_deadline?->format('j M Y') }}
                                    @if ($d->finalThesisOverdue()) <span class="status-badge rejected">Overdue</span> @endif</td>
                                <td>
                                    @if ($final)
                                        <a href="{{ route('documents.show', $final) }}">{{ $final->original_name }}</a><br>
                                        <span class="fstep-hint">Submitted {{ $d->final_thesis_submitted_at?->format('j M Y') }}</span>
                                    @else
                                        <span class="fstep-hint">Not submitted yet</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($d->final_thesis_submitted_at)
                                        <form method="POST" action="{{ route('reviva.corrections.accept', $d) }}">
                                            @csrf
                                            <button type="submit">Corrections accepted</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection
