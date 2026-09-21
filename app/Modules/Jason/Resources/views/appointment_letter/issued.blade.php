@extends('core::layouts.app')

@section('title', 'Issued Appointments')

@section('content')
<div class="card-container-inline">
    <x-core::page-header title="Issued Appointments" />

    <div class="card card-wide">
        <p class="queue-meta">
            Every nomination the Dean has approved, and whether each examiner's pack
            actually reached the mail server. A pack is marked delivered only once the
            mail transport accepts it; if a send fails, it stays undelivered here and
            can be sent again. A resend posts the same documents the Dean approved.
        </p>

        <p class="queue-meta">
            Appointments approved before delivery tracking existed have nothing recorded
            either way, so they read as not delivered. Resending one is harmless: the
            examiner receives the same approved documents again.
        </p>

        @if ($applications->isEmpty())
            <div class="empty-state">No appointments have been approved yet.</div>
        @else
            @php
                $pending = $examiners->flatten()->whereNull('pack_sent_at');
            @endphp

            @if ($pending->isNotEmpty())
                <div class="message-warning">
                    {{ $pending->count() }} {{ Str::plural('pack', $pending->count()) }} not yet delivered.
                    Resend below, or wait: a queued mail may still be in flight.
                </div>
            @endif

            <div style="max-height: 460px; overflow-y: auto; border: 1px solid var(--border, #ddd);">
            <table class="recent-activity-table" style="margin: 0;">
                <thead style="position: sticky; top: 0; background: #fff; z-index: 1;">
                    <tr>
                        <th>#</th><th>Candidate</th><th>Examiner</th>
                        <th>Appointed</th><th>Pack</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($applications as $application)
                        @forelse ($examiners[$application->id] ?? [] as $examiner)
                            <tr>
                                <td>{{ $application->id }}</td>
                                <td>{{ $application->student?->name ?? '—' }}</td>
                                <td>
                                    {{ $examiner->examiner_name }}
                                    <br><small>{{ $examiner->typeLabel() }} · {{ $examiner->examiner_email }}</small>
                                </td>
                                <td>{{ $examiner->appointed_at?->format('j M Y') ?? '—' }}</td>
                                <td>
                                    @if ($examiner->packSent())
                                        <span style="color: #1c7c3f;">Delivered</span>
                                        <br><small>{{ $examiner->pack_sent_at->format('j M Y, g:ia') }}</small>
                                    @else
                                        <span style="color: #a0521d;">Not delivered</span>
                                    @endif
                                </td>
                                <td>
                                    @unless ($examiner->packSent())
                                        <form method="POST"
                                              action="{{ route('appointment-letter.resend', [$application, $examiner]) }}">
                                            @csrf
                                            <button type="submit" style="padding: 4px 10px; font-size: 12px;">Resend</button>
                                        </form>
                                    @endunless
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td>{{ $application->id }}</td>
                                <td>{{ $application->student?->name ?? '—' }}</td>
                                <td colspan="4"><small>No examiners recorded on this nomination.</small></td>
                            </tr>
                        @endforelse
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>
</div>
@endsection
