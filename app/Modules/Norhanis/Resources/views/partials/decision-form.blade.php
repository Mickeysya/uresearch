{{--
    The approver's decision on one Travel, Publication or Student Claims
    application, at the end of the module's queue card. Its primary button
    says what this stage really does -- Endorse, Review or Approve, from the
    workflow's ACTIONS (Support\StageAction) -- and Reject is the same at
    every stage. Each button asks for confirmation in the same words.

    Replaces Core's x-core::decision-form in these rows only: Core's queue
    partial still renders that form after the module's detail view, and the
    rule and script below hide it in any row holding a .norhanis-decision,
    so teammates' queues are untouched. Nureen's decision bar does the same
    (see her SHARED_CHANGES.md §17 for the Core switch that would make this
    unnecessary). Both buttons post the engine's own 'approve' / 'reject'.

    Expects: $application, $route
--}}
@php
    $stage = $application->currentStage();
    $action = $stage ? \App\Modules\Norhanis\Support\StageAction::forStage($stage) : null;
    $chain = $application->module()->stages($application);
    $keys = array_map(fn ($s) => $s->key, $chain);
    $at = $stage ? array_search($stage->key, $keys, true) : false;
    $next = $at !== false ? ($chain[$at + 1] ?? null) : null;
    $reference = $application->module()->label().' application #'.$application->id;
    $formId = 'norhanis-decision-'.$application->id;
@endphp

@if ($action)
    <form method="POST" action="{{ $route }}" class="norhanis-decision" id="{{ $formId }}" data-norhanis-decision>
        @csrf

        <label for="{{ $formId }}-remarks">Remarks <span style="color: var(--text-grey)">(optional)</span></label>
        <textarea id="{{ $formId }}-remarks" name="remarks" rows="2" maxlength="2000"
                  placeholder="Visible to the student and recorded in the audit trail."></textarea>

        <div class="decision-row">
            <button type="submit" name="decision" value="approve"
                    data-confirm="{{ $action->label() }} {{ $reference }}? {{ $next ? 'It moves on to the '.$next->label.'.' : 'This is the final approval.' }}">{{ $action->label() }}</button>
            <button type="submit" name="decision" value="reject" class="btn-reject"
                    data-confirm="Reject {{ $reference }}? This ends the application.">Reject</button>
        </div>
    </form>
@endif

@once
    <style>
        .queue-row-body:has(.norhanis-decision) > form:not([class]) { display: none; }
        .norhanis-decision { margin-top: 12px; }
        .norhanis-decision textarea { width: 100%; box-sizing: border-box; }
    </style>
    <script @cspNonce>
    (function () {
        // Core's own Approve/Reject form, in rows that carry this one. The
        // CSS above does it where :has() is supported; this covers the rest.
        document.querySelectorAll('.norhanis-decision').forEach(function (form) {
            var body = form.closest('.queue-row-body');
            if (! body) return;
            Array.prototype.forEach.call(body.children, function (child) {
                if (child.tagName === 'FORM' && ! child.className) child.remove();
            });
        });

        // The confirmation dialog, worded like the button.
        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (! form.matches || ! form.matches('[data-norhanis-decision]')) return;
            var button = event.submitter;
            if (button && button.dataset.confirm && ! window.confirm(button.dataset.confirm)) {
                event.preventDefault();
            }
        }, true);
    })();
    </script>
@endonce
