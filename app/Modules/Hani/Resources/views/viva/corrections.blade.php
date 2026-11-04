@extends('core::layouts.app')

@section('title', 'Corrected Final Thesis')

@section('content')
@php $d = $progress->detail; @endphp
<div class="card-container-inline">
    <x-core::page-header
        title="Corrected Final Thesis"
        :subtitle="'Result '.$d->resultLabel().'. Upload your corrected thesis by '.$d->corrections_due_at->format('j M Y').'.'" />

    @include('hani::partials.module_stepper', ['tracker' => $tracker])

    <div class="card card-wide">
        @if ($d->corrections_submitted_at)
            <p class="message-info">You submitted corrections on {{ $d->corrections_submitted_at->format('j M Y') }}. You can upload a newer version until your Academic Executive accepts them.</p>
        @endif

        <form method="POST" action="{{ route('viva.corrections.store') }}" enctype="multipart/form-data">
            @csrf
            <label for="corrected_thesis">Corrected Final Thesis</label>
            <input type="file" name="corrected_thesis" id="corrected_thesis" accept="application/pdf,.pdf" required
                   class="@error('corrected_thesis') is-invalid @enderror">
            <p class="fstep-hint">PDF, up to {{ $maxMb }} MB. Make the corrections the chairman's viva report asks for.</p>
            @error('corrected_thesis') <p class="field-error">{{ $message }}</p> @enderror
            <button type="submit">Submit Corrected Thesis</button>
        </form>
    </div>
</div>
@endsection
