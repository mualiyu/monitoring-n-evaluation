{{--
    <x-ui.form.checkbox /> — native checkbox, brand-tinted via accent-color so it
    keeps platform semantics and renders correctly on old Android WebViews.
    Self-labelling (the label is part of the control), so it does NOT need
    <x-ui.form.group> unless you also want a hint or an error message.

    Props: name, id, label, description, value, checked

        <x-ui.form.checkbox name="remember" :label="__('Keep me signed in')"
                            :description="__('Not on a shared or public computer.')" />
--}}
@props([
    'name' => null,
    'id' => null,
    'label' => '',
    'description' => null,
    'value' => '1',
    'checked' => false,
])

@php
    $fieldId = $id ?? trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $name), '-');
    $hasError = $name && ($errors ?? null)?->has($name);
@endphp

<div class="flex items-start gap-3">
    <input
        type="checkbox"
        id="{{ $fieldId }}"
        @if ($name) name="{{ $name }}" @endif
        value="{{ $value }}"
        @checked($checked)
        @if ($description) aria-describedby="{{ $fieldId }}-description" @endif
        @if ($hasError) aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @endif
        {{ $attributes->class('mt-0.5 size-5 shrink-0 cursor-pointer accent-brand') }}
    />

    <div class="min-w-0">
        <label for="{{ $fieldId }}" class="cursor-pointer text-sm font-medium text-ink">{{ $label }}</label>
        @if ($description)
            <p id="{{ $fieldId }}-description" class="text-xs text-ink-muted">{{ $description }}</p>
        @endif
    </div>
</div>
