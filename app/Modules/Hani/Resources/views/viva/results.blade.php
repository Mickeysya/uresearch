@extends('core::layouts.app')

@section('title', 'Viva Results')

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Viva Results"
        subtitle="After the viva, upload the chairman's viva report and record the result. Deadlines, examiner records and the student's next step follow from the category." />

    @php
        $sections = [
            'awaiting' => ['Awaiting result', 'The viva has taken place. Upload the chairman\'s report and record the category.'],
            'submitted' => ['Corrections submitted', 'The student has uploaded the corrected final thesis.'],
            'corrections' => ['Awaiting corrections', 'Categories 1 to 3: the student is making corrections.'],
            'reexamination' => ['Re-examination (category 4)', 'The Senior Executive logs the re-viva when the thesis is resubmitted.'],
            'upcoming' => ['Viva not held yet', ''],
            'closed' => ['Closed', 'Corrections accepted, or a final result.'],
        ];
    @endphp

    @foreach ($sections as $key => [$heading, $hint])
        @php $rows = $groups->get($key, collect()); @endphp
        @continue($rows->isEmpty() && in_array($key, ['upcoming', 'closed'], true))
        <div class="card card-wide">
            <h3>{{ $heading }} ({{ $rows->count() }})</h3>
            @if ($hint) <p class="fstep-hint">{{ $hint }}</p> @endif

            @if ($rows->isEmpty())
                <p class="fstep-hint">None.</p>
            @else
                <div class="table-scroll">
                    <table class="data-table">
                        <thead><tr><th>Student</th><th>Viva</th><th>Result</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($rows as $p)
                                @php
                                    $d = $p->detail;
                                    $report = $p->notice->documents->where('doc_type', 'Viva Report')->last();
                                    $corrected = $p->notice->documents->where('doc_type', 'Corrected Final Thesis')->last();
                                @endphp
                                <tr>
                                    <td>{{ $p->notice->student->name }}<br><span class="fstep-hint">{{ $d->thesis_title }}</span></td>
                                    <td>{{ $d->viva_at->format('j M Y, g:i a') }}</td>
                                    <td>
                                        @if ($d->hasResult())
                                            <b>{{ $d->resultLabel() }}</b>
                                            @if ($report) <br><a href="{{ route('documents.show', $report) }}">Viva report</a> @endif
                                            @if ($d->corrections_due_at)
                                                <br>Corrections due {{ $d->corrections_due_at->format('j M Y') }}
                                                @if (! $d->corrections_accepted_at && $d->corrections_due_at->isPast()) <span class="status-badge rejected">Overdue</span> @endif
                                            @endif
                                            @if ($d->resubmission_opens_at)
                                                <br>Resubmission {{ $d->resubmission_opens_at->format('j M Y') }} to {{ $d->resubmission_closes_at->format('j M Y') }}
                                            @endif
                                            @if ($d->corrections_accepted_at)
                                                <br>Corrections accepted {{ $d->corrections_accepted_at->format('j M Y') }}
                                            @endif
                                        @else
                                            Not recorded
                                        @endif
                                    </td>
                                    <td>
                                        @if ($key === 'awaiting')
                                            <form method="POST" action="{{ route('viva.results.record', $p->notice) }}" enctype="multipart/form-data">
                                                @csrf
                                                <label for="result_category_{{ $p->notice->id }}">Result category</label>
                                                <select name="result_category" id="result_category_{{ $p->notice->id }}" required>
                                                    <option value="">Select</option>
                                                    @foreach ($categories as $n => [$label, $meaning])
                                                        <option value="{{ $n }}">{{ $n }}. {{ $label }} ({{ $meaning }})</option>
                                                    @endforeach
                                                </select>
                                                <label for="viva_report_{{ $p->notice->id }}">Chairman's viva report (PDF)</label>
                                                <input type="file" name="viva_report" id="viva_report_{{ $p->notice->id }}" accept="application/pdf,.pdf" required>
                                                <p class="fstep-hint">The student will see the report and the result. Examiners' individual categories are never shown to them.</p>
                                                <button type="submit">Record Result</button>
                                            </form>
                                        @elseif ($key === 'submitted')
                                            @if ($corrected) <a href="{{ route('documents.show', $corrected) }}">Corrected thesis</a><br> @endif
                                            <form method="POST" action="{{ route('viva.results.accept', $p->notice) }}">
                                                @csrf
                                                <button type="submit">Corrections Accepted</button>
                                            </form>
                                        @elseif ($key === 'reexamination')
                                            <span class="fstep-hint">{{ $d->examiners_freed_at ? 'Window closed with no re-viva; examiners freed '.$d->examiners_freed_at->format('j M Y').'.' : 'Main examiners held for the re-viva.' }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endforeach

    @if ($errors->any())
        <div class="card card-wide">
            @foreach ($errors->all() as $error) <p class="field-error">{{ $error }}</p> @endforeach
        </div>
    @endif
</div>
@endsection
