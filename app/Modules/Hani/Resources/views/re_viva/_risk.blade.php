{{-- The deadline risk score with every factor that makes it up
     (Support\ReVivaRisk). Expects: $risk (?array). --}}
@if ($risk)
    <span class="status-badge {{ ['high' => 'rejected', 'medium' => 'pending', 'low' => 'approved'][$risk['band']] }}">
        {{ $risk['score'] }} ({{ ucfirst($risk['band']) }})
    </span>
    <details>
        <summary>Why</summary>
        <ul class="reviva-risk-factors">
            @foreach ($risk['factors'] as $factor)
                <li><b>{{ $factor['label'] }}</b>: {{ $factor['points'] }} of {{ $factor['max'] }}. <span class="fstep-hint">{{ $factor['detail'] }}</span></li>
            @endforeach
        </ul>
    </details>
@else
    <span class="fstep-hint">Closed</span>
@endif
