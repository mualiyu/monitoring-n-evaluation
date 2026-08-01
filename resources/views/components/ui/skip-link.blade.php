{{--
    <x-ui.skip-link /> — first tab stop on every shell. Invisible until focused.
--}}
@props([
    'target' => '#main-content',
])

<a
    href="{{ $target }}"
    class="sr-only focus:not-sr-only focus:absolute focus:top-3 focus:left-3 focus:z-[60] focus:rounded-lg focus:bg-brand focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-on-brand focus:shadow-e2"
>
    {{ $slot->isNotEmpty() ? $slot : __('Skip to main content') }}
</a>
