{{-- Publication-specific lines inside the shared queue card. --}}
@php($detail = $details[$application->id] ?? null)

@if ($detail)
    <p><b>Type of Request:</b> {{ $detail->requestTypeLabel() }}</p>
    <p><b>Internal Reviewers Required:</b> {{ \App\Modules\Norhanis\Models\PublicationDetail::reviewersRequired($detail->type_of_request, $detail->conference_scope) }}</p>
    <p><b>Title of Paper:</b> {{ $detail->title_of_paper }}</p>
    <p><b>Conference / Journal:</b> {{ $detail->title_of_conference_journal }}</p>
    <p><b>Organizer / Publisher:</b> {{ $detail->organizer_publisher }}</p>
    @if ($detail->venue) <p><b>Venue:</b> {{ $detail->venue }}</p> @endif
    <p><b>Dates:</b> {{ $detail->conference_start_date->format('j M Y') }} – {{ $detail->conference_end_date->format('j M Y') }}</p>
    <p><b>Fee:</b> {{ $detail->currency_type }} {{ number_format($detail->conference_journal_fee, 2) }}</p>
    <p><b>Cost Centre to be Utilized:</b> {{ $detail->costCentreLabel() }}</p>
    @if ($detail->cost_centre === 'split_memo')
        <p style="color: var(--text-grey);">CGS will email the split memo template; the supervisor completes it outside UResearch.</p>
    @endif
    @include('norhanis::pack._buttons', ['application' => $application])

    <table class="recent-activity-table" style="margin-top: 12px;">
        <tr>
            <th>Author</th>
            <th>Designation</th>
            <th>Organisation</th>
            <th>Role / Contribution</th>
            <th>Certification Signed</th>
        </tr>
        @foreach ($detail->authors as $author)
            <tr>
                <td>{{ $author->author_name }}</td>
                <td>{{ $author->designation ?? '—' }}</td>
                <td>{{ $author->organisation ?? '—' }}</td>
                <td>{{ $author->role_contribution ?? '—' }}</td>
                <td>{{ $author->signed_at?->format('j M Y, g:ia') ?? '—' }}</td>
            </tr>
        @endforeach
    </table>

    {{-- Every reviewer ever asked, replaced ones included, so a rejection
         the student moved past is still in front of the approver. --}}
    @if ($detail->evaluations->isNotEmpty())
        <table class="recent-activity-table" style="margin-top: 12px;">
            <tr>
                <th>Reviewer</th>
                <th>Ratings (Obj / Orig / Meth / Res)</th>
                <th>Recommendation</th>
                <th>Signed</th>
                <th>Student's Response</th>
            </tr>
            @foreach ($detail->evaluations as $evaluation)
                <tr>
                    <td>
                        {{ $evaluation->position }}. {{ $evaluation->reviewer_name }}
                        @if ($evaluation->isReplaced()) <span style="color: var(--text-grey);">(replaced)</span> @endif
                    </td>
                    <td>
                        {{ $evaluation->isSubmitted() ? implode(' / ', [$evaluation->objective_rating, $evaluation->originality_rating, $evaluation->methodology_rating, $evaluation->results_rating]) : '—' }}
                    </td>
                    <td>{{ $evaluation->isSubmitted() ? $evaluation->recommendationLabel() : ($evaluation->isDeclined() ? 'Unable to review' : '—') }}</td>
                    <td>{{ $evaluation->signed_at?->format('j M Y, g:ia') ?? '—' }}</td>
                    <td>{{ $evaluation->response ?? '—' }}</td>
                </tr>
            @endforeach
        </table>
    @endif
@else
    <p style="color: var(--text-grey);">Detail record missing for this application.</p>
@endif
