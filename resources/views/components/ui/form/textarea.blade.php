{{--
    <x-ui.form.textarea /> — long-form narrative (challenges encountered, remarks,
    stakeholder feedback). Wrap in <x-ui.form.group> for label + error.

    Props
      name, id, rows
      maxlength   enables the live character counter (plain Alpine, no plugin)
      counter     force the counter on/off
      hasHint, describedBy — see <x-ui.form.input>
--}}
@props([
    'name' => null,
    'id' => null,
    'rows' => 4,
    'maxlength' => null,
    'counter' => null,
    'hasHint' => false,
    'describedBy' => null,
])

@php
    $fieldId = $id ?? trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $name), '-');
    $hasError = $name && ($errors ?? null)?->has($name);
    $showCounter = $counter ?? filled($maxlength);

    $described = array_filter([
        $hasHint ? $fieldId.'-hint' : null,
        $hasError ? $fieldId.'-error' : null,
        $showCounter ? $fieldId.'-counter' : null,
        $describedBy,
    ]);
@endphp

<div @if ($showCounter) x-data="{ used: 0 }" x-init="used = $refs.field.value.length" @endif>
    <textarea
        id="{{ $fieldId }}"
        rows="{{ $rows }}"
        @if ($name) name="{{ $name }}" @endif
        @if ($maxlength) maxlength="{{ $maxlength }}" @endif
        @if ($showCounter) x-ref="field" x-on:input="used = $event.target.value.length" @endif
        @if ($described) aria-describedby="{{ implode(' ', $described) }}" @endif
        @if ($hasError) aria-invalid="true" @endif
        {{ $attributes->class('ui-field min-h-24 resize-y leading-6') }}
    >{{ $slot }}</textarea>

    @if ($showCounter)
        <p id="{{ $fieldId }}-counter" class="mt-1 text-right text-xs text-ink-muted tabular-nums">
            <span x-text="used">0</span>@if ($maxlength) / {{ $maxlength }} @endif
            <span class="sr-only">{{ __('characters used') }}</span>
        </p>
    @endif
</div>
