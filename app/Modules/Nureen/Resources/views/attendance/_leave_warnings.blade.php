{{-- Students over an approved-leave allowance (Support\AttendanceLeave),
     after an upload or after CGS records leave. A warning only: the leave
     was approved by the Chair, so it still counts. --}}
@if (session('leave_warnings'))
    <div class="message-warning" role="status" style="margin-bottom: var(--space-4);">
        <p style="margin: 0 0 var(--space-1);"><b>Over the leave allowance.</b> The days still count as approved leave; check them with the Chair.</p>
        <ul style="margin: 0; padding-left: var(--space-5);">
            @foreach (session('leave_warnings') as $warning)
                <li>{{ $warning }}</li>
            @endforeach
        </ul>
    </div>
@endif
