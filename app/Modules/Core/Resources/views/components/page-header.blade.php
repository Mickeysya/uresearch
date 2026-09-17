@props(['title', 'subtitle' => null])

{{--
    The top of every screen: title, one line of context, and the page's
    actions on the right.

        <x-core::page-header title="Examiner List"
                             subtitle="The examiners a Chair can put on a panel.">
            <a href="..." class="btn">Add an examiner</a>
        </x-core::page-header>

    When the context line needs Blade of its own -- a count, a condition, a
    link -- pass it as a slot instead of an attribute:

        <x-core::page-header title="Documents">
            <x-slot:subtitle>
                {{ $documents->count() }} {{ Str::plural('file', $documents->count()) }}
            </x-slot:subtitle>

            <form ...>...</form>
        </x-core::page-header>

    The default slot is the actions and is optional. Below 720px the whole
    thing stacks and the actions go full width, so there is no second layout
    to keep in step.
--}}
<header {{ $attributes->merge(['class' => 'page-header']) }}>
    <div class="page-header-text">
        <h2>{{ $title }}</h2>

        @if ($subtitle)
            <p>{{ $subtitle }}</p>
        @endif
    </div>

    @if (! $slot->isEmpty())
        <div class="page-header-actions">{{ $slot }}</div>
    @endif
</header>
