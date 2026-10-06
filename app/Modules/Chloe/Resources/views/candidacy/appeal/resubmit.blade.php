@extends('core::layouts.app')

@section('title', 'Revise and Resubmit Appeal')

@section('content')
@include('chloe::partials.styles')

<div class="card-container-inline">
    <x-core::page-header :title="'Revise Appeal #'.$application->id"
                         subtitle="Make the changes asked for below. It goes back to the same reviewer, not to the start of the chain.">
        <a href="{{ route('candidacy-appeal.show', $application) }}" class="btn-secondary">Back to the appeal</a>
    </x-core::page-header>

    <div class="card card-wide">
        @if ($lastReturn)
            <div class="message-warning">
                <b>Returned at the {{ $lastReturn->stage_label }} stage.</b>
                @if ($lastReturn->remarks)
                    "{{ $lastReturn->remarks }}"
                @endif
            </div>
        @endif

        <form method="POST" action="{{ route('candidacy-appeal.resubmit', $application) }}" enctype="multipart/form-data"
              class="app-form" id="appeal-form" data-stepper
              data-stepper-review="Check your changes. Resubmitting sends the appeal back to the reviewer who returned it.">
            @csrf

            @include('chloe::candidacy.appeal._fields', ['candidacy' => $detail->candidacy, 'detail' => $detail])

            <button type="submit">Resubmit Appeal</button>
        </form>
    </div>
</div>

@include('core::partials.form-stepper')
@endsection
