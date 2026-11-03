@php use App\Modules\Chloe\Support\CandidacyAppealPresenter as P; @endphp
<section class="wca-card" aria-labelledby="wca-h-rev">
    <h3 id="wca-h-rev">Review your appeal</h3>
    <dl class="wca-kv">
        <dt>Department</dt><dd>{{ $department?->name ?? '—' }}</dd>
        <dt>Supervisor / Programme Manager</dt><dd>{{ $appeal->supervisorProfile?->full_name ?? ($supervisor?->full_name ?? '—') }}</dd>
        <dt>Department Chair</dt><dd>{{ $chair?->full_name ?? '—' }}</dd>
        <dt>Dean of PGR</dt><dd>{{ $dean?->full_name ?? '—' }}</dd>
        <dt>Current phase</dt><dd>{{ $phases[$appeal->phase] ?? '—' }}</dd>
        <dt>Thesis written</dt><dd>{{ $appeal->percent_complete !== null ? $appeal->percent_complete.'%' : '—' }}</dd>
        <dt>RCS</dt><dd>{{ $appeal->rcs_status === 'done' ? 'Done'.($appeal->rcs_date ? ' ('.$appeal->rcs_date->format('j M Y').')' : '') : ($appeal->rcs_status === 'pending' ? 'Pending'.($appeal->rcs_expected_date ? ' (expected '.$appeal->rcs_expected_date->format('j M Y').')' : '') : '—') }}</dd>
        <dt>Publications</dt><dd>{{ $appeal->publications->count() }}</dd>
        <dt>Months requested</dt><dd>{{ $appeal->requested_months ?? '—' }}</dd>
        <dt>Official form</dt><dd>{{ $officialForm?->original_name ?? 'Missing' }}</dd>
        <dt>Supporting documents</dt><dd>{{ $supporting->count() }}</dd>
        <dt>Declaration</dt><dd>{{ $appeal->declaration_accepted_at ? 'Accepted' : 'Not accepted' }}</dd>
    </dl>
    <h4 class="wca-doc-title">Reason</h4>
    <p>{!! nl2br(e($appeal->reason ?: '—')) !!}</p>
</section>

<form method="POST" action="{{ route('candidacy.appeals.submit') }}">
    @csrf
    <section class="wca-card">
        <label class="wca-choice"><input type="checkbox" name="confirm" value="1"> Once submitted you cannot withdraw this appeal. I understand and want to submit.</label>
        @error('confirm')<p class="wca-error">{{ $message }}</p>@enderror
    </section>
    <div class="wca-actions">
        <a class="wca-btn" href="{{ route('candidacy.appeals.draft', ['step' => 3]) }}">Back</a>
        <button type="submit" class="wca-btn is-primary">Submit appeal</button>
    </div>
</form>
