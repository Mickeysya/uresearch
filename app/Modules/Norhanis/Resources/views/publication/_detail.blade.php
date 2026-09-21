{{-- Publication-specific lines inside the shared queue card. --}}
@php($detail = $details[$application->id] ?? null)

@if ($detail)
    <p><b>Type of Request:</b> {{ \App\Modules\Norhanis\Models\PublicationDetail::requestTypes()[$detail->type_of_request] ?? $detail->type_of_request }}</p>
    <p><b>Title of Paper:</b> {{ $detail->title_of_paper }}</p>
    <p><b>Conference / Journal:</b> {{ $detail->title_of_conference_journal }}</p>
    <p><b>Organizer / Publisher:</b> {{ $detail->organizer_publisher }}</p>
    <p><b>Dates:</b> {{ $detail->conference_start_date->format('j M Y') }} – {{ $detail->conference_end_date->format('j M Y') }}</p>
    <p><b>Fee:</b> {{ $detail->currency_type }} {{ number_format($detail->conference_journal_fee, 2) }}</p>
    <p><b>Cost Centre:</b> {{ $detail->cost_centre }}</p>
    <p><b>Letter of Undertaking Requested:</b> {{ $detail->wants_letter_of_undertaking ? 'Yes' : 'No' }}</p>

    <table class="recent-activity-table" style="margin-top: 12px;">
        <tr>
            <th>Author</th>
            <th>Designation</th>
            <th>Organisation</th>
            <th>Role / Contribution</th>
        </tr>
        @foreach ($detail->authors as $author)
            <tr>
                <td>{{ $author->author_name }}</td>
                <td>{{ $author->designation ?? '—' }}</td>
                <td>{{ $author->organisation ?? '—' }}</td>
                <td>{{ $author->role_contribution ?? '—' }}</td>
            </tr>
        @endforeach
    </table>
@else
    <p style="color: var(--text-grey);">Detail record missing for this application.</p>
@endif
