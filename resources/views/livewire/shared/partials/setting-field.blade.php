{{--
    One configurable setting, rendered from its App\Actions\Settings\SettingDefinition.

    Shared by the instance screen and the workspace override screen so a field
    cannot look — or validate — differently depending on which one you opened.

    @include('livewire.shared.partials.setting-field', [
        'definition' => $definition,
        'model'      => "values.{$definition->group}.{$definition->key}",
        'hint'       => null,       // overrides the definition's own hint
        'disabled'   => false,
    ])
--}}
@php
    $hint = $hint ?? $definition->hint;
    $disabled = $disabled ?? false;
    $field = str_replace('.', '_', $model);
@endphp

@if ($definition->type === \App\Actions\Settings\SettingDefinition::TYPE_BOOL)
    <div>
        <x-ui.form.checkbox
            :name="$model"
            :id="$field"
            :label="$definition->label"
            :description="$hint"
            wire:model="{{ $model }}"
            :disabled="$disabled"
        />
        <x-ui.form.error :name="$model" />
    </div>
@else
    <x-ui.form.group :name="$model" :id="$field" :label="$definition->label" :hint="$hint">
        @if ($definition->type === \App\Actions\Settings\SettingDefinition::TYPE_SELECT)
            <x-ui.form.select
                :name="$model"
                :id="$field"
                :options="$definition->options"
                wire:model="{{ $model }}"
                :disabled="$disabled"
            />
        @elseif ($definition->type === \App\Actions\Settings\SettingDefinition::TYPE_TEXT)
            <x-ui.form.textarea
                :name="$model"
                :id="$field"
                rows="3"
                wire:model="{{ $model }}"
                :disabled="$disabled"
            />
        @elseif ($definition->type === \App\Actions\Settings\SettingDefinition::TYPE_INT)
            <x-ui.form.input
                :name="$model"
                :id="$field"
                type="number"
                inputmode="numeric"
                wire:model="{{ $model }}"
                :disabled="$disabled"
            />
        @else
            <x-ui.form.input
                :name="$model"
                :id="$field"
                type="text"
                wire:model="{{ $model }}"
                :disabled="$disabled"
            />
        @endif
    </x-ui.form.group>
@endif
