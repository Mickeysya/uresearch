{{-- The student's viva at a glance, shown above the locked checklist once a
     notice is filed. Expects: $progress (Hani\Support\VivaProgress). --}}
@php
    use App\Modules\Core\Models\Application;

    $notice = $progress->notice;
    $softbound = $progress->softbound;
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
                @if ($softbound === null)
                    Not submitted yet
                @elseif ($softbound->status === Application::STATUS_PENDING)
                    With your Academic Executive for checking
                @elseif ($softbound->status === Application::STATUS_APPROVED)
                    Verified
                @else
                    Returned for corrections
                @endif
                @if ($softbound)
                    &middot; <a href="{{ route('applications.show', $softbound) }}">View</a>
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
