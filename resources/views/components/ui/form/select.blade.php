{{--
    <x-ui.form.select /> — native select (fast, keyboard-native, works offline on a
    cheap Android). Wrap in <x-ui.form.group> for label + error.

    Props
      name, id
      options      ['value' => 'Label'] or [['value' => …, 'label' => …], …] or a
                   plain list of strings. An id-keyed map (->pluck('name','id'))
                   is the first form — its keys become the option values.
                   Call ->values() on anything you ->filter(): a gappy integer
                   -keyed array is not a list and not a map, and its indices
                   would be rendered as the values.
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

    /*
        A plain list (['Draft', 'Submitted']) uses each entry as both value and
        label. An associative map uses the KEY as the value — and that includes
        id-keyed maps like ->pluck('name', 'id'), whose keys are integers.

        Telling those apart needs array_is_list(), NOT is_int($key): an id-keyed
        map has integer keys too, so an is_int() test classified every record
        picker as a plain list and rendered the NAME as the option value. Every
        select that picks a row by id then submitted a label where an id was
        expected, and the field failed `exists` validation ("The selected sector
        is invalid"). array_is_list() is exact — a list's keys are 0..n-1 — and
        `$table->id()` starts at 1, so a plucked map is never mistaken for one.
    */
    $entries = $options instanceof \Illuminate\Support\Collection ? $options->all() : (array) $options;
    $isPlainList = array_is_list($entries);

    $normalised = collect($entries)->map(function ($label, $key) use ($isPlainList) {
        if (is_array($label)) {
            return ['value' => $label['value'] ?? '', 'label' => $label['label'] ?? ''];
        }

        return ['value' => $isPlainList ? $label : $key, 'label' => $label];
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
