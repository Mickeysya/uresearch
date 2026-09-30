@extends('core::layouts.app')

@section('title', 'Sign the Confirmation of Correction')

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Confirmation of Correction to Thesis"
        subtitle="UTP/CGS/017A: the examiner's signature" />

    <div class="card card-wide">
        @if ($signed)
            <div class="empty-state">
                <p><b>This form has already been signed.</b></p>
                <p class="queue-meta">
                    {{ $signed->examiner?->examiner_name ?? 'An examiner' }} signed it on
                    {{ $signed->signed_at?->format('j F Y') }}. Nothing further is needed from you.
                </p>
            </div>
        @else
            <p class="queue-meta">
                Dear {{ $examiner->examiner_name }}, the candidate below has completed the
                corrections required at their viva voce examination. The supervisor and the
                Chairman of the Viva Voce Examination have both confirmed this, and the form
                now needs your signature as {{ strtolower($examiner->typeLabel()) }}.
            </p>

            <table class="data-table" style="margin-bottom: 20px;">
                <tr>
                    <th style="width: 190px;">Candidate</th>
                    <td>{{ $student?->name }} @if ($student?->matric_no) ({{ $student->matric_no }}) @endif</td>
                </tr>
                <tr><th>Programme</th><td>{{ $detail?->programme }}</td></tr>
                <tr><th>Thesis</th><td>{{ $detail?->thesis_title }}</td></tr>
                <tr><th>Viva voce</th><td>{{ $detail?->viva_date?->format('j F Y') }}</td></tr>
                <tr><th>Supervisor</th><td>{{ $detail?->supervisor_name }}</td></tr>
            </table>

            <form method="POST"
                  action="{{ url()->signedRoute('hardbound.examiner.sign.store', ['application' => $application->id, 'examiner' => $examiner->id], now()->addDay()) }}"
                  enctype="multipart/form-data">
                @csrf

                <label for="signature">Your signature</label>
                <input type="file" name="signature" id="signature" required
                       accept="image/png,image/jpeg"
                       class="@error('signature') is-invalid @enderror">
                @error('signature') <p class="field-error">{{ $message }}</p> @enderror
                <p class="queue-meta" style="margin-top: -8px;">
                    A PNG or JPG of your signature, up to 1 MB. It is stamped onto the form with
                    today's date, alongside the signatures already collected, and is used for
                    nothing else.
                </p>

                <button type="submit">Sign the Confirmation</button>
            </form>

            <p class="queue-meta" style="margin-top: 18px;">
                Would rather sign on paper? Reply to the email that brought you here and the
                Centre for Graduate Studies will arrange it.
            </p>
        @endif
    </div>
</div>
@endsection
