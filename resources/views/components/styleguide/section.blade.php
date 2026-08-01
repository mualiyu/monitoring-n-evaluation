{{--
    Docs-only wrapper used by resources/views/styleguide.blade.php.
    Not part of the product component library — do not use it on real screens.
--}}
@props([
    'id' => null,
    'title' => '',
    'description' => null,
    'usage' => null,
])

<section @if ($id) id="{{ $id }}" @endif {{ $attributes->class('scroll-mt-20 space-y-4') }}>
    <div class="border-b border-line pb-3">
        <h2 class="text-lg font-semibold tracking-tight text-ink">{{ $title }}</h2>
        @if ($description)
            <p class="mt-1 max-w-3xl text-sm text-ink-muted">{{ $description }}</p>
        @endif
        @if ($usage)
            <p class="mt-2 inline-block rounded-md bg-surface-sunken px-2 py-1 font-mono text-xs text-ink-muted">{{ $usage }}</p>
        @endif
    </div>

    {{ $slot }}
</section>
