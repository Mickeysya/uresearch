@extends('core::layouts.app')

@section('title', 'Appeal #' . $application->id)

@section('content')
@php use App\Modules\Core\Models\Application; @endphp

<div class="card-container-inline">
    <x-core::page-header :title="'Study Candidacy Appeal #'.$application->id">
        <x-slot:subtitle>
            Submitted {{ $application->submitted_at?->format('j M Y, g:ia') }}
        </x-slot:subtitle>

        <a href="{{ route('candidacy.status') }}" class="btn-secondary">My candidacy</a>
    </x-core::page-header>

    <div class="app-item">
        <div class="app-item-header">
            <p><b>Progress</b></p>
            <x-core::status-badge :status="$application->status" />
        </div>

        <x-core::stepper :application="$application" />

        @if ($application->status === Application::STATUS_RETURNED)
            @php($lastReturn = $application->history->where('decision', 'returned')->last())
            <div class="message-warning">
                <b>Returned at the {{ $lastReturn?->stage_label }} stage. Your action is needed.</b>
                @if ($lastReturn?->remarks)
                    "{{ $lastReturn->remarks }}"
                @endif
            </div>
            <p><a href="{{ route('candidacy-appeal.edit', $application) }}" class="btn">Edit and resubmit</a></p>
        @elseif ($rejection = $application->rejection())
            <div class="rejection-note">
                Rejected at {{ $rejection->stage_label }}.
                @if ($rejection->remarks) Remarks: {{ $rejection->remarks }} @endif
                This is final, so no further appeals can be submitted for this candidacy.
            </div>
        @elseif ($application->status === Application::STATUS_APPROVED)
            <p class="message-success">
                Approved. Your candidacy now expires on
                <b>{{ $detail->new_expiry_date?->format('j M Y') ?? $detail->candidacy->candidacy_expiry_date->format('j M Y') }}</b>.
            </p>
        @endif
    </div>

    <div class="app-item">
        <p><b>What you submitted</b></p>
        @include('chloe::candidacy.appeal._sections', ['detail' => $detail])

        @if ($application->documents->isNotEmpty())
            <ul class="doc-list">
                @foreach ($application->documents as $doc)
                    <li><a href="{{ $doc->url() }}">{{ $doc->doc_type }}: {{ $doc->original_name }}</a></li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="app-item">
        <p><b>Decisions</b></p>
        <div class="history-trail">
            @forelse ($application->history as $entry)
                <div class="entry">
                    <b>{{ $entry->stage_label }}</b>: {{ $entry->decision }}
                    by {{ $entry->approver->name }}
                    on {{ $entry->created_at->format('j M Y, g:ia') }}
                    @if ($entry->remarks) "{{ $entry->remarks }}" @endif
                </div>
            @empty
                <div class="entry">No decisions recorded yet.</div>
            @endforelse
        </div>
    </div>
</div>
@endsection
