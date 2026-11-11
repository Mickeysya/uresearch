{{--
    The step tracker for Hani's modules. Same markup and classes as
    core::components.stepper, so it looks like every other module's stepper;
    the difference is that its steps can span several applications (the viva
    is a notice, a softbound and a nomination), worked out by
    Hani\Support\ModuleTracker from stored status.

    Expects: $tracker (see ModuleTracker). Optional: $compact (no "What to do
    now" line, for a row inside a list); $hideTitle (the page's own heading
    already says it); $inline (one line of context, for a
    page whose form already has core::partials.form-stepper's step bar) with
    $journey as its lead-in when the tracker has no title ("Your viva").

    Why $inline: one page, one stepper. A circles tracker stacked above the
    form's own step bar read as one process with two answers to "where am
    I?" -- design systems (Ontario's step indicator) say a section inside a
    multi-step process does not get a second indicator. So on a form page the
    journey becomes a breadcrumb-style line, on a solid strip so it stays
    legible over the background photograph.
--}}
@if (! empty($inline))
    @once
        <style>
            .journey-line { display: flex; flex-wrap: wrap; align-items: baseline; gap: var(--space-2) var(--space-3);
                margin: 0 0 var(--space-4); padding: var(--space-3) var(--space-5);
                background: var(--surface); border-radius: var(--radius-md); font-size: var(--text-sm); }
            .journey-line-lead { color: var(--text-grey); }
            .journey-line ol { display: flex; flex-wrap: wrap; gap: var(--space-1) var(--space-2); margin: 0; padding: 0; list-style: none; }
            .journey-line li { color: var(--text-grey); }
            .journey-line li + li::before { content: '\203A'; margin-right: var(--space-2); color: var(--border-strong); }
            .journey-line li.completed { color: var(--text-body); }
            .journey-line li.current { color: var(--text-dark); font-weight: var(--weight-semi); }
            .journey-line li.rejected { color: var(--danger-fg); }
            .journey-line a { color: inherit; }
        </style>
    @endonce
    @php($lead = $tracker['title'] ?? ($journey ?? 'Your progress'))
    <nav class="journey-line" aria-label="{{ $lead }}">
        <span class="journey-line-lead">{{ $lead }}</span>
        <ol>
            @foreach ($tracker['steps'] as $step)
                <li class="{{ $step['state'] }}" @if ($step['state'] === 'current') aria-current="step" @endif>
                    @if ($step['url'])
                        <a href="{{ $step['url'] }}">{{ $step['label'] }}</a>
                    @else
                        {{ $step['label'] }}
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>
@else
@if (! empty($tracker['title']) && empty($hideTitle))
    <p><b>{{ $tracker['title'] }}</b></p>
@endif

@php($dense = count($tracker['steps']) > 7)
@if ($dense)
    @once
        {{-- Core's steps keep a 100px minimum each, so a long tracker (the
             re-viva's ten steps) ran off the card. Here the steps share the
             width instead, with smaller circles and shorter lines; in a
             narrow table cell they stop at 56px and the row scrolls, as
             Core's stepper does. --}}
        <style>
            .stepper.stepper-dense .step { min-width: 56px; flex: 1 1 0; }
            .stepper.stepper-dense .step-circle { width: 26px; height: 26px; font-size: 12px; }
            .stepper.stepper-dense .step-label { max-width: none; font-size: 11px; padding: 0 2px; }
            .stepper.stepper-dense .step-line { flex: 0 1 18px; min-width: 6px; margin-top: 13px; }
        </style>
    @endonce
@endif

<div class="stepper{{ $dense ? ' stepper-dense' : '' }}">
    @foreach ($tracker['steps'] as $i => $step)
        <div class="step {{ $step['state'] }}">
            <div class="step-circle">
                @if ($step['state'] === 'completed')
                    &#10003;
                @elseif ($step['state'] === 'rejected')
                    &#10005;
                @else
                    {{ $i + 1 }}
                @endif
            </div>
            <div class="step-label">
                {{-- Completed steps open their page read-only; upcoming ones are not links. --}}
                @if ($step['url'])
                    <a href="{{ $step['url'] }}">{{ $step['label'] }}</a>
                @else
                    {{ $step['label'] }}
                @endif
            </div>
        </div>

        @if (! $loop->last)
            <div class="step-line {{ $step['state'] === 'completed' ? 'completed' : '' }}"></div>
        @endif
    @endforeach
</div>

@if (empty($compact) && ! empty($tracker['now']))
    <p class="message-success"><b>What to do now:</b> {{ $tracker['now'] }}</p>
@endif
@endif
