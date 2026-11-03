@extends('core::layouts.app')

@section('title', 'Viva Eligibility Checklist')

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Viva Eligibility Checklist"
        subtitle="Confirm each requirement from the Postgraduate Handbook before you submit your Notice of Thesis Submission. Your progress is saved, so you can come back to it." />

    @if ($level !== null)
        @include('hani::partials.module_stepper', ['tracker' => $tracker])
    @endif

    <div class="card card-wide">
        @if ($level === null)
            <p class="empty-state">
                Your programme is not recorded as MSc by Research or PhD, so the
                viva checklist does not apply. If that is wrong, ask CGS to
                correct the programme on your profile.
            </p>
        @else
            <p>
                Programme: <b>{{ auth()->user()->programme }}</b>
                ({{ $level === \App\Modules\Hani\Support\VivaChecklist::PHD ? 'PhD' : 'MSc by Research' }} requirements)
            </p>

            @if ($openNotice)
                @include('hani::viva._progress', ['progress' => $progress])

                <h3>Eligibility Checklist</h3>
                <p class="fstep-hint">Locked, because your notice was filed on it.</p>
            @endif

            <form method="POST" action="{{ route('viva.eligibility.save') }}" id="viva-checklist">
                @csrf

                <fieldset @disabled($openNotice)>
                    @foreach ($items as $column => $item)
                        <div class="checkbox-row">
                            <input type="checkbox" name="{{ $column }}" id="{{ $column }}" value="1"
                                   data-check
                                   @checked(old($column, $record?->$column))>
                            <label for="{{ $column }}">{{ $item['label'] }}</label>
                        </div>
                        <p class="fstep-hint">{{ $item['hint'] }}</p>
                        @error($column) <p class="field-error">{{ $message }}</p> @enderror
                    @endforeach

                    <p class="fstep-hint">
                        <span data-count>{{ collect($items)->keys()->filter(fn ($c) => $record?->$c)->count() }}</span>
                        of {{ count($items) }} requirements confirmed. These are self-declared;
                        CGS may ask for evidence.
                    </p>

                    <button type="submit" class="btn-secondary">Save Progress</button>
                    <button type="submit" name="continue" value="1" data-continue>
                        Submit Notice of Thesis Submission
                    </button>
                </fieldset>
            </form>
        @endif
    </div>
</div>

@push('scripts')
    <script @cspNonce>
        // Unlocks "Submit Notice" once every box is ticked. A convenience
        // only: VivaController::noticeGuard() is the actual rule.
        (function () {
            var form = document.getElementById('viva-checklist');
            if (!form) return;

            var boxes = form.querySelectorAll('[data-check]');
            var button = form.querySelector('[data-continue]');
            var count = form.querySelector('[data-count]');

            function sync() {
                var ticked = Array.prototype.filter.call(boxes, function (b) { return b.checked; }).length;
                count.textContent = ticked;
                button.disabled = ticked !== boxes.length;
            }

            boxes.forEach(function (b) { b.addEventListener('change', sync); });
            sync();
        })();
    </script>
@endpush
@endsection
