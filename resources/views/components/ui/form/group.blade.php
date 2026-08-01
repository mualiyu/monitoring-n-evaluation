{{--
    <x-ui.form.group /> — label + hint + control + inline error, wired for a11y.

    The group and the control derive the same element id from `name`, so
    aria-describedby lines up without passing ids around. When you give the group
    a :hint, tell the control about it with `has-hint` — that keeps the
    aria-describedby reference valid instead of pointing at a missing node.

        <x-ui.form.group name="contract_sum" label="Contract sum (₦)" hint="Excluding VAT" required>
            <x-ui.form.input name="contract_sum" type="number" has-hint wire:model.blur="form.contract_sum" />
        </x-ui.form.group>

    Props: name, label, hint, required, optional, id, error (force an error message)
--}}
@props([
    'name' => null,
    'label' => null,
    'hint' => null,
    'required' => false,
    'optional' => false,
    'id' => null,
    'error' => null,
])

@php
    $fieldId = $id ?? trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $name), '-');
@endphp

<div {{ $attributes->class('space-y-1.5') }}>
    @if ($label)
        <x-ui.form.label :for="$fieldId" :required="$required" :optional="$optional">{{ $label }}</x-ui.form.label>
    @endif

    @if ($hint)
        <p id="{{ $fieldId }}-hint" class="text-xs text-ink-muted">{{ $hint }}</p>
    @endif

    {{ $slot }}

    <x-ui.form.error :name="$name" :id="$fieldId.'-error'" :message="$error" />
</div>
