{{-- Publication-specific lines inside the shared queue card. --}}
@php($detail = $details[$application->id] ?? null)

@if ($detail)
    <p><b>Type of Request:</b> {{ $detail->requestTypeLabel() }}</p>
    <p><b>Internal Reviewers Required:</b> {{ \App\Modules\Norhanis\Models\PublicationDetail::reviewersRequired($detail->type_of_request, $detail->conference_scope) }}</p>
    <p><b>Title of Paper:</b> {{ $detail->title_of_paper }}</p>
    <p><b>{{ $detail->titleLabel() }}:</b> {{ $detail->title_of_conference_journal }}</p>
    <p><b>Organizer / Publisher:</b> {{ $detail->organizer_publisher }}</p>
    <p><b>{{ $detail->feeLabelText() }}:</b> {{ $detail->currency_type }} {{ number_format($detail->conference_journal_fee, 2) }}</p>
    {{-- Travel Details: a conference only. --}}
    @if ($rows = $detail->travelDetailRows())
        <p style="margin-top: 8px;"><b>Travel Details</b></p>
        @foreach ($rows as $label => $value)
            <p><b>{{ $label }}:</b> {{ $value }}</p>
        @endforeach
    @endif
    <p><b>Cost Centre to be Utilized:</b> {{ $detail->costCentreLabel() }}</p>
    @if ($detail->usesCostCentre(\App\Modules\Norhanis\Models\PublicationDetail::SPLIT_MEMO))
        <p style="color: var(--text-grey);">On final approval the student is emailed the CGS &amp; Grant - Split Memo template and sends the signed memo to CGS.</p>
    @elseif ($detail->usesCostCentre(\App\Modules\Norhanis\Models\PublicationDetail::LETTER_OF_UNDERTAKING))
        <p style="color: var(--text-grey);">Letter of Undertaking: processed directly by CGS.</p>
    @endif
    @include('norhanis::partials.stage-estimate', ['application' => $application])
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

    @include('norhanis::pack._inline_documents', ['application' => $application])
@else
    <p style="color: var(--text-grey);">Detail record missing for this application.</p>
@endif

@include('norhanis::partials.decision-form', ['application' => $application, 'route' => route($decideRoute, $application)])
