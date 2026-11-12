@extends('core::layouts.app')
@section('title', 'Re-viva: ' . $stage->queueTitle())
@section('content')
<div class="card-container-inline">
    <x-core::page-header title="Re-viva: {{ $stage->queueTitle() }}" />
    <div class="card card-wide">
        <p class="queue-meta">{{ $applications->total() }} {{ Str::plural('round', $applications->total()) }} at this step.</p>
        @foreach (['stage', 'pack', 'review', 'send'] as $key) @error($key) <p class="message-error">{{ $message }}</p> @enderror @endforeach
        @error('signature') <p class="message-error">{{ $message }} <a href="{{ route('signatures.index') }}">Open My Signatures</a></p> @enderror

        @forelse ($applications as $application)
            @php
                $detail = $details[$application->id] ?? null;
                $panel = $mains[$application->id] ?? [];
                $act = fn ($a) => route('reviva.act', [$application, $a]);
                $pack = $detail?->staffDocuments->whereIn('kind', ['correction_letter', 're_evaluation_form']) ?? collect();
            @endphp
            <div class="app-item">
                <div class="app-item-header"><p><b>{{ $application->student->name }}</b>@if ($application->student->matric_no) ({{ $application->student->matric_no }})@endif, round {{ $detail?->cycle_number }}</p>
                    <span class="fstep-hint">Submitted {{ $application->submitted_at?->diffForHumans() }}</span></div>

                @if ($detail)
                    @include('hani::partials.module_stepper', ['tracker' => \App\Modules\Hani\Support\ModuleTracker::reviva($detail), 'compact' => true])

                    <h4>Student's documents</h4>
                    @foreach ($detail->studentDocuments() as $doc)
                        @include('hani::re_viva._preview', ['src' => route('documents.view', $doc), 'title' => $doc->doc_type.': '.$doc->original_name])
                    @endforeach

                    @php($deanReturns = $application->history()->with('approver')->where('decision', 'returned')->where('stage_key', 'dean_approval')->latest('id')->get())
                    @if ($detail->documentReturns->isNotEmpty() || $deanReturns->isNotEmpty())
                        <h4>Return history</h4>
                        <ul>
                            @foreach ($deanReturns as $h)<li>{{ $h->created_at->format('j M Y') }}, returned by the Dean of PGR ({{ $h->approver?->name }}): {{ $h->remarks }}</li>@endforeach
                            @foreach ($detail->documentReturns as $r)<li>{{ $r->created_at->format('j M Y') }}, returned to the student by {{ $r->returnedBy?->name }}: {{ implode(', ', $r->documents) }}. {{ $r->reason }} @if ($r->resubmitted_at)<span class="fstep-hint">(re-uploaded {{ $r->resubmitted_at->format('j M Y') }})</span>@endif</li>@endforeach
                        </ul>
                    @endif

                    @if ($pack->isNotEmpty() && in_array($stage->key, ['pack_preparation', 'dean_approval', 'ready_to_send'], true))
                        <h4>Letters and report forms</h4>
                        @foreach ($pack->sortBy('id') as $doc)
                            @include('hani::re_viva._preview', ['src' => route('reviva.staff-documents.download', ['document' => $doc, 'inline' => 1]), 'title' => $doc->kindLabel().': '.$doc->examiner?->name])
                        @endforeach
                    @endif
                @endif

                @switch($stage->key)
                    @case('student_submitted')
                        <form method="POST" action="{{ $act('accept') }}">@csrf <button type="submit">Accept documents</button></form>
                        <form method="POST" action="{{ $act('return') }}">
                            @csrf
                            <fieldset><legend>Return to student: which documents?</legend>
                                @foreach (\App\Modules\Hani\Support\ReVivaLogging::REQUIRED as $type)<label><input type="checkbox" name="documents[]" value="{{ $type }}"> {{ $type }}</label>@endforeach</fieldset>
                            @error('documents') <p class="field-error">{{ $message }}</p> @enderror
                            <label for="reason-{{ $application->id }}">Reason</label><textarea id="reason-{{ $application->id }}" name="reason" rows="2" required></textarea>
                            <button type="submit" class="btn-secondary">Return to student</button>
                        </form>
                        @break
                    @case('pack_preparation')
                        <form method="POST" action="{{ $act('send-to-dean') }}">@csrf <button type="submit">Send to Dean</button></form>
                        <form method="POST" action="{{ $act('regenerate') }}">@csrf <button type="submit" class="btn-secondary">Regenerate</button></form>
                        @break
                    @case('dean_approval')
                        <form method="POST" action="{{ $act('dean-approve') }}">@csrf <button type="submit">Approve</button></form>
                        <form method="POST" action="{{ $act('dean-return') }}">@csrf
                            <label for="reason-{{ $application->id }}">Reason for returning (required; the Senior Executive sees it)</label><textarea id="reason-{{ $application->id }}" name="reason" rows="2" required></textarea>
                            @error('reason') <p class="field-error">{{ $message }}</p> @enderror
                            <button type="submit" class="btn-secondary">Return</button></form>
                        @break
                    @case('ready_to_send')
                        <ul>@foreach ($panel as $seat => $examiner)<li>{{ \App\Modules\Hani\Support\ReVivaProcess::seatLabel($seat) }}: {{ $examiner->name }} @if ($examiner->emailNeedsUpdating())<span class="status-badge rejected">Email needs updating</span>@endif</li>@endforeach</ul>
                        <form method="POST" action="{{ $act('send-to-panel') }}">@csrf <button type="submit">Send to panel</button></form>
                        @break
                    @case('under_panel_review')
                    @case('reports_received')
                    @case('cgs_review')
                        @if ($stage->key === 'under_panel_review' && $detail?->sent_to_panel_at)
                            <p>With the panel for <b>{{ \App\Modules\Hani\Support\WorkingDays::between($detail->sent_to_panel_at, today()) }}</b> of {{ \App\Modules\Hani\Support\ReVivaLetters::panelWorkingDays($detail) }} working days. Deadline {{ $detail->panel_deadline?->format('j F Y') }}.</p>
                            @include('hani::re_viva._staff_documents', ['detail' => $detail])
                        @endif
                        @include('hani::re_viva._evaluations', ['detail' => $detail, 'mains' => $panel])
                        @foreach ($panel as $seat => $examiner)
                            <details><summary>Record for {{ $examiner->name }} ({{ \App\Modules\Hani\Support\ReVivaProcess::seatLabel($seat) }})</summary>
                                @include('hani::re_viva._return_form', ['action' => route('reviva.evaluations.record', [$application, $seat]), 'id' => $application->id.$seat, 'existing' => $detail->currentEvaluations($panel)->get($seat)])
                            </details>
                        @endforeach
                        @if ($stage->key === 'under_panel_review' && $detail?->evaluationsComplete())
                            <form method="POST" action="{{ $act('start-review') }}">@csrf <button type="submit">Start review</button></form>
                        @endif
                        @if ($stage->key === 'cgs_review')
                            @php($s = $suggestions[$application->id])
                            <p>@if ($s['level'])<span class="status-badge approved">Suggested</span> @else<span class="status-badge pending">Consolidation needed</span> @endif {{ $s['text'] }}</p>
                            <form method="POST" action="{{ $act('decide-outcome') }}">@csrf
                                <label for="lvl-{{ $application->id }}">Result with no meeting</label>
                                <select id="lvl-{{ $application->id }}" name="outcome_level" required><option value="">Select</option>
                                    @foreach ([1, 2, 3, 5] as $l)<option value="{{ $l }}" @selected($s['level'] === $l)>{{ \App\Modules\Hani\Models\ReVivaDetail::OUTCOME_LABELS[$l] }}</option>@endforeach</select>
                                @error('outcome_level') <p class="field-error">{{ $message }}</p> @enderror
                                <label for="rem-{{ $application->id }}">Remarks (required to dismiss or to differ from the examiners)</label><textarea id="rem-{{ $application->id }}" name="outcome_remarks" rows="2"></textarea>
                                @error('outcome_remarks') <p class="field-error">{{ $message }}</p> @enderror
                                <button type="submit">Record result</button></form>
                            <form method="POST" action="{{ $act('schedule-meeting') }}">@csrf
                                <label for="kind-{{ $application->id }}">Or schedule</label>
                                <select id="kind-{{ $application->id }}" name="meeting_kind" required><option value="consolidation">Consolidation meeting</option><option value="re_viva">Re-viva (oral examination)</option></select>
                                <label for="at-{{ $application->id }}">Date and time</label><input type="datetime-local" id="at-{{ $application->id }}" name="meeting_at" required>
                                <label for="venue-{{ $application->id }}">Venue or online link</label><input type="text" id="venue-{{ $application->id }}" name="meeting_venue" required>
                                @php($vivaChair = $detail->vivaDetail())
                                @if ($vivaChair?->chair_email)
                                    <p class="fstep-hint">Invited: you, the two main examiners and the viva chairman ({{ $vivaChair->chair_name }}). The student and supervisor are told the date, without the calendar file.</p>
                                @else
                                    <p class="message-warning" role="alert">The viva chairman is not on record for this student's viva. Type the chairman's name and email so they are invited.</p>
                                    <label for="chair-name-{{ $application->id }}">Chairman's name</label><input type="text" id="chair-name-{{ $application->id }}" name="meeting_chair_name" maxlength="150" value="{{ old('meeting_chair_name') }}">
                                    @error('meeting_chair_name') <p class="field-error">{{ $message }}</p> @enderror
                                    <label for="chair-email-{{ $application->id }}">Chairman's email</label><input type="email" id="chair-email-{{ $application->id }}" name="meeting_chair_email" maxlength="150" value="{{ old('meeting_chair_email') }}">
                                    @error('meeting_chair_email') <p class="field-error">{{ $message }}</p> @enderror
                                @endif
                                <button type="submit" class="btn-secondary">Schedule and send invitations</button></form>
                        @endif
                        @break
                    @case('meeting_scheduled')
                        <p>{{ $detail->meeting_kind === 're_viva' ? 'Re-viva' : 'Consolidation meeting' }}: {{ $detail->meeting_at?->format('j F Y, g:i a') }}, {{ $detail->meeting_venue }}</p>
                        @include('hani::re_viva._evaluations', ['detail' => $detail, 'mains' => $panel])
                        <form method="POST" action="{{ $act('meeting-result') }}">@csrf
                            <label for="lvl-{{ $application->id }}">Final result</label>
                            <select id="lvl-{{ $application->id }}" name="outcome_level" required><option value="">Select</option>
                                @foreach (\App\Modules\Hani\Models\ReVivaDetail::OUTCOME_LABELS as $l => $label)<option value="{{ $l }}">{{ $label }}</option>@endforeach</select>
                            <label for="rem-{{ $application->id }}">Remarks</label><textarea id="rem-{{ $application->id }}" name="outcome_remarks" rows="2"></textarea>
                            @error('outcome_remarks') <p class="field-error">{{ $message }}</p> @enderror
                            <button type="submit">Record result</button></form>
                        @break
                @endswitch
            </div>
        @empty
            <div class="empty-state">Nothing at this step right now.</div>
        @endforelse
        @include('core::partials.pagination', ['paginator' => $applications, 'label' => 'Re-viva pages'])
    </div>
</div>
@endsection
