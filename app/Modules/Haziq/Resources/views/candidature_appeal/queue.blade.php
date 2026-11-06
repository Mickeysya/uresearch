@extends('core::layouts.app')

@section('title', 'Candidature Appeals')

@push('head')
    @include('haziq::partials.styles')
@endpush

@section('content')
<h2>Candidature Appeals</h2>
<p class="queue-meta">
    @if ($canDecide)
        Appeals against a Stage Gate termination. Only a Senior Director can approve or reject.
    @else
        Appeals against a Stage Gate termination — view only; only a Senior Director can decide.
    @endif
</p>

@forelse ($appeals as $appeal)
    <div class="app-item">
        <div class="app-item-header">
            <p><b>{{ $appeal->application->reference() }}</b> — {{ $appeal->application->student->name }}
                @if ($appeal->application->student->matric_no) ({{ $appeal->application->student->matric_no }}) @endif
            </p>
            <span style="color: var(--text-grey); font-size: 12.5px;">Submitted {{ $appeal->created_at->diffForHumans() }}</span>
        </div>

        <p><x-core::status-badge :status="$appeal->status" /></p>

        @php $letter = $appeal->application->documents->firstWhere('doc_type', 'Candidature Appeal Letter'); @endphp
        @if ($letter)
            <p><a href="{{ $letter->url() }}">View appeal letter — {{ $letter->original_name }}</a></p>
        @endif

        @if ($appeal->status !== 'pending')
            <p class="queue-meta">
                Decided by {{ $appeal->decidedBy?->name }} {{ $appeal->decided_at?->diffForHumans() }}.
                @if ($appeal->decision_notes)
                    Reason: {{ $appeal->decision_notes }}
                @endif
            </p>
        @elseif ($canDecide)
            @include('haziq::partials.decision_form', ['application' => $appeal->application, 'route' => route('candidature-appeal.decide', $appeal)])
        @else
            <p class="queue-meta">Awaiting a Senior Director's decision.</p>
        @endif
    </div>
@empty
    <div class="empty-state">No candidature appeals.</div>
@endforelse

<div style="margin-top: 16px;">{{ $appeals->links() }}</div>

@include('haziq::partials.confirm_scripts')
@endsection
