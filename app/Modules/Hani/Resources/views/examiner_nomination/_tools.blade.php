{{--
    Rendered into the queue's $tools slot. Everything a stage on this chain
    needs beyond deciding: read the compiled list, or take it away as a file.

    The export is offered at every stage. What comes out of it --
    reportRows() scopes an Academic Executive to their own department and
    gives everyone above the department the lot.
--}}
<div class="queue-tools queue-tools-actions">
    <a href="{{ route('examiner-nomination.report') }}" class="btn-secondary">Open the compiled list</a>
    <a href="{{ route('examiner-nomination.export', ['format' => 'xlsx']) }}" class="btn-secondary" data-region-swap-ignore>Download Excel: awaiting letters</a>
    <a href="{{ route('examiner-nomination.export', ['format' => 'xlsx', 'scope' => 'full']) }}" class="btn-secondary" data-region-swap-ignore>Download Excel: full list</a>
</div>
