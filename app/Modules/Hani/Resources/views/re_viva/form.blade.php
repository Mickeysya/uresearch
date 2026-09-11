@extends('core::layouts.app')

@section('title', 'Re-viva Monitoring')

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Re-viva Monitoring</h2>
        <div class="card-divider"></div>

        @if ($blockReason)
            <div class="empty-state">{{ $blockReason }}</div>
        @else
            <p class="queue-meta">
                Upload your re-corrected thesis to start tracking. The moment you submit is
                logged as the formal resubmission timestamp — the 6-month correction and
                1-year hardbound deadlines are both counted from it.
            </p>

            <form method="POST" action="{{ route('reviva.store') }}" enctype="multipart/form-data">
                @csrf

                <label for="thesis">Re-corrected Thesis</label>
                <input type="file" name="thesis" id="thesis" required
                       class="@error('thesis') is-invalid @enderror">
                @error('thesis') <p class="field-error">{{ $message }}</p> @enderror

                <button type="submit">Submit Re-corrected Thesis</button>
            </form>
        @endif
    </div>
</div>
@endsection
