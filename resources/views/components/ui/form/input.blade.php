{{--
    <x-ui.form.input /> — text-ish control. Wrap it in <x-ui.form.group> for the
    label + hint + error (that component owns the a11y wiring).

    Props
      name      also drives the element id and the error lookup
      id        override the derived id
      type      text | number | email | password | date | search | tel …
      prefix    leading adornment, e.g. "₦"   (kept inside the field, not a placeholder)
      suffix    trailing adornment, e.g. "%"
      icon      leading <x-ui.icon> name (search fields)
      hasHint   the group above renders a hint → link it via aria-describedby
      describedBy  extra element ids to append

        <x-ui.form.input name="q" type="search" icon="magnifying-glass"
                         placeholder="Search projects…" wire:model.live.debounce.300ms="search" />
--}}
@props([
    'name' => null,
    'id' => null,
    'type' => 'text',
    'prefix' => null,
    'suffix' => null,
    'icon' => null,
    'hasHint' => false,
    'describedBy' => null,
])

@php
    $fieldId = $id ?? trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $name), '-');
    $hasError = $name && ($errors ?? null)?->has($name);

    $described = array_filter([
        $hasHint ? $fieldId.'-hint' : null,
        $hasError ? $fieldId.'-error' : null,
        $describedBy,
    ]);

    $padLeft = $icon ? 'pl-10' : ($prefix ? 'pl-9' : null);
    $padRight = $suffix ? 'pr-9' : null;
@endphp

<div class="relative">
    @if ($icon)
        <x-ui.icon :name="$icon" class="pointer-events-none absolute top-1/2 left-3 size-4.5 -translate-y-1/2 text-ink-subtle" />
    @elseif ($prefix)
        <span class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-ink-muted">{{ $prefix }}</span>
    @endif

    <input
        type="{{ $type }}"
        id="{{ $fieldId }}"
        @if ($name) name="{{ $name }}" @endif
        @if ($described) aria-describedby="{{ implode(' ', $described) }}" @endif
        @if ($hasError) aria-invalid="true" @endif
        {{ $attributes->class(['ui-field', $padLeft, $padRight]) }}
    />

    @if ($suffix)
        <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-ink-muted">{{ $suffix }}</span>
    @endif
</div>
