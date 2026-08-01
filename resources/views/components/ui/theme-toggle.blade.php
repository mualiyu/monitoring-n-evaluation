{{--
    <x-ui.theme-toggle /> — light/dark switch. Writes `ui.theme` to localStorage; the
    pre-paint script in layouts/partials/head.blade.php reads it back before first
    paint so there is no flash. Falls back to the OS preference when never set.
--}}
@props([
    'size' => 'md',
])

<button
    type="button"
    x-data="{
        dark: document.documentElement.classList.contains('dark'),
        toggle() {
            this.dark = ! this.dark
            document.documentElement.classList.toggle('dark', this.dark)
            try { localStorage.setItem('ui.theme', this.dark ? 'dark' : 'light') } catch (e) {}
        },
    }"
    x-on:click="toggle()"
    :aria-pressed="dark ? 'true' : 'false'"
    {{ $attributes->class([
        'inline-flex items-center justify-center rounded-lg text-ink-muted transition-colors hover:bg-neutral-soft hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus',
        'size-9' => $size === 'sm',
        'size-10' => $size !== 'sm',
    ]) }}
>
    <span x-show="! dark" class="contents"><x-ui.icon name="moon" class="size-5" /></span>
    <span x-show="dark" x-cloak class="contents"><x-ui.icon name="sun" class="size-5" /></span>
    <span class="sr-only">{{ __('Toggle dark mode') }}</span>
</button>
