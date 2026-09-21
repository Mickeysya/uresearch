@extends('core::layouts.app')

@section('title', 'Appeal #' . $application->id)

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Study Candidacy Appeal #{{ $application->id }}</h2>
        <p class="queue-meta">
            <x-core::status-badge :status="$application->status" />
            &nbsp;Submitted {{ $application->submitted_at?->format('j M Y, g:ia') }}
        </p>
        <div class="card-divider"></div>

        <x-core::stepper :application="$application" />

        @if ($application->status === \App\Modules\Core\Models\Application::STATUS_RETURNED)
            @php($lastReturn = $application->history->where('decision', 'returned')->last())
            <div class="empty-state" style="text-align:left;">
                <b>Returned at the {{ $lastReturn?->stage_label }} stage — action needed</b>
                @if ($lastReturn?->remarks)
                    <p style="margin-top:6px;">"{{ $lastReturn->remarks }}"</p>
                @endif
                <p style="margin-top:10px;">
                    <a href="{{ route('candidacy-appeal.edit', $application) }}"><button type="submit">Edit and Resubmit</button></a>
                </p>
            </div>
        @elseif ($rejection = $application->rejection())
            <div class="rejection-note">
                Rejected at {{ $rejection->stage_label }}.
                @if ($rejection->remarks) Remarks: {{ $rejection->remarks }} @endif
                <p style="margin-top:6px;">This appeal was rejected — no further appeals can be submitted for this candidacy.</p>
            </div>
        @elseif ($application->status === \App\Modules\Core\Models\Application::STATUS_APPROVED)
            <div class="empty-state" style="text-align:left;">
                Approved. Your candidacy expiry date is now
                <b>{{ $detail->new_expiry_date?->format('j M Y') ?? $detail->candidacy->candidacy_expiry_date->format('j M Y') }}</b>.
            </div>
        @endif

        <h3 style="margin-top:20px;">What you submitted</h3>
        @include('chloe::candidacy.appeal._sections', ['detail' => $detail])

        @if ($application->documents->isNotEmpty())
            <ul class="doc-list">
                @foreach ($application->documents as $doc)
                    <li><a href="{{ $doc->url() }}">{{ $doc->doc_type }} — {{ $doc->original_name }}</a></li>
                @endforeach
            </ul>
        @endif

        <div class="history-trail" style="margin-top:16px;">
            @forelse ($application->history as $entry)
                <div class="entry">
                    <b>{{ $entry->stage_label }}</b> — {{ $entry->decision }}
                    by {{ $entry->approver->name }}
                    on {{ $entry->created_at->format('j M Y, g:ia') }}
                    @if ($entry->remarks) — "{{ $entry->remarks }}" @endif
                </div>
            @empty
                <div class="entry">No decisions recorded yet.</div>
            @endforelse
        </div>

        <p style="margin-top:16px;"><a href="{{ route('applications.index') }}">&larr; Back to all applications</a></p>
    </div>
</div>
@endsection
