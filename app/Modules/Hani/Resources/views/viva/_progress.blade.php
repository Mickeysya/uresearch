{{-- The student's viva at a glance, shown above the locked checklist once a
     notice is filed. Expects: $progress (Hani\Support\VivaProgress). --}}
@php
    use App\Modules\Core\Models\Application;

    $notice = $progress->notice;
    $softbound = $progress->softbound;
    $detail = $progress->detail;
    $returnReason = $progress->softboundReturnReason();
    $canFileSoftbound = $progress->softboundBlockReason() === null;
@endphp

<h3>Your Viva Progress</h3>

<table class="data-table">
    <tbody>
        <tr>
            <th scope="row">Notice of Thesis Submission</th>
            <td>
                {{ $notice->status === Application::STATUS_APPROVED ? 'Verified by your Academic Executive' : 'Waiting for your Academic Executive' }}
                &middot; <a href="{{ route('applications.show', $notice) }}">View</a>
            </td>
        </tr>
        <tr>
            <th scope="row">Examiner Nomination</th>
            <td>{{ $progress->nominationLabel() }}</td>
        </tr>
        <tr>
            <th scope="row">Softbound Thesis (Form #019)</th>
            <td>
                {{ $progress->softboundLabel() }}
                @if ($softbound)
                    &middot; <a href="{{ route('applications.show', $softbound) }}">View</a>
                @endif
            </td>
        </tr>
        <tr>
            <th scope="row">Viva Voce</th>
            <td>
                @if ($detail?->isScheduled())
                    <b>{{ $detail->viva_at->format('l, j M Y, g:i a') }}</b> &middot; {{ $detail->venue }}
                    <br><span class="fstep-hint">Chairman: {{ $detail->chair_name }}</span>
                @else
                    Not scheduled yet. Your Academic Executive sets the date once your examiners are approved and your softbound thesis is verified.
                @endif
            </td>
        </tr>
        @if ($detail?->hasResult())
            @php $vivaReport = $notice->documents()->where('doc_type', 'Viva Report')->latest('id')->first(); @endphp
            <tr>
                <th scope="row">Result</th>
                <td>
                    <b>Category {{ $detail->resultLabel() }}</b>
                    @if ($vivaReport) &middot; <a href="{{ route('documents.show', $vivaReport) }}">Chairman's viva report</a> @endif
                    @if ($detail->corrections_accepted_at)
                        <br>Corrections accepted on {{ $detail->corrections_accepted_at->format('j M Y') }}. Your viva is complete.
                    @elseif ($detail->needsCorrections())
                        <br>Corrected final thesis due by <b>{{ $detail->corrections_due_at->format('j M Y') }}</b>
                        @if ($detail->corrections_submitted_at) (submitted {{ $detail->corrections_submitted_at->format('j M Y') }}, being checked) @endif
                    @elseif ($detail->resubmission_opens_at)
                        <br>Resubmit for re-examination between {{ $detail->resubmission_opens_at->format('j M Y') }} and {{ $detail->resubmission_closes_at->format('j M Y') }}.
                    @endif
                </td>
            </tr>
        @endif
    </tbody>
</table>

@if ($detail?->needsCorrections() && ! $detail->corrections_accepted_at)
    <p><a href="{{ route('viva.corrections.create') }}" class="btn">
        {{ $detail->corrections_submitted_at ? 'Upload a Newer Corrected Thesis' : 'Upload Corrected Final Thesis' }}
    </a></p>
@endif

@if ($returnReason)
    <p class="message-warning">Your Academic Executive returned your softbound thesis: {{ $returnReason }}</p>
@endif

@if ($canFileSoftbound)
    <p><a href="{{ route('viva.softbound.create') }}" class="btn">
        {{ $returnReason ? 'Resubmit Softbound Thesis' : 'Submit Softbound Thesis' }}
    </a></p>
@endif
