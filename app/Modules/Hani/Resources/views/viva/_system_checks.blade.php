{{-- The "System checks" panel on a softbound card. Expects: $check (?VivaSoftboundCheck). --}}
@php
    $tones = ['pass' => 'approved', 'warning' => 'rejected', 'not_checked' => 'draft', 'info' => 'draft'];
    $words = ['pass' => 'Pass', 'warning' => 'Warning', 'not_checked' => 'Not checked', 'info' => 'Shown as-is'];
@endphp

<details class="system-checks" @if ($check?->overall() === 'warning') open @endif>
    <summary>
        <b>System checks</b>
        @if (! $check)
            &middot; not run for this attempt
        @elseif ($check->status === 'queued')
            &middot; running; refresh in a minute
        @elseif ($check->status === 'failed')
            &middot; <span class="status-badge draft">Could not run</span>
        @else
            &middot; <span class="status-badge {{ $tones[$check->overall()] }}">{{ $words[$check->overall()] }}</span>
            {{ collect($check->results)->where('status', 'warning')->count() }} warning(s),
            {{ collect($check->results)->where('status', 'not_checked')->count() }} not checked
        @endif
    </summary>

    @if ($check?->results)
        <table class="data-table">
            <tbody>
                @foreach ($check->results as $result)
                    <tr>
                        <th scope="row">{{ $result['label'] }}</th>
                        <td><span class="status-badge {{ $tones[$result['status']] ?? 'draft' }}">{{ $words[$result['status']] ?? $result['status'] }}</span></td>
                        <td>{{ $result['detail'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="fstep-hint">Checked {{ $check->checked_at?->format('j M Y, g:i a') }} by the {{ $check->engine }}. Nothing here blocks; the decision is yours.</p>
    @elseif ($check?->reading())
        @php $reading = $check->reading(); @endphp
        <p class="fstep-hint">
            @if ($reading->ok)
                Read from the report: {{ $reading->index }}% similarity index. Thesis checks still running.
            @else
                Not checked automatically: {{ $reading->failure }}
            @endif
        </p>
    @endif
</details>
