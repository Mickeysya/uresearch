@if (session('status'))
    <p class="message-success">{{ session('status') }}</p>
@endif

@if (session('warning'))
    <p class="message-warning">{{ session('warning') }}</p>
@endif

@if (session('error'))
    <p class="message-error">{{ session('error') }}</p>
@endif

{{-- The validation error bag, for every page at once. A form also shows each
     message beside its field; this summary is what a queue or CGS screen
     (no field to sit beside) and a wizard (the field may be on another
     step) would otherwise not show at all. Before 2026-10-06 only login and
     Jason's three queues rendered $errors, each by hand. --}}
@if ($errors->any())
    <div class="message-error" role="alert">
        @foreach ($errors->all() as $message)
            <p style="margin: 0;">{{ $message }}</p>
        @endforeach
    </div>
@endif
