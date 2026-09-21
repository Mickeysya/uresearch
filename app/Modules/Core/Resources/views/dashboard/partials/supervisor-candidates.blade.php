{{--
    The panel that makes this a supervisor's screen and not a generic
    approver's: the students they are accountable for, the ones in trouble
    first.

    Attendance arrives through Contracts\SuppliesAttendance, never from
    Nureen's models -- Core does not import another folder's Eloquent classes.
    If no module binds the contract the column is simply absent and the rest
    of the row still renders, which is the same degradation the student and
    CGS dashboards already use.
--}}
<section class="sdash-card approver-panel">
    <header class="sdash-card-head">
        <h3>My candidates</h3>
        @if ($candidateCount > $candidates->count())
            <span class="sdash-action">{{ $candidates->count() }} of {{ $candidateCount }}</span>
        @endif
    </header>

    @if (isset($unavailable['candidates']))
        @include('core::dashboard.partials.skeleton', ['type' => 'rows', 'rows' => 4])
    @elseif ($candidates->isEmpty())
        <div class="empty-state">
            <p>No students are assigned to you yet.</p>
            <p class="queue-meta">CGS sets the supervisor on a student's record.</p>
        </div>
    @else
        <ul class="approver-feed approver-scroll">
            @foreach ($candidates as $row)
                @php
                    $student = $row['student'];
                    $reading = $row['attendance'];
                @endphp
                <li class="approver-feed-row">
                    <span class="approver-feed-main">
                        <span class="cand-name">
                            @if ($reading?->at_risk)
                                <span class="cand-flag" title="Flagged at risk">●</span>
                            @endif
                            {{ $student->name }}
                        </span>
                        <span class="approver-feed-sub">
                            {{ $student->matric_no ?: 'No matric number' }}
                            @if ($student->programme) &middot; {{ $student->programme }} @endif
                        </span>
                    </span>

                    @if ($reading)
                        <span class="cand-attendance tone-{{ $row['tone'] }}"
                              title="{{ $reading->sessions_attended }} of {{ $reading->sessions_total }} sessions to {{ $reading->period_end->format('j M Y') }}">
                            {{ rtrim(rtrim(number_format($reading->percentage, 1), '0'), '.') }}%
                        </span>
                    @else
                        <span class="cand-attendance tone-none">no data</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
