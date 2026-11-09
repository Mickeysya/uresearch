@extends('core::layouts.app')

@section('title', 'Examiner Progress')

@php
    use App\Modules\Hani\Models\VivaExaminerProgress;

    $student = $progress->notice->student;
    $categories = VivaExaminerProgress::CATEGORIES;
@endphp

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        :title="'Examiner Progress: '.$student->name"
        subtitle="Record each main examiner's appointment letter, acceptance, receipt of the thesis and returned report. Late steps are chased automatically, and reports that disagree are flagged before the viva." />

    <div class="card card-wide" style="margin-bottom: var(--space-5)">
        @include('hani::partials.module_stepper', ['tracker' => \App\Modules\Hani\Support\ModuleTracker::viva($progress), 'compact' => true])
    </div>

    @if ($divergence)
        <div class="card card-wide">
            <p class="{{ $divergence['level'] === 'major' ? 'message-error' : 'message-warning' }}">
                <b>The examiners' reports {{ $divergence['level'] === 'major' ? 'diverge sharply' : 'differ' }}.</b>
                {{ $divergence['text'] }}.
                The chairman's invitation asks the panel to discuss the reports at the pre-viva meeting (without the categories).
            </p>
        </div>
    @endif

    @foreach ($current as $seat => $row)
        @php
            $due = $row->reportDueAt($level);
            $chaseDays = (int) config('hani.examiner_progress.ack_chase_days');
        @endphp
        <div class="card card-wide">
            <h3>{{ $row->sideLabel() }} examiner: {{ $row->examiner->name }}</h3>
            <p class="fstep-hint">
                {{ $row->examiner->email ?? 'No email on record' }}
                @include('hani::examiner_admin._email_badge', ['examiner' => $row->examiner])
                &middot; Now: <b>{{ $row->stage() }}</b>
                @if ($due && ! $row->report_received_at)
                    &middot; Report due {{ $due->format('j M Y') }}
                    @if ($due->isPast()) <span class="status-badge rejected">Overdue</span> @endif
                @endif
                @if ($row->letter_sent_at && ! $row->acknowledgement && $row->letter_sent_at->copy()->addDays($chaseDays)->isPast())
                    <span class="status-badge pending">No reply after {{ $chaseDays }} days</span>
                @endif
            </p>
            @include('hani::examiner_admin._report_email', ['examiner' => $row->examiner])

            <form method="POST" action="{{ route('viva.examiners.update', [$progress->notice, $row]) }}" enctype="multipart/form-data">
                @csrf

                <table class="data-table">
                    <tbody>
                        <tr>
                            <th scope="row">1. Letter sent</th>
                            <td>
                                @if ($row->letter_source === 'dispatch')
                                    {{ $row->letter_sent_at->format('j M Y') }} <span class="fstep-hint">(from the appointment letter module)</span>
                                @else
                                    <input type="date" name="letter_sent_at" max="{{ now()->toDateString() }}"
                                           value="{{ old('letter_sent_at', $row->letter_sent_at?->toDateString()) }}" aria-label="Letter sent on">
                                    @unless ($dispatchAvailable)
                                        <span class="fstep-hint">Entered by hand until the appointment letter module shares its dates.</span>
                                    @endunless
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">2. Accepted or rejected</th>
                            <td>
                                @if ($row->acknowledgement === 'accepted')
                                    Accepted on {{ $row->acknowledged_at->format('j M Y') }}
                                @else
                                    <select name="acknowledgement" aria-label="Acknowledgement" data-ack>
                                        <option value="">No reply yet</option>
                                        <option value="accepted" @selected(old('acknowledgement') === 'accepted')>Accepted</option>
                                        <option value="rejected" @selected(old('acknowledgement') === 'rejected')>Rejected (declines the seat)</option>
                                    </select>
                                    <input type="date" name="acknowledged_at" max="{{ now()->toDateString() }}" value="{{ old('acknowledged_at') }}" aria-label="Date of reply">
                                    <label for="rejection_reason_{{ $row->id }}" class="fstep-hint">Reason, if rejected</label>
                                    <input type="text" name="rejection_reason" id="rejection_reason_{{ $row->id }}" maxlength="1000" value="{{ old('rejection_reason') }}">
                                    <p class="fstep-hint">Rejected frees the examiner and promotes the backup, if they are still eligible.</p>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">3. Thesis received</th>
                            <td>
                                <input type="date" name="thesis_received_at" max="{{ now()->toDateString() }}"
                                       value="{{ old('thesis_received_at', $row->thesis_received_at?->toDateString()) }}" aria-label="Thesis received on">
                                <span class="fstep-hint">The date the examiner acknowledged receiving the thesis; the {{ $level === 'PHD' ? 6 : 4 }}-week report clock starts here.</span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">4. Report returned</th>
                            <td>
                                <input type="date" name="report_received_at" max="{{ now()->toDateString() }}"
                                       value="{{ old('report_received_at', $row->report_received_at?->toDateString()) }}" aria-label="Report received on">
                                <select name="category" aria-label="Recommended category">
                                    <option value="">Category</option>
                                    @foreach ($categories as $n => [$label, $meaning])
                                        <option value="{{ $n }}" @selected((int) old('category', $row->category) === $n)>{{ $n }}. {{ $label }} ({{ $meaning }})</option>
                                    @endforeach
                                </select>
                                <br>
                                <input type="file" name="report" accept="application/pdf,.pdf" aria-label="Examiner's report (PDF)">
                                @if ($row->report_path)
                                    <a href="{{ route('viva.examiners.report', [$progress->notice, $row]) }}">{{ $row->report_original_name }}</a>
                                @endif
                                <span class="fstep-hint">PDF, up to 10 MB. Kept private: only the Academic Executive and the Senior Executive can open it.</span>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <details>
                    <summary>Ratings from the report form (optional)</summary>
                    <div class="table-scroll">
                        <table class="data-table">
                            <tbody>
                                @foreach (['criteria' => $criteria, 'sections' => $sections] as $group => $items)
                                    <tr><th colspan="2">{{ $group === 'criteria' ? 'General criteria' : 'Thesis sections' }}</th></tr>
                                    @foreach ($items as $key => $label)
                                        <tr>
                                            <td>{{ $label }}</td>
                                            <td>
                                                <select name="ratings[{{ $group }}][{{ $key }}]" aria-label="{{ $label }}">
                                                    <option value="">Not rated</option>
                                                    @for ($i = 1; $i <= 5; $i++)
                                                        <option value="{{ $i }}" @selected((int) ($row->ratings[$group][$key] ?? 0) === $i)>{{ $i }}</option>
                                                    @endfor
                                                </select>
                                            </td>
                                        </tr>
                                    @endforeach
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>

                @foreach (['letter_sent_at', 'acknowledgement', 'acknowledged_at', 'rejection_reason', 'thesis_received_at', 'report_received_at', 'category', 'report'] as $field)
                    @error($field) <p class="field-error">{{ $message }}</p> @enderror
                @endforeach

                <button type="submit">Save {{ $row->sideLabel() }} Examiner</button>
            </form>
        </div>
    @endforeach

    @if ($current->count() < 2)
        <div class="card card-wide">
            <p class="message-warning">A main seat is empty. <a href="{{ route('examiner-panel.show', $panel) }}">Appoint a replacement on the panel page</a>.</p>
        </div>
    @endif

    @if ($history->isNotEmpty())
        <div class="card card-wide">
            <h3>Examiners who left the panel</h3>
            <ul>
                @foreach ($history as $row)
                    <li>{{ $row->sideLabel() }}: {{ $row->examiner->name }}, {{ $row->acknowledgement === 'rejected' ? 'rejected on '.$row->acknowledged_at?->format('j M Y').': '.$row->rejection_reason : 'replaced' }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <p><a href="{{ route('viva.schedule.index') }}">Back to Viva Scheduling</a></p>
</div>
@endsection
