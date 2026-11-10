{{-- One examiner's return. Expects: $action, $id, $existing (?ReVivaEvaluation). --}}
<form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="reviva-evaluation-form">
    @csrf
    <label for="category-{{ $id }}">Category on the report</label>
    <select name="category" id="category-{{ $id }}" required>
        <option value="">Select</option>
        @foreach (\App\Modules\Hani\Models\ReVivaEvaluation::CATEGORIES as $n => [$label, $meaning])
            <option value="{{ $n }}" @selected((int) old('category', $existing?->category) === $n)>{{ $n }}. {{ $label }} ({{ $meaning }})</option>
        @endforeach
    </select>
    @error('category') <p class="field-error">{{ $message }}</p> @enderror
    <fieldset>
        <legend>Category 4 only: viva again?</legend>
        <label><input type="radio" name="viva_again" value="1" @checked($existing?->viva_again === true)> Yes</label>
        <label><input type="radio" name="viva_again" value="0" @checked($existing?->viva_again === false)> No</label>
    </fieldset>
    @error('viva_again') <p class="field-error">{{ $message }}</p> @enderror
    <label for="report-{{ $id }}">Completed Thesis Re-evaluation Report, UTP/CGS/024 (PDF)</label>
    <input type="file" name="report" id="report-{{ $id }}" accept="application/pdf,.pdf" @required(! $existing)>
    @error('report') <p class="field-error">{{ $message }}</p> @enderror
    <label for="signed-{{ $id }}">Signed Correction of Thesis letter (PDF)</label>
    <input type="file" name="signed_letter" id="signed-{{ $id }}" accept="application/pdf,.pdf" @required(! $existing)>
    @error('signed_letter') <p class="field-error">{{ $message }}</p> @enderror
    <button type="submit">{{ $existing ? 'Update' : 'Submit' }}</button>
</form>
