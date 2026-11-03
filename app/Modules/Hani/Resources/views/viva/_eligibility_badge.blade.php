{{-- One eligibility item's source. Expects: $check (Hani\Support\Eligibility\EligibilityResult). --}}
@php
    use App\Modules\Hani\Support\Eligibility\EligibilityResult;

    $tone = match ($check->status) {
        EligibilityResult::VERIFIED => 'approved',
        EligibilityResult::NOT_MET, EligibilityResult::FAILED => 'rejected',
        default => 'draft',
    };
@endphp
<span class="status-badge {{ $tone }}">{{ $check->label() }}</span>
