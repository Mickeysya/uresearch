{{-- "Email not working": a small form, folded away. $examiner required. --}}
@if (filled($examiner->email) && ! $examiner->emailInvalid())
    <details class="report-email">
        <summary>Report email not working</summary>
        <form method="POST" action="{{ route('examiner-admin.report-email', $examiner) }}">
            @csrf
            <label for="report-reason-{{ $examiner->id }}">What happened?</label>
            <input type="text" name="reason" id="report-reason-{{ $examiner->id }}" required maxlength="500"
                   placeholder="e.g. The appointment letter bounced: mailbox does not exist">
            <button type="submit" class="btn-reject">Mark email as not working</button>
        </form>
    </details>
@endif
