{{--
    Admin's "Request Correction" control — shared by GRA's and GA's queue
    detail partials and full-detail page. Expects: $application,
    $documentTypes, $sectionLabels (empty for GA, which has no section-level
    correction), $sectionFieldLabels (section key => [field key => label];
    only set for sections with a fixed field list — GA has none, and GRA's
    Section D/E/F don't either, being repeatable tables), $requestRoute.

    A checklist, not a single dropdown: tick as many fields, sections and
    documents as are wrong, write one shared reason, submit once —
    HandlesApplicationCorrections::requestCorrection() creates one
    ApplicationCorrectionRequest row per ticked item and sends the student
    one notification listing all of them. A section with named fields
    (Project Details, GRA Details) lists its fields individually instead of
    one opaque "this section is wrong" box; a repeatable section
    (Academic Qualification, Working Experience, Publications) stays a
    single whole-section tick, since it has no fixed set of fields to list.
--}}
<form method="POST" action="{{ route($requestRoute, $application) }}" style="margin-top: 10px;">
    @csrf
    <label>Request Correction: tick everything that needs fixing</label>

    @if (count($sectionLabels))
        <p style="font-weight: 600; font-size: 13px; margin: 10px 0 4px;">Sections</p>
        @foreach ($sectionLabels as $key => $label)
            @if (! empty($sectionFieldLabels[$key]))
                <p style="font-size: 13px; margin: 8px 0 2px; color: var(--text-grey);">{{ $label }}</p>
                @foreach ($sectionFieldLabels[$key] as $field => $fieldLabel)
                    @php($id = 'target-'.$application->id.'-'.$key.'-'.$field)
                    <div class="checkbox-row" style="margin-left: 12px;">
                        <input type="checkbox" name="targets[]" value="{{ $key }}:{{ $field }}" id="{{ $id }}">
                        <label for="{{ $id }}">{{ $fieldLabel }}</label>
                    </div>
                @endforeach
            @else
                @php($id = 'target-'.$application->id.'-'.$key)
                <div class="checkbox-row">
                    <input type="checkbox" name="targets[]" value="{{ $key }}" id="{{ $id }}">
                    <label for="{{ $id }}">{{ $label }}</label>
                </div>
            @endif
        @endforeach
    @endif

    <p style="font-weight: 600; font-size: 13px; margin: 10px 0 4px;">Documents</p>
    @foreach ($documentTypes as $label)
        @php($id = 'target-'.$application->id.'-doc-'.Str::slug($label))
        <div class="checkbox-row">
            <input type="checkbox" name="targets[]" value="document:{{ $label }}" id="{{ $id }}">
            <label for="{{ $id }}">{{ $label }}</label>
        </div>
    @endforeach

    <label for="remarks-{{ $application->id }}" style="margin-top: 10px;">Reason: shown to the student</label>
    <textarea name="remarks" id="remarks-{{ $application->id }}" rows="2" required
              placeholder="Applies to everything ticked above."></textarea>

    <button type="submit" class="btn-secondary">Flag Selected</button>
</form>
