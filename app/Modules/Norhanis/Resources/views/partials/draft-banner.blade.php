{{-- Above a blank form: any saved draft of it, to continue. See Concerns\SavesDrafts. --}}
@if ($otherDrafts->isNotEmpty())
    <p class="message-info">
        You have {{ $otherDrafts->count() === 1 ? 'a saved draft' : $otherDrafts->count().' saved drafts' }} of this form.
        <a href="{{ route($draftEditRoute, $otherDrafts->first()) }}">Continue the latest</a>
        @if (Route::has('nureen.drafts'))
            or see <a href="{{ route('nureen.drafts') }}">My Drafts</a>
        @endif.
    </p>
@endif
