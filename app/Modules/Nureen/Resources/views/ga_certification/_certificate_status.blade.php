{{--
    Shared by _detail.blade.php (inside the pending queue, for an
    application that already has a certificate from a prior decision --
    uncommon but possible) and issued.blade.php (the list of everything
    ever issued, which is where this is reachable in the ordinary case: an
    approved application no longer appears in the pending queue at all, so
    _detail.blade.php alone would make Revoke unreachable the moment the
    certificate actually exists).

    Expects: $detail (GaCertificationDetail, reference_number present),
    $application.
--}}
<div style="margin-top: var(--space-3); padding-top: var(--space-3); border-top: 1px solid var(--border-subtle);">
    <p style="margin: 0 0 var(--space-2);">
        Certificate: <b>{{ $detail->reference_number }}</b>
        <span style="display: inline-block; padding: 2px 10px; border-radius: var(--radius-full);
                     font-size: var(--text-sm); font-weight: var(--weight-semi); margin-left: var(--space-2);
                     background: {{ $detail->isRevoked() ? 'var(--danger-bg)' : 'var(--success-bg)' }};
                     color: {{ $detail->isRevoked() ? 'var(--danger-fg)' : 'var(--success-fg)' }};">
            {{ $detail->isRevoked() ? 'Revoked' : 'Valid' }}
        </span>
    </p>

    @if ($detail->isRevoked())
        <p class="queue-meta">Revoked: {{ $detail->revoked_reason }}</p>
    @else
        <form method="POST" action="{{ route('ga-certification.revoke', $application) }}">
            @csrf
            <label for="revoked_reason-{{ $application->id }}">Revoke this certificate</label>
            <textarea id="revoked_reason-{{ $application->id }}" name="revoked_reason" rows="2" required
                      placeholder="Why this certificate is being revoked."></textarea>
            <button type="submit" class="btn-reject">Revoke Certificate</button>
        </form>
    @endif
</div>
