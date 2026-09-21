{{--
    Workspace settings (App\Livewire\Tenant\Settings\WorkspaceSettings).
    Each row shows what the state would give this workspace, beside the value
    the workspace has chosen for itself.
--}}
<div>
    <x-ui.page-header
        :title="__('Workspace settings')"
        :description="__('Where this workspace runs a rule differently from the state. Anything you do not set follows the state, and keeps following it when the state changes.')"
    >
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="bell" :href="route('tenant.settings.notifications')">
                {{ __('Notification preferences') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <x-ui.alert variant="positive" class="mb-5" dismissible>{{ session('status') }}</x-ui.alert>
    @endif

    @if ($failure)
        <x-ui.alert variant="critical" class="mb-5" :title="__('That setting could not be saved')">{{ $failure }}</x-ui.alert>
    @endif

    <div class="grid gap-4 lg:grid-cols-4">
        <nav class="lg:col-span-1" aria-label="{{ __('Settings groups') }}">
            <x-ui.card flush>
                <ul class="divide-y divide-line">
                    @foreach ($this->groups as $candidate)
                        <li wire:key="group-{{ $candidate }}">
                            <button
                                type="button"
                                wire:click="selectGroup('{{ $candidate }}')"
                                @if ($candidate === $group) aria-current="page" @endif
                                class="flex w-full items-center justify-between gap-2 px-4 py-3 text-left text-sm transition-colors focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-focus {{ $candidate === $group ? 'bg-brand-soft font-semibold text-brand-ink' : 'text-ink-muted hover:bg-neutral-soft hover:text-ink' }}"
                            >
                                <span>{{ $this->groupLabel($candidate) }}</span>
                                @if ($candidate === $group)
                                    <x-ui.icon name="chevron-right" class="size-4" />
                                @endif
                            </button>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        </nav>

        <div class="lg:col-span-3">
            <form wire:submit="saveGroup('{{ $group }}')">
                <x-ui.card
                    :title="$this->groupLabel($group)"
                    :subtitle="$this->groupDescription($group)"
                >
                    <div class="space-y-6">
                        @foreach ($this->definitions as $definition)
                            <div
                                wire:key="setting-{{ $definition->id() }}"
                                class="rounded-lg border border-line p-4 {{ $this->isOverridden($definition) ? 'bg-surface-sunken' : '' }}"
                            >
                                @include('livewire.shared.partials.setting-field', [
                                    'definition' => $definition,
                                    'model' => 'values.'.$definition->group.'.'.$definition->key,
                                    'hint' => $definition->hint,
                                    'disabled' => false,
                                ])

                                <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                                    <p class="inline-flex items-center gap-1.5 text-xs text-ink-muted">
                                        @if ($this->isOverridden($definition))
                                            <x-ui.icon name="pencil-square" class="size-3.5" />
                                            <span>
                                                {{ __('Set by this workspace.') }}
                                                <span class="text-ink-subtle">{{ __('The state uses :value.', ['value' => $this->inheritedDisplay($definition)]) }}</span>
                                            </span>
                                        @else
                                            <x-ui.icon name="arrows-right-left" class="size-3.5" />
                                            <span>
                                                {{ __('Following the state:') }}
                                                <span class="font-medium text-ink">{{ $this->inheritedDisplay($definition) }}</span>
                                            </span>
                                        @endif
                                    </p>

                                    @if ($this->isOverridden($definition))
                                        <x-ui.button
                                            variant="ghost"
                                            size="sm"
                                            icon="arrow-uturn-left"
                                            wire:click="clearOverride('{{ $definition->group }}', '{{ $definition->key }}')"
                                            wire:confirm="{{ __('Use the state\'s value for “:label” again? This workspace will follow the state whenever it changes.', ['label' => $definition->label]) }}"
                                        >{{ __('Use the state\'s value') }}</x-ui.button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <x-slot:footer>
                        <div class="flex justify-end">
                            <x-ui.button type="submit" icon="check" loading="saveGroup">
                                {{ __('Save :group', ['group' => $this->groupLabel($group)]) }}
                            </x-ui.button>
                        </div>
                    </x-slot:footer>
                </x-ui.card>
            </form>
        </div>
    </div>
</div>
