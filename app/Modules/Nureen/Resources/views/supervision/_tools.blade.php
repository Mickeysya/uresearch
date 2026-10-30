{{--
    Rendered into the queue's $tools slot -- see core::partials.queue. Only
    Non-Executive CGS can reach the Issued Appointment Letters list (same
    role the route itself is gated to), unlike the queue page this renders
    on, which Supervisor and Chair of Department also see.
--}}
@if (auth()->user()->role === \App\Modules\Core\Support\Role::NON_EXEC_CGS)
<div class="queue-tools queue-tools-actions">
    <a href="{{ route('supervision.issued') }}" class="btn-secondary">Issued Appointment Letters</a>
</div>
@endif
