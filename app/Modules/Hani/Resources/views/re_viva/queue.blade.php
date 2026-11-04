@extends('core::layouts.app')

@section('title', 'Re-viva: ' . $stage->queueTitle())

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Re-viva Monitoring: {{ $stage->queueTitle() }}" />

    <div class="card card-wide">
        {{-- total(), not count(): queues are paginated in Core now, and
             count() is how many are on THIS page. --}}
        <p class="queue-meta">
            {{ $applications->total() }} {{ Str::plural('cycle', $applications->total()) }} at this step.
        </p>

        @forelse ($applications as $application)
            @php($detail = $details[$application->id] ?? null)
            <div class="app-item">
                <div class="app-item-header">
                    <p><b>Application #{{ $application->id }}</b>
                        @if ($detail) Cycle {{ $detail->cycle_number }} @endif
                    </p>
                    <span style="color: var(--text-grey); font-size: 12.5px;">
                        Submitted {{ $application->submitted_at?->diffForHumans() }}
                    </span>
                </div>

                <p>Student: {{ $application->student->name }}@if ($application->student->matric_no) ({{ $application->student->matric_no }})@endif</p>

                @if ($detail)
                    @include('hani::partials.module_stepper', ['tracker' => \App\Modules\Hani\Support\ModuleTracker::reviva($detail->setRelation('application', $application))])

                    <p>Resubmitted: {{ $detail->resubmission_at->format('j M Y') }}
                        @if ($detail->windowLabel()) (window {{ $detail->windowLabel() }}) @endif
                        @if ($detail->logged_outside_window) <span class="status-badge returned">Logged outside window</span> @endif
                    </p>
                    @if ($detail->correction_deadline || $detail->hardbound_deadline)
                        <p>
                            @if ($detail->correction_deadline) Correction deadline: {{ $detail->correction_deadline->format('j M Y') }} @endif
                            @if ($detail->hardbound_deadline) Hardbound deadline: {{ $detail->hardbound_deadline->format('j M Y') }} @endif
                        </p>
                    @endif
                @endif

                @if ($application->documents->isNotEmpty())
                    <ul class="doc-list">
                        @foreach ($application->documents as $doc)
                            <li><a href="{{ $doc->url() }}">{{ $doc->doc_type }}: {{ $doc->original_name }}</a></li>
                        @endforeach
                    </ul>
                @endif

                @if ($detail?->sent_to_panel_at && $stage->key === 'under_panel_review')
                    @php($panelDays = \App\Modules\Hani\Support\WorkingDays::between($detail->sent_to_panel_at, today()))
                    <p>With the panel for <b>{{ $panelDays }}</b> of {{ config('hani.reviva.review_working_days') }} working days
                        (sent {{ $detail->sent_to_panel_at->format('j M Y') }}, reports due {{ \App\Modules\Hani\Support\ReVivaLetters::reviewDue($detail->sent_to_panel_at)->format('j M Y') }})
                        @if ($panelDays >= config('hani.reviva.panel_escalate_working_days')) <span class="status-badge rejected">Escalated to the Senior Executive</span>
                        @elseif ($panelDays >= config('hani.reviva.panel_chase_working_days')) <span class="status-badge returned">Chase the panel</span> @endif
                    </p>
                @endif

                @if ($detail)
                    @include('hani::re_viva._staff_documents', ['detail' => $detail])
                @endif

                @if ($stage->key === 'report_sent')
                    {{-- Stage 1: one action generates, stores and emails the letters and completes the step. --}}
                    @php($panel = $mains[$application->id] ?? [])
                    <h4>Correction letters</h4>
                    @if ($panel === [])
                        <p class="message-warning">No released panel with main examiners is linked to this round, so no letters can be generated.</p>
                    @else
                        <p class="fstep-hint">One "Correction of {{ ($detail?->vivaDetail()?->programme_level === 'PHD') ? 'Doctoral' : 'Master\'s' }} Thesis" letter per current main examiner, with a secure link to the corrected thesis and the Summary of Corrections. The panel has {{ config('hani.reviva.review_working_days') }} working days.</p>
                        <ul>
                            @foreach ($panel as $seat => $examiner)
                                <li>
                                    {{ \App\Modules\Hani\Models\VivaExaminerProgress::SEATS[$seat] }}: {{ $examiner->name }}
                                    @if ($examiner->emailNeedsUpdating())
                                        <span class="status-badge rejected">Email needs updating</span>
                                        <span class="fstep-hint">{{ $examiner->emailProblem() }} The letter is generated but not emailed.</span>
                                    @else
                                        <span class="fstep-hint">({{ $examiner->email }})</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        <form method="POST" action="{{ route('reviva.send-to-panel', $application) }}">
                            @csrf
                            <button type="submit">Send to panel</button>
                        </form>
                    @endif
                    @error('send') <p class="field-error">{{ $message }}</p> @enderror
                @else
                    @if ($detail && in_array($stage->key, ['report_received', 'consolidation_scheduled'], true))
                        @include('hani::re_viva._evaluations', ['detail' => $detail, 'mains' => $mains[$application->id] ?? []])
                    @endif

                    @if ($detail && $stage->key === 'report_received')
                        @foreach ($mains[$application->id] ?? [] as $seat => $examiner)
                            @include('hani::re_viva._evaluation_form', [
                                'seat' => $seat, 'examiner' => $examiner,
                                'existing' => $detail->currentEvaluations($mains[$application->id])->get($seat),
                            ])
                        @endforeach
                        @unless ($detail->evaluationsComplete())
                            <p class="fstep-hint">This step completes once both re-evaluations are recorded.</p>
                        @endunless
                    @endif

                    {{-- Advance only: this stepper has no meaningful "reject" step,
                         so unlike core::decision-form only one button is offered. --}}
                    <form method="POST" action="{{ route('reviva.decide', $application) }}">
                        @csrf
                        <input type="hidden" name="decision" value="approve">

                        <label for="remarks-{{ $application->id }}">Remarks <span style="color: var(--text-grey)">(optional, the student sees them)</span></label>
                        <textarea id="remarks-{{ $application->id }}" name="remarks" rows="2"></textarea>

                        <button type="submit">Advance to Next Step</button>
                    </form>
                @endif
            </div>
        @empty
            <div class="empty-state">Nothing at this step right now.</div>
        @endforelse

        {{-- Required, not optional: Core paginates queues, so without this
             every cycle past the first page is simply missing from the page
             with nothing to say so. --}}
        @include('core::partials.pagination', ['paginator' => $applications, 'label' => 'Re-viva pages'])
    </div>
</div>
@endsection
