@extends('core::layouts.app')
@section('title', 'My Re-evaluations')
@section('content')
<div class="card-container-inline">
    <x-core::page-header title="My Re-evaluations" subtitle="Your re-examination cases: documents, deadline and your return to CGS." />
    @forelse ($cases as $case)
        @php($d = $case['detail'])
        <div class="card card-wide">
            <h3>{{ $d->application->student->name }}, round {{ $d->cycle_number }}</h3>
            <p>Deadline: <b>{{ $d->panel_deadline?->format('j F Y') }}</b>
                @if ($case['daysLeft'] !== null) ({{ $case['daysLeft'] }} working days left) @endif</p>
            <ul class="doc-list">
                @foreach ($d->staffDocuments->where('examiner_id', $case['examiner']->id)->whereIn('kind', ['correction_letter', 're_evaluation_form']) as $doc)
                    <li><a href="{{ route('reviva.examiner.download', [$d, $doc]) }}">{{ $doc->kindLabel() }}</a></li>
                @endforeach
                @foreach ($case['studentDocuments'] as $doc)
                    <li><a href="{{ route('reviva.examiner.student-file', [$d, $doc]) }}">{{ $doc->doc_type }}</a></li>
                @endforeach
            </ul>
            @if ($case['evaluation']) <p class="message-success">Received: {{ $case['evaluation']->label() }}. You can correct it below until CGS confirms the result.</p> @endif
            @include('hani::re_viva._return_form', ['action' => route('reviva.examiner.upload', $d), 'id' => $d->id, 'existing' => $case['evaluation']])
        </div>
    @empty
        <div class="card card-wide"><div class="empty-state">No open cases.</div></div>
    @endforelse
</div>
@endsection
