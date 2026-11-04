{{-- Staff-only re-viva files for one round: letters, UTP/CGS/024 reports,
     Confirmation of Correction. Never included on a student's page.
     Expects: $detail (with staffDocuments loaded or loadable). --}}
@php $staffDocs = $detail->staffDocuments->sortBy('id'); @endphp
@if ($staffDocs->isNotEmpty())
    <p class="fstep-hint">Staff-only files (never shown to the student; every download is logged):</p>
    <ul class="doc-list">
        @foreach ($staffDocs as $doc)
            <li>
                <a href="{{ route('reviva.staff-documents.download', $doc) }}">{{ $doc->kindLabel() }}@if ($doc->examiner): {{ $doc->examiner->name }}@endif</a>
                @if ($doc->kind === \App\Modules\Hani\Models\ReVivaStaffDocument::LETTER)
                    @if ($doc->emailed_at)
                        <span class="fstep-hint">emailed {{ $doc->emailed_at->format('j M Y') }}</span>
                    @elseif ($doc->email_skipped_reason)
                        <span class="status-badge rejected">Not emailed</span> <span class="fstep-hint">{{ $doc->email_skipped_reason }}</span>
                    @endif
                @endif
            </li>
        @endforeach
    </ul>
@endif
