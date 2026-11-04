{{--
    This module's own Approve/Reject form — not Core's shared
    x-core::decision-form. Visually identical (same .decision-row/.btn-reject
    classes every module already uses) but with two behaviours specific to
    this module: a confirm prompt before either button actually submits, and
    Reject refusing to submit at all until a reason is typed (behaviour
    lives in decide_scripts.blade.php, shared with GA's interview Pass/Fail
    form). Built separately on purpose — x-core::decision-form is shared by
    every other module's approval screens (Travel, GA Extension, Examiner
    Nomination...), and changing it would change their UX too, not just
    this module's.

    Expects: $application, $route.
--}}
<form method="POST" action="{{ $route }}" class="haziq-decision-form">
    @csrf

    {{-- GRA's cgs_review stage only — current_stage never matches this key
         for any other module's stages, so this is safe in the shared GA
         queue that also includes this partial. CGS sets the allowance the
         moment they approve; see GraApplicationController::decide(). --}}
    @if (($application->current_stage ?? null) === 'cgs_review')
        <label for="proposed_allowance-{{ $application->id }}">Proposed Allowance (RM) <span style="color: var(--text-grey)">(required to approve)</span></label>
        <input type="number" step="0.01" min="0" id="proposed_allowance-{{ $application->id }}" name="proposed_allowance" placeholder="e.g. 1500.00">
    @endif

    <label for="remarks-{{ $application->id }}">Remarks <span style="color: var(--text-grey)">(required to reject)</span></label>
    <textarea id="remarks-{{ $application->id }}" name="remarks" rows="2"
              placeholder="Visible to the student and recorded in the audit trail."></textarea>

    <div class="decision-row">
        <button type="submit" name="decision" value="approve" class="haziq-decide-approve">Approve</button>
        <button type="submit" name="decision" value="reject" class="btn-reject haziq-decide-reject">Reject</button>
    </div>
</form>

@include('haziq::partials.decide_scripts')
