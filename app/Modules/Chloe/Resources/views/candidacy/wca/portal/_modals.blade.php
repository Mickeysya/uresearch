@php $decideUrl = route('candidacy.portal.decide', $appeal); @endphp
<div class="wca-modal" id="wca-modal-approve" role="dialog" aria-modal="true" aria-labelledby="wca-m-approve-t" hidden>
    <div class="wca-modal-box">
        <h3 id="wca-m-approve-t">{{ $isCgs ? 'Verify' : 'Approve' }} appeal {{ $appeal->appeal_no }}?</h3>
        <form method="POST" action="{{ $decideUrl }}">
            @csrf @if ($return)<input type="hidden" name="return" value="{{ $return }}">@endif <input type="hidden" name="action" value="{{ $isCgs ? 'verify' : 'approve' }}">
            @if ($isCgs)
                <div class="wca-field"><span class="wca-label">Documents complete</span>
                    <label class="wca-choice"><input type="radio" name="documents_complete" value="yes" checked> Yes, the official form and documents are complete</label>
                    <label class="wca-choice"><input type="radio" name="documents_complete" value="no"> No (then return the appeal to the student instead)</label></div>
            @endif
            @if ($isDean)
                @php $remainingMonths = max(1, (int) ($candidacy?->remainingAppealMonths() ?? $appeal->requested_months)); $requestedMonths = min((int) $appeal->requested_months, $remainingMonths); @endphp
                <p>The student requested <strong>{{ $appeal->requested_months }} month(s)</strong> and has <strong>{{ $candidacy?->remainingAppealMonths() ?? 0 }} month(s)</strong> of allowance left. The memo is issued to the student.</p>
                <div class="wca-field">
                    <label for="wca-approved-months">Months to approve</label>
                    <select id="wca-approved-months" name="approved_months" class="wca-select" data-requested="{{ $appeal->requested_months }}"
                            data-base-expiry="{{ ($candidacy?->candidacy_expiry_date ?? $appeal->expiry_at_submission)?->format('Y-m-d') }}">
                        @for ($m = 1; $m <= $remainingMonths; $m++)<option value="{{ $m }}" @selected($m === $requestedMonths)>{{ $m }} month(s){{ $m === (int) $appeal->requested_months ? ' (as requested)' : '' }}</option>@endfor
                    </select>
                    @error('approved_months')<p class="wca-error">{{ $message }}</p>@enderror
                    <p class="wca-muted wca-small">New expiry date: <strong id="wca-approved-expiry">{{ $preview ? $preview['new_expiry']->format('j M Y') : '—' }}</strong></p>
                </div>
                <div class="wca-field wca-hidden" id="wca-adjust-field">
                    <label for="wca-adjust-reason">Reason for approving a different number of months <span class="wca-muted">(required; shown to approvers and CGS, not to the student)</span></label>
                    <textarea id="wca-adjust-reason" name="adjust_reason" class="wca-textarea" maxlength="2000"></textarea>
                    @error('adjust_reason')<p class="wca-error">{{ $message }}</p>@enderror
                </div>
            @else
                <p>The appeal moves on to the next step.</p>
            @endif
            <div class="wca-field"><label for="wca-remark-approve">Remark (optional)</label><textarea id="wca-remark-approve" name="remark" class="wca-textarea" maxlength="2000"></textarea></div>
            <div class="wca-actions"><button type="submit" class="wca-btn is-primary">Confirm {{ $isCgs ? 'verification' : 'approval' }}</button><button type="button" class="wca-btn" data-modal-close>Cancel</button></div>
        </form>
    </div>
</div>

<div class="wca-modal" id="wca-modal-return" role="dialog" aria-modal="true" aria-labelledby="wca-m-return-t" hidden>
    <div class="wca-modal-box">
        <h3 id="wca-m-return-t">Return appeal {{ $appeal->appeal_no }} to the student?</h3>
        <form method="POST" action="{{ $decideUrl }}">
            @csrf @if ($return)<input type="hidden" name="return" value="{{ $return }}">@endif <input type="hidden" name="action" value="return">
            <div class="wca-field"><span class="wca-label">Reasons (tick at least one)</span>
                @foreach ($returnReasons as $key => $label)<label class="wca-choice"><input type="checkbox" name="reasons[]" value="{{ $key }}"> {{ $label }}</label>@endforeach</div>
            <div class="wca-field"><label for="wca-remark-return">Remark <span class="wca-muted wca-hidden" data-remark-need>(required when "Other" is ticked)</span></label><textarea id="wca-remark-return" name="remark" class="wca-textarea" maxlength="2000"></textarea></div>
            <div class="wca-actions"><button type="submit" class="wca-btn is-primary">Return to student</button><button type="button" class="wca-btn" data-modal-close>Cancel</button></div>
        </form>
    </div>
</div>

@if ($isDean)
<div class="wca-modal" id="wca-modal-reject" role="dialog" aria-modal="true" aria-labelledby="wca-m-reject-t" hidden>
    <div class="wca-modal-box">
        <h3 id="wca-m-reject-t">Reject appeal {{ $appeal->appeal_no }}?</h3>
        <p><strong>This is final.</strong> No further appeals will be accepted for this candidacy. The expiry date stays unchanged.</p>
        <form method="POST" action="{{ $decideUrl }}" id="wca-reject-form" data-confirm-form>
            @csrf @if ($return)<input type="hidden" name="return" value="{{ $return }}">@endif <input type="hidden" name="action" value="reject">
            <div class="wca-field"><span class="wca-label">Reasons (tick at least one)</span>
                @foreach ($rejectReasons as $key => $label)<label class="wca-choice"><input type="checkbox" name="reasons[]" value="{{ $key }}"> {{ $label }}</label>@endforeach</div>
            <div class="wca-field"><label for="wca-remark-reject">Remark <span class="wca-muted wca-hidden" data-remark-need>(required when "Other" is ticked)</span></label><textarea id="wca-remark-reject" name="remark" class="wca-textarea" maxlength="2000"></textarea></div>
            <p class="wca-error wca-hidden" id="wca-reject-problem" role="alert"></p>
            {{-- Nothing is saved here: this button only opens the second dialog. --}}
            <div class="wca-actions"><button type="button" class="wca-btn is-primary" data-confirm-open="wca-confirm-reject">Reject (final)</button><button type="button" class="wca-btn" data-modal-close>Cancel</button></div>
        </form>
    </div>
</div>

@php $expiry = ($candidacy?->candidacy_expiry_date ?? $appeal->expiry_at_submission)?->format('j M Y'); @endphp
{{-- The second, final confirmation. The decision is posted only by "Confirm rejection". --}}
<div class="wca-modal wca-confirm" id="wca-confirm-reject" role="alertdialog" aria-modal="true" aria-labelledby="wca-c-reject-t" aria-describedby="wca-c-reject-d" hidden data-label-prefix="{{ $appeal->appeal_no }}">
    <div class="wca-modal-box">
        <h3 id="wca-c-reject-t">Confirm rejection of {{ $appeal->appeal_no }}</h3>
        <div id="wca-c-reject-d">
            <p><strong>You are about to reject this appeal. This cannot be undone.</strong></p>
            <ul class="wca-consequences">
                <li>The appeal is closed as Rejected (final).</li>
                <li>No further appeal can be submitted for this candidacy.</li>
                <li>The expiry date stays at {{ $expiry }}.</li>
                <li>The student can still submit the Softbound thesis before {{ $expiry }}. If no Softbound is submitted by then, the student becomes a dismissal candidate for CGS to review.</li>
                <li>The student, the Supervisor / Programme Manager, the Department Chair and CGS are notified by e-mail and bell.</li>
            </ul>
            <p class="wca-label">Reasons</p>
            <ul class="wca-consequences" id="wca-confirm-reasons"></ul>
            <p class="wca-muted wca-hidden" id="wca-confirm-remark-wrap">Remark: <span id="wca-confirm-remark"></span></p>
        </div>
        <div class="wca-actions">
            <button type="button" class="wca-btn" id="wca-confirm-discard" data-confirm-close>Discard</button>
            <button type="button" class="wca-btn is-danger" id="wca-confirm-final" data-confirm-submit="wca-reject-form">Confirm rejection</button>
        </div>
    </div>
</div>
@endif
