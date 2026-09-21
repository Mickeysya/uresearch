{{--
    Rendered into the queue's $tools slot. Everything a stage on this chain
    needs beyond deciding: read the compiled list, or take it away as a file.

    The same two exports are offered at every stage. What differs is what
    comes out of them -- reportRows() scopes an Academic Executive to their
    own department and gives everyone above the department the lot.
--}}
<div class="queue-tools queue-tools-actions">
    <a href="{{ route('examiner-nomination.report') }}" class="btn-secondary">Open the compiled list</a>
    <a href="{{ route('examiner-nomination.export', ['format' => 'xlsx']) }}" class="btn-secondary">Download Excel</a>
    <a href="{{ route('examiner-nomination.export', ['format' => 'csv']) }}" class="btn-secondary">Download CSV</a>
</div>
