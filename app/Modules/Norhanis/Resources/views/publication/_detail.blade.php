{{-- Publication-specific lines inside the shared queue card. --}}
@php($detail = $details[$application->id] ?? null)

@if ($detail)
    <p><b>Publication Title:</b> {{ $detail->publication_title }}</p>
    <p><b>Conference/Journal:</b> {{ $detail->conference_or_journal_name }}</p>
    <p><b>Event Date:</b> {{ $detail->event_date->format('j M Y') }}</p>
    <p><b>Location:</b> {{ $detail->location ?? '—' }}</p>
    <p><b>Funding Requested:</b> RM {{ number_format($detail->funding_amount_requested, 2) }}</p>
    <p><b>Letter of Undertaking Required:</b> {{ $detail->requires_letter_of_undertaking ? 'Yes' : 'No' }}</p>

    <table class="recent-activity-table" style="margin-top: 12px;">
        <tr>
            <th>Author</th>
            <th>Corresponding / Presenting</th>
        </tr>
        @foreach ($detail->authors as $author)
            <tr>
                <td>{{ $author->name }}</td>
                <td>{{ $author->is_corresponding_author ? 'Yes' : 'No' }}</td>
            </tr>
        @endforeach
    </table>
@else
    <p style="color: var(--text-grey);">Detail record missing for this application.</p>
@endif
