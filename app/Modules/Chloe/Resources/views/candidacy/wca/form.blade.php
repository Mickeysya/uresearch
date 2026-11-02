@extends('core::layouts.app')

@section('title', $mode === 'resubmit' ? 'Resubmit Appeal' : 'New Appeal')

@include('chloe::candidacy.wca._assets')

@php
    $steps = [1 => 'Department and supervisor', 2 => 'Research and extension', 3 => 'Documents', 4 => 'Review and submit'];
@endphp

@section('content')
    <div class="wca-page">
        @if ($mode === 'resubmit')
            <x-core::page-header :title="'Resubmit Appeal '.$appeal->appeal_no" subtitle="Revise the appeal and send it back." :back="route('candidacy.appeals.show', $appeal)" :back-history="false">
                <a href="{{ \App\Modules\Chloe\Support\CandidacyHeaderLinks::home() }}"><button type="button">Home</button></a>
            </x-core::page-header>
        @else
            <x-core::page-header title="New Appeal" :subtitle="'Step '.$step.' of 4: '.$steps[$step]" :back="$step === 1 ? route('candidacy.status') : route('candidacy.appeals.draft', ['step' => $step - 1])" :back-history="false">
                <a href="{{ \App\Modules\Chloe\Support\CandidacyHeaderLinks::home() }}"><button type="button">Home</button></a>
            </x-core::page-header>
        @endif

        @include('chloe::candidacy.wca._flash')

        @if ($mode === 'draft')
            <ol class="wca-stepper" aria-label="Steps">
                @foreach ($steps as $no => $name)
                    <li class="{{ $no < $step ? 'is-done' : ($no === $step ? 'is-current' : '') }}">
                        <a href="{{ route('candidacy.appeals.draft', ['step' => $no]) }}" @if ($no === $step) aria-current="step" @endif>
                            <span class="wca-step-bar"></span><span class="wca-step-no">Step {{ $no }}</span><span class="wca-step-name">{{ $name }}</span>
                        </a>
                    </li>
                @endforeach
            </ol>
            <ul class="wca-legend" aria-label="Step colours"><li><span class="wca-dot is-green"></span>Step done</li><li><span class="wca-dot is-blue"></span>Current step</li><li><span class="wca-dot is-slate"></span>Not reached yet</li></ul>
        @endif

        @if ($mode === 'resubmit')
            @include('chloe::candidacy.wca._returned_reasons', ['event' => $returnedEvent])
            @include('chloe::candidacy.wca._form_documents')
            <form method="POST" action="{{ route('candidacy.appeals.resubmit', $appeal) }}" novalidate>
                @csrf
                @include('chloe::candidacy.wca._form_sections')
                <div class="wca-actions">
                    <button type="submit" class="wca-btn is-primary">Resubmit appeal</button>
                    <a class="wca-btn" href="{{ route('candidacy.appeals.show', $appeal) }}">Cancel</a>
                </div>
            </form>
        @elseif ($step === 3)
            @include('chloe::candidacy.wca._form_documents')
            <form method="POST" action="{{ route('candidacy.appeals.draft.save', ['step' => 3]) }}">
                @csrf
                <div class="wca-actions">
                    <button type="submit" name="intent" value="back" class="wca-btn">Back</button>
                    <button type="submit" name="intent" value="next" class="wca-btn is-primary">Next: review</button>
                </div>
            </form>
        @elseif ($step === 4)
            @include('chloe::candidacy.wca._form_review')
        @else
            <form method="POST" action="{{ route('candidacy.appeals.draft.save', ['step' => $step]) }}" novalidate>
                @csrf
                @if ($step === 1) @include('chloe::candidacy.wca._form_step1') @else @include('chloe::candidacy.wca._form_sections') @endif
                <div class="wca-actions">
                    @if ($step > 1)<button type="submit" name="intent" value="back" class="wca-btn">Back</button>@endif
                    <button type="submit" name="intent" value="save" class="wca-btn">Save draft</button>
                    <button type="submit" name="intent" value="next" class="wca-btn is-primary">Next</button>
                </div>
            </form>
        @endif
    </div>
    @include('chloe::workstation._assistant-widget', ['wsaDomain' => 'candidacy'])
@endsection
