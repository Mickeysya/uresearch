@extends('core::layouts.guest')

{{-- The signer's side of the Memo Request Split Budget: the supervisor
     (FROM), then the centre head (THROUGH). Reached from the signed link in
     their email; there is no login, and the page only ever shows the one
     memo that link names. --}}
@section('content')
@php($isSupervisor = $signer === \App\Modules\Norhanis\Models\PublicationMemo::SUPERVISOR)
<div class="card-container">
    <div class="card card-wide">
        <h2>Memo: Request to Split Budget</h2>
        <div class="card-divider"></div>

        <table class="recent-activity-table">
            <tr><th style="width: 22%;">To</th><td>Senior Director, Centre for Graduate Studies</td></tr>
            <tr><th>Through</th><td>{{ $memo->head_name }}, Head, {{ $memo->centre_name }}</td></tr>
            <tr><th>From</th><td>{{ $memo->supervisor_name }}@if ($memo->supervisor_department), {{ $memo->supervisor_department }}@endif</td></tr>
        </table>

        <p style="margin-top: 16px;">
            Request to use the Graduate Studies Fund and the <b>{{ $memo->grant_name }}</b> grant
            (cost centre {{ $memo->grant_cost_centre }}) to cover a publication fee that exceeds the
            RM{{ number_format(\App\Modules\Norhanis\Models\PublicationMemo::CGS_CAP) }} covered by the CGS fund.
        </p>

        <p><b>Student:</b> {{ $student?->name }}@if ($student?->matric_no) ({{ $student->matric_no }})@endif</p>
        <p><b>Paper:</b> {{ $detail->title_of_paper }}</p>
        <p><b>Conference / Journal:</b> {{ $detail->title_of_conference_journal }}</p>

        @include('norhanis::publication._memo_split')

        @if ($memo->hasSigned($signer))
            <p class="message-success" style="margin-top: 16px;">
                Thank you, {{ $memo->{$signer.'_name'} }}. You signed this memo on
                {{ $memo->{$signer.'_signed_at'}->format('j M Y, g:ia') }}. There is nothing more to do, and you can close this page.
            </p>
        @elseif ($memo->isDeclined())
            <p class="message-error" style="margin-top: 16px;">
                This memo was sent back to the student to be corrected. A new request will be emailed once it has been.
            </p>
        @elseif (! $open)
            <p class="message-error" style="margin-top: 16px;">
                This memo is waiting for {{ $memo->supervisor_name }} to sign first. You will be emailed again when it is your turn.
            </p>
        @else
            <form method="POST" action="{{ $urls['sign'] }}" style="margin-top: 16px;">
                @csrf

                @if ($isSupervisor)
                    <p class="queue-meta">The student entered the grant details. Correct them here if they are wrong.</p>

                    <label for="grant_name">Grant name</label>
                    <input type="text" name="grant_name" id="grant_name" required maxlength="150"
                           value="{{ old('grant_name', $memo->grant_name) }}" class="@error('grant_name') is-invalid @enderror">
                    @error('grant_name') <p class="field-error">{{ $message }}</p> @enderror

                    <label for="grant_cost_centre">Grant cost centre</label>
                    <input type="text" name="grant_cost_centre" id="grant_cost_centre" required maxlength="100"
                           value="{{ old('grant_cost_centre', $memo->grant_cost_centre) }}" class="@error('grant_cost_centre') is-invalid @enderror">
                    @error('grant_cost_centre') <p class="field-error">{{ $message }}</p> @enderror
                @endif

                <div class="checkbox-row" style="margin-top: 16px;">
                    <input type="checkbox" name="agrees" id="agrees" value="1" required>
                    <label for="agrees">
                        @if ($isSupervisor)
                            I request approval to use this grant for RM {{ number_format($memo->grant_amount, 2) }} of the fee.
                        @else
                            I agree to this grant being used for RM {{ number_format($memo->grant_amount, 2) }} of the fee.
                        @endif
                    </label>
                </div>
                @error('agrees') <p class="field-error">{{ $message }}</p> @enderror

                <label>Your signature</label>
                @include('norhanis::publication._signature_pad', ['name' => 'signature'])

                <button type="submit" style="margin-top: 16px;">Sign the memo</button>
            </form>

            <p class="queue-meta" style="margin-top: 16px;">
                Your signature, the date and the time are recorded with this application and printed on the memo.
            </p>

            <div class="card-divider"></div>

            <form method="POST" action="{{ $urls['decline'] }}">
                @csrf
                <p><b>Something is wrong with this memo?</b></p>
                <label for="decline_reason">What needs correcting</label>
                <input type="text" name="decline_reason" id="decline_reason" maxlength="500" required
                       value="{{ old('decline_reason') }}" class="@error('decline_reason') is-invalid @enderror">
                @error('decline_reason') <p class="field-error">{{ $message }}</p> @enderror
                <button type="submit" class="btn-secondary" style="margin-top: 8px;">Send it back to the student</button>
            </form>
        @endif
    </div>
</div>
@endsection
