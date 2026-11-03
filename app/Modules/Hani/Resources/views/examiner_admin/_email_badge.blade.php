{{-- The examiner's email state, wherever an examiner is listed. $examiner required. --}}
@if ($examiner->emailInvalid())
    <span class="status-badge rejected" title="Reported not working{{ $examiner->email_flagged_at ? ' on '.$examiner->email_flagged_at->format('j M Y') : '' }}">Email needs updating</span>
@elseif ($examiner->needsEmail())
    <span class="status-badge pending">Email needed</span>
@endif
