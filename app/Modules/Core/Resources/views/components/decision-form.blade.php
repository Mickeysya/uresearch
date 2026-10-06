@props(['application', 'route', 'allowReturn' => false, 'returnAction' => null])

{{--
    The approve/reject form. Both buttons post to the same route with a
    `decision` field; WorkflowEngine decides what that means for this stage,
    so no module ever hardcodes "next stage is the Chair".

    $allowReturn is opt-in and defaults to false, so every existing caller
    renders exactly as before. A module whose controller also accepts the
    engine's 'return' outcome (WorkflowEngine::decide()'s third decision,
    alongside approve/reject) passes true to get a Return button that sends
    it back to the STUDENT for revision (see WorkflowEngine::resubmit()).

    $returnAction is optional and opt-in: pass ['route' => ..., 'label' => ...]
    and a third button appears that posts to WorkflowEngine::returnTo()
    instead -- send it back to an earlier stage rather than ending it. A
    module that does not pass it renders exactly what it rendered before.

    The return button is a separate form, not a third value on this one: the
    reason is mandatory there and optional here, and one textarea cannot be
    both. Its own textarea is `required`, so the browser stops an empty
    return before the request is made.
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

@if ($returnAction)
    <form method="POST" action="{{ $returnAction['route'] }}" class="decision-return">
        @csrf

        <label for="return-remarks-{{ $application->id }}">
            {{ $returnAction['label'] }}: say what has to change
        </label>
        <textarea id="return-remarks-{{ $application->id }}" name="remarks" rows="2" required maxlength="2000"
                  placeholder="Sent to whoever has to act on it again, and to the candidate."></textarea>

        <div class="decision-row">
            <button type="submit" class="btn-secondary">{{ $returnAction['label'] }}</button>
        </div>
    </form>
@endif
