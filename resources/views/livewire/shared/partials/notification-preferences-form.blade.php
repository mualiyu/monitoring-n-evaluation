{{--
    The notification preferences form, shared by the workspace and oversight
    screens (App\Livewire\Shared\Concerns\ManagesNotificationPreferences).
--}}
@if (session('status'))
    <x-ui.alert variant="positive" class="mb-5" dismissible>{{ session('status') }}</x-ui.alert>
@endif

<form wire:submit="save">
    <x-ui.card :title="__('What you are told about')" :subtitle="__('In-app notifications appear in the bell; email arrives at your registered address.')">
        <ul class="space-y-4">
            @foreach ($this->categories as $key => $category)
                <li class="rounded-lg border border-line p-4" wire:key="category-{{ $key }}">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-ink">{{ $category['label'] }}</p>
                            <p class="mt-0.5 text-xs text-ink-muted">{{ $category['description'] }}</p>
                        </div>

                        <div class="flex shrink-0 flex-wrap gap-4">
                            @if ($category['mutable'])
                                @foreach ($this->channels() as $channel)
                                    <x-ui.form.checkbox
                                        :name="'preferences.'.$key.'.'.$channel"
                                        :id="'pref-'.$key.'-'.$channel"
                                        :label="$this->channelLabel($channel)"
                                        wire:model="preferences.{{ $key }}.{{ $channel }}"
                                    />
                                @endforeach
                            @else
                                <span class="inline-flex items-center gap-1.5 rounded-md bg-neutral-soft px-2 py-1 text-xs font-medium text-neutral-ink">
                                    <x-ui.icon name="shield-check" class="size-3.5" />
                                    {{ __('Always sent') }}
                                </span>
                            @endif
                        </div>
                    </div>

                    @unless ($category['mutable'])
                        <p class="mt-2 text-xs text-ink-subtle">
                            {{ __('These messages carry a sign-in link or an expiry, so they cannot be switched off.') }}
                        </p>
                    @endunless
                </li>
            @endforeach
        </ul>

        <x-slot:footer>
            <div class="flex justify-end">
                <x-ui.button type="submit" icon="check" loading="save">{{ __('Save preferences') }}</x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.card>
</form>
