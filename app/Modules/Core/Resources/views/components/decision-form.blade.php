@props(['application', 'route', 'allowReturn' => false])

{{--
    The approve/reject form. Both buttons post to the same route with a
    `decision` field; WorkflowEngine decides what that means for this stage,
    so no module ever hardcodes "next stage is the Chair".

    $allowReturn is opt-in and defaults to false, so every existing caller
    renders exactly as before. A module whose controller also accepts the
    engine's 'return' outcome (WorkflowEngine::decide()'s third decision,
    alongside approve/reject) passes true to get the third button.
--}}
<form method="POST" action="{{ $route }}">
    @csrf

    <label for="remarks-{{ $application->id }}">Remarks <span style="color: var(--text-grey)">(optional)</span></label>
    <textarea id="remarks-{{ $application->id }}" name="remarks" rows="2"
              placeholder="Visible to the student and recorded in the audit trail."></textarea>

    <div class="decision-row">
        <button type="submit" name="decision" value="approve">Approve</button>
        @if ($allowReturn)
            <button type="submit" name="decision" value="return" class="btn-reject">Return</button>
        @endif
        <button type="submit" name="decision" value="reject" class="btn-reject">Reject</button>
    </div>
</form>
