{{--
    Shared by _detail.blade.php (inside the pending queue, uncommon but
    possible) and issued.blade.php (the list of everything ever issued,
    which is where this is reachable in the ordinary case: an approved
    application no longer appears in the pending queue at all).

    Expects: $detail (SupervisionDetail, letter_reference present),
    $application.
--}}
<div style="margin-top: var(--space-3); padding-top: var(--space-3); border-top: 1px solid var(--border-subtle);">
    <p style="margin: 0 0 var(--space-2);">
        Appointment Letter: <b>{{ $detail->letter_reference }}</b>
        <span style="display: inline-block; padding: 2px 10px; border-radius: var(--radius-full);
                     font-size: var(--text-sm); font-weight: var(--weight-semi); margin-left: var(--space-2);
                     background: {{ $detail->isRevoked() ? 'var(--danger-bg)' : 'var(--success-bg)' }};
                     color: {{ $detail->isRevoked() ? 'var(--danger-fg)' : 'var(--success-fg)' }};">
            {{ $detail->isRevoked() ? 'Revoked' : 'Valid' }}
        </span>
        &middot; <a href="{{ route('supervision.letter.download', $application) }}">Download</a>
    </p>

    @if ($detail->isRevoked())
        <p class="queue-meta">Revoked: {{ $detail->letter_revoked_reason }}</p>
        @if ($detail->letter_revoke_note)
            <p class="queue-meta">{{ $detail->letter_revoke_note }}</p>
        @endif
    @else
        <form method="POST" action="{{ route('supervision.revoke', $application) }}">
            @csrf
            <label for="revoked_reason-{{ $application->id }}">Revoke this letter</label>
            <textarea id="revoked_reason-{{ $application->id }}" name="revoked_reason" rows="2" required
                      placeholder="Why this letter is being revoked."></textarea>
            <button type="submit" class="btn-reject">Revoke Letter</button>
        </form>
    @endif
</div>
