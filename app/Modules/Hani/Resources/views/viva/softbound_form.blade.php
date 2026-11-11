@extends('core::layouts.app')

@section('title', 'Soft Bound Thesis Submission')

@section('content')
@php($isPhd = $level === \App\Modules\Hani\Support\VivaChecklist::PHD)
<div class="card-container-inline">
    <x-core::page-header
        title="Soft Bound Thesis Submission"
        subtitle="CGS Online Form #019 (UTP/CGS/V3/VV/019). Get your thesis signed by yourself, your supervisor, co-supervisor and chair before submitting." />

    {{-- One line of context: the form below has its own step bar. --}}
    @include('hani::partials.module_stepper', ['tracker' => $tracker, 'inline' => true, 'journey' => 'Your viva'])

    <div class="card card-wide">
        @if ($returnReason)
            <p class="message-warning">Your last submission was returned: {{ $returnReason }}</p>
        @endif

        <form method="POST" action="{{ route('viva.softbound.store') }}" enctype="multipart/form-data" class="app-form" data-stepper>
            @csrf

            {{-- Section 1. Matric, name, department and programme are already on
                 the student's record, so they are shown, not asked for again. --}}
            <fieldset class="fstep" data-label="Student details">
                <p class="fstep-hint">From your UResearch profile. Ask CGS if any of this is wrong.</p>

                <p>Student Matric ID: <b>{{ $student->matric_no ?? 'Not recorded' }}</b></p>
                <p>Student Name: <b>{{ $student->name }}</b></p>
                <p>Department: <b>{{ $student->department ?? 'Not recorded' }}</b></p>
                <p>Programme: <b>{{ $student->programme ?? 'Not recorded' }}</b></p>

                <label for="field_of_study">Field of Study</label>
                <textarea name="field_of_study" id="field_of_study" rows="2" required maxlength="255"
                          class="@error('field_of_study') is-invalid @enderror">{{ old('field_of_study') }}</textarea>
                @error('field_of_study') <p class="field-error">{{ $message }}</p> @enderror

                <label for="contact_number">Contact Number</label>
                <input type="tel" name="contact_number" id="contact_number" required maxlength="30"
                       value="{{ old('contact_number', $student->contact_no) }}"
                       class="@error('contact_number') is-invalid @enderror">
                @error('contact_number') <p class="field-error">{{ $message }}</p> @enderror

                <label for="personal_email">Personal Email Address</label>
                <input type="email" name="personal_email" id="personal_email" required maxlength="150"
                       value="{{ old('personal_email') }}"
                       class="@error('personal_email') is-invalid @enderror">
                @error('personal_email') <p class="field-error">{{ $message }}</p> @enderror
            </fieldset>

            {{-- Section 2. Similarity % and word count are not on the paper form;
                 they are what lets UResearch enforce the Handbook's limits. --}}
            <fieldset class="fstep" data-label="Submission details">
                <label for="session">Session</label>
                <select name="session" id="session" required class="@error('session') is-invalid @enderror">
                    <option value="">Select</option>
                    @foreach (\App\Modules\Hani\Models\VivaSoftboundDetail::SESSIONS as $session)
                        <option value="{{ $session }}" @selected(old('session') === $session)>{{ $session }}</option>
                    @endforeach
                </select>
                @error('session') <p class="field-error">{{ $message }}</p> @enderror

                <label for="year">Year</label>
                <select name="year" id="year" required class="@error('year') is-invalid @enderror">
                    <option value="">Select</option>
                    @foreach (\App\Modules\Hani\Models\VivaSoftboundDetail::years() as $year)
                        <option value="{{ $year }}" @selected((int) old('year') === $year)>{{ $year }}</option>
                    @endforeach
                </select>
                @error('year') <p class="field-error">{{ $message }}</p> @enderror

                <label for="thesis_title">Thesis Title</label>
                <textarea name="thesis_title" id="thesis_title" rows="2" required maxlength="255"
                          data-title-words data-max="{{ \App\Modules\Hani\Models\VivaDetail::TITLE_MAX_WORDS }}"
                          class="@error('thesis_title') is-invalid @enderror">{{ old('thesis_title', $noticeTitle) }}</textarea>
                <p class="fstep-hint">From your notice. Update it if the title has changed. At most {{ \App\Modules\Hani\Models\VivaDetail::TITLE_MAX_WORDS }} words: <span data-title-count>0</span> so far.</p>
                @error('thesis_title') <p class="field-error">{{ $message }}</p> @enderror

                <div class="message-info" id="turnitin-guidance">
                    <p><b>Your Turnitin report.</b> Download it from
                        <a href="{{ $ulearnUrl }}" target="_blank" rel="noopener noreferrer">ULearnX (postgraduate students)</a>:
                        open your thesis submission and download the <b>full similarity report</b> as a PDF
                        (the Originality Report, with the similarity index and the list of primary sources).
                        The Digital Receipt on its own is not enough.</p>
                    <p>Upload it below first: UResearch reads the similarity index from it and fills it in for you to confirm.</p>
                </div>

                <label for="similarity_percent">Turnitin Similarity Index (%)</label>
                <input type="number" name="similarity_percent" id="similarity_percent" required
                       min="0" max="{{ $similarityMax }}" step="0.01"
                       value="{{ old('similarity_percent') }}"
                       class="@error('similarity_percent') is-invalid @enderror">
                <p class="fstep-hint">From your Turnitin report. {{ $similarityMax }}% or below.</p>
                <p class="fstep-hint" id="similarity-read" aria-live="polite" hidden></p>
                @error('similarity_percent') <p class="field-error">{{ $message }}</p> @enderror

                <label for="word_count">Word Count</label>
                <input type="number" name="word_count" id="word_count" required
                       min="1" max="{{ $wordLimit }}" step="1"
                       value="{{ old('word_count') }}"
                       class="@error('word_count') is-invalid @enderror">
                <p class="fstep-hint">{{ number_format($wordLimit) }} words or fewer for your programme.</p>
                @error('word_count') <p class="field-error">{{ $message }}</p> @enderror

                @foreach ($uploads as $field => [$label, $maxKb])
                    <label for="{{ $field }}">{{ $label }}</label>
                    <input type="file" name="{{ $field }}" id="{{ $field }}" required
                           accept="application/pdf,.pdf"
                           class="@error($field) is-invalid @enderror">
                    <p class="fstep-hint">PDF, up to {{ $maxKb / 1024 }} MB.</p>
                    @error($field) <p class="field-error">{{ $message }}</p> @enderror
                @endforeach

                @unless ($isPhd)
                    <p class="fstep-hint">The two RCS correction forms are for PhD candidates only, so they are not asked for here.</p>
                @endunless
            </fieldset>

            {{-- Section 3. Supervisor and AE come from the student's record and
                 department; only the co-supervisor is stored nowhere else. --}}
            <fieldset class="fstep" data-label="Approval &amp; notifications">
                <p class="fstep-hint">These people are notified about your submission.</p>

                <p>Supervisor: <b>{{ $student->supervisor?->name ?? 'Not recorded' }}</b>
                   @if ($student->supervisor) ({{ $student->supervisor->email }}) @endif</p>
                <p>Academic Executive:
                    <b>{{ $academicExecs->pluck('name')->implode(', ') ?: 'Not recorded' }}</b>
                    @if ($academicExecs->isNotEmpty()) ({{ $academicExecs->pluck('email')->implode(', ') }}) @endif
                </p>

                <label for="co_supervisor_email">Co-Supervisor Email Address <span style="color: var(--text-grey)">(if you have one)</span></label>
                <input type="email" name="co_supervisor_email" id="co_supervisor_email" maxlength="150"
                       placeholder="name@utp.edu.my"
                       value="{{ old('co_supervisor_email') }}"
                       class="@error('co_supervisor_email') is-invalid @enderror">
                <p class="fstep-hint">Their UTP email address, ending in @utp.edu.my. One address only.</p>
                @error('co_supervisor_email') <p class="field-error">{{ $message }}</p> @enderror
            </fieldset>

            <fieldset class="fstep" data-label="Agreement">
                <div class="checkbox-row">
                    <input type="checkbox" name="declaration" id="declaration" value="1" required
                           @checked(old('declaration'))>
                    <label for="declaration">
                        I hereby acknowledge that I have completely read, fully understand and agree with the
                        instructions or terms and conditions provided in the form. I also affirm that the
                        information provided on this form is true and correct to the best of my knowledge at
                        the time of application. I also understand that the University reserves the right to
                        reject my application if I provided false information or submit an incomplete application.
                    </label>
                </div>
                @error('declaration') <p class="field-error">{{ $message }}</p> @enderror
            </fieldset>

            <button type="submit">Submit Soft Bound Thesis</button>
        </form>
    </div>
</div>

@include('core::partials.form-stepper')

@push('scripts')
    <script @cspNonce>
        // Live word count for the thesis title; VivaSoftboundController is the rule.
        (function () {
            var title = document.querySelector('[data-title-words]');
            var out = document.querySelector('[data-title-count]');
            if (!title || !out) return;
            function sync() {
                var m = title.value.match(/[\p{L}\p{N}]+(?:['\-’][\p{L}\p{N}]+)*/gu);
                var n = m ? m.length : 0, max = +title.dataset.max;
                out.textContent = n;
                title.setCustomValidity(n > max ? 'The thesis title must be ' + max + ' words or fewer.' : '');
            }
            title.addEventListener('input', sync);
            sync();
        })();
    </script>
    <script @cspNonce>
        // Reads the chosen Turnitin report on the server and fills in the
        // similarity index for the student to confirm. A convenience only:
        // VivaSoftboundController::store() reads the stored report again and
        // enforces the limit itself.
        (function () {
            var input = document.getElementById('turnitin_report');
            var field = document.getElementById('similarity_percent');
            var note = document.getElementById('similarity-read');
            if (!input || !field || !note) return;

            function say(text, warn) {
                note.hidden = false;
                note.textContent = text;
                note.className = warn ? 'field-error' : 'fstep-hint';
            }

            input.addEventListener('change', function () {
                var file = input.files[0];
                if (!file) { note.hidden = true; return; }

                say('Reading your Turnitin report…', false);

                var body = new FormData();
                body.append('turnitin_report', file);
                body.append('_token', document.querySelector('input[name="_token"]').value);

                fetch(@json(route('viva.softbound.similarity-preview')), {
                    method: 'POST', body: body, credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                }).then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
                  .then(function (data) {
                      if (!data.ok) {
                          say('Not checked automatically: ' + data.message + ' Type the similarity index from your report; it will be marked as self-declared.', false);
                          return;
                      }
                      field.value = data.index;
                      var parts = [];
                      if (data.internet !== null) parts.push('internet sources ' + data.internet + '%');
                      if (data.publications !== null) parts.push('publications ' + data.publications + '%');
                      if (data.student_papers !== null) parts.push('student papers ' + data.student_papers + '%');
                      say((data.simulated ? 'Simulated reading: ' : 'Read from your report: ') + data.index + '% similarity index'
                          + (parts.length ? ' (' + parts.join(', ') + ')' : '') + '. Check that this matches your report.'
                          + (data.over_limit ? ' This is above the {{ $similarityMax }}% limit, so the submission will be refused.' : ''),
                          data.over_limit);
                  })
                  .catch(function () {
                      say('Not checked automatically: the report could not be read just now. Type the similarity index from your report.', false);
                  });
            });
        })();
    </script>
@endpush
@endsection
