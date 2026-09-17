@extends('core::layouts.app')

@section('title', 'Appeal a Hardbound Submission')

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Appeal Hardbound Submission</h2>
        <div class="card-divider"></div>

        @if ($submissions->isEmpty())
            <div class="empty-state">
                You have no hardbound submission to appeal.<br>
                An appeal can only be filed against a submission CGS has rejected, and only
                once. If CGS returned yours for correction, resubmit it instead.
            </div>
        @else
            <p class="queue-meta">
                CGS compiles your memo and the original submission into a Dean PFR report,
                and the Senior Executive rules on it. If the appeal is upheld you will be
                able to resubmit the thesis.
            </p>

            <form method="POST" action="{{ route('hardbound-appeal.store') }}"
                  enctype="multipart/form-data" class="app-form" data-stepper>
                @csrf

                <fieldset class="fstep" data-label="Which submission">
                <p class="fstep-hint">An appeal is filed against one rejected
                   submission, and only once.</p>

                <label for="hardbound_application_id">Submission Being Appealed</label>
                <select name="hardbound_application_id" id="hardbound_application_id" required
                        class="@error('hardbound_application_id') is-invalid @enderror">
                    <option value="">Select the rejected submission</option>
                    @foreach ($submissions as $submission)
                        <option value="{{ $submission->id }}"
                                @selected(old('hardbound_application_id') == $submission->id)>
                            #{{ $submission->id }}, {{ $submission->module()->summary($submission) }}
                        </option>
                    @endforeach
                </select>
                @error('hardbound_application_id') <p class="field-error">{{ $message }}</p> @enderror
                </fieldset>

                <fieldset class="fstep" data-label="Grounds">
                <p class="fstep-hint">What CGS got wrong, in your own words. This is
                   quoted in the Dean PFR report the Senior Executive rules on.</p>

                <label for="justification">Grounds for the Appeal</label>
                <textarea name="justification" id="justification" rows="6" required
                          class="@error('justification') is-invalid @enderror">{{ old('justification') }}</textarea>
                @error('justification') <p class="field-error">{{ $message }}</p> @enderror
                </fieldset>

                <fieldset class="fstep" data-label="Appeal memo">
                <p class="fstep-hint">Your formal memo. CGS compiles it with the
                   original submission into the report.</p>

                <label for="appeal_memo">Appeal Memo</label>
                <input type="file" name="appeal_memo" id="appeal_memo" required
                       class="@error('appeal_memo') is-invalid @enderror">
                @error('appeal_memo') <p class="field-error">{{ $message }}</p> @enderror
                <p class="queue-meta" style="margin-top: -8px;">
                    PDF, Word, Excel or an image, up to 10 MB.
                </p>
                </fieldset>

                <button type="submit">File Appeal</button>
            </form>

            @include('core::partials.form-stepper')
        @endif
    </div>
</div>

@endsection
