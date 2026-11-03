@include('nureen::charter._queue_clock')

@php($detail = $details[$application->id] ?? null)

@if ($detail)
    <p>
        Requested Supervisor: <b>{{ $detail->requestedSupervisor->name }}</b>,
        {{ $detail->appointment_role === \App\Modules\Nureen\Models\SupervisionDetail::ROLE_CO ? 'Co-Supervisor' : 'Main Supervisor' }}
        ({{ $detail->appointment_type ? ucfirst($detail->appointment_type) : 'Not recorded' }})
    </p>
    <p>Justification: {{ $detail->justification }}</p>

    {{-- The CGS endorsement stage ('endorse' and 'manual' modes): preview
         the exact letter, then endorse to issue it in your own name. --}}
    @if ($application->current_stage === 'cgs_review')
        @include('nureen::letters._preview_styles')
        <div class="nureen-letter-actions nureen-endorse">
            <a href="{{ route('supervision.letter.preview', $application) }}" target="_blank" rel="noopener" class="btn-secondary">Preview letter</a>
            <form method="POST" action="{{ route('supervision.decide', $application) }}">
                @csrf
                <input type="hidden" name="decision" value="approve">
                <button type="submit">Endorse &amp; issue letter</button>
            </form>
            <p class="hint">
                Endorsing assigns the reference number, issues the letter in your name, emails it to the
                student (copied to the supervisor) and approves the request. To reject, give the reason below.
            </p>
        </div>
    @endif

    @if ($detail->letter_reference)
        @include('nureen::supervision._letter_status', ['detail' => $detail, 'application' => $application])
    @endif
@else
    <p style="color: var(--text-grey);">Detail record missing for this application.</p>
@endif
