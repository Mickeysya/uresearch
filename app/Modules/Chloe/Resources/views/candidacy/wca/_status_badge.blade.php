@php $wcaTone = \App\Modules\Chloe\Support\CandidacyAppealPresenter::tone($appeal->status); @endphp
<span class="wca-status"><span class="wca-dot is-{{ $wcaTone }}"></span>{{ \App\Modules\Chloe\Support\CandidacyAppealPresenter::statusLabel($appeal) }}</span>
