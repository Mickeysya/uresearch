@extends('core::layouts.app')

@section('title', 'RPD Appeal / Extension')

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>RPD Appeal / Extension</h2>
        <div class="card-divider"></div>

        @if (! $candidacy)
            <div class="empty-state">
                You have no active RPD candidacy to appeal. Contact CGS if you believe this is a mistake.
            </div>
        @else
            <p class="queue-meta">
                Your current RPD deadline is <b>{{ $candidacy->deadline->format('j M Y') }}</b>
                ({{ $candidacy->programme === \App\Modules\Norhanis\Models\Candidacy::PROGRAMME_PHD ? 'PhD' : 'Masters' }},
                {{ $candidacy->study_mode === \App\Modules\Norhanis\Models\Candidacy::STUDY_MODE_PART_TIME ? 'Part-Time' : 'Full-Time' }}).
            </p>

            <form method="POST" action="{{ route('rpd-appeal.store') }}">
                @csrf

                <label for="reason">Reason for Appeal</label>
                <textarea name="reason" id="reason" rows="5" required
                          class="@error('reason') is-invalid @enderror">{{ old('reason') }}</textarea>
                @error('reason') <p class="field-error">{{ $message }}</p> @enderror

                <label for="requested_extension_months">Extension Requested</label>
                <select name="requested_extension_months" id="requested_extension_months" required
                        class="@error('requested_extension_months') is-invalid @enderror">
                    <option value="">Select months…</option>
                    @foreach (range(1, \App\Modules\Norhanis\Models\Candidacy::MAX_APPEAL_EXTENSION_MONTHS) as $months)
                        <option value="{{ $months }}" @selected(old('requested_extension_months') == $months)>
                            {{ $months }} {{ $months === 1 ? 'month' : 'months' }}
                        </option>
                    @endforeach
                </select>
                @error('requested_extension_months') <p class="field-error">{{ $message }}</p> @enderror
                <p class="queue-meta" style="margin-top: 4px;">
                    Up to {{ \App\Modules\Norhanis\Models\Candidacy::MAX_APPEAL_EXTENSION_MONTHS }} months, added to your current deadline
                    if the Dean of PGR approves.
                </p>

                <button type="submit" style="margin-top: 20px;">Submit Appeal</button>
            </form>
        @endif
    </div>
</div>
@endsection
