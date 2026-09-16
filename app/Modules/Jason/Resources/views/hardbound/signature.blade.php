@extends('core::layouts.app')

@section('title', 'My Signature')

@section('content')
<div class="card-container-inline">
    <div class="card">
        <h2>My Signature</h2>
        <div class="card-divider"></div>

        <p class="queue-meta">
            When you approve a Hardbound Submission as Supervisor or as Chairman of the Viva Voce
            Examination, this image is stamped into your block on the Confirmation of Correction to
            Thesis (UTP/CGS/017A) together with that day's date. Upload it once; replace it any time.
        </p>

        @if ($signature)
            <div class="app-item" style="margin-bottom: 18px;">
                <p><b>Signature on file</b> — {{ $signature->original_name }},
                    uploaded {{ $signature->updated_at->format('j M Y, g:ia') }}</p>
                <div style="border: 1px solid var(--border, #ddd); background: #fff; padding: 12px; display: inline-block;">
                    <img src="{{ route('hardbound.signature.image') }}?v={{ $signature->updated_at->timestamp }}"
                         alt="Your signature" style="max-height: 90px; max-width: 320px; display: block;">
                </div>
            </div>
        @else
            <div class="empty-state" style="margin-bottom: 18px;">
                No signature on file yet. You will not be able to approve a Hardbound Submission
                until one is uploaded.
            </div>
        @endif

        <form method="POST" action="{{ route('hardbound.signature.store') }}" enctype="multipart/form-data">
            @csrf

            <label for="signature">{{ $signature ? 'Replace signature' : 'Upload signature' }}</label>
            <input type="file" name="signature" id="signature" accept="image/png,image/jpeg" required
                   class="@error('signature') is-invalid @enderror">
            @error('signature') <p class="field-error">{{ $message }}</p> @enderror
            <p class="queue-meta" style="margin-top: -8px;">
                PNG or JPG, under 1 MB. Sign on white paper and photograph or scan it, or export
                from a signature app — a transparent PNG looks best on the form.
            </p>

            <button type="submit">Save Signature</button>
        </form>
    </div>
</div>
@endsection
