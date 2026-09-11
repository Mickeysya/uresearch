@extends('core::layouts.app')

@section('title', 'File an Attendance Appeal')

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Attendance Appeal</h2>
        <div class="card-divider"></div>

        <form method="POST" action="{{ route('attendance-appeal.store') }}" enctype="multipart/form-data">
            @csrf

            @if ($latestAtRisk)
                <input type="hidden" name="attendance_record_id" value="{{ $latestAtRisk->id }}">
                <p class="queue-meta">
                    This appeal will reference your record for the period ending
                    {{ $latestAtRisk->period_end->format('j M Y') }} ({{ $latestAtRisk->percentage }}%).
                </p>
            @endif

            <label for="reason">Reason for Appeal</label>
            <textarea name="reason" id="reason" rows="4" required
                      class="@error('reason') is-invalid @enderror">{{ old('reason') }}</textarea>
            @error('reason') <p class="field-error">{{ $message }}</p> @enderror

            <label for="supporting_document">Supporting Document <span style="color: var(--text-grey)">(optional)</span></label>
            <input type="file" name="supporting_document" id="supporting_document"
                   class="@error('supporting_document') is-invalid @enderror">
            @error('supporting_document') <p class="field-error">{{ $message }}</p> @enderror

            <button type="submit">Submit Appeal</button>
        </form>
    </div>
</div>
@endsection
