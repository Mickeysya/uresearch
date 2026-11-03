{{--
    The step tracker for Hani's modules. Same markup and classes as
    core::components.stepper, so it looks like every other module's stepper;
    the difference is that its steps can span several applications (the viva
    is a notice, a softbound and a nomination), worked out by
    Hani\Support\ModuleTracker from stored status.

    Expects: $tracker (see ModuleTracker). Optional: $compact (no "What to do
    now" line, for a row inside a list).
--}}
@if (! empty($tracker['title']))
    <p><b>{{ $tracker['title'] }}</b></p>
@endif

<div class="stepper">
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
