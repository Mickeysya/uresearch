{{-- Candidacy-appeal lines inside the shared queue row. --}}
@php($detail = $details[$application->id] ?? null)

@if ($detail)
    @include('chloe::candidacy.appeal._sections', ['detail' => $detail])
@else
    <p class="queue-meta">Detail record missing for this appeal.</p>
@endif
