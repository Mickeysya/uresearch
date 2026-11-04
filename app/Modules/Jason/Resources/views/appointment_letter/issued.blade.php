@extends('core::layouts.app')

@section('title', 'Issued Appointments')

@section('content')
@php
    $undelivered = $examiners->flatten()->whereNull('pack_sent_at');

    // Two different problems, and only one of them is fixed by resending.
    $blocked = $undelivered->filter(fn ($e) => (bool) $e->email_problem);
    $pending = $undelivered->reject(fn ($e) => (bool) $e->email_problem);
@endphp
<div class="card-container-inline">
    <x-core::page-header
        title="Issued Appointments"
        subtitle="Every nomination the Dean has approved, and whether each examiner's pack reached the mail server. A pack is marked delivered only once the mail transport accepts it; if a send fails, it stays undelivered here and can be sent again. A resend posts the same documents the Dean approved." />

    @if ($applications->isEmpty())
        <div class="empty-state">
            <p>No appointments have been approved yet.</p>
        </div>
    @else
        <p class="queue-meta">
            Appointments approved before delivery tracking existed have nothing recorded
            either way, so they read as not delivered. Resending one is harmless: the
            examiner receives the same approved documents again.
        </p>

        @if ($pending->isNotEmpty())
            <div class="message-warning">
                {{ $pending->count() }} {{ Str::plural('pack', $pending->count()) }} not yet delivered.
                Resend below, or wait; a queued mail may still be in flight.
            </div>
        @endif

        @if ($blocked->isNotEmpty())
            <div class="message-warning">
                {{ $blocked->count() }} {{ Str::plural('pack', $blocked->count()) }} cannot be
                emailed at all: the examiner records hold no usable address. Resending will not
                help. Put the address right in the Examiner Pool, or send the pack by post from
                the nomination's own page.
            </div>
        @endif

        <div class="card card-wide" style="max-height: 520px; overflow-y: auto;">
            <table class="data-table" style="margin: 0;">
                <thead>
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
                                    <br><small>{{ $examiner->typeLabel() }} ·
                                        {{ $examiner->examiner_email ?: 'no address on record' }}</small>
                                </td>
                                <td>{{ $examiner->appointed_at?->format('j M Y') ?? '—' }}</td>
                                <td>
                                    @if ($examiner->packSent())
                                        <span class="status-badge approved">Delivered</span>
                                        <br><small>{{ $examiner->pack_sent_at->format('j M Y, g:ia') }}</small>
                                    @elseif ($examiner->email_problem)
                                        {{-- Nothing was attempted, so "not delivered" would be
                                             misleading: the officer would resend and resend. --}}
                                        <span class="status-badge returned">Cannot email</span>
                                        <br><small>{{ $examiner->email_problem }}</small>
                                    @else
                                        <span class="status-badge rejected">Not delivered</span>
                                    @endif
                                </td>
                                <td>
                                    @if (! $examiner->packSent() && ! $examiner->email_problem)
                                        <form method="POST"
                                              action="{{ route('appointment-letter.resend', [$application, $examiner]) }}">
                                            @csrf
                                            <button type="submit" style="padding: 4px 10px; font-size: 12px;">Resend</button>
                                        </form>
                                    @endif
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
@endsection
