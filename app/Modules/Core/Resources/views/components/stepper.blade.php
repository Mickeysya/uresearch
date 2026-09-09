@props(['application'])

{{--
    Norhanis' stepper markup, with the state maths moved into
    WorkflowEngine::progress(). Her render_stepper() worked out completed /
    current / rejected inline with array_search and a four-branch condition;
    doing it in the engine means the student's stepper and the approver's
    routing can never disagree about where an application is.
--}}
@php($steps = $application->progress())

<div class="stepper">
    @foreach ($steps as $i => $step)
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
            <div class="step-label">{{ $step['stage']->label }}</div>
        </div>

        @if (! $loop->last)
            <div class="step-line {{ $step['state'] === 'completed' ? 'completed' : '' }}"></div>
        @endif
    @endforeach
</div>
