<section class="wca-card" aria-labelledby="wca-h-docs">
    <h3 id="wca-h-docs">Documents</h3>
    <p class="wca-muted wca-small">Excel (.xlsx), PDF or Word (.docx), at most 10 MB each. The official appeal form is required; you can add up to 4 supporting documents.</p>

    <h4>Official appeal form (.xlsx, required)</h4>
    @if ($officialForm)
        <ul class="wca-list"><li><span>{{ $officialForm->original_name }} <span class="wca-muted wca-small">({{ number_format($officialForm->size / 1024, 0) }} KB)</span></span>
            @if ($officialForm->round_uploaded === $currentRound)
                <form method="POST" action="{{ route('candidacy.appeals.documents.destroy', [$appeal, $officialForm]) }}">@csrf @method('DELETE')<button type="submit" class="wca-btn is-small">Remove</button></form>
            @endif
        </li></ul>
    @endif
    @if (! $officialForm || $officialForm->round_uploaded !== $currentRound || $mode === 'resubmit')
        <form method="POST" action="{{ route('candidacy.appeals.documents.store', $appeal) }}" enctype="multipart/form-data">
            @csrf <input type="hidden" name="kind" value="official_form">
            <div class="wca-field"><label for="wca-official">{{ $officialForm ? 'Replace the official form' : 'Choose the official form' }}</label><input id="wca-official" class="wca-input" type="file" name="file" accept=".xlsx"></div>
            <button type="submit" class="wca-btn is-small">Upload</button>
        </form>
    @endif

    <h4 class="wca-doc-title">Supporting documents (up to 4)</h4>
    @if ($supporting->isEmpty())<p class="wca-muted">None added.</p>
    @else
        <ul class="wca-list">
            @foreach ($supporting as $doc)
                <li><span>{{ $doc->original_name }} <span class="wca-muted wca-small">({{ number_format($doc->size / 1024, 0) }} KB)</span></span>
                    @if ($doc->round_uploaded === $currentRound)
                        <form method="POST" action="{{ route('candidacy.appeals.documents.destroy', [$appeal, $doc]) }}">@csrf @method('DELETE')<button type="submit" class="wca-btn is-small">Remove</button></form>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
    @if ($supporting->where('round_uploaded', $currentRound)->count() < 4)
        <form method="POST" action="{{ route('candidacy.appeals.documents.store', $appeal) }}" enctype="multipart/form-data">
            @csrf <input type="hidden" name="kind" value="supporting">
            <div class="wca-field"><label for="wca-supporting">Add a supporting document</label><input id="wca-supporting" class="wca-input" type="file" name="file" accept=".xlsx,.pdf,.docx"></div>
            <button type="submit" class="wca-btn is-small">Upload</button>
        </form>
    @endif
</section>
