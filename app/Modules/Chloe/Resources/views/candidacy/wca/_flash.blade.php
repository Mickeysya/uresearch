@if (session('wca_status'))
    <div class="wca-banner" role="status">{{ session('wca_status') }}</div>
@endif
@if ($errors->any())
    <div class="wca-banner is-error" role="alert">
        <strong>Please check the following.</strong>
        <ul>@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
    </div>
@endif
