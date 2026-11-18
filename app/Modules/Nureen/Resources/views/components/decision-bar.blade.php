@props([
    'application',
    'route',
    'approveLabel' => 'Approve',
    // One line under the buttons saying what the primary action does.
    'hint' => null,
    // ['route' => ..., 'label' => ...]: a secondary action posting the same
    // remarks to another route (GA Extension's return to the supervisor).
    'returnAction' => null,
    // A list of those, one button each -- GA Extension's Senior Director can
    // send it back to CGS or to the supervisor.
    'returnActions' => [],
])

{{--
    The approver's decision for one application, shared by Nureen's four
    modules: the remarks, then one row of actions -- primary (Approve, or
    Endorse & issue letter), Reject beside it, and an optional Return.

    Replaces Core's x-core::decision-form in Nureen's rows. Core's queue
    partial still renders that form and its download-only document list
    after the module's detail view; the rule at the bottom hides both in any
    row that holds a .nureen-row (each Nureen _detail view wraps itself in
    one), so teammates' queues are untouched. SHARED_CHANGES.md §17 proposes
    the Core switch that would make the rule unnecessary.

    Reject and Return need a reason. The script below stops the submit,
    relabels "Remarks (optional)" as "Reason (required)", says why and
    focuses the box; each controller's validation is the real guard, and
    its error lands back on this row (the hidden _row field) with the row
    opened.
--}}
@php
    // At a Non-Executive CGS stage the module may be one officer's
    // (config/cgs_assignment.php); anyone else gets a read-only line instead
    // of buttons the engine would refuse.
    $cgsVerdict = auth()->user()
        ? app(\App\Modules\Core\Services\CgsAssignment::class)->verdict(auth()->user(), $application)
        : null;
    $id = 'nureen-decision-'.$application->id;
    $thisRow = (string) old('_row') === (string) $application->id;
    $serverError = $thisRow ? $errors->first('remarks') : null;
@endphp

@if ($cgsVerdict && ! $cgsVerdict->allowed())
    <p class="decision-readonly nureen-readonly" role="note">
        {{ \App\Modules\Core\Exceptions\NotAssignedOfficer::messageFor($cgsVerdict->officer) }}
    </p>
@else
<form method="POST" action="{{ $route }}" class="nureen-decision" id="{{ $id }}" data-decision-bar
      @if ($serverError) data-decision-error @endif>
    @csrf
    <input type="hidden" name="_row" value="{{ $application->id }}">

    {{ $slot }}

    <label for="{{ $id }}-remarks" class="nureen-decision-label" data-remarks-label>
        {{ $serverError ? 'Reason (required)' : 'Remarks (optional)' }}
    </label>
    <textarea name="remarks" id="{{ $id }}-remarks" rows="3" maxlength="2000"
              aria-describedby="{{ $id }}-error"
              @if ($serverError) aria-invalid="true" @endif
              placeholder="Visible to the student and recorded in the audit trail.">{{ $thisRow ? old('remarks') : '' }}</textarea>
    {{-- Always present and one line tall, so the message never moves the buttons. --}}
    <p class="nureen-decision-error" id="{{ $id }}-error" aria-live="polite" data-remarks-error>{{ $serverError }}</p>

    <div class="nureen-decision-actions">
        <button type="submit" name="decision" value="approve">{{ $approveLabel }}</button>
        <button type="submit" name="decision" value="reject" class="btn-reject" data-needs-reason="Give a reason before rejecting: the student is told why.">Reject</button>
        @foreach (array_filter([$returnAction, ...$returnActions]) as $return)
            <button type="submit" class="btn-secondary" formaction="{{ $return['route'] }}"
                    data-needs-reason="Say what has to change before sending it back.">{{ $return['label'] }}</button>
        @endforeach
    </div>

    @if ($hint)
        <p class="nureen-decision-hint">{{ $hint }}</p>
    @endif
</form>
@endif

@once
    <style>
        /* Core's own decision form and download-only list, in rows whose
           detail view is Nureen's (see the comment at the top). */
        .queue-row-body:has(> .nureen-row) > ul.doc-list,
        .queue-row-body:has(> .nureen-row) > form:not([class]),
        .queue-row-body:has(> .nureen-row) > form.decision-return,
        .queue-row-body:has(> .nureen-row) > p.decision-readonly { display: none; }

        .nureen-decision { margin-top: var(--space-4); padding-top: var(--space-4); border-top: 1px solid var(--border-subtle); }
        .nureen-decision-label { display: block; margin: 0 0 var(--space-1); }
        .nureen-appeal-sessions { margin-bottom: var(--space-3); }
        .nureen-appeal-sessions input[type="number"] { width: 8em; }
        .nureen-appeal-sessions .queue-meta { margin: var(--space-1) 0 0; }
        .nureen-decision textarea { width: 100%; box-sizing: border-box; resize: vertical; }
        .nureen-decision textarea[aria-invalid="true"] { border-color: var(--danger-fg); }
        .nureen-decision-error { min-height: 1.4em; margin: var(--space-1) 0 0; color: var(--danger-fg); font-size: var(--text-sm); }
        .nureen-decision-actions { display: flex; flex-wrap: wrap; gap: var(--space-3); align-items: stretch; margin-top: var(--space-2); }
        .nureen-decision-actions button {
            margin: 0; min-height: 44px; padding: 10px 20px; line-height: 1.2;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: var(--text-md); border-radius: var(--radius-sm);
        }
        .nureen-decision-actions .btn-reject, .nureen-decision-actions .btn-secondary { border-width: 1px; }
        .nureen-decision-hint { margin: var(--space-2) 0 0; color: var(--text-grey); font-size: var(--text-sm); }
        .nureen-decision :is(button, textarea, input):focus-visible { outline: 3px solid var(--navy); outline-offset: 2px; }
        @media (max-width: 480px) {
            .nureen-decision-actions { flex-direction: column; }
            .nureen-decision-actions button { width: 100%; }
        }
    </style>
    <script @cspNonce>
    (function () {
        function bar(form) {
            return {
                textarea: form.querySelector('textarea[name="remarks"]'),
                label: form.querySelector('[data-remarks-label]'),
                error: form.querySelector('[data-remarks-error]'),
            };
        }

        // Reject / Return with no reason: stop, say so, focus the box.
        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!form.matches('[data-decision-bar]')) return;
            var button = event.submitter;
            if (!button || !button.hasAttribute('data-needs-reason')) return;
            var parts = bar(form);
            if (parts.textarea.value.trim() !== '') return;

            event.preventDefault();
            parts.label.textContent = 'Reason (required)';
            parts.textarea.setAttribute('aria-invalid', 'true');
            parts.error.textContent = button.getAttribute('data-needs-reason');
            parts.textarea.focus();
        }, true);

        // Typing a reason clears the error.
        document.addEventListener('input', function (event) {
            var form = event.target.closest && event.target.closest('[data-decision-bar]');
            if (!form || event.target.name !== 'remarks') return;
            var parts = bar(form);
            if (parts.textarea.value.trim() !== '') {
                parts.textarea.removeAttribute('aria-invalid');
                parts.error.textContent = '';
            }
        });

        // A server-side refusal comes back to this row: open it and focus the reason.
        document.addEventListener('DOMContentLoaded', function () {
            var failed = document.querySelector('[data-decision-bar][data-decision-error]');
            if (!failed) return;
            var row = failed.closest('details');
            if (row) row.open = true;
            bar(failed).textarea.focus();
        });
    })();
    </script>
@endonce
