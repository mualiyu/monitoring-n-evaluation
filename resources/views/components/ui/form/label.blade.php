{{--
    <x-ui.form.label /> — every input gets one. Placeholders are not labels.

    Props: for, required, optional
--}}
@props([
    'for' => null,
    'required' => false,
    'optional' => false,
])

<label
    @if ($for) for="{{ $for }}" @endif
    {{ $attributes->class('block text-sm font-medium text-ink') }}
>
    {{ $slot }}

    @if ($required)
        <span aria-hidden="true" class="text-critical-ink">*</span>
        <span class="sr-only">({{ __('required') }})</span>
    @elseif ($optional)
        <span class="ml-1 text-xs font-normal text-ink-muted">({{ __('optional') }})</span>
    @endif
</label>
