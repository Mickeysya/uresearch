@props(['card'])

{{--
    One application on Track My Applications. $card comes from
    Services\ApplicationTracker::card(); nothing here names a module.

    Status is always words (and an icon), never colour alone. The stepper
    is an ordered list a screen reader announces as "Step 3 of 4, current:
    CGS Staff, Waiting 20 working days". It runs across the card on a wide
    screen and down it on a phone (tracking.css).
--}}
@php
    $application = $card['application'];
    $badge = [
        'pending' => ['Pending', 'clock'],
        'approved' => ['Approved', 'check'],
        'rejected' => ['Rejected', 'cross'],
        'returned' => ['Returned', 'warning'],
    ][$card['status']] ?? [Str::headline($card['status']), 'info'];
    $stateWords = ['completed' => 'done', 'current' => 'current', 'upcoming' => 'next', 'rejected' => 'rejected', 'skipped' => 'not required'];
    $titleId = 'trk-title-'.$application->id;
@endphp

<article class="trk-card {{ $card['needs_student'] ? 'trk-card-needs' : '' }}" aria-labelledby="{{ $titleId }}">
    <span class="trk-icon trk-tone-{{ $card['category']['tone'] }}" aria-hidden="true">
        @include('core::dashboard.partials.icon', ['name' => $card['category']['icon']])
    </span>

    <div class="trk-body">
        <div class="trk-head">
            <div class="trk-head-text">
                <p class="trk-meta">
                    <span class="trk-module trk-tone-{{ $card['category']['tone'] }}">{{ $card['label'] }}</span>
                    <span class="trk-ref">{{ $card['reference'] }}</span>
                    @if ($card['document_reference'])
                        <span class="trk-ref">· Letter {{ $card['document_reference'] }}</span>
                    @endif
                </p>
                <h3 id="{{ $titleId }}" class="trk-title">{{ $card['title'] }}</h3>
                <p class="trk-sub">
                    Submitted {{ ($application->submitted_at ?? $application->created_at)?->format('j M Y') }}
                    @if ($card['expected'])
                        · <span class="trk-expected">Expected decision by {{ $card['expected']->format('j M') }}</span>
                    @endif
                </p>
            </div>

            <div class="trk-badges">
                <span class="trk-badge trk-badge-{{ $card['status'] }}">
                    <span aria-hidden="true">@include('core::dashboard.partials.icon', ['name' => $badge[1]])</span>
                    {{ $badge[0] }}
                </span>
                @if ($card['needs_student'])
                    <span class="trk-badge trk-badge-needs">Needs you</span>
                @endif
            </div>
        </div>

        @if ($card['steps'])
            <ol class="trk-steps" aria-label="Progress">
                @foreach ($card['steps'] as $i => $step)
                    <li class="trk-step is-{{ $step['state'] }}">
                        <span class="trk-dot" aria-hidden="true">
                            @if ($step['state'] === 'completed')
                                @include('core::dashboard.partials.icon', ['name' => 'check'])
                            @elseif ($step['state'] === 'rejected')
                                @include('core::dashboard.partials.icon', ['name' => 'cross'])
                            @endif
                        </span>
                        <span class="trk-step-text">
                            <span class="trk-sr">Step {{ $i + 1 }} of {{ count($card['steps']) }}, {{ $stateWords[$step['state']] ?? $step['state'] }}:</span>
                            <span class="trk-step-label">{{ $step['label'] }}</span>
                            <span class="trk-step-detail">{{ $step['detail'] }}</span>
                            @if ($step['overdue'])
                                <span class="trk-late">Taking longer than usual</span>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ol>
        @endif

        <div class="trk-foot">
            <p class="trk-sentence">{{ $card['sentence'] }}</p>
            <div class="trk-actions">
                @foreach ($card['actions'] as $action)
                    <a href="{{ $action['url'] }}" class="{{ $action['primary'] ? 'btn' : 'btn-secondary' }}">{{ $action['label'] }}</a>
                @endforeach
            </div>
        </div>
    </div>
</article>
