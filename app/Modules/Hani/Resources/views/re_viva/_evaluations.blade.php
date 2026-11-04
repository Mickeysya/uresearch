{{-- The main examiners' re-evaluations (UTP/CGS/024) and any divergence.
     STAFF ONLY: names and individual categories. Never include this on a
     student's or supervisor's page.
     Expects: $detail; optional $mains (current mains keyed by seat). --}}
@php
    $mains = $mains ?? $detail->currentMains();
    $evaluations = $detail->currentEvaluations($mains);
    $divergence = \App\Modules\Hani\Support\ExaminerDivergence::betweenReEvaluations($evaluations);
@endphp
<div class="reviva-evaluations">
    <p><b>Examiner re-evaluations</b></p>
    @if ($mains === [])
        <p class="fstep-hint">No panel linked to this round.</p>
    @else
        <ul>
            @foreach ($mains as $seat => $examiner)
                @php $e = $evaluations->get($seat); @endphp
                <li>
                    {{ \App\Modules\Hani\Models\VivaExaminerProgress::SEATS[$seat] }} ({{ $examiner->name }}):
                    @if ($e)
                        <b>{{ $e->label() }}</b>
                        @if ($e->report) · <a href="{{ route('reviva.staff-documents.download', $e->report) }}">UTP/CGS/024</a> @endif
                    @else
                        <span class="fstep-hint">not recorded yet</span>
                    @endif
                </li>
            @endforeach
        </ul>
        {{-- The viva badge, allowed to wrap: in a table cell it otherwise pushes the columns off the page. --}}
        @if ($divergence)
            <span class="status-badge {{ $divergence['level'] === 'major' ? 'rejected' : 'pending' }}" style="white-space: normal; display: inline-block; line-height: 1.5;"
                  title="The two main examiners recommend different outcomes">
                {{ $divergence['level'] === 'major' ? 'Major divergence' : 'Reports differ' }}: {{ $divergence['text'] }}
            </span>
        @endif
    @endif
</div>
