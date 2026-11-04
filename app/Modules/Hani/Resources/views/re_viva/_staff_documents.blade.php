{{-- Staff-only re-viva files for one round: letters (with when each was
     last sent, and "Resend letter"), UTP/CGS/024 reports, Confirmation of
     Correction. Never included on a student's page.
     Expects: $detail (with staffDocuments loaded or loadable). --}}
@php $staffDocs = $detail->staffDocuments->sortBy('id'); @endphp
@if ($staffDocs->isNotEmpty())
    <p class="fstep-hint">Staff-only files (never shown to the student; every download is logged):</p>
    <ul class="doc-list">
        @foreach ($staffDocs as $doc)
            <li>
                <a href="{{ route('reviva.staff-documents.download', $doc) }}">{{ $doc->kindLabel() }}@if ($doc->examiner): {{ $doc->examiner->name }}@endif</a>
                @if ($doc->kind === \App\Modules\Hani\Models\ReVivaStaffDocument::LETTER)
                    @if ($last = $doc->lastSent)
                        <span class="fstep-hint">last sent {{ $last->created_at->format('j M Y, g:i a') }}@if ($last->sentBy) by {{ $last->sentBy->name }}@endif{{ $last->kind === \App\Modules\Hani\Models\ReVivaLetterSend::RESEND ? ' (resent)' : '' }}@if ($last->link_expires_at); link works until {{ $last->link_expires_at->format('j M Y') }}@endif</span>
                    @elseif ($doc->emailed_at)
                        <span class="fstep-hint">emailed {{ $doc->emailed_at->format('j M Y') }}</span>
                    @endif
                    @if ($doc->email_skipped_reason)
                        <span class="status-badge rejected">Not emailed</span> <span class="fstep-hint">{{ $doc->email_skipped_reason }}</span>
                    @endif
                    {{-- AE of the department or Senior Executive, Under Panel Review, current main (ReVivaLetters::resendBlock). --}}
                    @if (auth()->user() && ! \App\Modules\Hani\Support\ReVivaLetters::resendBlock($doc, auth()->user()))
                        <form method="POST" action="{{ route('reviva.letters.resend', $doc) }}" style="display: inline">
                            @csrf
                            <button type="submit" class="btn-secondary">Resend letter</button>
                        </form>
                    @endif
                @endif
            </li>
        @endforeach
    </ul>
@endif
