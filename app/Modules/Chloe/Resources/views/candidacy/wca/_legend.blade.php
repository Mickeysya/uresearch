<ul class="wca-legend" aria-label="Status colours">
    @foreach (\App\Modules\Chloe\Support\CandidacyAppealPresenter::legend() as $tone => $label)
        <li><span class="wca-dot is-{{ $tone }}"></span>{{ $label }}</li>
    @endforeach
</ul>
