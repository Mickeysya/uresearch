{{-- Stage 3: record one main examiner's Thesis Re-evaluation Report
     (UTP/CGS/024). Expects: $application, $seat, $examiner, $existing (?ReVivaEvaluation). --}}
@php $id = $application->id.'-'.$seat; @endphp
<form method="POST" action="{{ route('reviva.evaluations.record', [$application, $seat]) }}" enctype="multipart/form-data" class="reviva-evaluation-form">
    @csrf
    <p><b>{{ \App\Modules\Hani\Models\VivaExaminerProgress::SEATS[$seat] }} examiner: {{ $examiner->name }}</b>
        @if ($existing) <span class="status-badge approved">Recorded: {{ $existing->label() }}</span> @endif
    </p>

    <label for="category-{{ $id }}">Recommendation on the report</label>
    <select name="category" id="category-{{ $id }}" required>
        <option value="">Select</option>
        @foreach (\App\Modules\Hani\Models\ReVivaEvaluation::CATEGORIES as $n => [$label, $meaning])
            <option value="{{ $n }}" @selected((int) old('category', $existing?->category) === $n)>{{ $n }}. {{ $label }} ({{ $meaning }})</option>
        @endforeach
    </select>

    <fieldset>
        <legend>Fail only: required to attend the viva again?</legend>
        <label><input type="radio" name="viva_again" value="1" @checked($existing?->viva_again === true)> Yes</label>
        <label><input type="radio" name="viva_again" value="0" @checked($existing?->viva_again === false)> No</label>
    </fieldset>

    <label for="report-{{ $id }}">Signed UTP/CGS/024 (PDF) @if ($existing) <span style="color: var(--text-grey)">(optional: replaces the one on file)</span> @endif</label>
    <input type="file" name="report" id="report-{{ $id }}" accept="application/pdf,.pdf" @required(! $existing)>
    <p class="fstep-hint">Kept staff-only: never shown to the student.</p>

    <details>
        <summary>Ratings (optional, 1 to 5)</summary>
        <div class="table-scroll">
            <table class="data-table">
                <tbody>
                    @foreach (['criteria' => 'General criteria', 'sections' => 'Thesis sections'] as $group => $heading)
                        <tr><th colspan="2">{{ $heading }}</th></tr>
                        @foreach (config("hani.examiner_progress.{$group}") as $key => $label)
                            <tr>
                                <td>{{ $label }}</td>
                                <td>
                                    <select name="ratings[{{ $group }}][{{ $key }}]" aria-label="{{ $label }}">
                                        <option value="">Not rated</option>
                                        @for ($i = 1; $i <= 5; $i++)
                                            <option value="{{ $i }}" @selected((int) ($existing?->ratings[$group][$key] ?? 0) === $i)>{{ $i }}</option>
                                        @endfor
                                    </select>
                                </td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>

    <button type="submit">{{ $existing ? 'Update re-evaluation' : 'Record re-evaluation' }}</button>
</form>
