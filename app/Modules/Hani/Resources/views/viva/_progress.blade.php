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
                {{ $notice->status === Application::STATUS_APPROVED ? 'Acknowledged by your Academic Executive' : 'Waiting for your Academic Executive' }}
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
    </tbody>
</table>

@if ($returnReason)
    <p class="message-warning">Your Academic Executive returned your softbound thesis: {{ $returnReason }}</p>
@endif

@if ($canFileSoftbound)
    <p><a href="{{ route('viva.softbound.create') }}" class="btn">
        {{ $returnReason ? 'Resubmit Softbound Thesis' : 'Submit Softbound Thesis' }}
    </a></p>
@endif
