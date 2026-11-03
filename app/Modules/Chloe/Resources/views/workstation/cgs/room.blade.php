@extends('core::layouts.app')

@section('title', 'Workstation: ' . $workstationLocation->name)

@section('content')
    @include('chloe::workstation._assistant-widget')

{{-- Scoped to this page -- nothing here touches a Core/shared stylesheet.
     The seat card itself is the shared _seat-card partial (see its own
     doc) -- this page's <style> only covers the list wrapper, same
     "page owns its own CSS" convention as every other Workstation view. --}}
<style>
    .cgs-seat-list { display: flex; flex-direction: column; gap: 16px; margin-top: 12px; }

    {{--
        Final polish PART 4.5 (found live): .cgs-seat-image (204px,
        flex-shrink:0) + .cgs-seat-button button (132px, flex-shrink:0) +
        their 16px gaps + the card's own 40px padding need over 400px
        before the text column gets any room at all -- guaranteed to
        overflow a narrow (390px) viewport regardless of content.
        flex-wrap lets the image/text/button wrap onto their own lines
        there instead, with no change to the wide layout (nothing wraps
        once there is enough room).
    --}}
    .cgs-seat-card {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        min-height: 178px;
        border-radius: 20px;
        background: var(--surface-sunken, #F8F9FB);
        padding: 14px 20px;
        gap: 16px;
    }
    .cgs-seat-image { width: 204px; height: 153px; object-fit: cover; border-radius: 8px; flex-shrink: 0; background: #fff; }
    {{--
        Found live: .cgs-seat-text itself had no max-width, so once the
        card wraps (above), this column can still grow past the card's
        own available width to fit a single long badge's nowrap text
        (e.g. "Awaiting Key Return") -- max-width:100% plus letting the
        shared .status-badge class's own white-space:nowrap wrap here
        (scoped to this page only, not the shared class itself) keeps
        every badge inside the card at any width.
    --}}
    .cgs-seat-text { flex: 1 1 160px; min-width: 0; max-width: 100%; }
    .cgs-seat-badges .status-badge { white-space: normal; max-width: 100%; box-sizing: border-box; }
    .cgs-seat-title { font-weight: 700; font-size: 17px; }
    .cgs-seat-sub { color: var(--text-grey); font-size: 13px; margin-top: 2px; }
    .cgs-seat-badges { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }
    .cgs-seat-sub-label { margin-top: 8px; font-size: 12px; color: var(--text-grey); text-transform: uppercase; letter-spacing: .03em; font-weight: 600; }
    .cgs-seat-lines { margin-top: 4px; min-width: 0; max-width: 360px; }
    .cgs-seat-line { font-size: 14px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .cgs-seat-line:nth-child(2), .cgs-seat-line:nth-child(3) { font-size: 13px; font-weight: 400; color: var(--text-grey); margin-top: 2px; }
    .cgs-seat-button { flex-shrink: 0; }
    .cgs-seat-button button { width: 132px; height: 50px; margin: 0; padding: 0; }
</style>

@include('chloe::workstation._live', ['liveKind' => 'cgs'])

<div class="card-container-inline">
    <x-core::page-header title="{{ $workstationLocation->name }}" :back="route('workstation.cgs.block', $workstationLocation->block)">
        <a href="{{ route('workstation.cgs.operations') }}"><button type="button">Home</button></a>
    </x-core::page-header>

    <div class="card card-wide" data-live-region>
        <p style="color: var(--text-grey); font-size: 13px;">
            {{ $workstationLocation->room_code }} &middot; Block {{ $workstationLocation->block }} &middot;
            <span class="status-badge {{ $workstationLocation->gender === 'female' ? 'rejected' : 'pending' }}">{{ $workstationLocation->genderLabel() }}</span>
        </p>

        <div class="card-divider"></div>

        @if ($seats->isEmpty())
            <div class="empty-state">This room has no seats in the catalogue yet.</div>
        @else
            <div class="cgs-seat-list">
                @foreach ($seats as $row)
                    @include('chloe::workstation.cgs._seat-card', ['row' => $row])
                @endforeach
            </div>
        @endif
    </div>
</div>

<script @cspNonce>
    document.querySelectorAll('[data-browser-back]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            if (window.history.length > 1 && document.referrer) {
                event.preventDefault();
                window.history.back();
            }
        });
    });
</script>
@endsection
