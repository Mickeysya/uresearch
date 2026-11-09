@extends('core::layouts.app')

@section('title', $mode === 'resubmit' ? 'Resubmit Appeal' : 'New Appeal')

@include('chloe::candidacy.wca._assets')

@php
    $steps = [1 => 'Department and Supervisor / Programme Manager', 2 => 'Research and extension', 3 => 'Documents', 4 => 'Review and submit'];
@endphp

@section('content')
    <div class="wca-page">
        @if ($mode === 'resubmit')
            <x-core::page-header :title="'Resubmit Appeal '.$appeal->appeal_no" subtitle="Revise the appeal and send it back." :back="route('candidacy.appeals.show', $appeal)" :back-history="false">
                <a href="{{ \App\Modules\Chloe\Support\CandidacyHeaderLinks::home() }}"><button type="button">Home</button></a>
            </x-core::page-header>
        @elseif ($mode === 'readonly')
            <x-core::page-header title="New Appeal" subtitle="Read only. This is the appeal form; nothing here can be saved." :back="route('candidacy.status')" :back-history="false">
                <a href="{{ \App\Modules\Chloe\Support\CandidacyHeaderLinks::home() }}"><button type="button">Home</button></a>
            </x-core::page-header>
        @else
            <x-core::page-header title="New Appeal" :subtitle="'Step '.$step.' of 4: '.$steps[$step]" :back="$step === 1 ? route('candidacy.status') : route('candidacy.appeals.draft', ['step' => $step - 1])" :back-history="false">
                <a href="{{ \App\Modules\Chloe\Support\CandidacyHeaderLinks::home() }}"><button type="button">Home</button></a>
            </x-core::page-header>
        @endif

        @include('chloe::candidacy.wca._flash')

        @if ($mode === 'readonly')
            <div class="wca-banner is-{{ $notice['tone'] }}" role="status">
                <p class="wca-notice-title"><strong>{{ $notice['title'] }}</strong></p>
                @if ($notice['reasons'])
                    <ul>@foreach ($notice['reasons'] as $reason)<li>{{ $reason }}</li>@endforeach</ul>
                @endif
                <p class="wca-notice-link"><a href="{{ route('candidacy.status') }}">Back to My Study Candidacy</a></p>
            </div>
            <ol class="wca-stepper is-static" aria-label="Steps">
                @foreach ($steps as $no => $name)
                    <li><span class="wca-step-static" aria-disabled="true"><span class="wca-step-bar"></span><span class="wca-step-no">Step {{ $no }}</span><span class="wca-step-name">{{ $name }}</span></span></li>
                @endforeach
            </ol>
            {{-- Read-only: no <form> and no action anywhere, and the fieldset disables every control. --}}
            <fieldset class="wca-readonly" disabled>
                @include('chloe::candidacy.wca._form_step1')
                @include('chloe::candidacy.wca._form_sections')
                <section class="wca-card" aria-labelledby="wca-h-docs-ro">
                    <h3 id="wca-h-docs-ro">Documents</h3>
                    <p class="wca-muted wca-small">Excel (.xlsx), PDF or Word (.docx), at most 10 MB each. The official appeal form is required; you can add up to 4 supporting documents.</p>
                    <div class="wca-field"><label for="wca-official-ro">Official appeal form (.xlsx, required)</label><input id="wca-official-ro" class="wca-input" type="file" accept=".xlsx"></div>
                    <div class="wca-field"><label for="wca-supporting-ro">Supporting documents (up to 4)</label><input id="wca-supporting-ro" class="wca-input" type="file" accept=".xlsx,.pdf,.docx"></div>
                </section>
                <div class="wca-actions">
                    <button type="button" class="wca-btn" disabled>Save draft</button>
                    <button type="button" class="wca-btn is-primary" disabled>Next</button>
                    <button type="button" class="wca-btn is-primary" disabled>Submit appeal</button>
                </div>
            </fieldset>
        @endif

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

        @if ($mode === 'readonly')
            {{-- shown above --}}
        @elseif ($mode === 'resubmit')
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
