@if (session('status'))
    <p class="message-success">{{ session('status') }}</p>
@endif

@if (session('warning'))
    <p class="message-warning">{{ session('warning') }}</p>
@endif

@if (session('error'))
    <p class="message-error">{{ session('error') }}</p>
@endif
