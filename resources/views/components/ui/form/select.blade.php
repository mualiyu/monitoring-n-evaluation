{{--
    <x-ui.form.select /> — native select (fast, keyboard-native, works offline on a
    cheap Android). Wrap in <x-ui.form.group> for label + error.

    Props
      name, id
      options      ['value' => 'Label'] or [['value' => …, 'label' => …], …] or a
                   plain list of strings
      selected     current value (ignored when using wire:model)
      placeholder  renders a disabled first option, e.g. "All MDAs"
      hasHint, describedBy — see <x-ui.form.input>

    Pass raw <option> markup in the slot instead of :options when you need groups.
--}}
@props([
    'name' => null,
    'id' => null,
    'options' => [],
    'selected' => null,
    'placeholder' => null,
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

    $normalised = collect($options)->map(function ($label, $key) {
        if (is_array($label)) {
            return ['value' => $label['value'] ?? '', 'label' => $label['label'] ?? ''];
        }

        return ['value' => is_int($key) ? $label : $key, 'label' => $label];
    })->values();
@endphp

<div class="relative">
    <select
        id="{{ $fieldId }}"
        @if ($name) name="{{ $name }}" @endif
        @if ($described) aria-describedby="{{ implode(' ', $described) }}" @endif
        @if ($hasError) aria-invalid="true" @endif
        {{ $attributes->class('ui-field appearance-none pr-10') }}
    >
        @if ($placeholder)
            <option value="">{{ $placeholder }}</option>
        @endif

        @foreach ($normalised as $option)
            <option value="{{ $option['value'] }}" @selected((string) $option['value'] === (string) $selected)>
                {{ $option['label'] }}
            </option>
        @endforeach

        {{ $slot }}
    </select>

    <x-ui.icon
        name="chevron-down"
        class="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-ink-subtle"
    />
</div>
