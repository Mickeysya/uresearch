{{-- The "Document checks" panel on a notice card (Support\NoticeChecks). Expects $application. Advisory only. --}}
@php
    $check = \App\Modules\Hani\Models\VivaNoticeCheck::where('application_id', $application->id)->first();
    $tones = ['pass' => 'approved', 'warning' => 'returned', 'not_checked' => 'draft', 'not_met' => 'rejected'];
    $words = ['pass' => 'Pass', 'warning' => 'Warning', 'not_checked' => 'Not checked', 'not_met' => 'Not met'];
@endphp

<h3>Document checks</h3>
@if (! $check)
    <p class="fstep-hint">Not run for this notice (filed before the checks existed, or still queued).</p>
@elseif ($check->status === 'queued')
    <p class="fstep-hint">Running; refresh in a minute.</p>
@elseif ($check->status === 'failed')
    <p class="fstep-hint"><span class="status-badge draft">Could not run</span> Check the papers by hand.</p>
@else
    <table class="table notice-checks">
        <tbody>
            @foreach ($check->results ?? [] as $result)
                <tr>
                    <th scope="row">{{ $result['label'] }}</th>
                    <td><span class="status-badge {{ $tones[$result['status']] ?? 'draft' }}">{{ $words[$result['status']] ?? $result['status'] }}</span></td>
                    <td>{{ $result['detail'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="fstep-hint">Checked {{ $check->checked_at?->format('j M Y, g:i a') }} by the {{ $check->engine }}. Nothing here rejects the notice; the decision is yours.</p>
@endif
